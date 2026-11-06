# MAD4B live-to-local reconciliation acceptance (2026-11-05)

## Source and effect
- Authoritative runtime: Central staging WordPress and the registered VPS original transport.
- Destination: local Git reconciliation branch; no GitHub push, no tenant WordPress deploy.
- Source/target identities are bound by Site Profile UUID + canonical origin + environment, never connector display name.
- Original Broker 305/306/312/313 remains the sole job authorization and receipt chain.

## Checks run on VPS
- Dynamic binding regression: 39/39 PASS.
- Registry MCP STDIO tests: 22/22 PASS.
- Broker busy deferral regression: 5/5 PASS.
- Unified router tests: 54/54 PASS.
- Control Plane: 12 changed PHP modules linted; JS syntax checked; 11 changed JSON catalogs parsed.
- Company Manager: plugin.install 10/10 PASS; corrected source-target readback PASS.
- Wayfatours Production: current WordPress Site Profile and Coolify PHP labels read back; candidate marked
  HOST_OBSERVED_IDENTITY_AND_AUTHORITY_PENDING. Registration remains observed_not_enrolled.
- Code reconciliation: 984 tracked files identical between the selected plugin source directories and live;
  only MAD4B-RUNTIME-BUILD.txt differs (generated build marker).
- Four Git-only Control Plane runtime paths have not been deployed by this reconciliation.
- Site Code / Broker source snapshot excludes host secrets, runtime registry and issued receipts.

## Do NOT infer
- Connected MCP is not equivalent to WordPress actor permissions.
- A healthy Original 313 worker with no claimed job is not a certified end-to-end 305/312/313 canary.
- Provider plugin.install readiness is not execution authorization or a prior execution receipt.
- The current ETG Staging Site Profile is readable, but its Central Company Portfolio binding is not verified:
  portfolio evidence is absent, so the existing company operation grant fails delegation readback.
  Owner-authenticated original signed enrollment must reconcile; do not forge an identity or restore a revoked grant.
- Original owner launcher explicitly requires an authenticated WordPress REST session; root SSH does not replace it.

## Outstanding independent gates
- Run one owner-authenticated exact read-only 305/306/312/313 canary; verify signed independent 313 receipt.
- Reconcile ETG signed portfolio binding and original grant via enrolled owner/manager provider.
- Keep Production writes fail-closed until operation-specific admission and independent readback.
- Peer-review the un-deployed Git-only modules and generated runtime build stamp before promotion.

This document is audit evidence only; it issues no new authorization.
