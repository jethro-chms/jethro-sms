# GET /v1/messages — Message lookup and account history

One endpoint, two modes, selected by query string. Read by
`MobileMessageSmsProvider::updateDelivery()` (lookup) and
`listRecentDeliveries()` (history).

## Endpoint

```
GET https://api.mobilemessage.com.au/v1/messages
```

## Authentication

HTTP **Basic** auth.

## Lookup — `?message_id=<uuid>` (recorded live 2026-10)

The message is `results[0]`:

```json
{
    "status": "complete",
    "results": [
        {
            "to": "61400000001",
            "message": "…",
            "sender": "61491570159",
            "custom_ref": null,
            "status": "scheduled",
            "cost": "1.00",
            "message_id": "f849f8d9-…",
            "requested_at": "2026-10-24 02:49:31",
            "scheduled_for": "2026-10-25 02:49:30"
        }
    ]
}
```

Statuses seen: `pending`, `scheduled`, `sent`, `delivered`, `cancelled`,
`failed`, `blocked` (blocked = destination opted out). `cost` is a string
here, an integer on the send reply. Unknown ID → `404 {"error": "No message(s) found with the given ID or reference."}`.

## History — `?from=&to=&limit=&offset=` (recorded live 2026-10)

`from`/`to` are UTC **days** (`Y-m-d`), `to` exclusive. Paged envelope:

```json
{
    "status": "complete",
    "total": 1,
    "limit": 200,
    "offset": 0,
    "results": [
        {
            "message_id": "f849f8d9-…",
            "recipient_number": "61400000001",
            "sender_id": "61491570159",
            "message_content": "…",
            "status": "scheduled",
            "cost": "1.00",
            "custom_ref": null,
            "requested_at": "2026-10-24 02:49:31",
            "scheduled_for": "2026-10-25 02:49:30"
        }
    ]
}
```

Newest first. The provider refines by send time client-side, since the API
filters by requested day.

## Source Reference

| Symbol | File | Approx. line |
|--------|------|--------------|
| `MobileMessageSmsProvider::updateDelivery()` | `jethro-sms/src/Providers/MobileMessageSmsProvider.php` | ~494 |
| `MobileMessageSmsProvider::listRecentDeliveries()` | same | ~590 |
| Mock handlers | `smsmockserver/src/Provider/MobileMessage.php` | `handleLookup()` / `handleHistory()` |
| Recording (lookup) | `smsmockserver/recordings/mobilemessage/actual/messages.lookup-scheduled.json` — sha1 `29ca5a729543702d68882594d8c2612bfad1553e` | |
| Recording (unknown ID) | `smsmockserver/recordings/mobilemessage/actual/messages.lookup-unknown.json` — sha1 `e4ad547247a1855475f16d6f3eaa383e5e9c6cf4` | |
| Recording (history) | `smsmockserver/recordings/mobilemessage/actual/messages.history.json` — sha1 `dbd2b925dd1d5684328c0fabe02142bc670aad5d` | |
