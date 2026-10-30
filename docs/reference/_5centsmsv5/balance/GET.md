# GET /balance — Query Account Balance

Retrieves the current credit balance for the authenticated 5CentSMS account.

## Endpoint

```
GET https://api.5centsms.com/api/v5/balance
```

## Authentication

Credentials are passed in the **JSON request body** — not in headers. This is non-standard for GET requests but required by the 5CentSMS v5 API.

| Field        | Type   | Required | Description                  |
|--------------|--------|----------|------------------------------|
| `key-id`     | string | Yes      | API key ID                   |
| `key-secret` | string | Yes      | API key secret               |

## Request

**Headers**

```
Content-Type: application/json
Content-Length: <body byte length>
```

**Body**

```json
{
    "key-id": "your-key-id",
    "key-secret": "your-key-secret"
}
```

**Timeout:** 5 seconds.

## Response

### Success — `200 OK`

```json
{
    "balance": {
        "credits": 12345
    }
}
```

| Field                | Type    | Description                        |
|----------------------|---------|------------------------------------|
| `balance.credits`    | integer | Remaining SMS credits on the account |

### Error — provider returns an error message

```json
{
    "error": "Invalid API key"
}
```

If `error` is non-empty the request is treated as a failure and the error string is propagated to the caller.

## Caching

The provider caches a successful balance result for **300 seconds** (5 minutes) using the key `sms_balance`. Sending an SMS evicts this cache entry because credits are consumed. If no cache is configured every call hits the API.

## Error Handling

| Condition                        | Outcome                                      |
|----------------------------------|----------------------------------------------|
| HTTP transport failure           | Propagates the HTTP error string             |
| Non-JSON response body           | Failure: `Invalid JSON response from balance API. Raw: …` |
| `error` field non-empty          | Failure: `<error value> — raw: …`            |
| `balance.credits` field absent   | Failure: `No credits field in balance response. Raw: …` |

## Source Reference

| Symbol              | File               | Lines     |
|---------------------|--------------------|-----------|
| `getBalance()`      | `jethro-sms/src/Providers/FiveCentSmsV5Provider.php`  | 2091–2128 |
| `parseBalance()`    | `jethro-sms/src/Providers/FiveCentSmsV5Provider.php`  | 2135–2152 |

**Class:** `Sms\Providers\FiveCentSmsV5Provider`

## Test Coverage

| Test file                                   | Cases                                                   |
|---------------------------------------------|---------------------------------------------------------|
| `jethro-sms/tests/providers/test_v5_parsing.php`   | Success (`{"balance":{"credits":42}}`), error field, missing `credits`, invalid JSON, HTTP failure |
| `tests/sms-mock-overrides-5centsms.json`    | Mock fixture: `"GET /balance": {"balance": {"credits": 12345}}` |

## Notes

- The `credits` value is cast to `int` before returning; fractional credits are truncated.
- Using a JSON body with `GET` is non-standard (RFC 7231 does not prohibit it, but many proxies and HTTP clients silently strip GET bodies). The PHP `NativeHttpClient` in this project explicitly writes the body via stream context regardless of the HTTP method.

## Error Response Structure

Unknown — not yet documented.

**Parsed by:** `FiveCentSmsV5Provider::parseBalance()`

