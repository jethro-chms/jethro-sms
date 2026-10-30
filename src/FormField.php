<?php

declare(strict_types=1);

namespace Sms;

/**
 * A single field in a registration form schema.
 *
 * Produced by getSenderIdFieldSchema() / getSenderNumberFieldSchema() and
 * consumed by the admin status panel and CLI to render dynamic forms.
 * @see RegistrationStep
 */

final readonly class FormField
{
	/**
	 * @param string[]|null $options  Dropdown option values (only for type: select)
	 */
	public function __construct(
		public string $name,
		public string $label,
		public string $type,
		public bool $required = false,
		public ?string $value = null,
		public ?array $options = null,
		public ?string $description = null,
	) {}
}
