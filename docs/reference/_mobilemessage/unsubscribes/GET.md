# GET /v1/unsubscribes — List opted-out numbers

Read by `MobileMessageSmsProvider::listOptOuts()`.

## Endpoint

```
GET https://api.mobilemessage.com.au/v1/unsubscribes?limit=200&offset=0
```

## Authentication

HTTP **Basic** auth.

## Response (recorded live 2026-10)

The recorded account had no opt-outs, so only the envelope is recorded:

```json
{
    "status": "complete",
    "total": 0,
    "limit": 200,
    "offset": 0,
    "results": []
}
```

The provider reads `number` and `updated_at` from each item (no contact
name is exposed by the API) and follows `total` across pages.

## Source Reference

| Symbol | File | Approx. line |
|--------|------|--------------|
| `MobileMessageSmsProvider::listOptOuts()` | `jethro-sms/src/Providers/MobileMessageSmsProvider.php` | ~700 |
| Mock handler | `smsmockserver/src/Provider/MobileMessage.php` | `handleUnsubscribes()` |
| Recording | `smsmockserver/recordings/mobilemessage/actual/unsubscribes.json` — sha1 `fc876964ce09f715b52a68e0f4e43c7174b74d37` | |
