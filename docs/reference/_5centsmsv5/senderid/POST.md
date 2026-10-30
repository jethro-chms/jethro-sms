# POST /senderid — Register Sender ID

Register an alphanumeric Sender ID with 5CentSMS. This is a **two-phase** workflow:

1. **Schema phase** — call with a `senderid` value only; the API creates the Sender ID upstream and returns the ACMA compliance form schema.
2. **Submission phase** — call again with both `senderid` and all compliance fields; the provider builds a structured summary and a `mailto:` link for manual email submission to 5CentSMS.

> **Important:** ACMA registration cannot be automated. After the API call, an authorised representative must email the compliance details to **hello@5centsms.com.au**. The registered status only becomes active after 5CentSMS manually approves the email.

---

## Endpoint

```
POST /api/v5/senderid
```

Base URL is provider-configured (typically `https://api.5centsms.com.au`).

---

## Authentication

API credentials are passed in the **JSON request body**. No `Authorization` header is used.

| Field        | Type   | Description                    |
|--------------|--------|--------------------------------|
| `key-id`     | string | Your API key ID                |
| `key-secret` | string | Your API key secret            |

---

## Phase 1 — Create Sender ID

Send the Sender ID to be created. The API call creates the record upstream; the response returns `""` for `error` on success.

### Request Body

```json
{
  "key-id": "your-key-id",
  "key-secret": "your-key-secret",
  "senderid": "MyChurch"
}
```

| Field      | Type   | Required | Description                                       |
|------------|--------|----------|---------------------------------------------------|
| `key-id`   | string | Yes      | API key ID                                        |
| `key-secret` | string | Yes    | API key secret                                    |
| `senderid` | string | Yes      | The alphanumeric sender ID to register (≤ 11 chars) |

### Response Body

```json
{
  "error": "",
  "id": "6acf0a2e1d4c8a0046b3d123",
  "message": "Sender ID created"
}
```

| Field     | Type   | Description                                                    |
|-----------|--------|----------------------------------------------------------------|
| `error`   | string | Empty string on success; non-empty string describes the error  |
| `id`      | string | 24-hex upstream registration ID                                |
| `message` | string | Human-readable confirmation message                            |

#### Error Responses

Missing `senderid` — recorded live 2026-10:

```json
{"status": 400, "error": "Missing Sender ID"}
```

Re-POSTing a sender ID **already on this account** is *not* an error: it
re-creates it and returns the success envelope above with a fresh `id`
(recorded live 2026-10 against an ACMA-approved ID; the account list shows
no duplicate afterwards). The previously documented refusal

```json
{"error": "Sender ID already exists"}
```

therefore applies to IDs owned by **other** accounts `[INFERENCE]` — it could
not be reproduced from an account that owns the ID. If `error` is a
non-empty string, the provider surfaces a `Result::failure($errorMsg)`.

---

## Phase 2 — Submit ACMA Compliance Details

After the Sender ID is created upstream, the provider collects ACMA compliance fields from the admin and packages them for manual email submission to `hello@5centsms.com.au`.

> **Note:** Phase 2 is handled entirely within the application layer — no second API call is made. The provider builds a `mailto:` link and a structured `form` summary that the caller (web or CLI) renders.

### ACMA Compliance Form Fields

These fields are collected from the user and included in the email to 5CentSMS:

| Field name            | Label                     | Type | Required | Notes                                  |
|-----------------------|---------------------------|------|----------|----------------------------------------|
| `senderid`            | Sender ID to register     | text | Yes      | Pre-filled with the registered value   |
| `contact_name`        | Contact Name              | text | Yes      |                                        |
| `contact_email`       | Contact Email             | text | Yes      |                                        |
| `contact_telephone`   | Contact Telephone         | text | Yes      |                                        |
| `abn`                 | ABN (if applicable)       | text | No       |                                        |
| `business_name`       | Business Name             | text | Yes      |                                        |
| `business_web_address`| Business Web Address      | text | No       |                                        |
| `business_address`    | Business Address          | text | Yes      |                                        |
| `business_telephone`  | Business Telephone        | text | Yes      |                                        |

---

## ACMA Eligibility Requirements

From the [5CentSMS documentation](https://docs.5centsms.com.au/#06c16433-59d6-4431-8558-499922dc6b02):

> Your requested ID must clearly represent a valid entity or brand, such as a sole trader, company, partnership, trust, co-operative, registered organisation, personal name, registered trademark, government body (with authorization), product or service name, or an acronym/initialism of at least three characters.

---

## State Machine Overview

```
registerSenderId(senderid, validationParams=null)
        │
        ├─ validationParams IS null, senderId IS null
        │   └─ Return schema + ACMA eligibility instructions (no API call, UI help only)
        │
        ├─ validationParams IS null, senderId IS set
        │   ├─ POST /api/v5/senderid  {"key-id", "key-secret", "senderid"}
        │   ├─ On error → Result::failure(errorMsg)
        │   └─ On success → Result::success(RegistrationStep{fields: [schema]})
        │
        └─ validationParams IS set
            └─ Build form summary + mailto link (no API call)
                └─ Result::success(RegistrationStep{
                       registered: false,
                       message: "Sender ID created — email the details below...",
                       instructions: "...",
                       contact: "mailto:hello@5centsms.com.au?subject=...&body=...",
                       form: [{label, value}, ...]
                   })
```

---

## RegistrationStep Response Object

All three phases return a `RegistrationStep` value object. The web and CLI layers render it; the provider emits no HTML.

| Field          | Type                                        | Description                                                         |
|----------------|---------------------------------------------|---------------------------------------------------------------------|
| `message`      | string                                      | Summary message for the user (empty in schema-only phase)           |
| `fields`       | `FormField[]`                               | Form fields to render for the next step; empty when complete        |
| `instructions` | string                                      | Plain-text next-step guidance                                        |
| `contact`      | string                                      | `mailto:` URL pre-populated with compliance body and subject line   |
| `form`         | `array<{label: string, value: string}>`     | Label/value rows of the submitted compliance details                |
| `registered`   | bool                                        | Always `false` — ACMA approval is a manual out-of-band step         |

---

## Test Mode

In test mode, `FiveCentSmsV5FakeHttpClient` intercepts the `POST /senderid` request and returns a mock response without contacting the real API:

```json
{
  "error": "",
  "message": "Sender ID created (test mode — not actually created)"
}
```

This prevents accidental real registrations during development and testing. All other phases (schema discovery, phase 2 summary) are unaffected by test mode.

---

## Error Response Structure

Documented under [Error Responses](#error-responses) — a missing `senderid`
is `400 "Missing Sender ID"`; an ID already on this account re-creates
instead of refusing.

---

## Source Reference

| Symbol                               | File              | Lines       |
|--------------------------------------|-------------------|-------------|
| `FiveCentSmsV5Provider::registerSenderId()` | `jethro-sms/src/Providers/FiveCentSmsV5Provider.php` | 578 |
| `FiveCentSmsV5Provider::createSenderViaApi()` | same | 641 |
| `FiveCentSmsV5Provider::getSenderIdFieldSchema()` | same | 682 |
| `FiveCentSmsV5Provider::buildSenderIdFormData()` | same | 706 |
| `FiveCentSmsV5FakeHttpClient::expectedResponses()` | `jethro-sms/src/Providers/FiveCentSmsV5FakeHttpClient.php` | ~28 |
| `RegistrationStep` (value object)    | `jethro-sms/src/RegistrationStep.php` | |
| `FormField` (schema item)            | `jethro-sms/src/FormField.php` | |
| Recording (missing ID) | `smsmockserver/recordings/5centsmsv5/actual/senderid.register-missing.json` — sha1 `b8e5a43882b949f5794892918656b265269104f2` | |
| Recording (existing ID) | `smsmockserver/recordings/5centsmsv5/actual/senderid.register-existing.json` — sha1 `f78372410d30c919818b45b70e6fd8ce1c85cd85` | |

External reference: [5CentSMS Sender ID docs](https://docs.5centsms.com.au/#06c16433-59d6-4431-8558-499922dc6b02)
