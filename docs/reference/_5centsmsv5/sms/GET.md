# GET /sms — List Sent Messages

Retrieve a paginated list of SMS messages sent from the account.

- **Method:** `GET`
- **URL:** `https://www.5centsms.com.au/api/v5/sms`
- **Content-Type:** `application/json`

> **Non-standard:** This GET request carries a JSON body for authentication. Most HTTP clients
> and proxies allow bodies on GET requests; the 5CentSMS v5 API requires it.

> **VERIFIED** — request/response shapes confirmed against the 5CentSMS v5 Postman collection
> (`5centsms_api.json`, item: "List Sent SMS Messages").

---

## Authentication

Credentials are sent in the JSON request body, not in HTTP headers.

| Field        | Type   | Required | Description                        |
|--------------|--------|----------|------------------------------------|
| `key-id`     | string | Yes      | API key ID (from dashboard)        |
| `key-secret` | string | Yes      | API key secret (from dashboard)    |

---

## Query Parameters

The API uses cursor-based pagination. The `next_page` field in each response provides the
ready-made URL (including its `after=` cursor) to fetch the next page.

| Parameter | Type   | Description                                                                 |
|-----------|--------|-----------------------------------------------------------------------------|
| `after`   | string | Cursor for the next page — copy the value directly from `next_page` in the previous response. Omit on the first request. |

Additional filter parameters (`to`, `from`, `page`, `limit`, `type`) may be accepted by the
API but are not reflected in the Postman collection. Consult the live API or 5CentSMS support
before relying on them in production.

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

### Example — first page

```http
GET /api/v5/sms HTTP/1.1
Host: www.5centsms.com.au
Content-Type: application/json

{
  "key-id": "abc123",
  "key-secret": "s3cr3t"
}
```

### Example — subsequent page (following a `next_page` cursor)

```http
GET /api/v5/sms?after=682dfd80e6b94fbde5054b43 HTTP/1.1
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
  "error": "",
  "messages": [
    {
      "destination": "0412333555",
      "id": "683554c596937cc4b90f5cf7",
      "status": 1011,
      "status_text": "Sending...",
      "message_text": "test message",
      "credits": 1,
      "send_timestamp": 1748340857,
      "delivery_timestamp": 1756743007,
      "delivery_carrier": "50502"
    }
  ],
  "next_page": "/api/v5/sms?after=682dfd80e6b94fbde5054b43",
  "count": 11
}
```

### Error

```json
{
  "error": "Invalid credentials"
}
```

A non-empty `error` string indicates failure. The `messages` array is absent or empty.

---

## Response Fields

### Top-level

| Field       | Type    | Description                                                                  |
|-------------|---------|------------------------------------------------------------------------------|
| `error`     | string  | Empty string on success; error message on failure.                           |
| `messages`  | array   | Array of message objects (see below). Empty array when no results.           |
| `count`     | integer | Number of messages returned in this page.                                    |
| `next_page` | string  | Relative URL path for the next page (e.g. `/api/v5/sms?after=<cursor>`). Absent when there are no further pages. |

### Each `messages` entry

| Field                | Type    | Description                                                                               |
|----------------------|---------|-------------------------------------------------------------------------------------------|
| `id`                 | string  | Unique provider-assigned message ID. Use with [GET /sms/\{id\}](\{id\}/GET.md) and [DELETE /sms/\{id\}](DELETE.md). |
| `destination`        | string  | Recipient phone number as submitted.                                                      |
| `status`             | integer | Numeric delivery status code — see [Status Codes](#status-codes) below.                  |
| `status_text`        | string  | Human-readable status label returned by the API.                                          |
| `message_text`       | string  | Original message body. May be empty if not retained.                                      |
| `credits`            | integer | SMS credits consumed for this message.                                                    |
| `send_timestamp`     | integer | Unix timestamp when the message was submitted to the carrier.                             |
| `delivery_timestamp` | integer | Unix timestamp of confirmed delivery. `0` or absent when not yet delivered.               |
| `delivery_carrier`   | string  | Network carrier code that confirmed delivery (e.g. `"50502"`). Also documented as `delivery_network` in the API spec — treat both names as equivalent. |

> **Trailing-space quirk:** The 5CentSMS v5 API occasionally returns JSON keys with trailing
> whitespace (e.g. `"messages "` instead of `"messages"`). The client trims all keys before
> parsing — see `FiveCentSmsV5Provider::trimArrayKeys()`.

---

## Pagination

Results are delivered in pages. The flow is:

1. Send the first request with no `after` param.
2. If `next_page` is present in the response, append its query string to the base URL and
   repeat with the same JSON body.
3. Stop when `next_page` is absent — you have reached the last page.

The `count` field reflects the current page only, not the total across all pages.

> **Retention:** Message data is retained for 365 days from the processing date, after which
> it is permanently purged. Contact 5CentSMS support to discuss alternative retention periods.

---

## Status Codes

The `status` integer in each `messages` entry is mapped to an internal status by
`statusFromV5Code()` (`jethro-sms/src/Providers/FiveCentSmsV5Provider.php:3402`).

| `status` | `status_text`    | Internal status          | Final? | Description                                   |
|----------|------------------|--------------------------|--------|-----------------------------------------------|
| `1000`   | —                | `QUEUED`                 | No     | Queued, not yet dispatched to carrier         |
| `1001`   | —                | `SENT`                   | No     | Accepted and sent to carrier                  |
| `1002`   | —                | `DELIVERED`              | Yes    | Delivery confirmed by carrier                 |
| `1003`   | —                | `FAILED`                 | Yes    | Delivery failed (invalid number, unreachable) |
| `1004`   | —                | `DELIVERY_IN_PROGRESS`   | No     | Carrier confirms in-progress delivery         |
| `1004`   | `Test Message`   | `TEST_MESSAGE`           | Yes    | Dry-run send; not a real delivery             |
| `1005`   | —                | `SCHEDULED`              | No     | Scheduled for future delivery                 |
| `1007`   | —                | `CANCELLED`              | Yes    | Cancelled before delivery                     |
| `1011`   | `Sending...`     | `SENDING`                | No     | Actively being sent (in-flight)               |
| `1527`   | —                | `SCHEDULED`              | No     | Alternative scheduled code                    |
| other    | —                | `FAILED`                 | Yes    | Unrecognised code treated as failure          |

**Non-final statuses** (`QUEUED`, `SENT`, `DELIVERY_IN_PROGRESS`, `SCHEDULED`, `SENDING`)
may change on subsequent fetches. **Final statuses** (`DELIVERED`, `FAILED`, `CANCELLED`,
`TEST_MESSAGE`) will not change.

---

## Related Endpoints

| Endpoint | File | Description |
|---|---|---|
| `GET /sms/{id}` | [`{id}/GET.md`](\{id\}/GET.md) | Fetch delivery status for a single message by ID |
| `POST /sms` | [`POST.md`](POST.md) | Send one or more messages |
| `DELETE /sms/{id}` | [`DELETE.md`](DELETE.md) | Cancel a scheduled message |
| `GET /balance` | [`../balance/GET.md`](../balance/GET.md) | Check remaining credit balance |

---

## Error Response Structure

Unknown — not yet documented.

## Source References

| Symbol | File | Approximate line |
|---|---|---|
| `FiveCentSmsV5Provider::trimArrayKeys()` | `jethro-sms/src/Providers/FiveCentSmsV5Provider.php` | ~2234 |
| `statusFromV5Code()` | `jethro-sms/src/Providers/FiveCentSmsV5Provider.php` | ~3402 |
