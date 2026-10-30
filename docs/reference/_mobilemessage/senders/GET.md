# GET /v1/senders — List senders (numbers and alphanumeric IDs)

Populates `MobileMessageSmsProvider::getSenderIds()` (items with
`type: "alpha"`) and `getSenderNumbers()` (types `own`, `shared`,
`dedicated`); `verifySenderNumber()` matches against the numbers.

## Endpoint

```
GET https://api.mobilemessage.com.au/v1/senders
```

Not paginated on the live account (the provider still follows a paged
`{total, limit, offset, results}` envelope if one appears).

## Authentication

HTTP **Basic** auth.

## Response — recorded live 2026-10

```json
{
    "status": "complete",
    "results": [
        {"sender": "61491570159", "type": "shared", "label": "Shared Number", "is_default": true},
        {"sender": "61491570157", "type": "own",    "label": "Your Mobile",   "is_default": false}
    ]
}
```

| Field | Notes |
|-------|-------|
| `sender` | The value sent as the outgoing sender. Numbers are 614…; alphanumeric IDs appear here too, with `type: "alpha"` |
| `type` | `shared` / `own` / `dedicated` = numbers; `alpha` = alphanumeric Sender ID |
| `label` | Free text (`"Your Mobile"` on the account's own number) |

**Mind the key**: list items use `sender`; registering a number via
[POST /v1/senders](POST.md) requires it under `number`.

## Related

- [POST /v1/senders](POST.md) — register a sender number.

## Source Reference

| Symbol | File | Approx. line |
|--------|------|--------------|
| `MobileMessageSmsProvider::fetchSenders()` | `jethro-sms/src/Providers/MobileMessageSmsProvider.php` | 344 |
| `MobileMessageSmsProvider::getSenderIds()` / `getSenderNumbers()` | same | 388 / 408 |
| Mock handler | `smsmockserver/src/Provider/MobileMessage.php` | `handleSenders()` |
| Recording | `smsmockserver/recordings/mobilemessage/actual/senders.json` — sha1 `28c0dc3663720203c084f7d80c0e033d79851b9f` | |
