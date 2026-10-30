<?php

declare(strict_types=1);

namespace Sms;

/**
 * S-expression template engine for SMS message token expansion.
 *
 * Expands %word% and %(fn arg)% tokens using registered functions and
 * a caller-supplied variable resolver.
 *
 * @see TemplateException
 * @see TokenExpandingSmsProvider
 */

final class Templater
{
    /** @var array<string, callable(string ...$args): string> */
    private array $functions = [];

    /**
     * @param array<string, callable> $functions  Initial function registry, name => callable
     */
    public function __construct(array $functions = [])
    {
        $this->functions = $functions;
    }

    /**
     * Register a function callable from s-expressions.
     *
     * The callable receives evaluated string arguments and must return string.
     * It may throw for unrecoverable errors — the evaluator catches these
     * and converts them to Result::failure().
     *
     *   $t->registerFunction('upper', strtoupper(...));
     *   $t->registerFunction('shorten', fn(string $url) => $shortener->shorten($url));
     */
    public function registerFunction(string $name, callable $fn): void
    {
        $this->functions[$name] = $fn;
    }

    /**
     * Get names of all registered functions.
     *
     * @return string[]
     */
    public function getFunctionNames(): array
    {
        return array_keys($this->functions);
    }

    /**
     * Whether the template contains any %...% tokens (including s-expressions).
     *
     * A bare '%' like "20% off" returns false — only %word% or %(...)% patterns
     * count as tokens.
     */
    public function hasTokens(string $template): bool
    {
        return str_contains($template, '%')
            && (str_contains($template, '%(') || preg_match('/%\w+%/', $template));
    }

    /**
     * Whether the template contains something that really looks like a token:
     * `%name%`, or `%(fn … )%`. Plain prose such as "Up 10%(approx)" contains
     * `%(` but is not token-shaped; senders can treat that literally instead of
     * failing the send, while a malformed but recognisable token (e.g.
     * `%(firstname%`) still fails, so it is not sent to everyone unexpanded.
     */
    public function hasRecognisableToken(string $template): bool
    {
        // A complete s-expression (%( … )%), a token-shaped but unclosed one
        // (%(name … with no ')'), or a legacy %word%.
        return (bool) preg_match('/%\([^%()]*\)%/', $template)
            || (bool) preg_match('/%\([A-Za-z_][\w-]*%/', $template)
            || (bool) preg_match('/%[A-Za-z_][\w-]*%/', $template);
    }

    /**
     * A snippet around the first unparseable token, for a statusline warning,
     * or null when the template parses (or has no token syntax at all).
     */
    public function unparseableSnippet(string $template, int $length = 30): ?string
    {
        if (!str_contains($template, '%(') || !$this->hasRecognisableToken($template)) {
            return null;
        }
        $result = $this->expand($template, static fn(string $name): ?string => null);
        if ($result->isSuccess()) {
            return null;
        }
        $error = (string) $result->getError();
        // Only syntax errors are "unrecognised tokens". Unknown variables or
        // functions are a different problem (and a valid token may simply need
        // a resolver the caller hasn't supplied).
        if (!preg_match('/Unclosed |Expected "%" to close token|Unexpected/', $error)) {
            return null;
        }
        $offset = preg_match('/position (\d+)/', $error, $m) ? (int) $m[1] : 0;
        return mb_substr($template, max(0, $offset - 10), $length, 'UTF-8');
    }

    /**
     * Check whether the template references any of the given variable names.
     *
     * Used by TokenExpandingSmsProvider to decide whether per-recipient
     * fan-out is needed.
     *
     * @param string[] $varNames  Known variable names
     */
    public function referencesVariables(string $template, array $varNames): bool
    {
        if ($varNames === [] || !str_contains($template, '%')) {
            return false;
        }

        // Legacy %word% tokens: fast regex check
        $alt = implode('|', array_map('preg_quote', $varNames));
        if (preg_match('/%(' . $alt . ')%/', $template)) {
            return true;
        }

        // S-expression tokens %(...)%: parse each and walk for atoms
        if (!str_contains($template, '%(')) {
            return false;
        }

        return $this->walkSexprsForVars($template, $varNames);
    }

    /**
     * Expand all %...% tokens in the template.
     *
     * @param callable(string $name): ?string $resolveVar  Variable resolver.
     *        Receives a variable name; return null if unknown.
     * @return \Result  Success with expanded string, or failure with error message.
     */
    public function expand(string $template, callable $resolveVar): \Result
    {
        try {
            return \Result::success($this->expandOrThrow($template, $resolveVar));
        } catch (TemplateException $e) {
            return \Result::failure($e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Internal
    // ------------------------------------------------------------------

    /**
     * Expand template, throwing on errors.
     *
     * @param callable(string $name): ?string $resolveVar
     * @throws TemplateException
     */
    private function expandOrThrow(string $template, callable $resolveVar): string
    {
        $result = '';
        $len    = strlen($template);
        $i      = 0;

        while ($i < $len) {
            // Look for '%'
            if ($template[$i] !== '%') {
                $result .= $template[$i];
                $i++;
                continue;
            }

            $start = $i;
            $i++; // consume '%'

            if ($i >= $len) {
                $result .= '%';
                break;
            }

            if ($template[$i] === '(') {
                // %(sexpr)%  —  s-expression token
                $i++; // consume '('
                $depth    = 1;
                $exprStart = $i;

                while ($i < $len && $depth > 0) {
                    if ($template[$i] === '(') {
                        $depth++;
                    } elseif ($template[$i] === ')') {
                        $depth--;
                    }
                    $i++;
                }

                if ($depth !== 0) {
                    throw new TemplateException(sprintf(
                        'Unclosed "(" in token at position %d',
                        $start,
                    ));
                }

                // i now points past the closing ')'.  Expect '%'.
                if ($i >= $len || $template[$i] !== '%') {
                    throw new TemplateException(sprintf(
                        'Expected "%%" to close token at position %d, got "%s"',
                        $i,
                        $i < $len ? $template[$i] : 'end of string',
                    ));
                }
                $i++; // consume closing '%'

                // Extract the s-expression body (between '(' and ')')
                $bodyLen = ($i - 2) - $exprStart; // -2 for trailing ')%'
                $body    = substr($template, $exprStart, $bodyLen);
                $tokens  = $this->tokenize($body);
                $pos     = 0;
                $result .= $this->evalExpr($tokens, $pos, $resolveVar);

            } else {
                // %word%  —  legacy variable token
                $wordStart = $i;
                while ($i < $len && (ctype_alnum($template[$i]) || $template[$i] === '_')) {
                    $i++;
                }

                if ($i >= $len || $template[$i] !== '%') {
                    // Not a valid token — treat as literal '%...'
                    $result .= substr($template, $start, $i - $start);
                    continue;
                }
                $i++; // consume closing '%'

                $word = substr($template, $wordStart, $i - $wordStart - 1);
                $value = $resolveVar($word);
                if ($value === null) {
                    // Unknown variable → leave literal unchanged (backward compat)
                    $result .= substr($template, $start, $i - $start);
                } else {
                    $result .= $value;
                }
            }
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // Tokeniser
    // ------------------------------------------------------------------

    /**
     * Split an s-expression body into tokens.
     *
     * Tokens are: '(' , ')' , quoted strings, and bare words.
     * Whitespace is ignored except as token separator.
     *
     * @param string $expr  The s-expression body (without surrounding parens)
     * @return string[]
     * @throws TemplateException
     */
    private function tokenize(string $expr): array
    {
        $tokens = [];
        $len    = strlen($expr);
        $i      = 0;

        while ($i < $len) {
            $ch = $expr[$i];

            // Whitespace
            if (ctype_space($ch)) {
                $i++;
                continue;
            }

            // Parens stand alone
            if ($ch === '(' || $ch === ')') {
                $tokens[] = $ch;
                $i++;
                continue;
            }

            // Quoted string
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                $i++; // consume opening quote
                $str = '';
                while ($i < $len && $expr[$i] !== $quote) {
                    if ($expr[$i] === '\\' && $i + 1 < $len) {
                        $i++;
                        $str .= $expr[$i];
                    } else {
                        $str .= $expr[$i];
                    }
                    $i++;
                }
                if ($i >= $len) {
                    throw new TemplateException(sprintf(
                        'Unclosed string literal: %s%s',
                        $quote,
                        $str,
                    ));
                }
                $i++; // consume closing quote
                // Store with quotes so evaluator can distinguish from variables
                $tokens[] = $quote . $str . $quote;
                continue;
            }

            // Bare word (variable name or function name)
            $word = '';
            while ($i < $len && !ctype_space($expr[$i]) && $expr[$i] !== '(' && $expr[$i] !== ')') {
                $word .= $expr[$i];
                $i++;
            }
            if ($word !== '') {
                $tokens[] = $word;
            }
        }

        return $tokens;
    }

    // ------------------------------------------------------------------
    // Evaluator
    // ------------------------------------------------------------------

    /**
     * Evaluate an s-expression from a token stream.
     *
     * @param string[] $tokens
     * @param int      $pos     Current position (mutated)
     * @param callable(string): ?string $resolveVar
     * @return string
     * @throws TemplateException
     */
    private function evalExpr(array $tokens, int &$pos, callable $resolveVar): string
    {
        if ($pos >= count($tokens)) {
            throw new TemplateException('Unexpected end of expression');
        }

        $token = $tokens[$pos];
        $pos++;

        // '(' → function call: (function_name arg*)
        if ($token === '(') {
            if ($pos >= count($tokens)) {
                throw new TemplateException('Expected function name after "("');
            }
            $fnName = $tokens[$pos];
            $pos++;

            if ($fnName === '(' || $fnName === ')') {
                throw new TemplateException(sprintf(
                    'Expected function name, got "%s"',
                    $fnName,
                ));
            }

            $args = [];
            while ($pos < count($tokens) && $tokens[$pos] !== ')') {
                $args[] = $this->evalExpr($tokens, $pos, $resolveVar);
            }

            if ($pos >= count($tokens)) {
                throw new TemplateException(sprintf(
                    'Missing ")" to close function call "%s"',
                    $fnName,
                ));
            }
            $pos++; // consume ')'

            if (!isset($this->functions[$fnName])) {
                throw new TemplateException(sprintf(
                    'Unknown function: %s',
                    $fnName,
                ));
            }

            try {
                return ($this->functions[$fnName])(...$args);
            } catch (\Throwable $e) {
                if ($e instanceof TemplateException) {
                    throw $e;
                }
                throw new TemplateException(sprintf(
                    'Error calling "%s": %s',
                    $fnName,
                    $e->getMessage(),
                ));
            }
        }

        // ')' with nothing to close
        if ($token === ')') {
            throw new TemplateException('Unexpected ")"');
        }

        // Quoted string literal
        if ((str_starts_with($token, '"') && str_ends_with($token, '"'))
            || (str_starts_with($token, "'") && str_ends_with($token, "'"))) {
            return substr($token, 1, -1);
        }

        // Bare word — could be a variable or a function call.
        //
        //   single token  → variable (or zero-arg function if registered)
        //   has args      → function call:  fnName arg1 arg2 ...
        //
        // This means %(upper firstname)% works without double-parens.

        // Zero-arg function: single token matching a registered function.
        if ($pos >= count($tokens) || $tokens[$pos] === ')') {
            if (isset($this->functions[$token])) {
                try {
                    return ($this->functions[$token])();
                } catch (\Throwable $e) {
                    if ($e instanceof TemplateException) {
                        throw $e;
                    }
                    throw new TemplateException(sprintf(
                        'Error calling "%s": %s',
                        $token,
                        $e->getMessage(),
                    ));
                }
            }
            // Variable
            $value = $resolveVar($token);
            if ($value === null) {
                throw new TemplateException(sprintf(
                    'Unknown variable: %s',
                    $token,
                ));
            }
            return $value;
        }

        // Has arguments → function call if registered:  fnName arg1 arg2 ...
        // Bare words that are NOT registered functions are always variables.
        if (!isset($this->functions[$token])) {
            // Not a function — treat as variable (even with trailing tokens,
            // those belong to an outer function call's arg list).
            $value = $resolveVar($token);
            if ($value === null) {
                throw new TemplateException(sprintf(
                    'Unknown variable: %s',
                    $token,
                ));
            }
            return $value;
        }

        $fnName = $token;

        $args = [];
        while ($pos < count($tokens) && $tokens[$pos] !== ')') {
            $args[] = $this->evalExpr($tokens, $pos, $resolveVar);
        }

        try {
            return ($this->functions[$fnName])(...$args);
        } catch (\Throwable $e) {
            if ($e instanceof TemplateException) {
                throw $e;
            }
            throw new TemplateException(sprintf(
                'Error calling "%s": %s',
                $fnName,
                $e->getMessage(),
            ));
        }
    }

    // ------------------------------------------------------------------
    // Variable scanning (for fan-out decision)
    // ------------------------------------------------------------------

    /**
     * Walk all s-expression tokens looking for variable references.
     *
     * Non-allocating: does not evaluate, just checks whether tokenised atoms
     * appear in $varNames.
     *
     * @param string[] $varNames
     */
    private function walkSexprsForVars(string $template, array $varNames): bool
    {
        $varSet = array_flip($varNames);

        // Find each %(...)% region and tokenise it
        $len = strlen($template);
        $i   = 0;

        while ($i < $len) {
            if ($template[$i] !== '%') {
                $i++;
                continue;
            }
            $i++; // consume '%'
            if ($i >= $len || $template[$i] !== '(') {
                continue;
            }
            $i++; // consume '('
            $depth    = 1;
            $exprStart = $i;

            while ($i < $len && $depth > 0) {
                if ($template[$i] === '(') {
                    $depth++;
                } elseif ($template[$i] === ')') {
                    $depth--;
                }
                $i++;
            }

            // Extract body and check each token
            $bodyLen = $i - $exprStart - 1; // -1 for trailing ')'
            $body    = substr($template, $exprStart, $bodyLen);

            try {
                $tokens = $this->tokenize($body);
            } catch (TemplateException $e) {
                // If we can't tokenise, treat it as token-bearing (will
                // error during expand() anyway).
                return true;
            }

            foreach ($tokens as $tok) {
                // Only bare words (variables), not strings or parens
                if ($tok !== '(' && $tok !== ')' && !str_starts_with($tok, '"') && !str_starts_with($tok, "'")) {
                    if (isset($varSet[$tok])) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
