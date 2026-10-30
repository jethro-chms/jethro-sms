# GET /api/v1/apiClient/getOptout

Retrieve the list of opted-out/unsubscribed numbers for the authenticated account.

**Status: VERIFIED** — response structure confirmed against real API (curl 2026-06-25).

---

## Request

```
GET /api/v1/apiClient/getOptout?page=1&size=100
Host: api.cellcast.com
Authorization: Bearer <SMS_CELLCAST_APIKEY>
Content-Type: application/json
```

### Headers

| Header          | Value                        | Required |
|-----------------|------------------------------|----------|
| `Authorization` | `Bearer <SMS_CELLCAST_APIKEY>` | Yes      |
| `Content-Type`  | `application/json`           | Yes      |

### Query Parameters

| Name        | Type    | Default | Description                                   |
|-------------|---------|---------|-----------------------------------------------|
| `page`      | integer | 1       | Page number, starting from 1                  |
| `size`      | integer | 100     | Items per page                                |
| `startDate` | string  | —       | ISO 8601 timestamp — filter opt-outs on/after  |
| `endDate`   | string  | —       | ISO 8601 timestamp — filter opt-outs on/before |

All query parameters are optional. The date range filters use UTC ISO 8601 format (e.g. `2025-09-01T14:38:42.435Z`).

### Base URL

The base URL defaults to `https://api.cellcast.com` and can be overridden with the `SMS_CELLCAST_URL` constant.

---

## Response

### Envelope

```json
{
  "meta": {
    "code": 200,
    "status": "SUCCESS"
  },
  "message": "You have 1000 optout contact(s)",
  "data": {
    "items": [ … ],
    "total": 1000,
    "limit": 100,
    "current": 1,
    "totalPages": 10,
    "pagingCounter": 1,
    "hasPrevPage": false,
    "hasNextPage": true,
    "prevPage": null,
    "nextPage": 2
  },
  "error": {}
}
```

### Fields

#### `meta` object

| Field    | Type    | Description                          |
|----------|---------|--------------------------------------|
| `code`   | integer | HTTP-style status code (e.g. `200`)  |
| `status` | string  | Human-readable status (e.g. `"SUCCESS"`) |

#### `message`

| Field     | Type   | Description                          |
|-----------|--------|--------------------------------------|
| `message` | string | Summary string, e.g. `"You have 0 optout contact(s)"` |

#### `data` object

| Field           | Type             | Description                                |
|-----------------|------------------|--------------------------------------------|
| `items`         | array            | List of opt-out records this page          |
| `total`         | integer          | Total opt-out records across all pages     |
| `limit`         | integer          | Items per page (as requested via `size`)    |
| `current`       | integer          | Current page number                        |
| `totalPages`    | integer          | Total number of pages                      |
| `pagingCounter` | integer          | First item index on this page (1-based)     |
| `hasPrevPage`   | boolean          | Whether there is a previous page           |
| `hasNextPage`   | boolean          | Whether there is a next page               |
| `prevPage`      | integer or null  | Previous page number, or null              |
| `nextPage`      | integer or null  | Next page number, or null                  |

#### `data.items[]` fields

| Field          | Type   | Description                                     |
|----------------|--------|--------------------------------------------------|
| `number`       | string | Phone number in international format (no `+`)    |
| `first_name`   | string | Subscriber first name (empty string if unknown)  |
| `last_name`    | string | Subscriber last name (empty string if unknown)   |
| `full_name`    | string | Subscriber full name (may be empty)              |
| `email`        | string | Subscriber email (empty string if unknown)       |
| `birthday`     | string | Birthday (empty string if unknown)               |
| `address`      | string | Postal address (empty string if unknown)         |
| `postalcode`   | string | Postal code (empty string if unknown)            |
| `gender`       | string | Gender (empty string if unknown)                 |
| `post_code`    | string | Post code (empty string if unknown)              |
| `date_of_birth`| string | Date of birth (empty string if unknown)          |

The only field guaranteed non-empty is `number`. All contact detail fields may be empty strings (they come from Cellcast's contacts database). The `number` field is in international format **without** a leading `+` (e.g. `"61400000000"`).

### Empty response

When there are no opt-outs:

```json
{
  "meta": { "code": 200, "status": "SUCCESS" },
  "message": "You have 0 optout contact(s)",
  "data": {
    "items": [],
    "total": 0,
    "limit": 5,
    "current": 1,
    "totalPages": 1,
    "pagingCounter": 1,
    "hasPrevPage": false,
    "hasNextPage": false,
    "prevPage": null,
    "nextPage": null
  },
  "error": {}
}
```

### Error response (auth failure)

```json
{ "code": 401, "message": "Token expired" }
```

---

## Pagination

Page-based, starting at 1. Follow `data.nextPage` until it is null. An empty `items` array with `total: 0` is the terminal state.

In practice, the provider **fetches all pages internally** and returns a flat list — the caller never deals with pagination.

---

## Example

### Request

```http
GET /api/v1/apiClient/getOptout?page=1&size=5 HTTP/1.1
Host: api.cellcast.com
Authorization: Bearer eyJhbGciOiJI...
Content-Type: application/json
```

### Response

```json
{
  "meta": { "code": 200, "status": "SUCCESS" },
  "message": "You have 2 optout contact(s)",
  "data": {
    "items": [
      {
        "number": "61400000000",
        "first_name": "Olivia",
        "last_name": "Thompson",
        "full_name": "Olivia Thompson",
        "email": "olivia@example.com",
        "birthday": "",
        "address": "",
        "postalcode": "",
        "gender": "",
        "post_code": "",
        "date_of_birth": ""
      },
      {
        "number": "61400000001",
        "first_name": "Ethan",
        "last_name": "Carter",
        "full_name": "Ethan Carter",
        "email": "",
        "birthday": "",
        "address": "",
        "postalcode": "",
        "gender": "",
        "post_code": "",
        "date_of_birth": ""
      }
    ],
    "total": 2,
    "limit": 5,
    "current": 1,
    "totalPages": 1,
    "pagingCounter": 1,
    "hasPrevPage": false,
    "hasNextPage": false,
    "prevPage": null,
    "nextPage": null
  },
  "error": {}
}
```

---

## Error Response Structure

Unknown — not yet documented.

---

## Source References

| Symbol | File | Approx. line |
|---|---|---|
| `listOptOuts()` | `jethro-sms/src/Providers/CellcastSmsProvider.php` | ~930 |

### Mock fixture

`tests/sms-mock-overrides-cellcast.json`, key `"GET /getOptout"`:

```json
"GET /getOptout?page=1&size=1000": {
  "meta": {"code": 200, "status": "SUCCESS"},
  "message": "You have 0 optout contact(s)",
  "data": {
    "items": [],
    "total": 0,
    "limit": 1000,
    "current": 1,
    "totalPages": 1,
    "pagingCounter": 1,
    "hasPrevPage": false,
    "hasNextPage": false,
    "prevPage": null,
    "nextPage": null
  },
  "error": {}
}
```
