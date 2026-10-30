# GET /api/v1/customNumber

**Status: VERIFIED 2026-10-09** against two live Cellcast accounts

Lists the account's **sender phone numbers** (custom numbers). Read by
`CellcastSmsProvider::getSenderNumbers()`.

It does **not** list alphanumeric Sender IDs (business names / Alpha IDs): an
account with an approved, active business name returned only its phone number
here. Those come from [`GET /api/v1/business`](../business/GET.md), read by
`getSenderIds()`. (Earlier versions of this doc and of `getSenderIds()` assumed
alphanumeric entries appeared here; they don't.)

---

## Request

```
GET /api/v1/customNumber
Authorization: Bearer <token>
```

No request body. No query parameters.

---

## Response

```json
{
  "status": true,
  "data": [
    {
      "_id": "…",
      "number": "614xxxxxxxx",
      "name": "Church Main",
      "status": "active",
      "isDefault": false,
      "deleted": false,
      "allocatedUsers": [],
      "user": "…",
      "approvedAt": "…",
      "createdBy": "…",
      "createdAt": "…",
      "updatedAt": "…",
      "__v": 0
    }
  ]
}
```

### Fields Jethro reads

| Field | Type | Description |
|---|---|---|
| `data` | array | Sender numbers. `[]` if none. |
| `data[].number` | string | The phone number (digits, optionally `+`). Entries that aren't all digits are skipped. |

### Error response (auth failure / server error)

Cellcast returns a top-level `code`/`message` object **without** a `data` key:

```json
{ "code": 401, "message": "Token expired", "stack": "APIError: Token expired ...." }
```

`fetchCachedList()` treats `isset($data['code']) && !isset($data['data'])` as a
failure rather than "no numbers".

---

## Caching

Session-cached for 30 minutes under `sms_sendernumbers`.

## Mock server

`smsmockserver` (`Provider/Cellcast.php`, `handleGetCustomNumber()`): a
profile's `setSenders()` list, else two default numbers plus approved number
registrations.
