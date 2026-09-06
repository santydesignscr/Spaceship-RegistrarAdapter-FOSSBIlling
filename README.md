# Spaceship for FOSSBilling — domain registration and management via API

Registrar adapter that connects FOSSBilling to the
[Spaceship](https://www.spaceship.com/) API to check availability, register,
renew, transfer, and manage domains (nameservers, WHOIS privacy, contacts,
lock/unlock, DNS records) without leaving the FOSSBilling panel.

Based strictly on Spaceship's official documentation:
- API reference (OpenAPI): https://docs.spaceship.dev/
- Authentication / API Manager: https://www.spaceship.com/application/api-manager/

## How it works

1. FOSSBilling calls the adapter with a `Registrar_Domain` object (never a
   bare string or a plain array) for every operation: availability,
   registration, renewal, transfer, nameservers, privacy, lock, contact.
2. Registration, renewal, restoration, and transfer are **asynchronous** on
   Spaceship's side: the API responds immediately with HTTP 202 and a
   `spaceship-async-operationid` header, and the real result is obtained by
   polling `GET /async-operations/{id}` until it stops being `pending`.
3. The adapter does that polling internally, so `registerDomain()`,
   `renewDomain()`, and `transferDomain()` can take up to 60 seconds to
   return. If you're calling them from a cron job or a queue instead of a
   live web request, use the `registerDomainAsync()` /
   `renewDomainAsync()` / `transferDomainAsync()` variants, which return the
   `operationId` immediately so you can poll it later.
4. Every time a domain is registered, transferred, or has its contact
   modified, the adapter saves a **new** contact on Spaceship
   (`PUT /contacts`) and assigns it as registrant/admin/tech/billing.
   Spaceship's API has no way to update an existing contact, so this is
   intentional, not an oversight.
5. Every domain that gets registered or transferred is left with
   **auto-renew disabled** — renewal is always manual (see below).

## Installation

1. Copy `Spaceship.php` to `library/Registrar/Adapter/Spaceship.php` in your
   FOSSBilling installation.
2. In the admin panel: **System → Domain registration → New domain
   registrar**, find "Spaceship" and enable it.
3. Go to the Registrars tab and set your API Key, API Secret, and the
   default WHOIS privacy level.

## Configuration in FOSSBilling

| Field | Where to get it |
| --- | --- |
| **API Key** | Spaceship's [API Manager](https://www.spaceship.com/application/api-manager/) → "New API key" button |
| **API Secret** | Generated together with the API Key in the same place |
| **Default WHOIS privacy level** | Your own choice (High = data hidden, Public = data visible); not something Spaceship provides |

When creating the API Key, enable the scopes you'll be using:
`domains:read`, `domains:write`, `domains:billing`, `domains:transfer`,
`contacts:read`, `contacts:write`, `dnsrecords:read`, `dnsrecords:write`,
`asyncoperations:read`.

## Functional scope

Operations required by `Registrar_AdapterAbstract` (the ones FOSSBilling
calls directly):

- Availability check
- Register, renew, and transfer domains
- Get domain details
- Update nameservers
- Enable/disable WHOIS privacy
- Registrar lock/unlock
- Update registrant/admin/tech/billing contact
- Get EPP/auth code

Extras available if you script against the adapter directly (not used by
FOSSBilling on its own):

- Batch availability check (`checkMultipleAvailability`)
- Non-blocking variants: `registerDomainAsync`, `renewDomainAsync`,
  `transferDomainAsync`, `getAsyncOperation`
- `restoreDomain()` for domains in redemption
- DNS record management (`saveDnsRecords`, `deleteDnsRecords`, `getDnsRecords`)
- Personal nameservers / glue records
- `getDomainList()`, `getNameservers()`, `changeAutoRenew()`, `getTransferStatus()`

## Known behavior and limitations

- **Auto-renew is always `false`.** Every registration or transfer is made
  with `autoRenew: false`, so a domain never renews on its own — you have
  to call `renewDomain()` (or FOSSBilling's own renewal flow) manually. If
  you ever want to turn it on for a specific domain, use
  `changeAutoRenew($domainName, true)`.
- **Deletion is not supported by Spaceship's API** (`DELETE
  /domains/{domain}` returns HTTP 501). `deleteDomain()` throws an
  exception explaining this instead of failing with a generic error.
  There's also no refund under Spaceship's policy, whether you cancel or
  transfer a domain before its paid term ends.
- **Contacts are never updated in place.** Every `registerDomain()`,
  `transferDomain()`, or `modifyContact()` call creates a new contact on
  Spaceship; there's no endpoint to edit an existing one.
- **Registrant email verification (RAA).** If Spaceship requires the
  registrant to confirm their email after a `modifyContact()` call, the
  adapter logs it in FOSSBilling's log but does not fail the call.

## Before using it in production

Spaceship doesn't offer a sandbox/test environment for domain registration,
so it's worth going step by step:

- [ ] Test `isDomainAvailable()` / `checkMultipleAvailability()` with known
      domains (one free, one already registered) to confirm your
      credentials and scopes are correct.
- [ ] Confirm the API Key has the scopes enabled for whatever operations
      you'll be using (a missing scope returns 403, not an auth error).
- [ ] Register **one real, cheap domain** end-to-end and verify the
      `operationId` eventually resolves to `success`.
- [ ] Check on Spaceship's dashboard that domain was left with auto-renew
      turned off.
- [ ] Test `modifyContact()` and check the log in case Spaceship asked for
      email verification.
- [ ] Confirm that trying to "delete" that domain from FOSSBilling fails
      with the expected message, not a cryptic error.

## Notes

- There's no sandbox/test mode for Spaceship's domain registration API: any
  end-to-end test involves a real, non-refundable charge.
- The adapter uses the HTTP client FOSSBilling already provides
  (`getHttpClient()`, Symfony HttpClient) — it doesn't add any new
  dependency.
- All HTTP traffic goes through `rawRequest()`/`apiRequest()`, so if any
  Spaceship endpoint's shape changes, that's the only place you need to
  touch.
