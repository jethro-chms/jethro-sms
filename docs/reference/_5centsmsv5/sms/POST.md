# POST /sms — Send SMS

Send one or more SMS messages to one or more recipients.

- **Method:** `POST`
- **URL:** `https://www.5centsms.com.au/api/v5/sms`
- **Content-Type:** `application/json`

---

## Authentication

Credentials are sent inside the JSON request body — **not** in HTTP headers.

| Field | Type | Description |
|---|---|---|
| `key-id` | string | API key identifier |
| `key-secret` | string | API key secret |

---

## Request Body

```json
{
  "key-id":     "YOUR_KEY_ID",
  "key-secret": "YOUR_KEY_SECRET",
  "sender":     "MyChurch",
  "to":         "61412345678,61498765432",
  "message":    "Service this Sunday at 10 am.",
  "test":       false
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `key-id` | string | yes | API key identifier |
| `key-secret` | string | yes | API key secret |
| `sender` | string | yes | Sender ID or phone number shown to recipients |
| `to` | string | yes | Comma-separated recipient numbers in international format (e.g. `614xxxxxxxx`) |
| `message` | string | yes | Message text |
| `test` | boolean | yes | `true` → API treats request as a dry-run; messages are accepted but **not delivered**. Real HTTP calls are still made to the provider. |
| `schedule` | integer | no | Unix timestamp (seconds since epoch) for delayed send. Omit for immediate delivery. |

> **Multiple recipients:** pass a single comma-separated string in `to`. The API maps each `destination` in its response back to the originating number.

---

## Response

HTTP `200 OK` with a JSON body.

### Success

```json
{
  "messages": [
    {
      "destination": "61412345678",
      "status":      1001,
      "status_text": "Sent",
      "id":          "msg_abc123",
      "credits":     1
    },
    {
      "destination": "61498765432",
      "status":      1005,
      "status_text": "Scheduled",
      "id":          "msg_def456",
      "credits":     1
    }
  ]
}
```

| Field | Type | Description |
|---|---|---|
| `destination` | string | Recipient phone number; matches an entry in the `to` field of the request |
| `status` | integer | Numeric status code — see [Status Codes](#status-codes) below |
| `status_text` | string | Human-readable status label returned by the API |
| `id` | string | Provider-assigned message ID; use with [GET /sms/\{id\}](GET.md) and [DELETE /sms/\{id\}](DELETE.md) |
| `credits` | integer | Credits consumed for this recipient |

### Error

When authentication fails or the request is rejected at the API level, the response contains a top-level `error` key:

```json
{
  "error": "Invalid credentials"
}
```

An error response (or one that can't be parsed) records every recipient of that request as `FAILED`, with the `error` text as the reason, and the send goes on with its other requests (one per entry: e.g. per recipient for personalised messages). If a request fails in transport (timeout, connection error, HTTP 5xx) after earlier requests were accepted, the accepted deliveries are kept, that request's recipients are recorded as `UNKNOWN` (it may have been sent), and later ones as `FAILED` (not sent) — see `\Sms\finishStoppedSend()`. Only a failure before anything was accepted is reported as a plain send failure.

---

## Status Codes

The `status` integer in each `messages` entry is mapped to an internal status by `statusFromV5Code()` (`jethro-sms/src/Providers/FiveCentSmsV5Provider.php:3402`).

| API `status` | Internal status | Meaning |
|---|---|---|
| `1000` | `QUEUED` | Accepted; queued for delivery |
| `1001` | `SENT` | Handed off to carrier |
| `1002` | `DELIVERED` | Delivery confirmed at handset |
| `1003` | `FAILED` | Delivery failed |
| `1004` | `DELIVERY_IN_PROGRESS` | In transit to handset |
| `1004` + `status_text == "Test Message"` | `TEST_MESSAGE` | Test send acknowledged; no delivery |
| `1005` | `SCHEDULED` | Accepted for future delivery |
| `1007` | `CANCELLED` | Message was cancelled before send |
| `1011` | `SENDING` | Currently being sent to carrier |
| `1527` | `SCHEDULED` | Alternate scheduled-send code |
| any other | `FAILED` | Unrecognised code; treated as failure |

> A recipient present in the request but **absent** from the `messages` array is also recorded as `FAILED` with the reason "Recipient not found in API response". Extra destinations in the response that were not requested are ignored entirely.

---

## Scheduled Send

Pass `schedule` as a Unix timestamp to defer delivery:

```json
{
  "key-id":     "YOUR_KEY_ID",
  "key-secret": "YOUR_KEY_SECRET",
  "sender":     "MyChurch",
  "to":         "61412345678",
  "message":    "Reminder: service tomorrow at 10 am.",
  "test":       false,
  "schedule":   1750500000
}
```

A successfully scheduled message returns status `1005` (or `1527`), both mapped to `SCHEDULED`.

---

## Test Mode

Setting `"test": true` in the request body instructs the 5CentSMS API to accept the message without delivering it. Unlike `FiveCentSmsV4Provider`, the v5 provider always makes a real HTTP call — there is no local fake-response layer for sends. The `FiveCentSmsV5FakeHttpClient` only intercepts `POST /senderid`; all other requests (including test-mode sends) reach the real API.

---

## Error Response Structure

Unknown — not yet documented.

---

## Source References

| Symbol | File | Line |
|---|---|---|
| `FiveCentSmsV5Provider::send()` | `jethro-sms/src/Providers/FiveCentSmsV5Provider.php` | 2742 |
| `FiveCentSmsV5Provider::parseResponse()` | `jethro-sms/src/Providers/FiveCentSmsV5Provider.php` | 2822 |
| `statusFromV5Code()` | `jethro-sms/src/Providers/FiveCentSmsV5Provider.php` | 3402 |
| `FiveCentSmsDelivery` | `jethro-sms/src/Providers/FiveCentSmsV5Provider.php` | 3429 |
| `FiveCentSmsV5FakeHttpClient` | `jethro-sms/src/Providers/FiveCentSmsV5FakeHttpClient.php` | — |
| Request body shape test | `jethro-sms/tests/providers/test_v5_parsing.php` | 216 |
| Mock response fixture | `tests/sms-mock-overrides-5centsms.json` | 21 |

**Parsed by:** `FiveCentSmsV5Provider::parseResponse()`

