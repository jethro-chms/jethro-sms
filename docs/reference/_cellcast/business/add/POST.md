# POST /api/v1/business/add

Submit a Sender ID (business name) registration request to Cellcast.

> **MOCK ONLY — not verified against real API.**
> The mock response is in `tests/sms-mock-overrides-cellcast.json` (`POST /business/add`).
> Cellcast reviews registrations manually; approval is not instant.

---

## Overview

This is Phase 2 of the sender ID registration flow. Phase 1 (`registerSenderId()` called without `$validationParams`) returns the field schema for display. Phase 2 (`registerSenderId($senderId, $validationParams)`) constructs and submits this request.

Cellcast's business-name registration is always a manual-approval process. A successful `200` response means the application was received, **not** that the sender ID is active. The provider sets `registered: false` in the returned `RegistrationStep` regardless of API success.

Reference: <code>https://developer.cellcast.com/sender-id/business-name.html</code>

---

## Request

```
POST /api/v1/business/add
```

### Headers

| Header          | Value                            |
|-----------------|----------------------------------|
| `Authorization` | `Bearer <api_token>`             |
| `Content-Type`  | `application/json`               |
| `Content-Length`| byte length of the JSON body     |

### Body

```json
{
  "businessname": "MyChurch",
  "descriptionInternal": "Main outbound line for St Barnabas",
  "purposeOfUse": "Transactional SMS",
  "ownership": true,
  "companyInformation": {
    "name": "St Barnabas Pty Ltd",
    "abn": "12 345 678 901",
    "website": "https://example.org",
    "address": "1 Church Street, Brisbane QLD 4000"
  },
  "requestorContact": {
    "firstName": "Jane",
    "lastName": "Smith",
    "position": "Office Manager",
    "phoneNumber": "0400000001",
    "email": "jane@example.org"
  },
  "customerContact": "+61400000001"
}
```

### Fields

All top-level fields except `customerContact` are required. Fields within
`companyInformation` and `requestorContact` are omitted from the payload if
their source form values are empty strings.

| Field | Type | Required | Description |
|---|---|---|---|
| `businessname` | string | **Yes** | The sender ID to register. 3–11 characters. |
| `descriptionInternal` | string | **Yes** | Internal description for this business name. |
| `purposeOfUse` | string | **Yes** | One of: `Promotional SMS`, `Transactional SMS`, `Service SMS`. |
| `ownership` | boolean | **Yes** | `true` if the requestor owns the business name. |
| `companyInformation` | object | **Yes** | Company details (see sub-fields below). |
| `companyInformation.name` | string | **Yes** | Legal company name. |
| `companyInformation.abn` | string | **Yes** | Australian Business Number. |
| `companyInformation.website` | string | **Yes** | Company website URL. |
| `companyInformation.address` | string | **Yes** | Company address. |
| `requestorContact` | object | **Yes** | Details of the person submitting the request. |
| `requestorContact.firstName` | string | **Yes** | Requestor first name. |
| `requestorContact.lastName` | string | **Yes** | Requestor last name. |
| `requestorContact.position` | string | **Yes** | Requestor's role/position. |
| `requestorContact.phoneNumber` | string | **Yes** | Requestor phone number (digits only). |
| `requestorContact.email` | string | **Yes** | Requestor email address. |
| `customerContact` | string | No | Contact number Cellcast may call for approval queries. Accepts `+61400000000`, `61400000000`, `0400000000`, or `400000000`. |

---

## Response

### Success — `200 OK`

```json
{
  "status": true,
  "message": "Business added successfully"
}
```

| Field | Type | Description |
|---|---|---|
| `status` | boolean | `true` if the application was accepted by the API. |
| `message` | string | Human-readable confirmation from Cellcast. |

The provider surfaces `message` to the caller as the `RegistrationStep.message`
value. `registered` is always `false` because Cellcast performs manual review.

### Error — `200 OK` (API-level error)

Cellcast may return HTTP 200 with `status: false` to signal a validation error:

```json
{
  "status": false,
  "message": "businessname is already registered"
}
```

The provider treats any response with `status` falsy as a failure and forwards
`message` to the caller.

### HTTP transport error

If the HTTP request itself fails (non-2xx, connection timeout, etc.) the provider
returns `Result::failure(...)` with the transport error string. No JSON is parsed.

---

## Mock response

**File:** `tests/sms-mock-overrides-cellcast.json`, key `POST /business/add`

```json
{
  "status": true,
  "message": "Business added successfully (test mode)",
  "data": []
}
```

The mock is active (not `DISABLED`). The `data` array is empty; the real API
response is not known to include a populated `data` field on success.

---

## Source

| Symbol | File | Lines |
|---|---|---|
| `CellcastSmsProvider::registerSenderId()` | `jethro-sms/src/Providers/CellcastSmsProvider.php` | ~818–906 |
| `CellcastSmsProvider::getSenderIdFieldSchema()` | `jethro-sms/src/Providers/CellcastSmsProvider.php` | ~915–933 |

### Relevant code path (Phase 2 submit)

```php
// jethro-sms/src/Providers/CellcastSmsProvider.php ~854-875
$body = array_filter([
    'businessname'        => $businessName,
    'descriptionInternal' => $descriptionInternal !== '' ? $descriptionInternal : null,
    'purposeOfUse'        => $purposeOfUse !== '' ? $purposeOfUse : null,
    'ownership'           => $ownership,
    'companyInformation'  => $companyInfo !== [] ? $companyInfo : null,
    'requestorContact'    => $requestorContact !== [] ? $requestorContact : null,
    'customerContact'     => $customerContact !== '' ? $customerContact : null,
], fn ($v) => $v !== null);

$httpRequest = new HttpRequest(
    url: $this->url . '/api/v1/business/add',
    method: 'POST',
    ...
);
```

`array_filter` with the null-exclusion callback means optional object fields
(`companyInformation`, `requestorContact`, `customerContact`) are dropped
entirely from the payload when the user leaves them blank — they are never sent
as `null`.

---

## Error Response Structure

Unknown — not yet documented.
