# POST /v1/senders — Register a Sender Number

Register one of the account's phone numbers (or a pending verification for a
new one) with MobileMessage. Read by
`MobileMessageSmsProvider::registerSenderNumber()`.

## Endpoint

```
POST https://api.mobilemessage.com.au/v1/senders
```

## Authentication

HTTP **Basic** auth (`SMS_MOBILEMESSAGE_APIKEY_USERNAME` /
`SMS_MOBILEMESSAGE_APIKEY_PASSWORD`) — unlike 5CentSMS, which authenticates
in the JSON body.

## Request Body

```json
{
  "number": "0491570157",
  "label": "Office mobile"
}
```

| Field    | Type   | Required | Description                                                    |
|----------|--------|----------|----------------------------------------------------------------|
| `number` | string | Yes      | The phone number to register, as entered                       |
| `label`  | string | No       | Human label (GET /v1/senders items return it back as `label`)  |

**The number must be under `number`.** POSTing it as `sender` (the key the
*GET* items use) is rejected exactly like a missing number.

A successful creation texts a verification link to the number; the owner must
click it on the handset (max 3 pending verifications at a time). There is no
API to complete or poll the click — poll `GET /v1/senders` instead.

## Responses — recorded live 2026-10

Missing number:

```json
{"status": 400, "error": "\"number\" is required."}
```

Number already on the account (shared, own or verified):

```json
{"status": 409, "error": "This number is already an active sender on your account."}
```

The provider turns any `error` envelope into a `Result::failure` — except
"already an active sender", which `registerSenderNumber()` reports as a
completed registration (`registered: true`), matching the Cellcast/v5
treatment of already-registered numbers.

The success envelope is not recorded — creating would text a real handset —
so the mock's `200 {"status":"complete","sender":…}` is plausible, not
verified; the provider does not read the success body beyond checking for
the error envelope.

## Related

- `GET /v1/senders` returns items shaped `{"sender":"614…","type":"own|shared|dedicated|alpha","label":…,"is_default":…}`
  (note `sender`, not `number`). `type: "alpha"` items are alphanumeric
  Sender IDs; the rest are sender numbers.

## Source Reference

| Symbol | File | Approx. line |
|--------|------|--------------|
| `MobileMessageSmsProvider::registerSenderNumber()` | `jethro-sms/src/Providers/MobileMessageSmsProvider.php` | 464 |
| `MobileMessageSmsProvider::fetchSenders()` | same | 344 |
| `MobileMessageSmsProvider::getSenderNumbers()` | same | 408 |
| `MobileMessageSmsProvider::verifySenderNumber()` | same | 428 |
| Recording (missing number) | `smsmockserver/recordings/mobilemessage/actual/senders.register-missing.json` — sha1 `225450878a5ca44770a09ecd6e004b5e4ee0a007` | |
| Recording (already active) | `smsmockserver/recordings/mobilemessage/actual/senders.register-active.json` — sha1 `eda2fad84fcc382be7d3208f3df0d5448b88be14` | |
