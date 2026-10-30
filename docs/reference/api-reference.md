---
sidebar_position: 12
---

# SMS Provider API Reference

Canonical reference for HTTP requests and responses of each SMS provider integration.

## Purpose

Every directory below a provider name mirrors an API endpoint path. Within each endpoint directory, `GET.md`, `POST.md`, or `DELETE.md` documents:

- The exact HTTP method, URL, and authentication mechanism
- The JSON request body (every field, its type, and whether it's required)
- The JSON response body (every field, its meaning, and edge cases)
- How the provider code maps the response to `SmsStatus` / `SmsDelivery` objects
- Verification status: **VERIFIED** (backed by real API responses) or **MOCK ONLY** (inferred from code and unit tests)

The "Source References" tables name the class file that holds each symbol. Their
approximate line numbers date from the recordings and the pre-split single-file
sources, so treat them as hints only — the class and method names are the reliable
part.

## How to verify an endpoint

1. Set `SMS_VERBOSE=true` and `SMS_TESTMODE=false` in `conf.php`
2. Invoke the operation via `scripts/sms.php`:
   ```bash
   ./scripts/sms.php sms --message "test" --to 0400000000
   ./scripts/sms.php balance
   ./scripts/sms.php senderids
   ```
3. Check the PHP error log for the HTTP request/response dump
4. Update the corresponding `.md` file and mark it **VERIFIED**

## Directory conventions

```
{provider} / {endpoint-path} / {HTTPVERB}.md
```

Examples:

| File | Meaning |
|---|---|
| `cellcast/gateway/POST.md` | `POST /api/v1/gateway` |
| `cellcast/report/message/GET.md` | `GET /api/v2/report/message/{id}` |
| `5centsmsv5/sms/POST.md` | `POST /api/v5/sms` |
| `5centsmsv5/sms/{id}/GET.md` | `GET /api/v5/sms/{id}` |
| `5centsmsv5/sms/DELETE.md` | `DELETE /api/v5/sms/{id}` |

Path segments with dynamic parameters (like `{id}`) are represented as literal directory names — they document the endpoint pattern, not a specific invocation.

## Providers

### Cellcast

Base URL: `https://api.cellcast.com` (v1 and v2 endpoints).  
Auth: `Authorization: Bearer <SMS_CELLCAST_APIKEY>` header.

| Endpoint | Method | File | Status |
|---|---|---|---|
| `/api/v1/gateway` | POST | `gateway/POST.md` | MOCK ONLY |
| `/api/v1/gateway/cancelScheduleQuickMessage` | POST | `gateway/cancelScheduleQuickMessage/POST.md` | VERIFIED |
| `/api/v1/customNumber` | GET | `customNumber/GET.md` | VERIFIED |
| `/api/v1/customNumber/add` | POST | `customNumber/add/POST.md` | MOCK ONLY |
| `/api/v1/customNumber/verifyCustomNumber` | POST | `customNumber/verifyCustomNumber/POST.md` | MOCK ONLY |
| `/api/v1/apiClient/account` | GET | `account/GET.md` | VERIFIED |
| `/api/v1/business/add` | POST | `business/add/POST.md` | MOCK ONLY |
| `/api/v1/business` | GET | `business/GET.md` | VERIFIED (undocumented by Cellcast) |
| `/api/v2/report/message/{id}` | GET | `report/message/GET.md` | MOCK ONLY |

### 5CentSMS v5

Base URL: `https://www.5centsms.com.au/api/v5`.  
Auth: `key-id` and `key-secret` fields in the JSON request body (not headers).

| Endpoint | Method | File | Status |
|---|---|---|---|
| `/api/v5/sms` | POST | `sms/POST.md` | VERIFIED |
| `/api/v5/sms` | GET | `sms/GET.md` | VERIFIED |
| `/api/v5/sms/{id}` | GET | `sms/{id}/GET.md` | VERIFIED |
| `/api/v5/sms/{id}` | DELETE | `sms/DELETE.md` | VERIFIED |
| `/api/v5/balance` | GET | `balance/GET.md` | VERIFIED |
| `/api/v5/senderid` | GET | `senderid/GET.md` | VERIFIED |
| `/api/v5/senderid` | POST | `senderid/POST.md` | VERIFIED |
| `/api/v5/senderid/{id}` | DELETE | `senderid/DELETE.md` | VERIFIED |


## See also

- `tests/sms-mock-overrides-cellcast.json` — Cellcast mock server overrides
- `tests/sms-mock-overrides-cellcast-stbarnabas.json` — Cellcast mock variant with StBarnabas sender ID
- `tests/sms/sms-mock-proxy.php` — Intercepting proxy for test scenarios
- `scripts/sms.php` — CLI for invoking SMS operations against live providers
