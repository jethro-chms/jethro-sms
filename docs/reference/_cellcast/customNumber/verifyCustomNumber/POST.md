# POST /api/v1/customNumber/verifyCustomNumber

**Status: MOCK ONLY — not verified against real API**

Submits an OTP code to complete sender number registration. This is phase 2 of the two-step sender number registration flow. It is called after `POST /api/v1/customNumber/add` returns a response indicating that OTP verification is required (i.e. the `add` response message contains neither "already exist" nor "created").

## Request

```
POST https://api.cellcast.com/api/v1/customNumber/verifyCustomNumber
Authorization: Bearer <API_TOKEN>
Content-Type: application/json
```

### Body

| Field    | Type   | Required | Description |
|----------|--------|----------|-------------|
| `number` | string | Yes      | Phone number in E.164 format without `+` (e.g. `61491570158`) |
| `name`   | string | Yes      | Label/name for the sender number |
| `otp`    | string | Yes      | One-time password code sent to the number by the previous `/add` step |

### Example

```json
{
  "number": "61491570158",
  "name": "Church Main",
  "otp": "123456"
}
```

Jethro sends the `number` as entered (e.g. `0491570158` or `61491570158`) and relies on Cellcast to normalise it. The `name` value comes from `ContactPhoneNumber->name` as stored in Jethro.

## Response

### Success (`status: true`)

```json
{
  "status": true,
  "message": "Number verified successfully (test mode — not actually verified)"
}
```

| Field     | Type    | Description |
|-----------|---------|-------------|
| `status`  | boolean | `true` on successful verification |
| `message` | string  | Human-readable confirmation |

On success the provider deletes the cached sender-number list (`sms_sendernumbers`) and returns a `RegistrationStep` with `registered: true`.

### Failure (`status: false` or absent)

```json
{
  "status": false,
  "message": "Invalid OTP"
}
```

The `message` field is surfaced directly as the error. If `message` is absent, the fallback error is `"OTP verification failed"`.

## Registration flow

This endpoint is only reached in phase 2. The full two-step flow is:

```
Phase 1  →  POST /api/v1/customNumber/add
               ├─ message contains "already exist"  →  registered immediately, no OTP
               ├─ message contains "created"        →  registered immediately, no OTP
               └─ other (e.g. "OTP sent…")          →  OTP required, proceed to phase 2

Phase 2  →  POST /api/v1/customNumber/verifyCustomNumber   ← this endpoint
               ├─ status: true   →  registered: true
               └─ status: false  →  failure, surface message to user
```

## Source references

- **Provider method:** `CellcastSmsProvider::registerSenderNumber()` — `jethro-sms/src/Providers/CellcastSmsProvider.php` lines 678–759
- **HTTP call (phase 2):** `jethro-sms/src/Providers/CellcastSmsProvider.php` line 736
- **Request body construction:** lines 737–739 (`name`, `number`, `otp`)
- **Success handling:** lines 748–754
- **Error handling:** lines 757–758
- **Test-mode mock:** `CellcastFakeHttpClient::expectedResponses()` — `jethro-sms/src/Providers/CellcastFakeHttpClient.php` lines 3160–3164

## Test-mode mock (`CellcastFakeHttpClient`)

The fake client always returns success:

```json
{
  "status": true,
  "message": "Number verified successfully (test mode — not actually verified)",
  "testMode": true
}
```

This mock is used when `SMS_TESTMODE` is enabled. It does not exercise a real Cellcast API call and does not send or validate any OTP.

## Error Response Structure

Unknown — not yet documented.
