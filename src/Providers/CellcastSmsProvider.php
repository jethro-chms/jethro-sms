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
 * SMS provider for the cellcast.com API.
 *
 * Uses JSON POST/GET requests with Bearer-token authentication.
 *
 * Numbers are sent as entered ('0401…' or '61401…') with countryCode
 * {@see self::COUNTRY_CODE}, which tells Cellcast how to read local numbers;
 * Cellcast normalises them. Numbers it returns are matched back to ours with
 * PhoneNumber::sameNumberAs() (last 9 digits), so no prefix settings are needed.
 *
 * @see SmsProvider
 * @see CellcastSmsDelivery
 */

class CellcastSmsProvider implements SmsProvider
{
	private const CANONICAL_URL = 'https://api.cellcast.com';

	/** Country code sent with every send; Cellcast is an Australian gateway. */
	private const COUNTRY_CODE = 61;

	public function __construct(
		/** API bearer token for authentication. */
		private string $apiToken,
		/** API base URL. */
		private string $url = self::CANONICAL_URL,
		/** Comma-separated Sender IDs. When set, overrides API-discovered Sender IDs. */
		private string $senderIds = '',
		private ?HttpClient $httpClient = null,
		private ?SmsCache $cache = null,
) {}

	/**
	 * Create a Cellcast provider from the given settings.
	 *
	 * Reads the SMS_CELLCAST_* settings.
	 *
	 * @param ?string $baseUrl  Absolute base URL of the Jethro instance. When a bare
	 *     path URL setting (starting with "/") is read, this is prepended to resolve it.
	 * @throws \RuntimeException when SMS_CELLCAST_APIKEY is missing
	 */
	public static function fromSettings(SmsSettings $settings, ?string $baseUrl = null): static
	{
		$apiToken = (string) $settings['SMS_CELLCAST_APIKEY']->val('');
		$url = rtrim(((string) $settings['SMS_CELLCAST_URL']->val('')) ?: self::CANONICAL_URL, '/');
		if ($url !== '' && $url[0] === '/' && $baseUrl !== null) {
			$origin = parse_url($baseUrl, PHP_URL_SCHEME) . '://' . parse_url($baseUrl, PHP_URL_HOST);
			$port = parse_url($baseUrl, PHP_URL_PORT);
			if ($port !== null) $origin .= ':' . $port;
			$url = $origin . $url;
		}

		if ($apiToken === '') {
			throw new \RuntimeException('Missing SMS configuration: ' . $settings['SMS_CELLCAST_APIKEY']->getName());
		}

		return new static(
			apiToken: $apiToken,
			url: $url,
			senderIds: (string) $settings['SMS_SENDER_OPTIONS']->val(''),
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
	 * Create the HTTP client for CellcastSmsProvider.
	 * @param bool $testMode
	 */
	public static function createHttpClient(bool $testMode, bool $verbose): HttpClient
	{
		if ($testMode) {
			$client = new CellcastFakeHttpClient(new NativeHttpClient());
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
	 * Build the common Authorization header.
	 */
	private function authHeader(): string
	{
		return "Authorization: Bearer {$this->apiToken}\r\n";
	}

	/**
	 * Send an HTTP request and return the parsed JSON body on success.
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

		return $this->parseJsonBody($response->getValue()->body);
	}

	/**
	 * Parse the JSON body of any Cellcast API response.
	 *
	 * Generic decoder used by all endpoints — not tied to a specific API shape.
	 */
	private function parseJsonBody(string $rawBody): \Result
	{
		$data = json_decode($rawBody, true);

		if (!\is_array($data)) {
			return \Result::failure('Invalid JSON response from Cellcast API. Raw: ' . $rawBody);
		}

		return \Result::success($data);
	}

	/**
	 * Resolve the effective sender: config override trumps caller-supplied.
	 */
	private function resolveSender(SmsSender $sender): string
	{
		// Phone-number senders go as entered; Cellcast normalises them.
		return (string) $sender;
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
		$allDeliveries = [];

		foreach (array_values($entries) as $index => $entry) {
			$message = $entry['message'];
			$recipients = $entry['recipients'];

			if ($preview) {
				foreach ($recipients as $r) {
					$allDeliveries[] = new SmsDelivery(
						recipient: $r->getPhoneNumber(),
						status: SmsStatus::QUEUED,
						message: $message,
					);
				}
				continue;
			}

		$body = [
			'message' => $message,
			'contacts' => array_map(
				fn (SmsRecipient $r) => $r->getPhoneNumber()->value,
				$recipients,
			),
			'sender' => $this->resolveSender($sender),
			'countryCode' => self::COUNTRY_CODE,
		];

		if ($sendAt !== null) {
			$body['scheduleAt'] = gmdate('Y-m-d H:i:s', $sendAt);
		}

		$result = $this->request('POST', '/api/v1/gateway', $body);

		// A transport failure may come after earlier entries were accepted
		// (sent and charged): keep them, so the send is logged in full.
		if ($result->isFailure()) {
			return \Sms\finishStoppedSend($entries, $index, $allDeliveries, $result->getError(), mayHaveBeenSent: true);
		}

		// Invalidate cached balance
		$this->cache?->delete('sms_balance');

		$data = $result->getValue();

			// Accept only a success envelope: status true (or absent) with a
			// data array. An auth error has no `status` at all — it comes as
			// {code: 401, message: …} — and must not be mistaken for success.
			$rejectReason = null;
			if (($data['status'] ?? null) === false) {
				$rejectReason = self::extractEnvelopeError($data);
			} elseif (isset($data['code']) && (int) $data['code'] >= 400) {
				$rejectReason = self::extractEnvelopeError($data);
			} elseif (!isset($data['data']) || !\is_array($data['data'])) {
				$rejectReason = 'Unexpected response: ' . json_encode($data);
			}
			if ($rejectReason !== null) {
				// Rejected: record its recipients as failed, and go on with the other entries.
				array_push($allDeliveries, ...\Sms\failedEntryDeliveries($entry, SmsStatus::FAILED, $rejectReason));
				continue;
			}

			foreach ($this->parseSendResponse($data, $recipients) as $d) {
				$allDeliveries[] = $d->with(message: $message);
			}
		}

		return \Result::success(new SmsDeliveryBatch(null, $allDeliveries));
	}

	/**
	 * Extract a human-readable error from a Cellcast API response with
	 * `status: false`.  Prefers the per-field messages in `error` (e.g.
	 * {"sender": "Your sender id is not registered."}), falling back to
	 * the top-level `message` field.
	 */
	private static function extractEnvelopeError(array $data): string
	{
		if (isset($data['error']) && \is_array($data['error']) && $data['error'] !== []) {
			return implode(' ', array_map('strval', $data['error']));
		}
		if (isset($data['message']) && $data['message'] !== '') {
			return (string) $data['message'];
		}
		return 'Unknown error — raw: ' . json_encode($data);
	}

	/**
	 * Parse the Cellcast send response into per-recipient SmsDelivery objects.
	 *
	 * The response data.queueResponse[] contains one entry per successfully
	 * queued recipient.  Recipients in our list that don't appear in
	 * queueResponse are marked as FAILED.
	 *
	 * @see docs/reference/_cellcast/gateway/POST.md
	 *
	 * @param array $data  Parsed JSON response
	 * @param SmsRecipient[] $recipients
	 * @return SmsDelivery[]
	 */
	private function parseSendResponse(array $data, array $recipients): array
	{
		$responseData = $data['data'] ?? null;
		if (!\is_array($responseData)) {
			return [];
		}

		// No queueResponse (or an empty one) means nothing was accepted: the
		// recipients below are marked failed rather than silently dropped.
		$queueItems = $responseData['queueResponse'] ?? [];

		// Build destination → queueItem map. Cellcast may return the number in
		// international form ('61401…') when we sent '0401…', so fall back to
		// PhoneNumber::sameNumberAs() when there is no exact match.
		$queuedMap = [];
		foreach ($queueItems as $item) {
			$number = isset($item['Number']) ? (string) $item['Number'] : '';
			if (preg_match('/\d/', $number)) {
				$queuedMap[$number] = $item;
			}
		}

		$results = [];
		foreach ($recipients as $recip) {
			$number = $recip->getPhoneNumber();
			$item = $queuedMap[$number->value] ?? null;
			if ($item === null) {
				foreach ($queuedMap as $returned => $candidate) {
					if ($number->sameNumberAs(new PhoneNumber((string) $returned))) {
						$item = $candidate;
						break;
					}
				}
			}
			if ($item !== null) {
				$results[] = new CellcastSmsDelivery($number, $item);
			} else {
				$results[] = new SmsDelivery(
					recipient: $number,
					status: SmsStatus::FAILED,
					statusDetail: 'Not accepted by the gateway',
				);
			}
		}

		return $results;
	}

	public function getBalance(): \Result
	{
		// 1. Cache hit
		$cached = $this->cache?->get('sms_balance');
		if ($cached !== null) {
			return \Result::success((int) $cached);
		}

		// 2. Fetch from API
		// Cellcast account endpoint uses a different envelope:
		//   { meta: { code, status }, data: { sms_balance, ... } }
		$result = $this->request('GET', '/api/v1/apiClient/account');

		if ($result->isFailure()) {
			return $result;
		}

		return $this->parseBalanceResponse($result->getValue());
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
	 * Parse the Cellcast account/balance response.
	 *
	 * @see docs/reference/_cellcast/account/GET.md
	 *
	 * @return \Result<int, string>
	 */
	private function parseBalanceResponse(array $data): \Result
	{
		// Cellcast account endpoint uses { meta, data } envelope
		$balanceData = $data['data'] ?? null;

		if (!\is_array($balanceData)) {
			return \Result::failure('No data field in balance response. Raw: ' . json_encode($data));
		}

		if (!isset($balanceData['sms_balance'])) {
			return \Result::failure('No sms_balance field in account response. Raw: ' . json_encode($data));
		}

		$balance = (int) (float) $balanceData['sms_balance'];

		// Cache the result
		$this->cache?->set('sms_balance', $balance, 300);

		return \Result::success($balance);
	}

	/**
	 * Fetch the raw /api/v1/customNumber response items (sender phone
	 * numbers), with session caching.
	 *
	 * @return \Result<array<array{number: string, name: string}>, string>
	 */
	private function fetchSenderItems(): \Result
	{
		return $this->fetchCachedList('/api/v1/customNumber', 'sms_sendernumbers');
	}

	/**
	 * GET a Cellcast list endpoint's `data` array, cached for the session
	 * under $cacheKey.
	 *
	 * @return \Result<array<int, array<string, mixed>>, string>
	 */
	private function fetchCachedList(string $path, string $cacheKey): \Result
	{
		$cached = $this->cache?->get($cacheKey);
		if ($cached !== null && \is_array($cached)) {
			return \Result::success($cached);
		}

		$result = $this->request('GET', $path);
		if ($result->isFailure()) {
			return $result;
		}

		$data = $result->getValue();

		// Cellcast auth/server errors return {"code": N, "message": "..."}
		// without a "data" key.  Distinguish from legitimate empty responses.
		if (isset($data['code']) && !isset($data['data'])) {
			$msg = $data['message'] ?? 'Unknown error';
			return \Result::failure("Cellcast API error (code {$data['code']}): {$msg}");
		}

		$items = isset($data['data']) && \is_array($data['data']) ? $data['data'] : [];

		$this->cache?->set($cacheKey, $items, 1800);

		return \Result::success($items);
	}

	/**
	 * Alphanumeric Sender IDs ("business names" / Alpha IDs) from
	 * GET /api/v1/business — not /api/v1/customNumber, which lists only
	 * sender phone numbers. The endpoint is undocumented; its shape is in
	 * jethro-sms/docs/reference/_cellcast/business/GET.md.
	 *
	 * Deleted entries are skipped. An ID is approved when Cellcast reports
	 * acmaStatus ACMA_APPROVED or status ACTIVE; anything else (requested,
	 * pending Cellcast or ACMA review, rejected) is not yet usable, so it is
	 * marked unapproved and {@see \Sms\excludeUnapprovedSenderIds()} hides it
	 * from senders unless $getAll.
	 *
	 * @return \Result<\Sms\SenderID[], string>
	 */
	public function getSenderIds(bool $getAll = false): \Result
	{
		return $this->fetchCachedList('/api/v1/business', 'sms_senderids')->map(function (array $items) use ($getAll) {
			$ids = [];
			foreach ($items as $item) {
				$name = isset($item['businessname']) ? trim((string) $item['businessname']) : '';
				if ($name === '' || !empty($item['isDeleted'])) continue;
				$approved = ($item['acmaStatus'] ?? null) === 'ACMA_APPROVED'
					|| ($item['status'] ?? null) === 'ACTIVE';
				$ids[] = new \Sms\SenderID($name, acmaApproved: $approved);
			}
			return $getAll ? $ids : \Sms\excludeUnapprovedSenderIds($ids);
		});
	}

	public function updateDelivery(SmsDelivery $delivery): \Result
	{
		$remoteId = $delivery->remoteId();
		if ($remoteId === null) {
			return \Result::failure('No remote ID on delivery');
		}

		// Cellcast delivery endpoint is v2: /api/v2/report/message/:messageId
		$result = $this->request('GET', '/api/v2/report/message/' . urlencode($remoteId));

		if ($result->isFailure()) {
			return $result;
		}

		return $this->parseDeliveryResponse($delivery->recipient(), $result->getValue());
	}

	/**
	 * Parse the Cellcast delivery status response.
	 *
	 * @see docs/reference/_cellcast/report/message/{id}/GET.md
	 *
	 * @return \Result<SmsDelivery, string>
	 */
	private function parseDeliveryResponse(PhoneNumber $recipient, array $data): \Result
	{
		// Check for API-level error envelope: Cellcast returns { status: false, message: "...", error: {...} }
		// for failed lookups (invalid message IDs, auth errors, etc.).
		if (($data['status'] ?? null) === false) {
			$msg = isset($data['message']) && is_string($data['message'])
				? $data['message']
				: (isset($data['error']['message']) && is_string($data['error']['message'])
					? $data['error']['message']
					: 'Unknown delivery lookup error');
			return \Result::failure($msg . '. Raw: ' . json_encode($data));
		}

		$deliveryData = $data['data'] ?? null;

		if (!\is_array($deliveryData)) {
			return \Result::failure('No data field in delivery response. Raw: ' . json_encode($data));
		}

		$statusStr = isset($deliveryData['status']) ? (string) $deliveryData['status'] : '';
		$status = match ($statusStr) {
			'queued'    => SmsStatus::QUEUED,
			'scheduled' => SmsStatus::SCHEDULED,
			'sent'      => SmsStatus::SENT,
			'delivered' => SmsStatus::DELIVERED,
			'failed', 'blocked', 'rejected', 'expired' => SmsStatus::FAILED,
			'canceled'  => SmsStatus::CANCELLED,
			default     => SmsStatus::UNKNOWN,
		};

		$sendTs = null;
		if (!empty($deliveryData['send_time'])) {
			$sendTs = strtotime((string) $deliveryData['send_time']) ?: null;
		}

		// Only set a delivery timestamp when the handset confirmed delivery.
		// updatedAt is "when the record last changed", not a delivery confirmation.
		$delTs = null;
		if ($status === SmsStatus::DELIVERED) {
			$updatedAt = !empty($deliveryData['updatedAt']) ? (string) $deliveryData['updatedAt'] : '';
			if ($updatedAt !== '') {
				$delTs = strtotime($updatedAt) ?: null;
			}
		}

		[$segments, $perSegment] = self::costFrom($deliveryData);
		return \Result::success(new SmsDelivery(
			recipient: $recipient,
			status: $status,
			remoteId: isset($deliveryData['_id']) ? (string) $deliveryData['_id'] : null,
			deliveryTimestamp: $delTs,
			sendTimestamp: $sendTs,
			segmentsUsed: $segments,
			perSegmentCost: $perSegment,
			rawResponse: json_encode($deliveryData),
		));
	}

	/**
	 * SMS segments and dollars-per-segment Cellcast charged for one message, from
	 * its `smsDetail` (report items and single-message lookups). One credit
	 * is one SMS segment; the price per segment depends on the bundle bought.
	 * The report gives `costpersms`; single lookups only `deductibleCredit`
	 * (the total), so the price is derived as deductibleCredit / smscount.
	 *
	 * @param array<string, mixed> $msg
	 * @return array{0: ?int, 1: ?float}  [segmentsUsed, perSegmentCost]
	 */
	private static function costFrom(array $msg): array
	{
		$detail = \is_array($msg['smsDetail'] ?? null) ? $msg['smsDetail'] : [];
		$segments = isset($detail['smscount']) && is_numeric($detail['smscount']) ? (int) $detail['smscount'] : null;
		$perSegment = null;
		if (isset($detail['costpersms']) && is_numeric($detail['costpersms'])) {
			$perSegment = (float) $detail['costpersms'];
		} elseif ($segments !== null && $segments > 0 && isset($detail['deductibleCredit']) && is_numeric($detail['deductibleCredit'])) {
			$perSegment = (float) $detail['deductibleCredit'] / $segments;
		}
		return [$segments, $perSegment];
	}

	/**
	 * Cancel previously sent (scheduled/deferred) SMS messages.
	 *
	 * Iterates each delivery in the batch; per-message gateway failures leave
	 * that delivery's status unchanged, with the upstream/transport message in
	 * statusDetail().  The batch-level result is always success unless there
	 * is a batch-level impossibility.
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
	 * Cancel a single delivery via POST to the Cellcast cancel endpoint.
	 *
	 * On failure — transport error, or an HTTP-200 envelope with status:false
	 * (e.g. "message not found") — returns the delivery unchanged except for
	 * statusDetail(), which carries the upstream or transport message.
	 */
	private function cancelOneDelivery(SmsDelivery $delivery): SmsDelivery
	{
		$remoteId = $delivery->remoteId();
		if ($remoteId === null) {
			return $delivery->with(statusDetail: 'no remote message ID to cancel');
		}

		$result = $this->request('POST', '/api/v1/gateway/cancelScheduleQuickMessage', [
			'messageId' => $remoteId,
			'type' => 'sms',
		]);

		if ($result->isFailure()) {
			return $delivery->with(statusDetail: $result->getError());
		}

		$data = $result->getValue();
		if (($data['status'] ?? false) !== true) {
			return $delivery->with(statusDetail: self::extractEnvelopeError($data));
		}

		return $delivery->with(status: SmsStatus::CANCELLED);
	}

	/**
	 * Check whether a specific phone number is approved as a sender.
	 *
	 * Calls getSenderNumbers() and checks whether the given number
	 * is among the registered custom numbers.
	 *
	 * Registered numbers come back in international form; they are compared
	 * with PhoneNumber::sameNumberAs() so local-form input still matches.
	 *
	 * @param PhoneNumber $number  The phone number to check
	 * @return \Result<bool, string>
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
	 * Get all registered sender phone numbers from Cellcast.
	 *
	 * Reads {@see fetchSenderItems()} (GET /api/v1/customNumber, session-cached
	 * under 'sms_sendernumbers'). Sender IDs come from a different endpoint
	 * ({@see getSenderIds()}).
	 *
	 * @return \Result<PhoneNumber[], string>
	 */
	public function getSenderNumbers(): \Result
	{
		return $this->fetchSenderItems()->map(function (array $items) {
			$numbers = [];
			foreach ($items as $item) {
				if (isset($item['number']) && \is_string($item['number']) && $item['number'] !== '') {
					$number = $item['number'];
					// Skip alphanumeric sender IDs — getSenderIds() handles those
					if (!preg_match('/^\+?\d+$/', $number)) continue;
					$numbers[] = new PhoneNumber($number);
				}
			}
			return $numbers;
		});
	}

	/**
	 * Sender records as the account's customNumber list reports them.
	 *
	 * Items carry `_id`, `status` and `createdAt` (recorded live — see
	 * docs/reference/_cellcast/customNumber/GET.md), so
	 * records expose the registration date and the record id.
	 *
	 * @return \Result<SenderNumberRecord[], string>
	 */
	public function getSenderNumberRecords(): \Result
	{
		return $this->fetchSenderItems()->map(function (array $items) {
			$records = [];
			foreach ($items as $item) {
				$value = isset($item['number']) && \is_string($item['number']) ? $item['number'] : '';
				if ($value === '') continue;
				$created = isset($item['createdAt']) && \is_string($item['createdAt']) ? strtotime($item['createdAt']) : false;
				$records[] = new SenderNumberRecord(
					$value,
					isset($item['status']) && \is_string($item['status']) ? $item['status'] : null,
					$created !== false ? $created : null,
					isset($item['_id']) && \is_scalar($item['_id']) ? (string) $item['_id'] : null,
				);
			}
			return $records;
		});
	}

	/** @inheritDoc */
	public function deleteSenderNumber(PhoneNumber $number): \Result
	{
		return \Result::failure('Cellcast does not support deleting sender numbers via the API.');
	}

	/**
	 * Register a phone number as a sender with Cellcast.
	 *
	 * Discovery (no args): returns the OTP field schema.
	 * Registration ($contact set, params null): calls POST
	 *   /api/v1/customNumber/add.  Returns 'registered': true if the
	 *   number is immediately usable (already known or created without
	 *   OTP), or 'registered': false with an OTP field if verification
	 *   is required.
	 * Verification ($contact set, params set): calls POST
	 *   /api/v1/customNumber/verifyCustomNumber with the OTP code.
	 *
	 * @inheritDoc
	 */
	public function registerSenderNumber(?ContactPhoneNumber $contact = null, ?array $validationParams = null): \Result
	{
		if ($validationParams === null) {
			// Bare schema — no contact, no side effects (UI help)
			if ($contact === null) {
				return \Result::success(new RegistrationStep(
					fields: [
						new FormField('otp', 'Verification code', 'text', required: true),
					],
				));
			}

			// Phase 1: Register the number upstream, as entered (Cellcast normalises it)
			$result = $this->request('POST', '/api/v1/customNumber/add', [
				'name' => $contact->name,
				'number' => $contact->phoneNumber->value,
			]);

			if ($result->isFailure()) {
				return $result;
			}

			$data = $result->getValue();
			$msg = isset($data['message']) ? (string) $data['message'] : '';

			if (empty($data['status'])) {
				return \Result::failure($msg !== '' ? $msg : 'Unknown error — raw: ' . json_encode($data));
			}

			// "Number already exist in system" — already registered
			if (stripos($msg, 'already exist') !== false) {
				$this->cache?->delete('sms_sendernumbers');
				return \Result::success(new RegistrationStep(registered: true, message: $msg));
			}

			// "Custom number created" — no OTP required, immediately registered
			if (stripos($msg, 'created') !== false) {
				$this->cache?->delete('sms_sendernumbers');
				return \Result::success(new RegistrationStep(registered: true, message: $msg));
			}

			// "OTP sent to your number successfully" — OTP required
			return \Result::success(new RegistrationStep(
				registered: false,
				message: $msg,
				fields: [
					new FormField('otp', 'Verification code', 'text', required: true),
				],
			));
		}

		// Phase 2: Submit OTP for verification
		$otp = $validationParams['otp'] ?? '';
		if ($otp === '') {
			return \Result::failure('OTP code is required.');
		}

		$number = $contact->phoneNumber;
		$result = $this->request('POST', '/api/v1/customNumber/verifyCustomNumber', [
			'name' => $contact->name,
			'number' => $number->value,
			'otp' => $otp,
		]);

		if ($result->isFailure()) {
			return \Result::failure($result->getError());
		}

		$data = $result->getValue();

		if (!empty($data['status'])) {
			$this->cache?->delete('sms_sendernumbers');
			return \Result::success(new RegistrationStep(
				registered: true,
				message: 'Number verified successfully.',
				number: $number->value,
			));
		}

		$errorMsg = isset($data['message']) ? (string) $data['message'] : 'OTP verification failed';
		return \Result::failure($errorMsg);
	}

	/** @return array<array{string, string, string}> */
	public static function getConstants(): array
	{
		return [
			['SMS_CELLCAST_APIKEY', 'required', 'API token from Cellcast dashboard'],
			['SMS_TESTMODE', 'optional', 'Dry-run test mode: bool'],
			['SMS_SENDER_OPTIONS', 'optional', 'Comma-separated sender options, may include _USER_MOBILE_ (overrides API discovery)'],
			['SMS_VERBOSE', 'optional', 'Log HTTP to error log'],
		];
	}

	public function getKey(): ?string
	{
		return 'cellcastv1';
	}

	public static function usagePreference(): int
	{
		return 9;
	}

	public function getDescription(): string
	{
		$desc = 'Cellcast';
		if ($this->url !== self::CANONICAL_URL) {
			$desc .= ' (via ' . $this->url . ')';
		}
		return $desc;
	}

	public function hasCapability(SmsCapability $cap): bool
	{
		return \in_array($cap, [
			SmsCapability::GET_BALANCE,
			SmsCapability::GET_SENDER_IDS,	// business names from GET /api/v1/business; see getSenderIds()
			SmsCapability::DEFERRED_SEND,
			SmsCapability::DEFERRED_SEND_CANCEL,
			SmsCapability::REGISTER_SENDER_NUMBER,
			SmsCapability::REGISTER_SENDER_ID,
			SmsCapability::LIST_OPT_OUTS,
			SmsCapability::BATCH_DELIVERY_QUERY,
			SmsCapability::LIST_HISTORY,
		], true);
	}

	public const SEGMENT_COST_MILLICENTS = 4300;

	public function getSegmentCost(): int
	{
		return static::SEGMENT_COST_MILLICENTS;
	}

	public function getDeferredSendMaxDelay(): ?int
	{
		return 86400; // 24 hours per Cellcast documentation
	}

	/**
	 * Fetches every report page from $since (date granularity) up to the
	 * reported totalPages. No page cap: Synchronize History relies on
	 * getting the whole range.
	 *
	 * Response shape: {data: {items: [{_id, status, message, receiver,
	 * scheduleAt, createdAt, …}], totalPages}}. `_id` is the MessageId the
	 * send returned; `receiver` is the national number (e.g. '491570157').
	 *
	 * @param ?int $since Unix timestamp; null = last 24h
	 * @return \Result<SmsDelivery[], string>
	 * @see Jethro's docs/docs/developer/sms/history-sync.md
	 */
	public function listRecentDeliveries(?int $since = null): \Result
	{
		$since = $since ?? time() - 86400;
		$all = [];
		$page = 1;
		do {
			$path = '/api/v2/report/message?campType=sms&fromDate=' . date('Y-m-d', $since) . '&page=' . $page . '&limit=100';
			$result = $this->request('GET', $path);
			if ($result->isFailure()) {
				return \Result::failure('listRecentDeliveries: ' . $result->getError());
			}
			$data = $result->getValue();
			// A 200 can still carry an error envelope (e.g. {code:401,message}).
			if (($data['status'] ?? true) === false || (int)($data['code'] ?? 0) >= 400) {
				return \Result::failure('listRecentDeliveries: ' . self::extractEnvelopeError($data));
			}
			$messages = $data['data']['items'] ?? [];
			if (!is_array($messages)) {
				return \Result::failure('listRecentDeliveries: unexpected response shape');
			}
			foreach ($messages as $msg) {
				if (!is_array($msg)) continue;
				$statusStr = isset($msg['status']) ? (string)$msg['status'] : '';
				$status = match ($statusStr) {
					'queued' => SmsStatus::QUEUED,
					'scheduled' => SmsStatus::SCHEDULED,
					'sent' => SmsStatus::SENT,
					'delivered' => SmsStatus::DELIVERED,
					'failed', 'blocked', 'rejected', 'expired' => SmsStatus::FAILED,
					'canceled' => SmsStatus::CANCELLED,
					default => SmsStatus::UNKNOWN,
				};
				// Scheduled sends go out at scheduleAt; immediate ones at createdAt.
				$sendTs = null;
				$sendAt = (string)($msg['scheduleAt'] ?? '') ?: (string)($msg['createdAt'] ?? '');
				if ($sendAt !== '') {
					$sendTs = strtotime($sendAt) ?: null;
				}
				$phone = preg_replace('/\D/', '', (string)($msg['receiver'] ?? ''));
				if ($phone === '') {
					continue; // skip records with empty/malformed phone numbers
				}
				[$segments, $perSegment] = self::costFrom($msg);
				$all[] = new SmsDelivery(
					recipient: new PhoneNumber($phone),
					status: $status,
					remoteId: isset($msg['_id']) ? (string)$msg['_id'] : null,
					deliveryTimestamp: null,
					sendTimestamp: $sendTs,
					message: (string)($msg['message'] ?? ''),
					rawResponse: json_encode($msg),
					segmentsUsed: $segments,
					perSegmentCost: $perSegment,
				);
			}
			$totalPages = (int)($data['data']['totalPages'] ?? 1);
			$page++;
		} while ($page <= $totalPages);
		return \Result::success($all);
	}

	/**
	 * List all opted-out phone numbers from Cellcast.
	 *
	 * Fetches all pages from GET /api/v1/apiClient/getOptout internally.
	 * The Cellcast API does not return an opt-out timestamp — optedOutAt
	 * is always null.  Full names are populated when available.
	 *
	 * @return \Result<\Sms\OptOutEntry[], string>
	 */
	public function listOptOuts(): \Result
	{
		// 1. Cache hit — stored as primitive arrays to avoid unserialize issues
		$cached = $this->cache?->get('sms_optouts');
		if ($cached !== null && \is_array($cached)) {
			$entries = [];
			foreach ($cached as $row) {
				$entries[] = new \Sms\OptOutEntry(
					number: new \Sms\PhoneNumber($row['number']),
					name: $row['name'] ?? null,
				);
			}
			return \Result::success($entries);
		}
        $page = 1;
        $size = 100;
        $hasNext = true;
        $allEntries = [];
				while ($hasNext) {
			$path = '/api/v1/apiClient/getOptout?page=' . $page . '&size=' . $size;
			$result = $this->request('GET', $path);

			if ($result->isFailure()) {
				return $result;
			}

			$data = $result->getValue();
			$responseData = $data['data'] ?? null;

			if (!\is_array($responseData)) {
				return \Result::failure('No data field in opt-out response. Raw: ' . json_encode($data));
			}

			$items = $responseData['items'] ?? [];
			foreach ($items as $item) {
				$number = isset($item['number']) ? (string) $item['number'] : '';
				if ($number === '') continue;

				$name = null;
				$fullName = isset($item['full_name']) ? (string) $item['full_name'] : '';
				if ($fullName !== '') {
					$name = $fullName;
				} else {
					$first = isset($item['first_name']) ? (string) $item['first_name'] : '';
					$last = isset($item['last_name']) ? (string) $item['last_name'] : '';
					if ($first !== '' || $last !== '') {
						$name = trim($first . ' ' . $last);
					}
				}

				$allEntries[] = new \Sms\OptOutEntry(
					number: new \Sms\PhoneNumber($number),
					name: $name,
				);
			}

			$hasNext = !empty($responseData['hasNextPage']);
			$page++;
		}

		// Cache primitive arrays (not OptOutEntry objects) for safe session serialization
		$cacheRows = [];
		foreach ($allEntries as $e) {
			$cacheRows[] = [
				'number' => $e->number->value,
				'name' => $e->name,
			];
		}
		$this->cache?->set('sms_optouts', $cacheRows, 0);

		return \Result::success($allEntries);
	}

	/**
	 * Remove a phone number from the opt-out list.
	 *
	 * Cellcast does not support opt-out removal via the API.
	 *
	 * @return \Result<bool, string>
	 */
	public function removeOptOut(\Sms\PhoneNumber $number): \Result
	{
		return \Result::failure('Cellcast does not support removing opt-outs via the API.');
	}

	/**
	 * Register a sender ID (business identity) with Cellcast.
	 *
	 * Discovery (no args): returns the business registration field schema.
	 * Creation ($senderId set, params null): returns the schema with the
	 *   sender ID pre-populated (Cellcast has no separate creation API).
	 * Submission ($senderId set, params set): calls POST
	 *   /api/v1/business/add with the full business registration payload.
	 *
	 * @see https://developer.cellcast.com/sender-id/business-name.html
	 * @inheritDoc
	 */
	public function registerSenderId(?SenderID $senderId = null, ?array $validationParams = null): \Result
	{
		if ($validationParams === null) {
			// Return field schema (bare or pre-populated)
			$sid = $senderId ?? new \Sms\SenderID('');
			return \Result::success(new RegistrationStep(
                message: 'Submitting this form will use the Cellcast Custom Business Name registration API (https://developer.cellcast.com/sender-id/business-name.html) to register a Sender ID.',
				fields: self::getSenderIdFieldSchema($sid),
			));
		}

		// Phase 2: Submit to Cellcast registration API
		$businessName        = $validationParams['businessname'] ?? '';
		$descriptionInternal = $validationParams['descriptionInternal'] ?? '';
		$purposeOfUse        = $validationParams['purposeOfUse'] ?? '';
		$ownership           = !empty($validationParams['ownership']);
		$customerContact     = $validationParams['customerContact'] ?? '';

		$companyInfo = [];
		if (!empty($validationParams['company_name']))    $companyInfo['name']    = $validationParams['company_name'];
		if (!empty($validationParams['company_abn']))     $companyInfo['abn']     = $validationParams['company_abn'];
		if (!empty($validationParams['company_website'])) $companyInfo['website'] = $validationParams['company_website'];
		if (!empty($validationParams['company_address'])) $companyInfo['address'] = $validationParams['company_address'];

		$requestorContact = [];
		if (!empty($validationParams['requestor_firstName']))   $requestorContact['firstName']   = $validationParams['requestor_firstName'];
		if (!empty($validationParams['requestor_lastName']))    $requestorContact['lastName']    = $validationParams['requestor_lastName'];
		if (!empty($validationParams['requestor_position']))    $requestorContact['position']    = $validationParams['requestor_position'];
		if (!empty($validationParams['requestor_phoneNumber'])) $requestorContact['phoneNumber'] = $validationParams['requestor_phoneNumber'];
		if (!empty($validationParams['requestor_email']))       $requestorContact['email']       = $validationParams['requestor_email'];

		if ($businessName === '') {
			return \Result::failure('businessname is required');
		}

		// Submit fields as per https://developer.cellcast.com/sender-id/business-name.html
		$body = array_filter([
			'businessname' => $businessName,
			'descriptionInternal' => $descriptionInternal !== '' ? $descriptionInternal : null,
			'purposeOfUse' => $purposeOfUse !== '' ? $purposeOfUse : null,
			'ownership' => $ownership,
			'companyInformation' => $companyInfo !== [] ? $companyInfo : null,
			'requestorContact' => $requestorContact !== [] ? $requestorContact : null,
			'customerContact' => $customerContact !== '' ? $customerContact : null,
		], fn ($v) => $v !== null);

		$headers = $this->authHeader();
		$bodyStr = json_encode($body);
		$headers .= "Content-Type: application/json\r\n";
		$headers .= 'Content-Length: ' . \strlen($bodyStr) . "\r\n";

		$httpRequest = new HttpRequest(
			url: $this->url . '/api/v1/business/add',
			method: 'POST',
			headers: $headers,
			body: $bodyStr,
			timeout: 30,
		);

		$response = $this->httpClient->send($httpRequest);

		if ($response->isFailure()) {
			return \Result::failure($response->getError());
		}

		$parsed = $this->parseJsonBody($response->getValue()->body);

		if ($parsed->isFailure()) {
			return \Result::failure($parsed->getError());
		}

		$apiData = $parsed->getValue();
		$apiMsg = \is_array($apiData) && isset($apiData['message']) ? (string) $apiData['message'] : '';
		$success = \is_array($apiData) && !empty($apiData['status']);

		// Rejections (e.g. "businessname is already registered") are failures,
		// matching FiveCentSmsV5Provider.
		if (!$success) {
			return \Result::failure($apiMsg !== '' ? $apiMsg : 'Cellcast rejected the Sender ID registration.');
		}

		// Build form rows from submitted params, mirroring V5's pattern
		$formRows = [];
		foreach (self::getSenderIdFieldSchema($senderId ?? new \Sms\SenderID('')) as $f) {
			$formRows[] = ['label' => $f->label, 'value' => (string) ($validationParams[$f->name] ?? '')];
		}

		return \Result::success(new RegistrationStep(
			registered: false, // Cellcast business-name approval is a manual step
			message: $apiMsg !== '' ? $apiMsg : 'Sender ID registration submitted.',
			instructions: 'Cellcast will review your business name registration. You will be contacted if further information is required.',
			contact: '',
			form: $formRows,
		));
	}

	/**
	 * Field schema for sender ID (business identity) registration.
	 *
     * Fields and descriptions are from https://developer.cellcast.com/sender-id/business-name.html
     *
	 * @return FormField[]
	 */
	public static function getSenderIdFieldSchema(\Sms\SenderID $senderId): array
	{
		return [
			new FormField('businessname',        'Business Name / Sender ID',          'text',     required: true,  description: 'Required. Must be between 3 and 11 characters.', value: $senderId->value),
			new FormField('descriptionInternal',  'Internal Description',   'text',     required: true,  description: 'Required. Internal description for this business name.'),
			new FormField('purposeOfUse',         'Purpose of Use',         'select',   required: true,  description: 'Required. Purpose of use / traffic type.', options: ['Promotional SMS', 'Transactional SMS', 'Service SMS']),
			new FormField('ownership',            'I own this business',     'checkbox', required: true,  description: 'Required. Boolean indicating ownership of the business name (true/false).'),
			new FormField('company_name',         'Company Name',            'text',     required: true,  description: 'Required. Company name.'),
			new FormField('company_abn',          'ABN',                     'text',     required: true,  description: 'Required. Australian Business Number (ABN).'),
			new FormField('company_website',      'Website',                 'text',     required: true,  description: 'Required. Company website URL.'),
			new FormField('company_address',      'Address',                 'text',     required: true,  description: 'Required. Company address.'),
			new FormField('requestor_firstName',  'First Name (requestor)',  'text',     required: true,  description: 'Required. Requestor\'s first name.'),
			new FormField('requestor_lastName',   'Last Name (requestor)',   'text',     required: true,  description: 'Required. Requestor\'s last name.'),
			new FormField('requestor_position',   'Position (requestor)',    'text',     required: true,  description: 'Required. Requestor\'s position/role.'),
			new FormField('requestor_phoneNumber','Phone Number (requestor)','text',     required: true,  description: 'Required. Requestor\'s phone number (digits only).'),
			new FormField('requestor_email',      'Email (requestor)',       'text',     required: true,  description: 'Required. Requestor\'s email address.'),
			new FormField('customerContact',      'Customer Contact Number', 'text',     required: false, description: 'Optional. Your contact number. Cellcast may contact you on this number for business name approval. You can use formats +61400000000, 61400000000, 0400000000, or 400000000.'),
		];
	}
}
