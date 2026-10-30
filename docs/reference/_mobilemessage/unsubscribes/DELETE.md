# DELETE /v1/unsubscribes — Remove an opt-out

Read by `MobileMessageSmsProvider::removeOptOut()`.

## Endpoint

```
DELETE https://api.mobilemessage.com.au/v1/unsubscribes
```

## Authentication

HTTP **Basic** auth.

## Request Body (recorded live 2026-10)

Query parameters are **ignored** on DELETE — the number must be in a JSON
body, international form:

```json
{"number": "61400000001"}
```

## Response (recorded live 2026-10)

`removed` is false when the number wasn't on the list (not an error):

```json
{
    "status": "complete",
    "number": "61400000001",
    "removed": false
}
```

## Source Reference

| Symbol | File | Approx. line |
|--------|------|--------------|
| `MobileMessageSmsProvider::removeOptOut()` | `jethro-sms/src/Providers/MobileMessageSmsProvider.php` | ~759 |
| Mock handler | `smsmockserver/src/Provider/MobileMessage.php` | `handleRemoveUnsubscribe()` |
| Recording (not listed) | `smsmockserver/recordings/mobilemessage/actual/unsubscribes.remove-unlisted.json` — sha1 `17ae76b67a41d8237ca0fe79b63ecce5f09165bd` | |
| Recording (query ignored) | `smsmockserver/recordings/mobilemessage/actual/unsubscribes.remove-by-query.json` — sha1 `1d12d10753975861d11ccafaeddf69ecd6d9ea95` | |
