# GET /api/v1/apiClient/account

Retrieve the SMS credit balance for the authenticated API client.

**Status: VERIFIED** — response structure confirmed against mock fixture (`tests/sms-mock-overrides-cellcast.json`).

---

## Request

```
GET /api/v1/apiClient/account
Host: api.cellcast.com
Authorization: Bearer <SMS_CELLCAST_APIKEY>
```

### Headers

| Header          | Value                       | Required |
|-----------------|-----------------------------|----------|
| `Authorization` | `Bearer <SMS_CELLCAST_APIKEY>` | Yes      |

No request body.

### Base URL

The base URL defaults to `https://api.cellcast.com` and can be overridden with the `SMS_CELLCAST_URL` environment constant.

---

## Response

### Envelope

This endpoint uses a **`meta` / `data` envelope**, which differs from most other Cellcast endpoints (which use a flat `status` / `data` structure).

```json
{
  "meta": {
    "code": 200,
    "status": "success"
  },
  "data": {
    "sms_balance": 12345
  }
}
```

### Fields

#### `meta` object

| Field    | Type    | Description                          |
|----------|---------|--------------------------------------|
| `code`   | integer | HTTP-style status code (e.g. `200`)  |
| `status` | string  | Human-readable status (e.g. `"success"`) |

#### `data` object

| Field         | Type             | Description                                              |
|---------------|------------------|----------------------------------------------------------|
| `sms_balance` | integer or float | Remaining SMS credits. Cast to `int` after reading (via `(int)(float)` to handle decimal API values). |

---

## Example

### Request

```http
GET /api/v1/apiClient/account HTTP/1.1
Host: api.cellcast.com
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
```

### Response

```json
{
  "meta": {
    "code": 200,
    "status": "success"
  },
  "data": {
    "sms_balance": 12345
  }
}
```

The provider extracts `data.sms_balance` and returns it as an integer. For `12345`, the caller receives `12345`.

---

## Error Cases

If the response is missing the `data` key the provider returns:

```
No data field in balance response. Raw: <raw JSON>
```

If `data` is present but `sms_balance` is absent:

```
No sms_balance field in account response. Raw: <raw JSON>
```

Both conditions surface as a `Result::failure(string)` to the caller.

---

## Caching

The balance is cached for **300 seconds** (5 minutes) under the key `sms_balance`. Subsequent calls within the cache window return the cached integer without hitting the API.

---

## Source References

| Symbol | File | Approx. line |
|---|---|---|
| `getBalance()` | `jethro-sms/src/Providers/CellcastSmsProvider.php` | ~375 |
| `parseBalanceResponse()` | `jethro-sms/src/Providers/CellcastSmsProvider.php` | ~406 |

### Mock fixture
f
`tests/sms-mock-overrides-cellcast.json`, key `"GET /account"`:

```json
{
  "meta": {"code": 200, "status": "success"},
  "data": {"sms_balance": 12345}
}
```

## Error Response Structure

Unknown — not yet documented.

---

**Parsed by:** `CellcastSmsProvider::parseBalanceResponse()`

