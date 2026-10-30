# GET /api/v5/optouts

List numbers that have opted out, newest first.

**Status: VERIFIED** — response structure confirmed against real API (curl 2026-06-25).

---

## Request

```
GET /api/v5/optouts
Host: www.5centsms.com.au
Content-Type: application/json
```

### Body

```json
{
  "key-id": "<SMS_5CENTSMS_APIKEY_ID>",
  "key-secret": "<SMS_5CENTSMS_APIKEY>"
}
```

### Query Parameters

| Parameter | Type   | Description                                                    |
|-----------|--------|----------------------------------------------------------------|
| `after`   | string | 24-character hex cursor — the `id` of the last record on the previous page. Omit for the first page. |

A malformed `after` returns HTTP 400 — `"Failed (Invalid Page)"`.

---

## Response

### Success

```json
{
  "error": "",
  "numbers": [
    {
      "id": "62754c8d1f2970b23b07ca62",
      "number": "61491570156",
      "timestamp": 1651854477
    }
  ],
  "count": 1,
  "next_page": "api/v5/optouts?after=62754c8d1f2970b23b07ca62"
}
```

### Fields

| Field                | Type    | Description                                          |
|----------------------|---------|------------------------------------------------------|
| `error`              | string  | Empty on success                                     |
| `numbers`            | array   | Opted-out numbers this page                          |
| `numbers[].id`       | string  | 24-character hex opt-out record ID                   |
| `numbers[].number`   | string  | Opted-out phone number in international format (no `+`) |
| `numbers[].timestamp`| integer | Unix epoch seconds when the opt-out was recorded     |
| `count`              | integer | Number of records on this page                       |
| `next_page`          | string  | Relative path for the next page, or `"api/v5/optouts?after="` when terminal |

### Terminal page (no more results)

When the cursor has exhausted all records:

```json
{
  "error": "",
  "numbers": [],
  "count": 0,
  "next_page": "api/v5/optouts?after="
}
```

`count: 0` and empty `numbers` indicate the end. The `next_page` value still contains a path but with an empty `after` parameter.

### Error (auth failure)

HTTP 401:

```json
{ "error": "Failed (Invalid Key ID)" }
```

---

## Pagination

Cursor-based, 1000 records per page. On each response, follow `next_page` to fetch the next page. Stop when `count` is `0` or `numbers` is empty.

In practice, the provider **fetches all pages internally** and returns a flat list — the caller never deals with pagination.

---

## Notes

- Phone numbers are in international format **without** a leading `+` (e.g. `"61491570156"`).
- The `timestamp` is a Unix epoch in seconds (not milliseconds).
- The `id` field serves as the cursor for pagination — pass it as the `after` query parameter.

---

## Source References

| Symbol | File | Approx. line |
|---|---|---|
| `listOptOuts()` | `jethro-sms/src/Providers/FiveCentSmsV5Provider.php` (FiveCentSmsV5Provider) | ~2600 |

### Mock fixture

`tests/sms-mock-overrides-5centsms.json`, key `"GET /optouts"`:

```json
"GET /optouts": {
  "error": "",
  "numbers": [
    {
      "id": "62754c8d1f2970b23b07ca62",
      "number": "61491570156",
      "timestamp": 1651854477
    }
  ],
  "count": 1,
  "next_page": "api/v5/optouts?after="
}
```

---

## Error Response Structure

Unknown — not yet documented.
