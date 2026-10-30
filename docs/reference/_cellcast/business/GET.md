# GET /api/v1/business

**Status: VERIFIED 2026-10-09** against a live Cellcast account with one
approved business name. **Undocumented by Cellcast** — their developer docs
cover only [`POST /api/v1/business/add`](add/POST.md); this endpoint was found
by probing and is what the Cellcast web UI's Sender ID list reflects.

Lists the account's **alphanumeric Sender IDs** ("business names" / Alpha IDs),
including ones still under review. Read by `CellcastSmsProvider::getSenderIds()`.
Sender phone numbers are a separate list:
[`GET /api/v1/customNumber`](../customNumber/GET.md).

---

## Request

```
GET /api/v1/business
Authorization: Bearer <token>
```

No request body. No query parameters.

---

## Response

```json
{
  "status": true,
  "message": "Business listed succesfully",
  "data": [
    {
      "_id": "…",
      "businessname": "StBarnabas",
      "status": "ACTIVE",
      "registrationStatus": "ACTIVE",
      "registrationStatusLabel": "Active",
      "acmaStatus": "ACMA_APPROVED",
      "isDeleted": false,
      "isDefault": false,
      "requestedAt": "2026-09-17T05:34:54.124Z",
      "statusHistory": [ { "status": "PENDING_CELLCAST_REVIEW", "…": "…" }, "…" ],
      "descriptionInternal": "…",
      "purposeOfUse": "…",
      "ownership": true,
      "companyInformation": { "name": "…", "abn": "…", "website": "…", "address": "…" },
      "requestorContact": { "firstName": "…", "lastName": "…", "position": "…", "phoneNumber": "…", "email": "…" },
      "…": "acma* timestamps, approvedBy, statusNotes, user, createdBy, createdAt, updatedAt, __v"
    }
  ],
  "error": {}
}
```

(The message's spelling "succesfully" is Cellcast's.) The entry also carries
the registrant's company and contact details — never log or display them.

### Fields Jethro reads

| Field | Description |
|---|---|
| `businessname` | The Sender ID. |
| `isDeleted` | `true` → skipped. |
| `acmaStatus` | `ACMA_APPROVED` → approved. |
| `status` | `ACTIVE` → approved. |

Anything else is **not approved** (`SenderID::$acmaApproved = false`), so
`excludeUnapprovedSenderIds()` hides it from the sender dropdown unless
`getAll`. Observed lifecycle (from `statusHistory`):
`PENDING_CELLCAST_REVIEW` → `PENDING_ACMA_APPROVAL` → `ACMA_APPROVED`, with
`status` `REQUESTED` on submission (see `POST /api/v1/business/add`) and
`ACTIVE` once usable. Rejection values have not been observed.

### Error response

Same top-level `code`/`message` shape as other Cellcast endpoints; treated as a
failure.

---

## Caching

Session-cached for 30 minutes under `sms_senderids`.

## Mock server

`smsmockserver` (`Provider/Cellcast.php`, `handleGetBusiness()`): a profile's
`CellcastProfile::setBusinessNames()` list (strings are active, approved names;
arrays override fields), else the default `StBarnabas` — plus names registered
through `POST /api/v1/business/add` (`handleBusinessAdd()`, stored as kind
`businessname`). A registration is listed `REQUESTED` /
`PENDING_CELLCAST_REVIEW` while pending (profile approval delay or
`pendingIndefinite()`), then `ACTIVE` / `ACMA_APPROVED`; `REJECTED` /
`ACMA_REJECTED` is the mock's guess, not observed on the real API.
Re-registering a name the account already lists returns `status: false`,
"businessname is already registered"; a register hook's rejection returns
`status: false` with its reason.
