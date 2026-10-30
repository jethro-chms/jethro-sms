# GET /api/v2/report/message/\{messageId\}

Fetches the current delivery status of a single previously-sent message.

> **VERIFIED** — tested against the real API 2026-06-28.

---

## Request

```
GET /api/v2/report/message/{messageId}
Authorization: Bearer <api_token>
```

| Parameter   | In   | Type   | Required | Description                                           |
|-------------|------|--------|----------|-------------------------------------------------------|
| messageId   | path | string | Yes      | The `MessageId` returned in the send (`/gateway`) response. |

No request body.

---

## Response

```json
{
  "data": {
    "_id": "msg_test_001",
    "status": "delivered",
    "updatedAt": "2026-06-21T12:00:00Z",
    "send_time": "2026-06-21T11:59:00Z"
  }
}
```

### Top-level fields

| Field  | Type   | Description                        |
|--------|--------|------------------------------------|
| `data` | object | Delivery record for the message.   |

### `data` object

| Field       | Type   | Description                                                           |
|-------------|--------|-----------------------------------------------------------------------|
| `_id`       | string | Cellcast internal message ID (echoed back as `remoteId`).            |
| `status`    | string | Delivery status string. See [Status values](#status-values) below.   |
| `updatedAt` | string | ISO 8601 timestamp of when the record last changed. Used as the delivery confirmation timestamp **only** when `status` is `delivered`. |
| `send_time` | string | ISO 8601 timestamp of when the message was submitted to the carrier. Mapped to `SmsDelivery::sendTimestamp`. |

---

## Error Response Structure

Cellcast returns a top-level `status: false` envelope for failed lookups
(invalid message IDs, auth errors, etc.).  The `data` value is typically
an empty object `{}` and the `error` key carries structured details.

```json
{
  "status": false,
  "message": "<human-readable summary>",
  "data": {},
  "error": {
    "name": "<error class>",
    "message": "<detailed error message>",
    "value": "<offending value>",
    "path": "<field path>"
  }
}
```

### Key fields

| Field             | Type           | Description |
|-------------------|----------------|-------------|
| `status`          | `false`        | Explicitly `false` — distinguishes errors from successful responses (where `status` is absent or `true`). |
| `message`         | string         | Human-readable summary (e.g. `"internal server error"`). |
| `data`            | object         | Typically `{}` — the intended payload is absent. |
| `error`           | object?        | Structured error details. May be absent for some error types. |
| `error.message`   | string?        | Detailed error description (e.g. `"Cast to ObjectId failed for value …"`). |
| `error.name`      | string?        | Error class name (e.g. `"CastError"`). |

Real example (invalid message ID):

```json
{
  "status": false,
  "message": "internal server error",
  "data": {},
  "error": {
    "stringValue": "\"fake_863ac00c9b585327\"",
    "valueType": "string",
    "kind": "ObjectId",
    "value": "fake_863ac00c9b585327",
    "path": "_id",
    "reason": {},
    "name": "CastError",
    "message": "Cast to ObjectId failed for value \"fake_863ac00c9b585327\" (type string) at path \"_id\" for model \"gatewaymessage\""
  }
}
```

`parseDeliveryResponse()` checks `status === false` first and returns
`Result::failure` with the error message, preventing the response from
being misinterpreted as a valid delivery update.
---

## Status values

The `status` string from the API is mapped to the internal `SmsStatus` enum in
`parseDeliveryResponse()` (`jethro-sms/src/Providers/CellcastSmsProvider.php` line 507–515).

| Cellcast `status`              | `SmsStatus` enum     | Notes                                      |
|--------------------------------|----------------------|--------------------------------------------|
| `queued`                       | `QUEUED`             | Accepted, waiting for carrier handoff.     |
| `scheduled`                    | `SCHEDULED`          | Deferred send — not yet dispatched.        |
| `sent`                         | `SENT`               | Handed off to the carrier.                 |
| `delivered`                    | `DELIVERED`          | Handset confirmed receipt.                 |
| `failed`                       | `FAILED`             | Permanent delivery failure.                |
| `blocked`                      | `FAILED`             | Carrier or compliance block.               |
| `rejected`                     | `FAILED`             | Rejected by upstream gateway.              |
| `expired`                      | `EXPIRED`            | TTL elapsed before delivery.               |
| `canceled`                     | `CANCELLED`          | Cancelled before dispatch (note: one `l`). |
| _(any other value)_            | `UNKNOWN`            | Unrecognised status; treated as non-final. |

### Timestamp rules

- **`sendTimestamp`** — populated from `send_time` for all terminal statuses.
- **`deliveryTimestamp`** — populated from `updatedAt` **only** when `status == "delivered"`.
  For all other statuses `updatedAt` represents the last record-change time and is not
  surfaced as a delivery confirmation.

---

## Source references

| Symbol                   | File                         | Lines       |
|--------------------------|------------------------------|-------------|
| `updateDelivery()`       | `jethro-sms/src/Providers/CellcastSmsProvider.php`   | 465–479     |
| `parseDeliveryResponse()`| `jethro-sms/src/Providers/CellcastSmsProvider.php`   | 488–540     |

The URL is constructed as:

```php
// CellcastSmsProvider::parseDeliveryResponse()
$result = $this->request('GET', '/api/v2/report/message/' . urlencode($remoteId));
```

Note the version prefix: this is **`/api/v2/`**, not `v1` like the other Cellcast endpoints.

---

## Mock fixture

File: `tests/sms-mock-overrides-cellcast.json`, key `"GET /report/message"`.

```json
"GET /report/message": {
    "data": {
        "status": "delivered",
        "_id": "msg_test_001",
        "updatedAt": "2026-06-21T12:00:00Z",
        "send_time": "2026-06-21T11:59:00Z"
    }
}
```

The mock always returns `delivered` regardless of the `{messageId}` path segment.

**Parsed by:** `CellcastSmsProvider::parseDeliveryResponse()`
