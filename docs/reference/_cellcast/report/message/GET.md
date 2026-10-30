# GET /api/v2/report/message

Fetches a paginated list of messages, optionally filtered by date range and status.

> **VERIFIED** — tested against the real API 2026-06-28.

---

## Request

```
GET /api/v2/report/message?campType=sms&fromDate=2026-06-21&toDate=2026-06-28&page=1&limit=10&groupBy=&status=scheduled
Authorization: Bearer <api_token>
```

| Parameter   | In    | Type   | Required | Description |
|-------------|-------|--------|----------|-------------|
| `campType`  | query | string | Yes      | Must be `"sms"`. |
| `fromDate`  | query | string | No       | Start date in `YYYY-MM-DD` format (inclusive). Omit for all time. |
| `toDate`    | query | string | No       | End date in `YYYY-MM-DD` format (inclusive). Omit for all time. |
| `page`      | query | int    | No       | Page number (1-based, default 1). |
| `limit`     | query | int    | No       | Items per page (default varies). |
| `groupBy`   | query | string | No       | Grouping key (empty = no grouping). |
| `status`    | query | string | No       | Filter by delivery status (e.g. `"scheduled"`, `"sent"`). Omit for all. |

No request body.

## Response

```json
{
  "status": true,
  "message": "Success",
  "data": {
    "items": [
      {
        "_id": "6a3ff2f396d2db7da9d0daa6",
        "status": "scheduled",
        "message": "test message text",
        "sender": "61491570158",
        "receiver": "491570156",
        "scheduleAt": "2026-06-27T16:30:00.094Z",
        "createdAt": "2026-06-27T15:57:39.853Z",
        "request_from": "APP",
        "request_name": null,
        "creditAmount": 1,
        "gateway_count": 1,
        "smsDetail": {
          "_id": "6a3ff2f396d2db7da9d0dad4",
          "messagelength": 31,
          "smscount": 1,
          "costpersms": 0.047,
          "deductibleCredit": 0.047
        },
        "replyStop": false,
        "replyMessage": null
      }
    ],
    "total": 36,
    "limit": 10,
    "current": 1,
    "totalPages": 4,
    "hasPrevPage": false,
    "hasNextPage": true,
    "prevPage": null,
    "nextPage": 2
  }
}
```

### Top-level fields

| Field    | Type    | Description |
|----------|---------|-------------|
| `status` | boolean | `true` on success. |
| `data`   | object  | Paginated result (see below). |

### `data` object

| Field         | Type    | Description |
|---------------|---------|-------------|
| `items`       | array   | Array of message objects (see below). |
| `total`       | int     | Total matching messages across all pages. |
| `limit`       | int     | Page size. |
| `current`     | int     | Current page number (1-based). |
| `totalPages`  | int     | Total pages. |
| `hasNextPage` | boolean | Whether more pages exist after this one. |
| `hasPrevPage` | boolean | Whether a previous page exists. |
| `nextPage`    | int?    | Next page number, or `null`. |
| `prevPage`    | int?    | Previous page number, or `null`. |

### `items[]` object

| Field          | Type      | Description |
|----------------|-----------|-------------|
| `_id`          | string    | Cellcast internal message ID. |
| `status`       | string    | Delivery status — see [Status values](#status-values) below. |
| `message`      | string    | Message body text. |
| `sender`       | string    | Sender phone number or ID. |
| `receiver`     | string    | Recipient phone number. |
| `scheduleAt`   | string?   | ISO 8601 timestamp for scheduled sends, `null` for immediate. |
| `createdAt`    | string    | ISO 8601 creation timestamp. |
| `request_from` | string    | `"APP"` (web UI) or `"API"`. |
| `request_name` | string?   | Sender name if `request_from` is `"API"`. |
| `smsDetail`    | object    | Segment/cost breakdown. |
| `replyStop`    | boolean   | Whether STOP reply handling is enabled. |
| `replyMessage` | string?   | Auto-reply message if configured. |

### `smsDetail` object

| Field              | Type   | Description |
|--------------------|--------|-------------|
| `_id`              | string | Detail record ID. |
| `messagelength`    | int    | Character count. |
| `smscount`         | int    | Number of segments. |
| `costpersms`       | float  | Cost per segment in dollars. |
| `deductibleCredit` | float  | Credits deducted. |

---

## Status values

The `status` field on each item uses the same values as the single-message lookup. See [`GET /api/v2/report/message/{messageId}`](\{id\}/GET.md#status-values) for the mapping.

---

## Error Response Structure

Unknown — not yet documented.

---
