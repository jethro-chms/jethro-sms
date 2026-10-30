<?php

declare(strict_types=1);

namespace Sms;

/**
 * Typed result of a registration state-machine step.
 *
 * Replaces the loosely-typed `array` that registerSenderId() and
 * registerSenderNumber() previously returned.  Every consumer —
 * renderRegistrationStepHtml(), renderRegistrationStepText(),
 * DbLoggingSmsProvider, and the status-panel dispatch —
 * now receives this object instead of a stringly-typed bag.
 *
 * Registration steps are returned as this value object, not as arrays
 */

final readonly class RegistrationStep
{
	/**
	 * `message`, `instructions` and `contact` are plain text (they can carry
	 * upstream provider text): HTML renderers must escape them.
	 *
	 * @param FormField[]                                 $fields        Form fields for the next state-machine step (empty = complete)
	 * @param array<int, array{label: string, value: string}> $form       Summary label/value rows for the completed registration
	 */
	public function __construct(
		public string $message = '',
		public array $fields = [],
		public string $instructions = '',
		public string $contact = '',
		public array $form = [],
		public ?string $number = null,
		public bool $registered = false,
	) {}

	/** Whether the registration state machine has reached a terminal state. */
	public function isComplete(): bool
	{
		return $this->fields === [];
	}

	/**
	 * Return a copy with the given fields replaced.
	 *
	 * Unspecified fields keep their existing values.  Because RegistrationStep
	 * is readonly and immutable, this is the only way to produce a modified copy.
	 * Mirrors {@see SmsDelivery::with()}.
	 */
	public function with(
		?string $message = null,
		?array $fields = null,
		?string $instructions = null,
		?string $contact = null,
		?array $form = null,
		?string $number = null,
		?bool $registered = null,
	): self {
		return new self(
			message: $message ?? $this->message,
			fields: $fields ?? $this->fields,
			instructions: $instructions ?? $this->instructions,
			contact: $contact ?? $this->contact,
			form: $form ?? $this->form,
			number: $number ?? $this->number,
			registered: $registered ?? $this->registered,
		);
	}
}
