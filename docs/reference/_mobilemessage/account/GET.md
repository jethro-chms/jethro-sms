# GET /v1/account — Account balance

Read by `MobileMessageSmsProvider::getBalance()`.

## Endpoint

```
GET https://api.mobilemessage.com.au/v1/account
```

## Authentication

HTTP **Basic** auth (`SMS_MOBILEMESSAGE_APIKEY_USERNAME` /
`SMS_MOBILEMESSAGE_APIKEY_PASSWORD`).

## Response — recorded live 2026-10

```json
{
    "status": "complete",
    "credit_balance": 50,
    "credit_price": null
}
```

`credit_balance` is an integer number of credits (1 credit = 1 SMS segment);
`credit_price` was null on the recorded account. No auth → `401 {"error": "Unauthorized"}`.

## Source Reference

| Symbol | File | Approx. line |
|--------|------|--------------|
| `MobileMessageSmsProvider::getBalance()` | `jethro-sms/src/Providers/MobileMessageSmsProvider.php` | ~290 |
| Mock handler (`/v1/account`) | `smsmockserver/src/Provider/MobileMessage.php` | `handle()` |
| Recording | `smsmockserver/recordings/mobilemessage/actual/account.json` — sha1 `3dfc8cab7dcd8372bafbd0b7052f2fcd818da5fe` | |
