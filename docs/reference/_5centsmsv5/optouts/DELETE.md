# DELETE /api/v5/optouts/:number

Remove a number from your opt-out list.

**Status: DOCUMENTED** — verified against published API documentation. Not curl-tested (DELETE is not a safe GET operation).

---

## Request

```
DELETE /api/v5/optouts/61491570156
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

### Path Variables

| Variable | Type   | Description                            |
|----------|--------|----------------------------------------|
| `number` | string | The opted-out phone number to remove (international format, no `+`) |

---

## Response

### Success

```json
{
  "error": "",
  "message": "Success"
}
```

### Fields

| Field     | Type   | Description           |
|-----------|--------|-----------------------|
| `error`   | string | Empty on success      |
| `message` | string | Confirmation message  |

### Error (not found / already removed)

HTTP 400:

```json
{
  "error": "Record Not Found",
  "message": ""
}
```

### Error (auth failure)

HTTP 401:

```json
{
  "error": "Failed (Invalid Key ID)"
}
```

---

## Notes

- The number in the path **must not** include a `+` prefix — e.g. `61491570156`, not `+61491570156`.
- Removing a number that is not in the opt-out list returns a 400 `"Record Not Found"` error.

---

## Error Response Structure

Unknown — not yet documented.

---

## Source References

| Symbol | File | Approx. line |
|---|---|---|
| `removeOptOut()` | `jethro-sms/src/Providers/FiveCentSmsV5Provider.php` (FiveCentSmsV5Provider) | ~2600 |

### Mock fixture

`tests/sms-mock-overrides-5centsms.json`, key `"DELETE /optouts/:number"`:

```json
"DELETE /optouts/61491570156": {
  "error": "",
  "message": "Success"
}
```
