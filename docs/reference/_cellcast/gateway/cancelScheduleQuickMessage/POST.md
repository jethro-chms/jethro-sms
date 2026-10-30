# POST /api/v1/gateway/cancelScheduleQuickMessage

> **VERIFIED** — response recorded from the live Cellcast API.

Cancel a previously scheduled SMS message. Only messages in `scheduled` status can be cancelled; messages already dispatched to the carrier cannot be recalled.

## Request

```
POST https://api.cellcast.com/api/v1/gateway/cancelScheduleQuickMessage
Authorization: Bearer <SMS_CELLCAST_APIKEY>
Content-Type: application/json
```

### Body

| Field       | Type   | Required | Description                                                       |
|-------------|--------|----------|-------------------------------------------------------------------|
| `messageId` | string | yes      | The remote message ID returned by the send endpoint (`MessageId`) |
| `type`      | string | yes      | Always `"sms"`                                                    |

```json
{
  "messageId": "abc123",
  "type": "sms"
}
```

## Response

Standard Cellcast v1 envelope: `{ status, message, data, error }`.

| Field            | Type    | Description                                       |
|------------------|---------|---------------------------------------------------|
| `status`         | boolean | `true` on success, `false` on failure             |
| `message`        | string  | Human-readable outcome                            |
| `data.message`   | string  | Echoes the outcome message                        |

### Success (HTTP 200)

```json
{
  "status": true,
  "message": "Job removed successfully",
  "data": {
    "message": "Job removed successfully"
  }
}
```

> Real API response also includes top-level fields such as `app_type`, `error: {}`, which are ignored by the provider.

### Failure

When the message cannot be cancelled (e.g. already sent, unknown ID), the API returns HTTP 200 with `status: false`. Example, for an unknown `messageId`:

```json
{
  "status": false,
  "message": "message not found",
  "data": {},
  "error": { "error": "message not found" }
}
```

## Provider behaviour

`cancelOneDelivery()` is called per-message from `cancel()`. On success it returns the delivery with `status = SmsStatus::CANCELLED`. On failure — a transport error **or** an HTTP-200 envelope with `status: false` — it returns the delivery unchanged except for `statusDetail()`, which carries the upstream (or transport) message so callers can show the real reason. The batch-level `cancel()` always returns `Result::success`; per-delivery outcomes are read from each returned delivery.

If the delivery has no `remoteId` (i.e. it was never acknowledged by the gateway), the cancel call is skipped and `statusDetail()` says so.

## Source

| Symbol              | File                       | Lines     |
|---------------------|----------------------------|-----------|
| `cancel()`          | `jethro-sms/src/Providers/CellcastSmsProvider.php` | 527–534   |
| `cancelOneDelivery()` | `jethro-sms/src/Providers/CellcastSmsProvider.php` | 541–563 |

## Mock

`tests/sms-mock-overrides-cellcast.json` — key `"POST /cancelScheduleQuickMessage"` (active in test mode):

```json
{
  "status": true,
  "message": "SMS cancelled (test mode)"
}
```

Note: the mock omits `data.message`; the provider does not inspect response fields beyond the top-level `status`, so the abbreviated mock is functionally equivalent.

## Error Response Structure

Unknown — not yet documented.
