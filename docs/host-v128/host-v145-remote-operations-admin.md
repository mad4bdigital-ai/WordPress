# v145: Remote Operations and Authorization from MAD4B Central

## Purpose

Original Host Authority already supplies Owner Review (420/425/430),
Original305 SSH grant issue, Original306 broker dispatch, Original312 exact
operation verification, Original313 receipts and original core recovery.
Company Credentials is a different system for vendor-key metadata, not
Original305 SSH grants.

The missing feature is a single owner-facing screen. v145 adds a minimal
native WordPress admin integration ABOVE existing contracts, not another
issuer, Owner identity, grant store, or direct SSH endpoint.

## Tours.dev admin

/wp-admin/admin.php?page=mad4b-remote-operations

For the original enrolled Central administrator only:
1. Current Original318/v3 signed persistent Write status.
2. Existing Original305 SSH transports bound to enrolled company and site.
3. Historic scoped one-time/persistent Grants and status/expiry/readback.
4. Admitted, unexpired requests and explicit Dispatch via original POST
   broker-dispatch, only for the original authenticated requesting owner.
5. Original broker job list with independent readback and signed recovery
   evidence inspections, without showing sealed payloads.
6. Exact Original305 POST grants as explicit owner action: transport from
   the original site registry, operation fixed to maintenance.shell,
   reason, and one-time or existing eligible persistent transport mode.

Links to Host Authority v3 and Company Credentials reinforce distinct
ownership, SSH operation scope and vendor-key management.

## Backend boundary

Single new REST GET endpoint: mad4b-remote-operations/v1/overview.
Permission requires original enrolled Central administrator, manage_options
and exact signed-v3 Tours.dev site-binding.

All company, environment, site UUID and origin evidence is read from the
existing mad4b_company_environment_registry_v2. Grant, request, broker
and transport records are **allowlist projected** and cannot return a
sealed payload, credential reference, private key or signature.

No new POST/DELETE endpoint, permission grant, signer, raw shell, SQL,
WordPress option mutation or automated execution exists in this module.

### Owner actions

- Explicit click and confirmation calls the ORIGINAL 305 grant endpoint
  and ORIGINAL 306 broker dispatch endpoint through the browser's
  authenticated WP REST nonce. Original permitted() stays authoritative.
- New scopes do not enable SSH Write automatically, nor waive Original313.
- One-time grants default to 300 seconds. Persistent issuance requires the
  original pending independently verified transport and every original
  owner / issuer guard.
- No raw shell command is accepted on this screen. Signed command intake
  is exclusively original 305. Broker/Recovery readback is GET only.
- Dispatch is not execution success; original signed readback still needed.
- No automatic replay of ambiguous or previously attempted operations.

## Rollout and acceptance

Deploy only the new source-pinned 431 MU admin file on Tours.dev staging,
without editing the existing 420, 425, 430 trust sources or Production.
Verify git blob, PHP lint, Node syntax/UI fixture, server-side safe grants,
independent readback and absence of any new POST API.
After selective deploy, check WordPress register_rest_route, owner menu
and authenticated overview, guest REST 403, original signed Host Runner
Current, pre/post identical stored grant/review/request counts, Origin305
historical HOLD, and no production writes.

No live grant should be issued merely as a smoke test.
