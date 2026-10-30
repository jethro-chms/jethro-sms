# DELETE /api/v5/sms/\{id\} — Cancel Scheduled SMS

---

## Overview

Cancels a previously scheduled (deferred) SMS message by its gateway-assigned ID.

Only messages in a **scheduled** state can be cancelled. Messages that have already been dispatched to the carrier cannot be recalled.

Called by `FiveCentSmsV5Provider::cancel()` → `cancelOneDelivery()` in `jethro-sms/src/Providers/FiveCentSmsV5Provider.php` (lines 2260–2302).

---

## Request

```
DELETE https://www.5centsms.com.au/api/v5/sms/{messageId}
Content-Type: application/json
```

`{messageId}` is the gateway-assigned message ID returned by the original `POST /api/v5/sms` call (stored as `SmsDelivery::$remoteId`). It is URL-encoded before insertion.

### Body

Authentication is passed in the JSON body. This is non-standard — a DELETE request with a body — but required by the 5CentSMS v5 API.

```json
{
  "key-id":     "your-api-key-id",
  "key-secret": "your-api-key-secret"
}
```

| Field        | Type   | Required | Description |
|--------------|--------|----------|-------------|
| `key-id`     | string | yes      | API key ID from the 5CentSMS dashboard (`SMS_5CENTSMS_APIKEY_ID`). |
| `key-secret` | string | yes      | API key secret from the 5CentSMS dashboard (`SMS_5CENTSMS_APIKEY`). |

No other fields are sent. The message to cancel is identified solely by the URL path parameter.

---

## Response

### Success — message cancelled

```json
{
  "messages": {
    "id":          "12345",
    "status":      1007,
    "status_text": "Cancelled"
  }
}
```

> **Note:** The DELETE response wraps the message object under `"messages"` (plural), unlike `GET /sms/{id}` which uses `"message"` (singular). The parser (`parseDeliveryInfo()`) accepts both keys.

### Error — non-JSON or unexpected shape

The gateway may return an `"error"` field or a non-JSON body on failure:

```json
{
  "error": "Message not found or already delivered."
}
```

On any gateway or transport failure (non-JSON, `error` field, HTTP error), `cancelOneDelivery()` returns the delivery **unchanged** — the batch-level result remains success, preserving the delivery's prior status. Only a complete auth or transport failure before any attempt is propagated as a batch-level error.

---

## Response fields

| Field        | Type    | Description |
|--------------|---------|-------------|
| `messages`   | object  | Present on success; contains the cancelled message record. |
| `messages.id` | string | Gateway-assigned message ID (echoed back from the request path). |
| `messages.status` | integer | Numeric status code. `1007` = cancelled. |
| `messages.status_text` | string | Human-readable status, e.g. `"Cancelled"`. |
| `error`      | string  | Present on failure; human-readable error description. |

### Status codes

| Code | `SmsStatus`             | Meaning |
|------|-------------------------|---------|
| 1007 | `SmsStatus::CANCELLED`  | Message was cancelled before delivery. |
| 1003 | `SmsStatus::FAILED`     | Cancellation was not possible (message already sent, expired, or not found). |
| _(other)_ | `SmsStatus::FAILED` | Unrecognised code; treated as failure. |

Mapped by `statusFromV5Code()` in `jethro-sms/src/Providers/FiveCentSmsV5Provider.php` (line 3402).

---

## Behavior

- **No-op for deliveries without a remote ID.** If `SmsDelivery::remoteId()` returns `null` (e.g. message was sent in test mode), `cancelOneDelivery()` returns the delivery unchanged without making an HTTP call.
- **Silent per-delivery failures.** If the HTTP request fails or the response cannot be parsed, `cancelOneDelivery()` returns the original delivery unchanged. The batch-level `Result` is still `success`.
- **Batch iteration.** `cancel()` iterates every delivery in the batch independently; each message triggers its own DELETE request.
- **Timeout.** Each request times out after 5 seconds.

---

## Source references

| Symbol | File | Line |
|--------|------|------|
| `FiveCentSmsV5Provider::cancel()` | `jethro-sms/src/Providers/FiveCentSmsV5Provider.php` | 2260 |
| `FiveCentSmsV5Provider::cancelOneDelivery()` | `jethro-sms/src/Providers/FiveCentSmsV5Provider.php` | 2274 |
| `FiveCentSmsV5Provider::parseDeliveryInfo()` | `jethro-sms/src/Providers/FiveCentSmsV5Provider.php` | 2192 |
| `statusFromV5Code()` | `jethro-sms/src/Providers/FiveCentSmsV5Provider.php` | 3402 |
| `SmsStatus::CANCELLED` | `jethro-sms/src/Providers/FiveCentSmsV5Provider.php` | 886 |

## Error Response Structure

Unknown — not yet documented.

---

**Parsed by:** `FiveCentSmsV5Provider::parseDeliveryInfo()`
