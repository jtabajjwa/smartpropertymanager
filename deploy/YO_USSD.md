# Yo! Custom USSD: Primora call-outs

Tenants (or someone paying for them) dial Primora's code on Yo!'s shared
USSD code, `*217*XXX#`, and pay rent by mobile money.

**Who does what:**
- **Yo!'s Custom USSD app** runs the menu screens and sends the mobile money
  prompt.
- **Primora** answers three call-outs, at the steps in
  *PrimoraPropTech_USSD_Process_V.1.0*, and records the payment when Yo!'s
  payment notification arrives.

The code is in `app/Ussd/` (`YoUssdCallout`, `UssdPayments`) and
`app/Http/Controllers/Api/YoUssdController.php`. The tests are in
`tests/Feature/YoUssdTest.php`.

## Call-out URLs

Each step has its own URL. All of them are `POST` with a JSON body, as Yo!'s
*Third Party URL Call-Out API v2.7* describes.

| Process step | What it does | Test system (staging) | Production |
|---|---|---|---|
| 2.1.2 | Unit ID typed | `https://staging.primoraproptech.com/api/ussd/yo/unit` | `https://app.primoraproptech.com/api/ussd/yo/unit` |
| 2.2.1.2 and 2.2.2.4 | Units for a phone number | `https://staging.primoraproptech.com/api/ussd/yo/units` | `https://app.primoraproptech.com/api/ussd/yo/units` |
| 2.2.1.7 and 2.2.2.9 | Chosen unit and amount | `https://staging.primoraproptech.com/api/ussd/yo/confirm` | `https://app.primoraproptech.com/api/ussd/yo/confirm` |

**Payment notifications (IPN):** every call-out that hands out a payment
reference also returns `success_ipn_url` and `failure_ipn_url`. They point to
the same endpoints the tenant portal's payments use:
- `https://<domain>/payments/callback/yo/ipn`
- `https://<domain>/payments/callback/yo/failure`

## Menu variables Primora reads

These are the variable names to use when configuring the menu. Yo! adds them
to the call-out body.

| Variable | Step | Example |
|---|---|---|
| `property_unit_number` | 2.1.1 | `1000124`. This is the unit ID printed on Primora invoices; digits only, so spaces or dashes are ignored. |
| `other_tenant_phonenumber` | 2.2.2.3 | `0772123456`. Any Ugandan format works. Leave it out for "Pay for Self". |
| `selected_property_number` | 2.2.1.4 / 2.2.2.6 | `1`, the number picked from the list |
| `user_phonenumber` | 2.2.1.7 / 2.2.2.9 | Send back `ussd_processor_params.user_phonenumber` from the `units` call-out |
| `amount` | 2.2.1.6 / 2.2.2.8 | `200000`. Commas are ignored. |

**Which step is which:**
- The `units` call-out treats the request as "Pay for Others" when
  `other_tenant_phonenumber` is present, and as "Pay for Self" (using
  `anumbermsisdn`) when it isn't.
- Extra spaces in variable names (e.g. `"amount "`) are tolerated.

## What Primora returns

### Every call-out
- **`validated: true`:** carry on to the next screen.
- **`validated: false`:** the session ends, and `message` explains why. For
  example: unit ID not found, no tenancy on that phone, the landlord doesn't
  take payments through Primora, or an amount below UGX 500 or above
  UGX 5,000,000.

### `ussd_processor_params` keys for menu texts and placeholders

| Key | From | Example |
|---|---|---|
| `tenant_name` | unit, confirm | `Grace Namata` |
| `estate_name` | unit, confirm | `Acacia Court` |
| `unit_number` | unit, confirm | `A4 (1000124)`: the landlord's name for the unit, plus its unit ID |
| `property_unit_number` | unit, confirm | `1000124` |
| `balance`, `balance_formatted` | unit | `500000`, `UGX 500,000` |
| `amount`, `amount_formatted` | confirm | `200000`, `UGX 200,000` |
| `property_unit_list_text` | units | `Primora PropTech:\n1. Acacia Court A4 (Grace, Bal: UGX 500,000)` (at most 4 units) |
| `property_unit_count` | units | `1` |
| `user_phonenumber` | units | `256772123456` |
| `payment_external_reference` | unit, confirm | `RNT-261030-K3QX7M` |

The template's placeholders map to these keys:
- `[XXdispatcher_property_unit_numberXX]` → `property_unit_number`
- `[XXdispatcher_estate_nameXX]` → `estate_name`
- `[XXdispatcher_tenant_nameXX]` → `tenant_name`
- `[XXdispatcher_property_unit_list_textXX]` → `property_unit_list_text`

`message` is the information block only, for example
`Primora PropTech:\nEstate: ...\nTenant: ...\nUnit No: ...\nBal: UGX ...`.
The `1. Continue` and `00. Cancel` options are part of Yo!'s menu
configuration.

## Payment reference and IPN

- **Required:** `payment_external_reference` must be sent as the
  **ExternalReference** of the `acdepositfunds` request. That is how
  Primora knows which tenancy a payment is for.
- **When a payment is recorded:** a reference is only a reservation until
  money moves.
  1. Yo!'s IPN arrives with it. If the IPN is missed, a status check every
     ~20 minutes picks the payment up.
  2. Primora confirms the payment with `actransactioncheckstatus` on
     Primora's Yo! account.
  3. It records the payment for the tenant, against that tenancy's oldest
     unpaid invoices, and sends the SMS receipt.
- **Unused references:** references nobody pays against expire after 3 hours.
- **Amount:** on the unit-ID path (2.1.x) the amount is typed after the
  call-out, so Primora takes the amount actually paid from Yo!.
- **Collection fee:** it comes out of the amount paid.
  - If the landlord bears it, the tenant is credited the full amount.
  - If the landlord passes it to tenants, the tenant is credited the amount
    minus the fee.
- **Narrative:** the template's dynamic narrative
  (`{"propty_unit_number":"$property_unit_number","tenant_name":"$tenant_name"}`)
  is fine. Primora doesn't rely on it.

## Signature

Primora checks every call-out's signature with Yo!'s public key, following
the spec: SHA1 of `datetime` + `anumbermsisdn`, verified with RSA. Requests
that fail the check get `validated: false`.

The keys are in `resources/certs/yo/ussd-sandbox.pem` and
`ussd-production.pem`. Which one is used follows `YO_USSD_MODE`, which
defaults to `YO_MODE`:
- staging: `sandbox`
- production: `production`

## To confirm with Yo!

1. **Primora's Yo! account:** the USSD app must collect into **Primora's Yo!
   Payments account**, the same API user as in `.env`. Primora confirms each
   payment with a status check on that account; payments into any other
   account can't be confirmed.
2. **Unchanged reference:** the `ExternalReference` of the deposit must be
   exactly the `payment_external_reference` Primora returned.
3. **Short code:** the short code (`*217*XXX#`) and the sandbox account
   number, for the template.
