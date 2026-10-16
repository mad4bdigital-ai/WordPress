# Native-first root cause and remediation — isolated source increment

## Scope and provenance
- Based on the exact source head of PR #395: `9972430d83182d9a141b52b230e3f5ac6bd4a0dd`.
- Implemented on an isolated branch; it does **not** change the still-draft PR #395, the live ETG/All Royal Staging sites, Production, databases, Google OAuth, owner grants or signed release artifacts.
- This is a read-only diagnostic increment. It is **not** an approved plugin distribution and does not certify the site was repaired.

## New provider-neutral route
`mad4b/root-cause-remediation-plan` is a private, read-only WordPress Ability.

1. Accept bounded evidence about the error, baseline, optimization bypass, restored failure, suspected script owner and active optimizers.
2. Treat all submitted evidence as caller-reported, never as trusted browser attestation or an execution grant.
3. Do not claim a root cause is confirmed from a single bypass observation.
4. Refuse automatic LiteSpeed attribution when the evidence implicates Elementor or another provider and no LiteSpeed differential exists.
5. When multiple optimizers are present, require isolation before selecting a provider.
6. Rank provider-native diagnostics, registered WordPress Abilities, the current MAD4B Adapter and finally extension of that Adapter.
7. Emit a precise `CAPABILITY_GAP` if certified settings reads, bounded writes, or rollback are missing.
8. Never automatically suggest a new standalone plugin or issue a write approval.

The existing Adaptive Operation Router now returns a `native_first_diagnostic` handoff for browser JavaScript failure tasks, even when its ordinary semantic search finds no registered route. It does not execute the diagnostic or infer browser observations.

Provider IDs in evidence are bounded slugs, not an exact hard-coded version allowlist. This applies equally to Elementor, JetEngine, JetSmartFilters, WPML, Rank Math, Fluent Forms and newly discovered provider names. Actual native tool existence must still be independently verified.

## LiteSpeed Adapter additions
- `litespeed/get-optimization-settings`: verified, bounded use of the public `litespeed_conf` getter **when its runtime hook is mounted**, for ten non-secret JS/guest optimization settings. Returns values plus a SHA-256 snapshot. Reports `CAPABILITY_GAP` when the hook is absent; never fabricates values.
- `litespeed/diagnose-script-order`: bounded PHP WordPress Scripts registry snapshot, with explicit warning it is not the browser runtime.
- `litespeed/settings-change-plan`: native-first investigation plan with explicit missing write/rollback contract, no mutation.
- `litespeed/verify-optimization`: browser acceptance checklist; does not assert it ran.
- Existing `status`, `purge-url` and `purge-all` remain unchanged in authority and behavior.

LiteSpeed's official API also documents `litespeed_conf` for reading settings and `litespeed_save_conf` / `litespeed_update_confs` for persisting them. Slugs are verified against upstream `LiteSpeed\\Base` constants (`optm-js_comb`, `optm-js_defer`, `optm-js_exc`, and related JS/guest controls). The new getter reads through the vendor hook and checks types/size. The documented native write hooks do **not** by themselves establish the required CAS, reversible restore, one-time approval or browser acceptance. Therefore the new writer is deliberately **not registered** or auto-granted. Optimizer exclusion hooks (`litespeed_optimize_js_excludes`, `litespeed_optm_js_defer_exc`, `litespeed_optm_gm_js_exc`, `litespeed_optm_uri_exc`) remain native alternatives to evaluate.

Official API reference: https://docs.litespeedtech.com/lscache/lscwp/api/

## End-to-end closure requirements (NOT YET IMPLEMENTED)
- Verified provider-native settings getter and complete contract for installed runtime, including Guest Optimization effective-state overrides.
- Actual browser evidence harness: console stack, unoptimized/optimized response comparison, time-order/dependency trace and provider isolation.
- Bounded reversible settings mutation: explicit allowlist, `expected_sha256`, exact signed owner plan, one-time ticket, append-only audit, readback and idempotent rollback.
- Browser acceptance on **both** Staging sites for JavaScript, filters, AJAX dataset identity, WPML, SEO metadata and performance; failure must trigger controlled rollback.
- Post-update independent reconciliation of native contracts, skills and write grants; do not regrant without owner approval.
- Source fingerprints/build manifests and package provenance must be rebuilt and separately verified. GitHub CI and live certification are mandatory before release.

## Negative-path acceptance
1. `wp is not defined` + repeatable bypass: optimization is *suspected*, never certified.
2. Elementor-origin stack + no LiteSpeed-specific differential: no LiteSpeed mutation recommended.
3. More than one optimizer: isolate the optimizer before remediation.
4. Wrong types, unknown fields, arbitrary script handles, untrusted provider names: reject.
5. A claimed write grant does not authorize execution: read-only output only.
6. PHP script registry must not be misrepresented as browser order.
7. Unknown LiteSpeed version: read continuity can remain; writes stay blocked.

Test fixture: `tests/native-first-root-cause-runtime.php` (PHP 7.4 + 8.3).

## Fleet-wide generalization and native-hook correction (2026-10-17)
The original JavaScript-only intake was generalized without expanding mutation authority:

- `issue_family`: frontend_js, ajax_filters, visual_builder, dynamic_content, multilingual, seo, forms, performance, integration and general.
- `affected_provider` / `isolated_provider`: bounded provider slugs; a submitted intervention/bypass/restore report is **never** root-cause attestation.
- `include_provider_fleet`: bounded passive inventory from already-registered MAD4B Adapters, without activating extensions or calling third-party provider servers.
- `provider_version_changed`: explicit version-neutral read revalidation signal; changed writer contracts still need exact recertification and owner review; unrelated provider grants remain untouched.
- Existing Adaptive Operation Router maps commonly reported symptoms to these diagnostic families without making any provider mutation decision.
- A private provider is **not** assumed absent merely because no matching MAD4B Adapter is registered; native WordPress Abilities / external provider MCP discovery must be verified separately.
- Existing `mad4b/browser-acceptance-plan` and `mad4b/browser-acceptance-result` are the designated browser evidence lifecycle. No synthetic test is marked as browser acceptance.

### Detailed acceptance by observed provider
| Provider/family | New diagnostic discovery | Vendor setting write & rollback | Next required live proof |
|---|---|---|---|
| Elementor | Passive adapter read/write/restore declaration and visual builder diagnosis | Existing governed adapter subject to exact runtime approval | Inspect exact widget contract, Chrome/Firefox editor and frontend readback |
| JetEngine | Dynamic-content diagnosis and adapter inventory | No new grant or generic mutation | Dynamic listing and query parity |
| JetSmartFilters | AJAX/filter diagnosis and existing reversible writer declaration | Existing governed `_query_var` path only, unchanged | AJAX/dataset identity before/after and rollback |
| WPML | Multilingual diagnosis, explicit native provider lookup | New mutation blocked until independent provider proof | Language links, hreflang and translation parity |
| Rank Math | SEO diagnosis and native provider lookup | New mutation blocked until independent provider proof | Canonicals, schema and meta checks |
| Fluent Forms | Form submission diagnosis and existing form adapter inventory | No inferred new writer | Submit, validation, anti-spam and notification checks |
| LiteSpeed | Documented ten-key read-only `litespeed_conf` snapshot with SHA-256 | `litespeed_update_confs` documented, but no new governed reversible writer registered | Live hook and settings readback; browser comparison; certify writer and undo separately |
| Newly installed plugin | Bounded generic slug and passive fleet inventory | Unknown write denied, no standalone plugin fallback | Signed native tool/schema inventory and behavioral test |

### Cross-cutting hard gates
**P0 safety:** native-first; exact provider isolation; no false root-cause claim; no auto-grant, auto-replay or arbitrary `update_option`.

**P1 mutation readiness:** only allowlisted vendor keys, CAS `expected_sha256`, enrolled owner OAuth and signed one-time approval, durable before-state and independent after-state, provider-specific reversible contract. The runtime did not receive a new mutation operation before those requirements were demonstrated.

**P2 operational closure:** actual external browser baseline/bypass/restore regression, scope-limited controlled rollback, independent post-update provider/skill and write-grant reconciliation on both Staging sites. Production has an independent gate and is not covered by a Staging code test.

### Local tests (not live certificates)
`native-first-root-cause-runtime.php`, `cross-provider-remediation-runtime.php`, `litespeed-optimization-hook-runtime.php` on PHP 7.4 and 8.3.
`provider-version-neutral-read-runtime.php`, G2–G9 fingerprints, compatibility contracts and repository checks remain mandatory.
Official LiteSpeed read/write API: https://docs.litespeedtech.com/lscache/lscwp/api/
Upstream option slugs: https://github.com/litespeedtech/lscache_wp/blob/master/src/base.cls.php
