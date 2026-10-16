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
- `litespeed/get-optimization-settings`: capability discovery and the relevant vendor-documented hooks and settings for investigation, **not** a fabricated settings value read.
- `litespeed/diagnose-script-order`: bounded PHP WordPress Scripts registry snapshot, with explicit warning it is not the browser runtime.
- `litespeed/settings-change-plan`: native-first investigation plan with explicit missing write/rollback contract, no mutation.
- `litespeed/verify-optimization`: browser acceptance checklist; does not assert it ran.
- Existing `status`, `purge-url` and `purge-all` remain unchanged in authority and behavior.

LiteSpeed's public integration documentation recommends using hooks rather than private classes. Documented hooks include `litespeed_can_optm`, `litespeed_optimize_js_excludes`, `litespeed_optm_js_defer_exc`, `litespeed_optm_gm_js_exc` and `litespeed_optm_uri_exc`. Their existence does **not** certify a persistent settings writer, schema, CAS safety or reversible state. Native settings writes therefore remain fail-closed.

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
