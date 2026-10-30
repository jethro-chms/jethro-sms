# GET /sms/\{id\} — Delivery Status

Poll the 5CentSMS v5 API for the current delivery status of a previously sent message.

**Method:** `GET`  
**URL:** `https://www.5centsms.com.au/api/v5/sms/{id}`

> **Non-standard:** This GET request carries a JSON body for authentication. Most HTTP clients
> and proxies allow bodies on GET requests; the 5CentSMS v5 API requires it.

---

## Authentication

Credentials are sent in the JSON request body, not in HTTP headers.

| Field        | Type   | Description                   |
|--------------|--------|-------------------------------|
| `key-id`     | string | API key ID (from dashboard)   |
| `key-secret` | string | API key secret (from dashboard) |

---

## Path Parameter

| Parameter | Type   | Description                                         |
|-----------|--------|-----------------------------------------------------|
| `id`      | string | Remote message ID returned by `POST /sms` at send time |

---

## Request

### Headers

```
Content-Type: application/json
```

### Body

```json
{
  "key-id": "your-api-key-id",
  "key-secret": "your-api-key-secret"
}
```

### Example

```http
GET /api/v5/sms/12345 HTTP/1.1
Host: www.5centsms.com.au
Content-Type: application/json

{
  "key-id": "abc123",
  "key-secret": "s3cr3t"
}
```

---

## Response

HTTP `200 OK` with a JSON body.

### Success

```json
{
  "message": {
    "id": "12345",
    "status": 1002,
    "status_text": "Delivered",
    "delivery_timestamp": 1719100800,
    "send_timestamp": 1719100750
  }
}
```

### Error

```json
{
  "error": "Message not found"
}
```

### Response Fields

| Field                | Type    | Description                                      |
|----------------------|---------|--------------------------------------------------|
| `message.id`         | string  | Remote message ID (may differ from path `{id}`) |
| `message.status`     | integer | Numeric status code (see table below)            |
| `message.status_text`| string  | Human-readable status label from the gateway     |
| `message.delivery_timestamp` | integer | Unix timestamp of confirmed delivery; `0` or absent when not yet delivered |
| `message.send_timestamp`     | integer | Unix timestamp when the message was sent to the carrier; `0` or absent when unavailable |

> **Trailing-space quirk:** The 5CentSMS v5 API occasionally returns JSON keys with trailing
> whitespace (e.g. `"messages "` instead of `"messages"`). The client trims all keys
> before parsing — see `FiveCentSmsV5Provider::trimArrayKeys()`.

---

## Status Codes

The `message.status` integer is mapped to an internal `SmsStatus` by `statusFromV5Code()`.

| `status` | `status_text`  | Internal status          | Final? | Description                                    |
|----------|----------------|--------------------------|--------|------------------------------------------------|
| `1000`   | —              | `QUEUED`                 | No     | Queued, not yet dispatched to carrier          |
| `1001`   | —              | `SENT`                   | No     | Accepted and sent to carrier                   |
| `1002`   | —              | `DELIVERED`              | Yes    | Delivery confirmed by carrier                  |
| `1003`   | —              | `FAILED`                 | Yes    | Delivery failed (invalid number, unreachable)  |
| `1004`   | —              | `DELIVERY_IN_PROGRESS`   | No     | Carrier confirms in-progress delivery          |
| `1004`   | `Test Message` | `TEST_MESSAGE`           | Yes    | Dry-run send (test mode); not a real delivery  |
| `1005`   | —              | `SCHEDULED`              | No     | Scheduled for future delivery                  |
| `1007`   | —              | `CANCELLED`              | Yes    | Cancelled before delivery                      |
| `1011`   | —              | `SENDING`                | No     | Actively being sent (in-flight)                |
| `1527`   | —              | `SCHEDULED`              | No     | Alternative scheduled code                     |
| other    | —              | `FAILED`                 | Yes    | Unrecognised code treated as failure           |

**Non-final statuses** (`QUEUED`, `SENT`, `DELIVERY_IN_PROGRESS`, `SCHEDULED`, `SENDING`)
warrant continued polling. **Final statuses** (`DELIVERED`, `FAILED`, `CANCELLED`,
`TEST_MESSAGE`) will not change — polling can stop.

---

## Shared Response Parser

`parseDeliveryInfo()` handles both this endpoint and `DELETE /sms/{id}`. It accepts the
top-level key `"message"` (GET) **or** `"messages"` (DELETE). This means DELETE responses
can be fed through the same parser to read back the post-cancellation status without
separate logic. Both keys hold an object (not an array).

---

## Error Response Structure

Unknown — not yet documented.

---

## Source References

| Symbol | File | Approximate line |
|---|---|---|
| `FiveCentSmsV5Provider::updateDelivery()` | `jethro-sms/src/Providers/FiveCentSmsV5Provider.php` | ~2160 |
| `FiveCentSmsV5Provider::parseDeliveryInfo()` | `jethro-sms/src/Providers/FiveCentSmsV5Provider.php` | ~2192 |
| `statusFromV5Code()` | `jethro-sms/src/Providers/FiveCentSmsV5Provider.php` | ~3402 |
| `FiveCentSmsV5Provider::trimArrayKeys()` | `jethro-sms/src/Providers/FiveCentSmsV5Provider.php` | ~2234 |

**Parsed by:** `FiveCentSmsV5Provider::parseDeliveryInfo()`

