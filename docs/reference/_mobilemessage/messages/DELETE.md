# DELETE /v1/messages — Cancel a scheduled message

Read by `MobileMessageSmsProvider::cancel()`. Cancelling refunds the credit
(`credits_refunded`).

## Endpoint

```
DELETE https://api.mobilemessage.com.au/v1/messages
```

## Authentication

HTTP **Basic** auth.

## Request Body (recorded live 2026-10)

Query parameters are **ignored** on DELETE — the ID must be in a JSON body:

```json
{"message_id": "f849f8d9-e111-4ba0-979e-183f6a450fd9"}
```

## Responses (recorded live 2026-10)

Success — the message was still scheduled:

```json
{
    "status": "complete",
    "message_id": "f849f8d9-…",
    "cancelled": true,
    "credits_refunded": 1
}
```

Already sent/cancelled — status unchanged, reason in `error`:

```json
{"status": 409, "error": "Message is not in a scheduled state."}
```

Unknown ID → `404 {"error": "Message not found."}` (wording differs from the
lookup's 404). A DELETE without the ID in the body (e.g. sent as a query
parameter) → `400 {"error": "\"message_id\", \"custom_ref\", or
\"send_id\" is required."}`.

## Source Reference

| Symbol | File | Approx. line |
|--------|------|--------------|
| `MobileMessageSmsProvider::cancel()` | `jethro-sms/src/Providers/MobileMessageSmsProvider.php` | ~490 |
| Mock handler | `smsmockserver/src/Provider/MobileMessage.php` | `handleCancel()` |
| Recording (cancelled) | `smsmockserver/recordings/mobilemessage/actual/messages.cancel.json` — sha1 `a5bae47a9b2b4be9ad5ab6dcb61a94c4cdc77d1a` | |
| Recording (already sent) | `smsmockserver/recordings/mobilemessage/actual/messages.cancel-again.json` — sha1 `b4de2ae7b8f2c52bb124ec528966d5792b051a63` | |
| Recording (ID not in body) | `smsmockserver/recordings/mobilemessage/actual/messages.cancel-by-query.json` — sha1 `7e28f6c18d9650bda2070264ee67e142f8e5ce72` | |
