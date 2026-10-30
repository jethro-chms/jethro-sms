<?php

declare(strict_types=1);

namespace Sms\Providers;

use Sms\ContactPhoneNumber;
use Sms\FormField;
use Sms\HttpClient;
use Sms\HttpRequest;
use Sms\LoggingHttpClient;
use Sms\NativeHttpClient;
use Sms\OptOutEntry;
use Sms\PhoneNumber;
use Sms\RegistrationStep;
use Sms\SenderID;
use Sms\SenderNumberRecord;
use Sms\SmsCache;
use Sms\SmsCapability;
use Sms\SmsSettings;
use Sms\SmsDelivery;
use Sms\SmsDeliveryBatch;
use Sms\SmsProvider;
use Sms\SmsRecipient;
use Sms\SmsSender;
use Sms\SmsStatus;

/**
 * SMS provider for the mobilemessage.com.au API (https://api.mobilemessage.com.au).
 *
 * Uses JSON requests with HTTP Basic authentication (API username + password
 * from the dashboard's Settings → API). All endpoints return a single
 * `{"error": "…"}` envelope on 4xx/5xx; sends reply per-message inside an
 * HTTP 200, so a 200 does not mean every message was accepted.
 *
 * POST /v1/messages carries one MessageInput per recipient (up to 10,000) and
 * replies with `results[]` in request order — the positional pairing with our
 * recipients is 1:1. `custom_ref` is left unset; cancel and status lookups
 * key on the returned `message_id` UUID, which Jethro stores as remoteId.
 *
 * Scheduling: `scheduled_for` must be more than 60 seconds ahead — anything
 * at or inside that threshold is SILENTLY downgraded to an immediate send.
 * Jethro's 30-second undo cool-off schedules sends 30 s ahead, so send()
 * clamps deferred sends up to now+90 s: a 61-second margin leaves no room for
 * network latency or clock skew, and the downgraded send reports no
 * scheduled_for, so the undo window would vanish with no error.
 *
 * Unicode: `enable_unicode` follows SMS_UNICODE_PERMITTED. With it off
 * ('false'), the API silently strips non-GSM characters rather than failing.
 *
 * @see SmsProvider
 * @see MobileMessageSmsDelivery
 */
class MobileMessageSmsProvider implements SmsProvider
{
	private const CANONICAL_URL = 'https://api.mobilemessage.com.au';

	/**
	 * The API silently downgrades scheduled_for ≤60 s ahead to an immediate
	 * send. Measured when the request arrives, so keep a real buffer: 61 s is
	 * within one slow round-trip of the threshold.
	 */
	private const MIN_SCHEDULE_SECONDS = 90;

	/** Page size for the paginated list endpoints (API clamps at 200). */
	private const PAGE_SIZE = 200;

	public function __construct(
		/** API username (Settings → API in the MobileMessage dashboard). */
		private string $username,
		/** API password. Multiple keys per account support rotation. */
		private string $password,
		/** API base URL. */
		private string $url = self::CANONICAL_URL,
		/** Whether non-GSM characters may be sent (SMS_UNICODE_PERMITTED !== 'false'). */
		private bool $unicodePermitted = true,
		private ?HttpClient $httpClient = null,
		private ?SmsCache $cache = null,
	) {}

	/**
	 * Create a MobileMessage provider from the given settings.
	 *
	 * @param ?string $baseUrl  Absolute base URL of the Jethro instance. When a bare
	 *     path URL setting (starting with "/") is read, this is prepended to resolve it.
	 * @throws \RuntimeException when the username or password is missing
	 */
	public static function fromSettings(SmsSettings $settings, ?string $baseUrl = null): static
	{
		$username = (string) $settings['SMS_MOBILEMESSAGE_APIKEY_USERNAME']->val('');
		$password = (string) $settings['SMS_MOBILEMESSAGE_APIKEY_PASSWORD']->val('');

		$url = rtrim(((string) $settings['SMS_MOBILEMESSAGE_URL']->val('')) ?: self::CANONICAL_URL, '/');
		if ($url !== '' && $url[0] === '/' && $baseUrl !== null) {
			$origin = parse_url($baseUrl, PHP_URL_SCHEME) . '://' . parse_url($baseUrl, PHP_URL_HOST);
			$port = parse_url($baseUrl, PHP_URL_PORT);
			if ($port !== null) $origin .= ':' . $port;
			$url = $origin . $url;
		}

		if ($username === '' || $password === '') {
			throw new \RuntimeException('Missing SMS configuration: ' . $settings['SMS_MOBILEMESSAGE_APIKEY_USERNAME']->getName() . ' and ' . $settings['SMS_MOBILEMESSAGE_APIKEY_PASSWORD']->getName());
		}

		// 'false' = "Not permitted": let the API strip non-GSM characters.
		// 'when_free' / 'true' = permit unicode (multi-segment UCS-2 costs extra).
		$unicodePermitted = (string) $settings['SMS_UNICODE_PERMITTED']->val('when_free') !== 'false';

		return new static(
			username: $username,
			password: $password,
			url: $url,
			unicodePermitted: $unicodePermitted,
			httpClient: static::createHttpClient(
				filter_var($settings['SMS_TESTMODE']->val(false), FILTER_VALIDATE_BOOLEAN),
				filter_var($settings['SMS_VERBOSE']->val(false), FILTER_VALIDATE_BOOLEAN),
			),
		);
	}

	public function withCache(SmsCache $cache): static
	{
		$clone = clone $this;
		$clone->cache = $cache;
		return $clone;
	}

	/**
	 * Create the HTTP client for MobileMessageSmsProvider.
	 * @param bool $testMode
	 */
	public static function createHttpClient(bool $testMode, bool $verbose): HttpClient
	{
		if ($testMode) {
			$client = new MobileMessageFakeHttpClient(new NativeHttpClient());
		} else {
			$client = new NativeHttpClient();
		}
		if ($verbose) {
			$client = new LoggingHttpClient($client);
		}
		return $client;
	}

	// -----------------------------------------------------------------------
	// HTTP helpers
	// -----------------------------------------------------------------------

	/**
	 * Build the common Authorization header (HTTP Basic).
	 */
	private function authHeader(): string
	{
		return 'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password) . "\r\n";
	}

	/**
	 * Send an HTTP request and return the parsed JSON body on success.
	 *
	 * Every 4xx/5xx carries {"error": "…"}, surfaced as a failure.
	 *
	 * @return \Result<array, string>
	 */
	private function request(string $method, string $path, ?array $body = null): \Result
	{
		$headers = $this->authHeader();
		$bodyStr = '';

		if ($body !== null) {
			$bodyStr = json_encode($body);
			if ($bodyStr === false) {
				return \Result::failure('Could not encode request body: ' . json_last_error_msg());
			}
			$headers .= "Content-Type: application/json\r\n";
			$headers .= 'Content-Length: ' . \strlen($bodyStr) . "\r\n";
		}

		$httpRequest = new HttpRequest(
			url: $this->url . $path,
			method: $method,
			headers: $headers,
			body: $bodyStr,
			timeout: 30,
		);

		$response = $this->httpClient->send($httpRequest);

		if ($response->isFailure()) {
			return \Result::failure($response->getError());
		}

		$data = json_decode($response->getValue()->body, true);

		if (!\is_array($data)) {
			return \Result::failure('Invalid JSON response from MobileMessage API. Raw: ' . $response->getValue()->body);
		}

		if (isset($data['error']) && \is_string($data['error']) && $data['error'] !== '') {
			return \Result::failure('MobileMessage API error: ' . $data['error']);
		}

		return \Result::success($data);
	}

	// -----------------------------------------------------------------------
	// SmsProvider implementation
	// -----------------------------------------------------------------------

	/** @return \Result<SmsDeliveryBatch, string> */
	public function send(
		array $entries,
		SmsSender $sender,
		?int $sendAt = null,
		bool $preview = false,
	): \Result {
		if ($preview) {
			$allDeliveries = [];
			foreach (array_values($entries) as $entry) {
				foreach ($entry['recipients'] as $r) {
					$allDeliveries[] = new SmsDelivery(
						recipient: $r->getPhoneNumber(),
						status: SmsStatus::QUEUED,
						message: $entry['message'],
					);
				}
			}
			return \Result::success(new SmsDeliveryBatch(null, $allDeliveries));
		}

		// One MessageInput per recipient; results[] comes back in request order.
		$recipients = [];
		$messages = [];
		$scheduledFor = null;
		if ($sendAt !== null) {
			// The API silently downgrades scheduled_for ≤60 s ahead to an
			// immediate send, which would defeat the undo cool-off (default
			// 30 s) — clamp up past the threshold instead.
			$sendAt = max($sendAt, time() + self::MIN_SCHEDULE_SECONDS);
			$scheduledFor = gmdate('Y-m-d\TH:i:s\Z', $sendAt);
		}
		foreach (array_values($entries) as $entry) {
			foreach ($entry['recipients'] as $r) {
				$recipients[] = $r;
				$message = [
					'to' => $r->getPhoneNumber()->value,
					// Must be a Sender ID active on the account; OverridingSmsProvider
					// has already enforced the configured allowlist.
					'sender' => (string) $sender,
					'message' => $entry['message'],
				];
				if ($scheduledFor !== null) {
					$message['scheduled_for'] = $scheduledFor;
				}
				$messages[] = $message;
			}
		}

		$body = [
			'messages' => $messages,
			'enable_unicode' => $this->unicodePermitted,
		];

		$result = $this->request('POST', '/v1/messages', $body);

		// The one request carries every entry, so nothing was accepted before
		// it: finishStoppedSend() returns this as a plain failure whatever the
		// flag (an API 4xx sent nothing; a transport failure or 5xx may have
		// been charged).
		if ($result->isFailure()) {
			return \Sms\finishStoppedSend($entries, 0, [], $result->getError(), mayHaveBeenSent: true);
		}

		// Invalidate cached balance
		$this->cache?->delete('sms_balance');

		$data = $result->getValue();
		$results = $data['results'] ?? null;
		if (!\is_array($results)) {
			return \Sms\finishStoppedSend($entries, 0, [], 'Malformed send response — no results array. Raw: ' . json_encode($data), mayHaveBeenSent: true);
		}

		$allDeliveries = [];
		$i = 0;
		$anyBlocked = false;
		foreach (array_values($entries) as $entry) {
			foreach ($entry['recipients'] as $r) {
				$item = $results[$i] ?? null;
				$i++;
				if (\is_array($item) && ($item['status'] ?? null) === 'blocked') {
					$anyBlocked = true;
				}
				$allDeliveries[] = \is_array($item)
					? (new MobileMessageSmsDelivery($r->getPhoneNumber(), $item))->with(message: $entry['message'])
					: new SmsDelivery(recipient: $r->getPhoneNumber(), status: SmsStatus::FAILED, message: $entry['message'], statusDetail: 'No result returned for this recipient');
			}
		}
		// A blocked result means the number is on the upstream opt-out list,
		// which the cached list may not know about yet.
		if ($anyBlocked) {
			$this->cache?->delete('sms_optouts');
		}

		return \Result::success(new SmsDeliveryBatch(null, $allDeliveries));
	}

	public function getBalance(): \Result
	{
		// 1. Cache hit
		$cached = $this->cache?->get('sms_balance');
		if ($cached !== null) {
			return \Result::success((int) $cached);
		}

		// 2. Fetch from API: {"credit_balance": 1234, "credit_price": "0.049"}
		$result = $this->request('GET', '/v1/account');
		if ($result->isFailure()) {
			return $result;
		}

		$data = $result->getValue();
		if (!isset($data['credit_balance'])) {
			return \Result::failure('No credit_balance field in account response. Raw: ' . json_encode($data));
		}

		$balance = (int) $data['credit_balance'];
		$this->cache?->set('sms_balance', $balance, 300);

		return \Result::success($balance);
	}

	public function isOperational(): \Result
	{
		$cached = $this->cache?->get('sms_operational');
		if ($cached !== null) {
			return \Result::success((bool) $cached);
		}

		$result = $this->getBalance();

		if ($result->isSuccess()) {
			$this->cache?->set('sms_operational', true, 300);
			return \Result::success(true);
		}

		$this->cache?->set('sms_operational', false, 60);
		return \Result::success(false);
	}

	/**
	 * Fetch the raw GET /v1/senders items, session-cached. The live API
	 * returns them all in one page; a paged {total, limit, offset, results[]}
	 * response is still followed to the end.
	 *
	 * @return \Result<array<int, array<string, mixed>>, string>
	 */
	private function fetchSenders(): \Result
	{
		$cached = $this->cache?->get('sms_senders');
		if ($cached !== null && \is_array($cached)) {
			return \Result::success($cached);
		}

		$items = [];
		$offset = 0;
		$total = null;
		while (true) {
			$result = $this->request('GET', '/v1/senders?limit=' . self::PAGE_SIZE . '&offset=' . $offset);
			if ($result->isFailure()) {
				return $result;
			}
			$data = $result->getValue();
			$page = $data['results'] ?? null;
			if (!\is_array($page)) {
				return \Result::failure('No results field in senders response. Raw: ' . json_encode($data));
			}
			foreach ($page as $item) {
				if (\is_array($item)) $items[] = $item;
			}
			$total = (int) ($data['total'] ?? \count($items));
			$offset += \count($page);
			if (\count($page) < self::PAGE_SIZE || \count($items) >= $total) {
				break;
			}
		}

		$this->cache?->set('sms_senders', $items, 1800);
		return \Result::success($items);
	}

	/**
	 * Alphanumeric Sender IDs from GET /v1/senders (items with type "alpha").
	 *
	 * The API exposes no per-ID ACMA-approved flag on this endpoint, so every
	 * ID is returned with acmaApproved null (kept by the default sender list);
	 * per-ID status is a separate GET /v1/acma-registration?sender= lookup we
	 * don't need for sending.
	 *
	 * @return \Result<SenderID[], string>
	 */
	public function getSenderIds(bool $getAll = false): \Result
	{
		return $this->fetchSenders()->map(function (array $items) use ($getAll) {
			$ids = [];
			foreach ($items as $item) {
				$sender = isset($item['sender']) ? trim((string) $item['sender']) : '';
				if ($sender === '' || ($item['type'] ?? '') !== 'alpha') continue;
				$ids[] = new SenderID($sender, acmaApproved: null);
			}
			return $getAll ? $ids : \Sms\excludeUnapprovedSenderIds($ids);
		});
	}

	/**
	 * Registered sender phone numbers from GET /v1/senders (types "own",
	 * "dedicated" and "shared"). Alphanumeric IDs are skipped — those are
	 * Sender IDs, handled by {@see getSenderIds()}.
	 *
	 * @return \Result<PhoneNumber[], string>
	 */
	public function getSenderNumbers(): \Result
	{
		return $this->fetchSenders()->map(function (array $items) {
			$numbers = [];
			foreach ($items as $item) {
				$sender = isset($item['sender']) ? trim((string) $item['sender']) : '';
				if ($sender === '' || ($item['type'] ?? '') === 'alpha') continue;
				if (!preg_match('/^\+?\d+$/', $sender)) continue;
				$numbers[] = new PhoneNumber($sender);
			}
			return $numbers;
		});
	}

	/**
	 * Sender records from GET /v1/senders.  The live list carries no record
	 * ids or dates ({sender, type, label, is_default}), so only the value and
	 * status are populated.  A listed number is a usable sender — own/shared/
	 * dedicated normalise to 'active'; 'alpha' marks alphanumeric IDs.
	 *
	 * @return \Result<SenderNumberRecord[], string>
	 */
	public function getSenderNumberRecords(): \Result
	{
		return $this->fetchSenders()->map(function (array $items) {
			$records = [];
			foreach ($items as $item) {
				$sender = isset($item['sender']) ? trim((string) $item['sender']) : '';
				if ($sender === '') continue;
				$type = isset($item['type']) && \is_string($item['type']) ? $item['type'] : null;
				$records[] = new SenderNumberRecord(
					$sender,
					$type === 'alpha' ? 'alpha' : ($type !== null ? 'active' : null),
					null,
					null,
				);
			}
			return $records;
		});
	}

	/** @inheritDoc */
	public function deleteSenderNumber(PhoneNumber $number): \Result
	{
		return \Result::failure('MobileMessage does not support deleting sender numbers via the API — remove them in the MobileMessage dashboard.');
	}

	/**
	 * Check whether a specific phone number is an approved sender.
	 *
	 * Registered numbers are compared with PhoneNumber::sameNumberAs() so
	 * local-form input ('0401…') still matches international '61401…'.
	 */
	public function verifySenderNumber(PhoneNumber $number): \Result
	{
		$numbersResult = $this->getSenderNumbers();
		if ($numbersResult->isFailure()) {
			return \Result::failure('Could not fetch sender numbers: ' . $numbersResult->getError());
		}

		foreach ($numbersResult->getValue() as $registered) {
			if ($registered->sameNumberAs($number)) {
				return \Result::success(true);
			}
		}

		return \Result::success(false);
	}

	/**
	 * Register a phone number as a sender with MobileMessage.
	 *
	 * Discovery (no args): returns the number-entry field schema.
	 * Registration ($contact set, params null): calls POST /v1/senders,
	 *   which texts a verification link to the number (owner clicks it;
	 *   max 3 pending at once). The API has no call to complete the click,
	 *   so this returns registered: false with no further fields — the
	 *   caller re-checks via verifySenderNumber()/getSenderNumbers().
	 *
	 * @inheritDoc
	 */
	public function registerSenderNumber(?ContactPhoneNumber $contact = null, ?array $params = null): \Result
	{
		if ($contact === null) {
			return \Result::success(new RegistrationStep(
				fields: [
					new FormField('number', 'Mobile number to verify', 'text', required: true, description: 'An SMS with a verification link will be sent to this number. The owner must click the link within 3 days.'),
					new FormField('name', 'Label', 'text', required: false, description: 'A label for the number, e.g. the church office mobile.'),
				],
			));
		}

		$number = $contact->phoneNumber;
		// The API requires the number under "number" (a "sender" key fails
		// with '"number" is required.'); GET /v1/senders items do use
		// "sender", but that shape doesn't apply here.
		$body = ['number' => $number->value];
		if ($contact->name !== '') {
			$body['label'] = $contact->name;
		}

		$result = $this->request('POST', '/v1/senders', $body);
		if ($result->isFailure()) {
			// Already on the account is the verification-already-done state,
			// not a failure — report it as done instead of an error.
			if (stripos($result->getError(), 'already an active sender') !== false) {
				return \Result::success(new RegistrationStep(
					registered: true,
					message: $number->value . ' is already an active sender on the account — no verification needed.',
					number: $number->value,
					fields: [],
				));
			}
			return $result;
		}

		$data = $result->getValue();
		$this->cache?->delete('sms_senders');

		return \Result::success(new RegistrationStep(
			registered: false,
			message: 'Verification link sent to ' . $number->value . '.',
			instructions: 'The owner must click the verification link on the handset (up to 3 pending verifications at a time). Once approved, the number appears under verified sender numbers.',
			number: $number->value,
		));
	}

	/**
	 * Alphanumeric Sender IDs are registered through MobileMessage's dashboard
	 * (which submits to the ACMA Sender ID Register with brand and contact
	 * details). The API submission needs brand/relationship data we don't
	 * collect, so registration happens off-Jethro; sending AS a registered ID
	 * works normally.
	 *
	 * @inheritDoc
	 */
	public function registerSenderId(?SenderID $senderId = null, ?array $params = null): \Result
	{
		return \Result::failure('Register alphanumeric Sender IDs through the MobileMessage dashboard (ACMA registration).');
	}

	public function updateDelivery(SmsDelivery $delivery): \Result
	{
		$remoteId = $delivery->remoteId();
		if ($remoteId === null) {
			return \Result::failure('No remote ID on delivery');
		}

		$result = $this->request('GET', '/v1/messages?message_id=' . urlencode($remoteId));
		if ($result->isFailure()) {
			return $result;
		}

		return $this->parseDeliveryResponse($delivery, $result->getValue());
	}

	/**
	 * Parse a GET /v1/messages?message_id= lookup (shape verified against the
	 * live API):
	 *   {"status":"complete","results":[{"to":"614…","status":"delivered",
	 *    "cost":"1.00","message_id":"…","requested_at":"2026-10-17 10:59:49",
	 *    "scheduled_for":null,…}]}
	 * The envelope's own `status` is always "complete"; the message status is
	 * results[0].status: pending | scheduled | sent | delivered | failed |
	 * cancelled. `cost` is a decimal string of whole credits (0.00 once a
	 * scheduled send is cancelled and refunded).
	 *
	 * @return \Result<SmsDelivery, string>
	 */
	private function parseDeliveryResponse(SmsDelivery $delivery, array $data): \Result
	{
		$msg = $data['results'][0] ?? null;
		if (!\is_array($msg) || ($msg['status'] ?? '') === '') {
			return \Result::failure('No message status in delivery response. Raw: ' . json_encode($data));
		}

		// The lookup carries no handset-confirmed delivered timestamp, so
		// deliveryTimestamp stays null — DELIVERED alone is authoritative.
		return \Result::success(new SmsDelivery(
			recipient: $delivery->recipient(),
			status: self::statusFromApi((string) $msg['status']),
			remoteId: $delivery->remoteId(),
			sendTimestamp: self::sendTimestampOf($msg),
			segmentsUsed: isset($msg['cost']) && is_numeric($msg['cost']) ? (int) round((float) $msg['cost']) : null,
			rawResponse: json_encode($msg),
		));
	}

	/**
	 * Parse an API timestamp. The API returns "YYYY-MM-DD HH:MM:SS" with no
	 * zone; the values are UTC (a schedule sent as 10:59:48Z+65s came back
	 * as "11:00:53"), so they must not be read in the server's local zone.
	 */
	public static function utcTimestamp(mixed $value): ?int
	{
		if (!\is_string($value) || $value === '') {
			return null;
		}
		try {
			return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp();
		} catch (\Exception) {
			return null;
		}
	}

	/** When a message goes (or went) out: its schedule time if any, else when it was requested. */
	private static function sendTimestampOf(array $msg): ?int
	{
		return self::utcTimestamp($msg['scheduled_for'] ?? null) ?? self::utcTimestamp($msg['requested_at'] ?? null);
	}

	/** Map an API message status to a Jethro status. */
	private static function statusFromApi(string $status): SmsStatus
	{
		return match ($status) {
			'pending' => SmsStatus::QUEUED,
			'scheduled' => SmsStatus::SCHEDULED,
			'sent' => SmsStatus::SENT,
			'delivered' => SmsStatus::DELIVERED,
			'failed' => SmsStatus::FAILED,
			// The destination is on the opt-out list: a final status, not an
			// unknown one that polling would chase forever.  Same mapping as
			// on send (MobileMessageSmsDelivery).
			'blocked' => SmsStatus::OPTED_OUT,
			'cancelled' => SmsStatus::CANCELLED,
			default => SmsStatus::UNKNOWN,
		};
	}

	/**
	 * Cancel previously scheduled SMS messages.
	 *
	 * Iterates each delivery in the batch; the gateway refuses anything not
	 * still in "scheduled" state (HTTP 409), which is reported per-delivery in
	 * statusDetail() with the delivery's status unchanged. Cancelled credits
	 * are refunded upstream.
	 *
	 * @return \Result<\Sms\SmsDeliveryBatch, string>
	 */
	public function cancel(\Sms\SmsDeliveryBatch $batch): \Result
	{
		$results = [];
		foreach ($batch->deliveries as $delivery) {
			$results[] = $this->cancelOneDelivery($delivery);
		}
		return \Result::success(new \Sms\SmsDeliveryBatch($batch->batchId, $results));
	}

	/**
	 * Cancel one delivery via DELETE /v1/messages with a JSON body
	 * {"message_id": …}. The API ignores query parameters on DELETE (400
	 * "message_id … is required"). Success reply:
	 *   {"status":"complete","message_id":"…","cancelled":true,"credits_refunded":1}
	 *
	 * On failure — transport error, an API error envelope ("Message not
	 * found." / "not in a scheduled state"), or cancelled !== true — returns
	 * the delivery unchanged except for statusDetail().
	 */
	private function cancelOneDelivery(SmsDelivery $delivery): SmsDelivery
	{
		$remoteId = $delivery->remoteId();
		if ($remoteId === null) {
			return $delivery->with(statusDetail: 'no remote message ID to cancel');
		}

		$result = $this->request('DELETE', '/v1/messages', ['message_id' => $remoteId]);

		if ($result->isFailure()) {
			return $delivery->with(statusDetail: $result->getError());
		}
		if (($result->getValue()['cancelled'] ?? false) !== true) {
			return $delivery->with(statusDetail: 'Cancel not confirmed. Raw: ' . json_encode($result->getValue()));
		}

		// Cancelled credits are refunded in full upstream; cost is zeroed so
		// a later status poll can't re-apply the pre-cancel segments.
		return $delivery->with(status: SmsStatus::CANCELLED, segmentsUsed: 0);
	}

	/**
	 * Query recent delivery statuses in one batch call.
	 *
	 * GET /v1/messages without message_id is the account history (shape
	 * verified against the live API):
	 *   {total, limit, offset, results: [{message_id, recipient_number,
	 *    sender_id, message_content, status, cost, custom_ref, requested_at,
	 *    scheduled_for}]}
	 *
	 * Date filters are day-granular UTC with an exclusive `to`, so the fetch
	 * starts at the start of $since's UTC day and rows are refined
	 * client-side against their send time. remoteId is the per-message
	 * `message_id`, the same UUID the send returned, so history sync matches
	 * local deliveries.
	 *
	 * @param ?int $since Unix timestamp; null = last 24h
	 * @return \Result<SmsDelivery[], string>
	 */
	public function listRecentDeliveries(?int $since = null): \Result
	{
		$since = $since ?? time() - 86400;
		$fromDay = gmdate('Y-m-d', $since);
		$toDay = gmdate('Y-m-d', time() + 86400);

		$all = [];
		$offset = 0;
		while (true) {
			$result = $this->request('GET', '/v1/messages?from=' . $fromDay . '&to=' . $toDay . '&limit=' . self::PAGE_SIZE . '&offset=' . $offset);
			if ($result->isFailure()) {
				return $result;
			}
			$data = $result->getValue();
			$page = $data['results'] ?? null;
			if (!\is_array($page)) {
				return \Result::failure('No results field in history response. Raw: ' . json_encode($data));
			}
			foreach ($page as $msg) {
				if (!\is_array($msg)) continue;
				// Day-granular server filter — refine to the exact timestamp.
				$sendTs = self::sendTimestampOf($msg);
				if ($sendTs !== null && $sendTs < $since) continue;
				$phone = preg_replace('/\D/', '', (string) ($msg['recipient_number'] ?? ''));
				if ($phone === '') continue; // skip records with empty/malformed phone numbers
				$all[] = new SmsDelivery(
					recipient: new PhoneNumber($phone),
					status: self::statusFromApi((string) ($msg['status'] ?? '')),
					remoteId: isset($msg['message_id']) && $msg['message_id'] !== '' ? (string) $msg['message_id'] : null,
					sendTimestamp: $sendTs,
					message: (string) ($msg['message_content'] ?? ''),
					rawResponse: json_encode($msg),
					segmentsUsed: isset($msg['cost']) && is_numeric($msg['cost']) ? (int) round((float) $msg['cost']) : null,
				);
			}
			// A reply without `total` still ends: a short page is the last one.
			$total = isset($data['total']) ? (int) $data['total'] : null;
			$offset += \count($page);
			if (\count($page) < self::PAGE_SIZE || ($total !== null && $offset >= $total)) {
				break;
			}
		}
		return \Result::success($all);
	}

	/**
	 * List all opted-out phone numbers from MobileMessage.
	 *
	 * Fetches all pages from GET /v1/unsubscribes internally. The API
	 * exposes only the opt-out timestamp (updated_at), no contact name.
	 *
	 * @return \Result<OptOutEntry[], string>
	 */
	public function listOptOuts(): \Result
	{
		// 1. Cache hit — stored as primitive arrays to avoid unserialize issues
		$cached = $this->cache?->get('sms_optouts');
		if ($cached !== null && \is_array($cached)) {
			$entries = [];
			foreach ($cached as $row) {
				$entries[] = new OptOutEntry(
					number: new PhoneNumber($row['number']),
					optedOutAt: $row['optedOutAt'] ?? null,
				);
			}
			return \Result::success($entries);
		}

		$all = [];
		$offset = 0;
		while (true) {
			$result = $this->request('GET', '/v1/unsubscribes?limit=' . self::PAGE_SIZE . '&offset=' . $offset);
			if ($result->isFailure()) {
				return $result;
			}
			$data = $result->getValue();
			$page = $data['results'] ?? null;
			if (!\is_array($page)) {
				return \Result::failure('No results field in unsubscribes response. Raw: ' . json_encode($data));
			}
			foreach ($page as $item) {
				$number = isset($item['number']) ? (string) $item['number'] : '';
				if ($number === '') continue;
				$all[] = new OptOutEntry(
					number: new PhoneNumber($number),
					optedOutAt: self::utcTimestamp($item['updated_at'] ?? null),
				);
			}
			// A reply without `total` still ends: a short page is the last one.
			$total = isset($data['total']) ? (int) $data['total'] : null;
			$offset += \count($page);
			if (\count($page) < self::PAGE_SIZE || ($total !== null && $offset >= $total)) {
				break;
			}
		}

		// Cache primitive arrays (not OptOutEntry objects) for safe session serialization
		$this->cache?->set('sms_optouts', array_map(static fn(OptOutEntry $e) => ['number' => $e->number->value, 'optedOutAt' => $e->optedOutAt], $all), 0);

		return \Result::success($all);
	}

	/**
	 * Remove a phone number from the opt-out list: DELETE /v1/unsubscribes
	 * with a JSON body {"number": …} (query parameters are ignored on DELETE).
	 * Reply: {"status":"complete","number":"614…","removed":true|false};
	 * removed is false when the number wasn't on the list.
	 *
	 * @return \Result<bool, string>  success(true) once the number is off the list
	 */
	public function removeOptOut(PhoneNumber $number): \Result
	{
		$result = $this->request('DELETE', '/v1/unsubscribes', ['number' => $number->value]);
		if ($result->isFailure()) {
			return $result;
		}

		$this->cache?->delete('sms_optouts');
		return \Result::success(true);
	}

	/** @return array<array{string, string, string}> */
	public static function getConstants(): array
	{
		return [
			['SMS_MOBILEMESSAGE_APIKEY_USERNAME', 'required', 'API username from the MobileMessage dashboard (Settings → API)'],
			['SMS_MOBILEMESSAGE_APIKEY_PASSWORD', 'required', 'API password from the MobileMessage dashboard (Settings → API)'],
			['SMS_MOBILEMESSAGE_URL', 'optional', 'API base URL (default: https://api.mobilemessage.com.au)'],
			['SMS_SENDER_OPTIONS', 'optional', 'Comma-separated sender options, may include _USER_MOBILE_ (overrides API discovery)'],
			['SMS_TESTMODE', 'optional', 'Dry-run without delivering'],
			['SMS_VERBOSE', 'optional', 'Log HTTP to error log'],
		];
	}

	public function getKey(): ?string
	{
		return 'mobilemessagev1';
	}

	public static function usagePreference(): int
	{
		return 10;
	}

	public function getDescription(): string
	{
		$desc = 'MobileMessage';
		if ($this->url !== self::CANONICAL_URL) {
			$desc .= ' (via ' . $this->url . ')';
		}
		return $desc;
	}

	public function hasCapability(SmsCapability $cap): bool
	{
		return \in_array($cap, [
			SmsCapability::GET_BALANCE,
			SmsCapability::GET_SENDER_IDS,   // alphanumeric IDs from GET /v1/senders
			SmsCapability::DEFERRED_SEND,
			SmsCapability::DEFERRED_SEND_CANCEL,
			SmsCapability::REGISTER_SENDER_NUMBER,
			// REGISTER_SENDER_ID is deliberately absent: ACMA registration
			// happens in the MobileMessage dashboard (see registerSenderId()).
			SmsCapability::LIST_OPT_OUTS,
			SmsCapability::REMOVE_OPT_OUT,
			SmsCapability::BATCH_DELIVERY_QUERY,
			SmsCapability::LIST_HISTORY,
		], true);
	}

	/**
	 * Nominal entry-tier credit price (2¢). Real per-credit pricing comes
	 * with each purchase — GET /v1/account exposes it as credit_price, and
	 * every send reports its cost in credits, so recorded costs self-correct.
	 */
	public const SEGMENT_COST_MILLICENTS = 2000;

	public function getSegmentCost(): int
	{
		return static::SEGMENT_COST_MILLICENTS;
	}

	/**
	 * The API documents only a 60-second MINIMUM for scheduled_for — no
	 * maximum — so scheduling is unbounded.
	 */
	public function getDeferredSendMaxDelay(): ?int
	{
		return null;
	}
}
