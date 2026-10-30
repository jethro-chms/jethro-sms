<?php

/**
 * Unit tests for jethro-sms/src/Templater.php — s-expression templater.
 *
 * Tests the Templater class and its integration with the %...% SMS token
 * syntax.  Covers:
 *   - hasTokens() detection
 *   - referencesVariables() fan-out decision
 *   - expand() with legacy %word% variables
 *   - expand() with %(sexpr)% function calls
 *   - Error handling
 *   - Edge cases
 */

namespace Test\Sms\Token;

use function \Test\{test, assert_true, assert_false, assert_eq, assert_contains, assert_not_contains, assert_throws};
use \Sms\Templater;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../../src/load.php';

// ---------------------------------------------------------------------------
// hasTokens()
// ---------------------------------------------------------------------------

test('hasTokens: empty string → false', function () {
    $t = new Templater();
    assert_false($t->hasTokens(''), 'Empty string has no tokens');
});

test('hasTokens: bare "%" → false', function () {
    $t = new Templater();
    assert_false($t->hasTokens('Save 20% off'), 'Bare percent is not a token');
});

test('hasTokens: "%word%" → true', function () {
    $t = new Templater();
    assert_true($t->hasTokens('Hi %firstname%'), 'Percent-delimited word is a token');
});

test('hasTokens: "%(...)%" → true', function () {
    $t = new Templater();
    assert_true($t->hasTokens('%(hello)%'), 'S-expression is a token');
});

test('hasTokens: "%(fn arg)%" → true', function () {
    $t = new Templater();
    assert_true($t->hasTokens('%(upper firstname)%'), 'S-expression with args is a token');
});

test('hasTokens: no "%" → false', function () {
    $t = new Templater();
    assert_false($t->hasTokens('Hello world'), 'No percent sign → false');
});

test('hasTokens: "%" at end of string → false', function () {
    $t = new Templater();
    assert_false($t->hasTokens('Hello%'), 'Trailing bare percent is not a token');
});

// ---------------------------------------------------------------------------
// referencesVariables()
// ---------------------------------------------------------------------------

test('referencesVariables: empty var list → false', function () {
    $t = new Templater();
    assert_false($t->referencesVariables('%firstname%', []), 'Empty var list always false');
});

test('referencesVariables: no "%" → false', function () {
    $t = new Templater();
    assert_false($t->referencesVariables('Hello', ['firstname']), 'No percent → false');
});

test('referencesVariables: "20% off" → false', function () {
    $t = new Templater();
    assert_false($t->referencesVariables('Save 20% off', ['firstname']), 'Bare percent → false');
});

test('referencesVariables: known %word% → true', function () {
    $t = new Templater();
    assert_true($t->referencesVariables('Hi %firstname%', ['firstname', 'lastname']), 'Known variable → true');
});

test('referencesVariables: unknown %word% → false', function () {
    $t = new Templater();
    assert_false($t->referencesVariables('%unknown% applies', ['firstname']), 'Unknown variable → false');
});

test('referencesVariables: %(...)% with known variable → true', function () {
    $t = new Templater();
    assert_true($t->referencesVariables('%(upper firstname)%', ['firstname']), 'S-expr referencing known var → true');
});

test('referencesVariables: %(...)% without known variable → false', function () {
    $t = new Templater();
    assert_false($t->referencesVariables('%(shorten "https://static.url")%', ['firstname']), 'S-expr without known var → false');
});

test('referencesVariables: nested %(...)% with known var → true', function () {
    $t = new Templater();
    assert_true(
        $t->referencesVariables('%(shorten (concat "https://x.com/" firstname))%', ['firstname', 'lastname']),
        'Nested s-expr referencing known var → true',
    );
});

test('referencesVariables: s-expr with quoted string matching var name is NOT a reference', function () {
    $t = new Templater();
    // "firstname" is a quoted string, not a variable reference — so should return false
    assert_false(
        $t->referencesVariables('%(echo "firstname")%', ['firstname']),
        'Quoted string matching var name is not a variable reference',
    );
});

// ---------------------------------------------------------------------------
// expand() — legacy %word% variables
// ---------------------------------------------------------------------------

test('expand: no tokens → returns input unchanged', function () {
    $t = new Templater();
    $r = $t->expand('Hello world', fn(string $n): ?string => null);
    assert_true($r->isSuccess(), 'Expected success');
    assert_eq($r->getValue(), 'Hello world', 'No tokens must return unchanged');
});

test('expand: "20% off" → literal, no expansion', function () {
    $t = new Templater();
    $r = $t->expand('Save 20% off today', fn(string $n): ?string => null);
    assert_true($r->isSuccess(), 'Expected success');
    assert_eq($r->getValue(), 'Save 20% off today', 'Bare percent must pass through');
});

test('expand: known %word% → expanded', function () {
    $t = new Templater();
    $r = $t->expand('Hi %firstname%!', fn(string $n): ?string => match($n) {
        'firstname' => 'Alice',
        default => null,
    });
    assert_true($r->isSuccess(), 'Expected success');
    assert_eq($r->getValue(), 'Hi Alice!', 'Known variable must expand');
});

test('expand: unknown %word% → literal preserved', function () {
    $t = new Templater();
    $r = $t->expand('%discount% applies', fn(string $n): ?string => null);
    assert_true($r->isSuccess(), 'Expected success');
    assert_eq($r->getValue(), '%discount% applies', 'Unknown word token preserved literally');
});

test('expand: multiple %word% tokens', function () {
    $t = new Templater();
    $resolve = fn(string $n): ?string => match($n) {
        'firstname' => 'Alice',
        'lastname'  => 'Smith',
        default     => null,
    };
    $r = $t->expand('%firstname% %lastname%', $resolve);
    assert_true($r->isSuccess(), 'Expected success');
    assert_eq($r->getValue(), 'Alice Smith', 'Multiple tokens expand correctly');
});

test('expand: "%word%" with trailing text after "%" → literal', function () {
    $t = new Templater();
    $r = $t->expand('Price: %5 off', fn(string $n): ?string => null);
    assert_true($r->isSuccess(), 'Expected success');
    assert_eq($r->getValue(), 'Price: %5 off', 'Percent-digit-word is literal');
});

// ---------------------------------------------------------------------------
// expand() — %(sexpr)% function calls
// ---------------------------------------------------------------------------

test('expand: bare variable in %(var)%', function () {
    $t = new Templater();
    $r = $t->expand('%(firstname)%', fn(string $n): ?string => match($n) {
        'firstname' => 'Jeff',
        default => null,
    });
    assert_true($r->isSuccess(), 'Expected success');
    assert_eq($r->getValue(), 'Jeff', 'Bare variable in s-expr expands');
});

test('expand: simple function call (upper)', function () {
    $t = new Templater();
    $t->registerFunction('upper', strtoupper(...));
    $r = $t->expand('%(upper "hello")%', fn(string $n): ?string => null);
    assert_true($r->isSuccess(), 'Expected success');
    assert_eq($r->getValue(), 'HELLO', 'upper() works');
});

test('expand: function call with variable arg — flat form %(fn var)%', function () {
    $t = new Templater();
    $t->registerFunction('upper', strtoupper(...));
    $r = $t->expand('%(upper firstname)%', fn(string $n): ?string => match($n) {
        'firstname' => 'jeff',
        default => null,
    });
    assert_true($r->isSuccess(), 'Expected success');
    assert_eq($r->getValue(), 'JEFF', 'flat %(upper firstname)% works without double parens');
});

test('expand: function call with mixed string and variable args', function () {
    $t = new Templater();
    $t->registerFunction('concat', fn(string ...$parts): string => implode('', $parts));
    $r = $t->expand('%(concat "Hello, " firstname "!")%', fn(string $n): ?string => match($n) {
        'firstname' => 'World',
        default => null,
    });
    assert_true($r->isSuccess(), 'Expected success');
    assert_eq($r->getValue(), 'Hello, World!', 'mixed string + variable in flat form');
});

test('expand: flat form, variable not a function → still treated as variable', function () {
    $t = new Templater();
    $t->registerFunction('concat', fn(string ...$parts): string => implode('', $parts));
    // 'firstname' is not a registered function, so even though it's followed by
    // more tokens (the string "!"), it remains a variable reference, not a fn call.
    // The string "!" gets consumed as another arg to concat.
    $r = $t->expand('%(concat firstname "!")%', fn(string $n): ?string => match($n) {
        'firstname' => 'Jeff',
        default => null,
    });
    assert_true($r->isSuccess(), 'Expected success');
    assert_eq($r->getValue(), 'Jeff!', 'unregistered bare word followed by tokens → variable, not fn');
});

test('expand: function receives variable value', function () {
    $t = new Templater();
    $t->registerFunction('upper', strtoupper(...));
    $r = $t->expand('%(upper firstname)%', fn(string $n): ?string => match($n) {
        'firstname' => 'jeff',
        default => null,
    });
    assert_true($r->isSuccess(), 'Expected success');
    assert_eq($r->getValue(), 'JEFF', 'upper(firstname) works');
});

test('expand: concat function with string and variable', function () {
    $t = new Templater();
    $t->registerFunction('concat', fn(string ...$parts): string => implode('', $parts));
    $r = $t->expand('%(concat "https://x.com/" persontoken)%', fn(string $n): ?string => match($n) {
        'persontoken' => 'abc123',
        default => null,
    });
    assert_true($r->isSuccess(), 'Expected success');
    assert_eq($r->getValue(), 'https://x.com/abc123', 'concat with string + variable works');
});

test('expand: nested function calls', function () {
    $t = new Templater();
    $t->registerFunction('concat', fn(string ...$parts): string => implode('', $parts));
    $t->registerFunction('upper', strtoupper(...));
    $r = $t->expand('%(upper (concat "hello" "world"))%', fn(string $n): ?string => null);
    assert_true($r->isSuccess(), 'Expected success');
    assert_eq($r->getValue(), 'HELLOWORLD', 'Nested call (upper (concat ...)) works');
});

test('expand: function with multiple string args', function () {
    $t = new Templater();
    $t->registerFunction('concat', fn(string ...$parts): string => implode('/', $parts));
    $r = $t->expand('%(concat "a" "b" "c")%', fn(string $n): ?string => null);
    assert_true($r->isSuccess(), 'Expected success');
    assert_eq($r->getValue(), 'a/b/c', 'Multi-arg function works');
});

test('expand: mixed %word% and %(sexpr)% in same message', function () {
    $t = new Templater();
    $t->registerFunction('upper', strtoupper(...));
    $resolve = fn(string $n): ?string => match($n) {
        'firstname' => 'jeff',
        'lastname'  => 'turner',
        default     => null,
    };
    $r = $t->expand('Hi %firstname% %(upper lastname)%', $resolve);
    assert_true($r->isSuccess(), 'Expected success');
    assert_eq($r->getValue(), 'Hi jeff TURNER', 'Mixed legacy and s-expr tokens');
});

// ---------------------------------------------------------------------------
// expand() — string literals
// ---------------------------------------------------------------------------

test('expand: double-quoted string', function () {
    $t = new Templater();
    $t->registerFunction('echo', fn(string $s): string => $s);
    $r = $t->expand('%(echo "hello world")%', fn(string $n): ?string => null);
    assert_true($r->isSuccess(), 'Expected success');
    assert_eq($r->getValue(), 'hello world', 'Double-quoted string works');
});

test('expand: single-quoted string', function () {
    $t = new Templater();
    $t->registerFunction('echo', fn(string $s): string => $s);
    $r = $t->expand("%(echo 'hello world')%", fn(string $n): ?string => null);
    assert_true($r->isSuccess(), 'Expected success');
    assert_eq($r->getValue(), 'hello world', 'Single-quoted string works');
});

test('expand: string with escaped quote', function () {
    $t = new Templater();
    $t->registerFunction('echo', fn(string $s): string => $s);
    $r = $t->expand("%(echo 'it\\'s working')%", fn(string $n): ?string => null);
    assert_true($r->isSuccess(), 'Expected success');
    assert_eq($r->getValue(), "it's working", 'Escaped single quote works');
});

test('expand: string containing "%" inside %(...)%', function () {
    $t = new Templater();
    $t->registerFunction('echo', fn(string $s): string => $s);
    $r = $t->expand('%(echo "20% off")%', fn(string $n): ?string => null);
    assert_true($r->isSuccess(), 'Expected success');
    assert_eq($r->getValue(), '20% off', 'Percent inside s-expr string works');
});

// ---------------------------------------------------------------------------
// expand() — error handling
// ---------------------------------------------------------------------------

test('expand: unknown function via flat form → treated as variable, error on unresolvable', function () {
    $t = new Templater();
    // "nope" is not a registered function — with the flat form, unregistered
    // bare words with args remain variables.  Since "nope" can't be resolved
    // as a variable either, it errors as an unknown variable.
    $r = $t->expand('%(nope "hello")%', fn(string $n): ?string => null);
    assert_true($r->isFailure(), 'Unregistered bare word with args must error');
    assert_contains($r->getError(), 'Unknown variable: nope', 'Error must name the unresolved token');
});

test('expand: unknown variable in %(var)% → Result::failure', function () {
    $t = new Templater();
    $r = $t->expand('%(unknown)%', fn(string $n): ?string => null);
    assert_true($r->isFailure(), 'Unknown variable in s-expr must return failure');
    assert_contains($r->getError(), 'Unknown variable: unknown', 'Error must name the variable');
});

test('expand: unclosed "%(..." without closing ")%" → error', function () {
    $t = new Templater();
    $r = $t->expand('%(hello', fn(string $n): ?string => null);
    assert_true($r->isFailure(), 'Unclosed s-expr token must fail');
});

test('expand: unbalanced parens → error', function () {
    $t = new Templater();
    $t->registerFunction('upper', strtoupper(...));
    $r = $t->expand('%(upper ("hello")%', fn(string $n): ?string => null);
    assert_true($r->isFailure(), 'Unbalanced parens must fail');
});

test('expand: function throws → Result::failure', function () {
    $t = new Templater();
    $t->registerFunction('explode', function (): string {
        throw new \RuntimeException('kaboom');
    });
    $r = $t->expand('%(explode)%', fn(string $n): ?string => null);
    assert_true($r->isFailure(), 'Function throwing must return failure');
    assert_contains($r->getError(), 'kaboom', 'Error must contain original message');
});

test('expand: "%)%" outside s-expr → passed through as literal', function () {
    $t = new Templater();
    // "%)%" has no s-expression opener "(%(" — the bare ")" between percents
    // is treated as literal text, same as any non-token %...% sequence.
    $r = $t->expand('%)%', fn(string $n): ?string => null);
    assert_true($r->isSuccess(), 'Bare %)% must pass through as literal');
    assert_eq($r->getValue(), '%)%', 'Literal %)% preserved');
});

// ---------------------------------------------------------------------------
// registerFunction() and getFunctionNames()
// ---------------------------------------------------------------------------

test('getFunctionNames: empty by default', function () {
    $t = new Templater();
    assert_eq($t->getFunctionNames(), [], 'No functions registered by default');
});

test('getFunctionNames: returns registered function names', function () {
    $t = new Templater();
    $t->registerFunction('upper', strtoupper(...));
    $t->registerFunction('lower', strtolower(...));
    assert_eq($t->getFunctionNames(), ['upper', 'lower'], 'Returns all registered names');
});

test('registerFunction: overwrites existing', function () {
    $t = new Templater();
    $t->registerFunction('fn', strtoupper(...));
    $t->registerFunction('fn', strtolower(...));
    $r = $t->expand('%(fn "HELLO")%', fn(string $n): ?string => null);
    assert_true($r->isSuccess());
    assert_eq($r->getValue(), 'hello', 'Last registration wins');
});

test('constructor: initial functions', function () {
    $t = new Templater(['echo' => fn(string $s): string => $s]);
    $r = $t->expand('%(echo "hi")%', fn(string $n): ?string => null);
    assert_true($r->isSuccess());
    assert_eq($r->getValue(), 'hi', 'Constructor-injected functions work');
});

// ---------------------------------------------------------------------------
// Edge cases
// ---------------------------------------------------------------------------

test('expand: empty string', function () {
    $t = new Templater();
    $r = $t->expand('', fn(string $n): ?string => null);
    assert_true($r->isSuccess(), 'Empty string must succeed');
    assert_eq($r->getValue(), '', 'Empty string yields empty string');
});

test('expand: single "%"', function () {
    $t = new Templater();
    $r = $t->expand('%', fn(string $n): ?string => null);
    assert_true($r->isSuccess(), 'Single percent must succeed');
    assert_eq($r->getValue(), '%', 'Single percent passed through');
});

test('expand: function returning empty string', function () {
    $t = new Templater();
    $t->registerFunction('empty', fn(): string => '');
    $r = $t->expand('before%(empty)%after', fn(string $n): ?string => null);
    assert_true($r->isSuccess(), 'Empty return must succeed');
    assert_eq($r->getValue(), 'beforeafter', 'Empty return inserts nothing');
});

test('expand: zero-arg function', function () {
    $t = new Templater();
    $t->registerFunction('greeting', fn(): string => 'Hello');
    $r = $t->expand('%(greeting)%', fn(string $n): ?string => null);
    assert_true($r->isSuccess(), 'Zero-arg function must succeed');
    assert_eq($r->getValue(), 'Hello', 'Zero-arg function works');
});

test('expand: token at end of message', function () {
    $t = new Templater();
    $r = $t->expand('Hello %firstname%', fn(string $n): ?string => match($n) {
        'firstname' => 'Jeff',
        default => null,
    });
    assert_true($r->isSuccess(), 'Token at end must succeed');
    assert_eq($r->getValue(), 'Hello Jeff', 'Token at end expanded correctly');
});

test('expand: token at start of message', function () {
    $t = new Templater();
    $r = $t->expand('%firstname% is here', fn(string $n): ?string => match($n) {
        'firstname' => 'Jeff',
        default => null,
    });
    assert_true($r->isSuccess(), 'Token at start must succeed');
    assert_eq($r->getValue(), 'Jeff is here', 'Token at start expanded correctly');
});

test('expand: adjacent tokens', function () {
    $t = new Templater();
    $resolve = fn(string $n): ?string => match($n) {
        'firstname' => 'Jeff',
        'lastname'  => 'Turner',
        default     => null,
    };
    $r = $t->expand('%firstname%%lastname%', $resolve);
    assert_true($r->isSuccess(), 'Adjacent tokens must succeed');
    assert_eq($r->getValue(), 'JeffTurner', 'Adjacent tokens expanded correctly');
});

test('expand: underscore in variable name', function () {
    $t = new Templater();
    $r = $t->expand('%(person_first_name)%', fn(string $n): ?string => match($n) {
        'person_first_name' => 'Jeff',
        default => null,
    });
    assert_true($r->isSuccess(), 'Underscore in var name must succeed');
    assert_eq($r->getValue(), 'Jeff', 'Underscore variable works');
});

test('expand: function name with underscore', function () {
    $t = new Templater();
    $t->registerFunction('my_func', fn(string $s): string => strtoupper($s));
    $r = $t->expand('%(my_func "hello")%', fn(string $n): ?string => null);
    assert_true($r->isSuccess(), 'Function with underscore must succeed');
    assert_eq($r->getValue(), 'HELLO', 'Underscore function works');
});

test('expand: unclosed "%(...)" without ")%" → error', function () {
    $t = new Templater();
    // "%(" always opens an s-expression — if the closing ")%" is missing,
    // that's a template error.
    $r = $t->expand('Use %( for notes', fn(string $n): ?string => null);
    assert_true($r->isFailure(), 'Unclosed %( must error');
});

test('expand: complex URL shorten simulation', function () {
    $t = new Templater();
    $t->registerFunction('concat', fn(string ...$parts): string => implode('', $parts));
    $t->registerFunction('shorten', fn(string $url): string => 'https://sho.rt/' . substr(md5($url), 0, 6));
    $resolve = fn(string $n): ?string => match($n) {
        'persontoken' => 'tok_abc123',
        default => null,
    };
    $r = $t->expand(
        'Click %(shorten (concat "https://conf.org/?t=" persontoken))% to register',
        $resolve,
    );
    assert_true($r->isSuccess(), 'Complex URL shorten must succeed');
    // Verify it produced a shortened URL (not the original, not a token)
    assert_contains($r->getValue(), 'Click https://sho.rt/', 'URL must be shortened');
    assert_not_contains($r->getValue(), 'persontoken', 'Token must be expanded');
    assert_not_contains($r->getValue(), '%(', 'S-expr must be replaced');
});

// ---------------------------------------------------------------------------
// concat() — targeted tests
// ---------------------------------------------------------------------------

test('concat: empty call → empty string', function () {
    $t = new Templater();
    $t->registerFunction('concat', fn(string ...$parts): string => implode('', $parts));
    $r = $t->expand('%(concat)%', fn(string $n): ?string => null);
    assert_true($r->isSuccess(), 'concat() with zero args must succeed');
    assert_eq($r->getValue(), '', 'concat() with no args yields empty string');
});

test('concat: single string literal', function () {
    $t = new Templater();
    $t->registerFunction('concat', fn(string ...$parts): string => implode('', $parts));
    $r = $t->expand('%(concat "hello")%', fn(string $n): ?string => null);
    assert_true($r->isSuccess());
    assert_eq($r->getValue(), 'hello', 'concat with single arg returns it unchanged');
});

test('concat: three strings', function () {
    $t = new Templater();
    $t->registerFunction('concat', fn(string ...$parts): string => implode('', $parts));
    $r = $t->expand('%(concat "a" "b" "c")%', fn(string $n): ?string => null);
    assert_true($r->isSuccess());
    assert_eq($r->getValue(), 'abc', 'concat joins in order');
});

test('concat: order is preserved (not reversed)', function () {
    $t = new Templater();
    $t->registerFunction('concat', fn(string ...$parts): string => implode('', $parts));
    $r = $t->expand('%(concat "1" "2" "3")%', fn(string $n): ?string => null);
    assert_true($r->isSuccess());
    assert_eq($r->getValue(), '123', 'concat must preserve argument order');
});

test('concat: string with spaces', function () {
    $t = new Templater();
    $t->registerFunction('concat', fn(string ...$parts): string => implode('', $parts));
    $r = $t->expand('%(concat "Hello, " "world!")%', fn(string $n): ?string => null);
    assert_true($r->isSuccess());
    assert_eq($r->getValue(), 'Hello, world!', 'concat preserves spaces within strings');
});

test('concat: variable and string literal together', function () {
    $t = new Templater();
    $t->registerFunction('concat', fn(string ...$parts): string => implode('', $parts));
    $resolve = fn(string $n): ?string => match($n) {
        'persontoken' => 'tok_xyz',
        default => null,
    };
    $r = $t->expand('%(concat "https://x.com/?t=" persontoken)%', $resolve);
    assert_true($r->isSuccess());
    assert_eq($r->getValue(), 'https://x.com/?t=tok_xyz', 'concat with string + variable');
});

test('concat: variable first, then string', function () {
    $t = new Templater();
    $t->registerFunction('concat', fn(string ...$parts): string => implode('', $parts));
    $resolve = fn(string $n): ?string => match($n) {
        'name' => 'Jeff',
        default => null,
    };
    $r = $t->expand('%(concat name ", hello!")%', $resolve);
    assert_true($r->isSuccess());
    assert_eq($r->getValue(), 'Jeff, hello!', 'concat with variable first');
});

test('concat: returns failure when variable is unknown', function () {
    $t = new Templater();
    $t->registerFunction('concat', fn(string ...$parts): string => implode('', $parts));
    $r = $t->expand('%(concat "x" unknown_var)%', fn(string $n): ?string => null);
    assert_true($r->isFailure(), 'concat with unknown variable must fail');
    assert_contains($r->getError(), 'Unknown variable: unknown_var');
});
