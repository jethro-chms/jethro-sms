# POST /v1/messages — Send (immediately or scheduled)

Read by `MobileMessageSmsProvider::send()`.

## Endpoint

```
POST https://api.mobilemessage.com.au/v1/messages
```

## Authentication

HTTP **Basic** auth.

## Request Body — recorded live 2026-10

```json
{
    "messages": [
        {
            "to": "0400000000",
            "sender": "61491570159",
            "message": "…",
            "scheduled_for": "2026-10-25T02:49:30Z"
        }
    ],
    "enable_unicode": false
}
```

| Field | Notes |
|-------|-------|
| `messages[]` | One entry per message; `to` may be local (04…) or international, normalised to 614… in replies |
| `sender` | A sender on the account (number or approved alphanumeric ID) |
| `scheduled_for` | ISO-8601 UTC. **≤ 60 s ahead is silently sent at once** (no error, reply shows `scheduled_for: null`) |
| `enable_unicode` | `false` unless the provider is constructed with unicode permitted |
| `custom_ref` | Optional per message, echoed back |

## Response — recorded live 2026-10

```json
{
    "status": "complete",
    "ignore_unsubscribes": false,
    "shorten_urls": false,
    "total_cost": 1,
    "results": [
        {
            "to": "61400000001",
            "message": "…",
            "sender": "61491570159",
            "custom_ref": null,
            "status": "success",
            "cost": 1,
            "message_id": "f849f8d9-e111-4ba0-979e-183f6a450fd9",
            "conversation": 0,
            "scheduled_for": "2026-10-25 02:49:30"
        }
    ]
}
```

Results are one per request entry, in request order. `cost` is an integer in
the send reply (`"1.00"` string on lookups). Timestamps are `"Y-m-d H:i:s"`
UTC without zone. An API-level refusal (e.g. insufficient credits) comes
back as `{"error": "…"}`.

## Source Reference

| Symbol | File | Approx. line |
|--------|------|--------------|
| `MobileMessageSmsProvider::send()` | `jethro-sms/src/Providers/MobileMessageSmsProvider.php` | 208 |
| Mock handler | `smsmockserver/src/Provider/MobileMessage.php` | `handleSend()` |
| Recording (shape) | `smsmockserver/recordings/mobilemessage/actual/messages.send-day-ahead.json` — sha1 `6917c6e057fe57ec65490eb0c85928dd2266656e` | |
| Recording (refusal) | `smsmockserver/recordings/mobilemessage/actual/messages.send-invalid.json` — sha1 `b7dfa782446319f9088508754443006b5cf9e7f3` | |
