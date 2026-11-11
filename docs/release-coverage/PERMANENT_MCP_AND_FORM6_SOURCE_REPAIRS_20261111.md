# MAD4B — MCP protocol, CLI Readiness and Form 6 source repairs (11 Nov 2026)

## Corrected MCP incompatibility

- MCP Protocol Profile status previously referenced undefined readiness/protocol variables and a fixed Adapter 0.7.1 allowance. It now computes readiness through existing Adapter transport compatibility, actually loaded runtime protocol support, and original canonical request-scope evidence.
- A catalog reader required an explicit version_gate false field, but the source JSON omitted it. This has now been defined as false, preserving the catalog as a non-authorizing reference.
- Profile negotiation rejects a protocol revision not implemented by the loaded runtime. Version differences alone do not grant write rights, and unknown installed versions without source/transport evidence fail closed.
- Legacy static compatibility tests were updated to use the existing installed_adapter_version_gate false and certified release-set source, instead of demanding a hardcoded version from runtime-release-policy.json.
- The CLI Readiness plan previously read an undefined status variable for its profile digest. It now uses the current enrolled Site Profile status while preserving the original confirmation, plan SHA, site-binding and scheduled-run scope.

Offline PHP behavior: 10 test cases PASS, including compatible installed version, fake future-version verified layout, unknown version denial and unsupported runtime-protocol denial. Five existing compatibility contract tests PASS. No actual live session was certified.

## Fluent Forms mail and Passport search

- The Core and Company Manager had each registered the same Fluent Forms recipient callback twice. Each now registers it once, without altering sender permissions, SMTP or actual messages.
- Site-specific Tours.dev Passport Form 6 PHP and public AJAX search JS/CSS were captured byte-for-byte, without editing 195 original saved option values or prior submissions. The more recent JS also invalidates stale in-flight searches during debounce and retains native-select fallback.
- The new Rate Widget 2.2.0 asset is a separate observed live source revision. It avoids re-registering JetAccordion during initial page hydration and allows bounded repair after late Ajax. Three offline lifecycle scenarios PASS; actual browser acceptance is still pending.
- The new Form 6 submission mode JS/CSS is source-only. The client defaults to quote_only and only displays advance_checkout after current server readiness plus exact selected rate/date/occupancy/adult checks. It does not create Stripe payment or confirm bookings.

All source snapshots are in Git only. Original318 v82, Host Issue/Revoke handlers, durable high-water source, installed v2 Worker and genuinely signed independent Original313 receipt remain unverified. The existing Playwright service rejected an independent Chromium launch, so offline tests cannot be described as browser acceptance. No website, Production company settings or original runtime authority was changed.
