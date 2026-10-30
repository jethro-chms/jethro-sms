<?php

declare(strict_types=1);

namespace Sms;

/**
 * One configuration value looked up from an {@see SmsSettings} source.
 */
final readonly class SmsSetting
{
    public function __construct(
        private string $name,
        private bool $isSet,
        private mixed $value,
    ) {
    }

    /**
     * The setting an administrator actually defines to supply this value.
     *
     * May differ from the requested name when a settings source remaps names.
     * Intended for error messages ("Missing SMS configuration: X").
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * The value, or $default when the setting is unset.
     *
     * A setting that is set to false, '' or 0 is returned as-is.
     */
    public function val(mixed $default = null): mixed
    {
        return $this->isSet ? $this->value : $default;
    }
}

/**
 * Read-only source of provider configuration, indexed by setting name.
 *
 * offsetGet() always returns an SmsSetting, never null: callers reporting a
 * missing setting need its getName() precisely when it is unset.
 */
abstract class SmsSettings implements \ArrayAccess
{
    abstract public function offsetGet(mixed $name): SmsSetting;

    public function offsetExists(mixed $name): bool
    {
        return $this[$name]->val() !== null;
    }

    public function offsetSet(mixed $name, mixed $value): void
    {
        throw new \LogicException('SmsSettings is read-only');
    }

    public function offsetUnset(mixed $name): void
    {
        throw new \LogicException('SmsSettings is read-only');
    }
}

/**
 * Settings backed by global PHP constants (define()).
 */
final class ConstantSettings extends SmsSettings
{
    public function offsetGet(mixed $name): SmsSetting
    {
        $defined = defined($name);
        return new SmsSetting($name, $defined, $defined ? constant($name) : null);
    }
}
