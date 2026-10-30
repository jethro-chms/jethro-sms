<?php

declare(strict_types=1);

namespace Sms;

/**
 * Thrown for unrecoverable template errors (parse errors, unknown variables).
 *
 * Caught internally by {@see Templater::expand()} → {@see \Result::failure()}.
 *
 * @see Templater
 */

final class TemplateException extends \RuntimeException
{
}
