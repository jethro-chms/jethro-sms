# DELETE /senderid/\{id\} — Delete Sender ID

> **VERIFIED** — request and response shapes confirmed from the 5CentSMS Postman collection (schema version 2.1).

Remove a registered Sender ID from the account. The `{id}` path parameter is the gateway-assigned record ID, not the alphanumeric sender ID string itself.

> **Implemented** — `FiveCentSmsV5Provider::deleteSenderNumber()` (jethro-sms) resolves the
> record id via `GET /senderid` and deletes by it; callers pass the phone number. Exposed as
> `sms.php delete-sendernum --phonenumber=<number>` and by the System Configuration page's
> SMS Gateway panel ("(delete)" links / Delete Sender Number operation). The mock server
> simulates this endpoint (see `smsmockserver`).

---

## Endpoint

```
DELETE https://www.5centsms.com.au/api/v5/senderid/{id}
Content-Type: application/json
```

### URL Parameters

| Parameter | Type   | Required | Description                                                                 |
|-----------|--------|----------|-----------------------------------------------------------------------------|
| `{id}`    | string | Yes      | Gateway-assigned record ID for the Sender ID (returned by `GET /senderid` as the `id` field on each object item). URL-encode before insertion. |

---

## Authentication

Credentials are passed in the **JSON request body**. No `Authorization` header is used. This is non-standard — a DELETE request with a body — but required by the 5CentSMS v5 API (the same pattern as `DELETE /sms/{id}`).

---

## Request

### Headers

```
Content-Type: application/json
```

### Body

```json
{
  "key-id":     "your-api-key-id",
  "key-secret": "your-api-key-secret"
}
```

| Field        | Type   | Required | Description                                                             |
|--------------|--------|----------|-------------------------------------------------------------------------|
| `key-id`     | string | Yes      | API key ID from the 5CentSMS dashboard (`SMS_5CENTSMS_APIKEY_ID`).      |
| `key-secret` | string | Yes      | API key secret from the 5CentSMS dashboard (`SMS_5CENTSMS_APIKEY`).     |

No other fields are sent. The Sender ID to delete is identified solely by the URL path parameter.

### Example (curl)

```bash
curl -X DELETE "https://www.5centsms.com.au/api/v5/senderid/682b799beb1416819405256a" \
  -H "Content-Type: application/json" \
  -d '{"key-id":"your-key-id","key-secret":"your-key-secret"}'
```

---

## Response

### Success — `200 OK`

```json
{
  "error": ""
}
```

| Field   | Type   | Description                                                            |
|---------|--------|------------------------------------------------------------------------|
| `error` | string | Empty string on success; non-empty string contains the error message.  |

### Error

```json
{
  "error": "Sender ID not found or already deleted."
}
```

A non-empty `error` field indicates failure. The specific message is gateway-defined; treat any non-empty value as a hard failure.

---

## Behavior Notes

- The `id` to pass here is the `id` field from the object items returned by `GET /senderid` (e.g. `"682b799beb1416819405256a"`). It is **not** the alphanumeric sender ID string (e.g. `"MYCHURCH"`).
- Successful deletion should be followed by a cache invalidation of the `sms_senderids` key (see `GET /senderid` — responses are cached for 1800 seconds).
- The Postman schema contains no example response beyond the success shape above (`response: []`); error shapes are inferred from the API's general error convention.

---

## Related Endpoints

| Method | Path                  | Description                              |
|--------|-----------------------|------------------------------------------|
| GET    | `/api/v5/senderid`   | List all Sender IDs; returns the `id` field needed here. |
| POST   | `/api/v5/senderid`    | Register a new Sender ID.                |

---

## Configuration Constants

| Constant                 | Required | Default                              | Purpose                              |
|--------------------------|----------|--------------------------------------|--------------------------------------|
| `SMS_5CENTSMS_APIKEY_ID` | Yes      | —                                    | API key ID sent as `key-id`          |
| `SMS_5CENTSMS_APIKEY`    | Yes      | —                                    | API key secret sent as `key-secret`  |
| `SMS_5CENTSMS_URL`       | No       | `https://www.5centsms.com.au/api/v5` | Base URL; append `/senderid/{id}`    |

---

## Error Response Structure

Unknown — not yet documented.
