# MAD4B CSO — Runtime wiring, deployment controls and acceptance

**Scope:** Feature 007 / CSO01 opt-in runtime in the existing Site Control Plane plugin. This document does not grant a new WordPress, OAuth, MCP, provider or Production authority.

## Actual source coverage

- `MAD4B_SCP_CSO_Scope`: exact deployed Site Profile + Brand/Profile revisions + WordPress actor/Blog/Network + HTTPS origin, read permission and environment fence, bounded input, conversation secret-key redaction, and HMAC-sealed first-party handoff state.
- `MAD4B_SCP_CSO_Registry` and `MAD4B_SCP_CSO_Forms`: **read-only** discovery and typed form preparation/validation/help via existing CSO01 canonical descriptors. Every form value is rechecked against the current descriptor digest. No arbitrary wp_options, post_meta, database row or plugin setting editor is created.
- `MAD4B_SCP_CSO_Gateway`: one strictly origin-bound, cookie+nonce first-party REST route; public read-only Abilities are only registered for implemented handlers. Every other planned CSO action (single write, bulk, workflow, promotion, etc.) is rejected `IMPLEMENTATION_NOT_CERTIFIED`, including direct dispatch. Private secret session may only originate from authenticated first-party REST, never the generic MCP tool.
- `MAD4B_SCP_CSO_Form_UI`: first-party accessible RTL/LTR typed validation page, **never** an execution/commit UI. This implementation does not assert external ChatGPT UI resource acceptance.
- `MAD4B_SCP_CSO_Secrets`: existing provider-owned encrypted/native secret handoff with explicit consent, short-lived one-use same-site session, credential POST directly to WordPress only, strict native Ability and provider behavioral certification, CAS journal and independent lifecycle readback. It requires a correct release/source/runtime/restore identity and does **not** accept plaintext from ChatGPT. If a certified provider does not implement the complete lifecycle, handoff is unsupported.

## Feature flags

All advanced functions remain **OFF by default**. A trusted deployment configuration (not a conversation input or WordPress option edit) can define:

```php
define( 'MAD4B_CSO_ENABLED_COMPONENTS', array(
    'discovery' => true,
    'forms' => true,
    'secrets' => false,
    'single_write' => false,
    'bulk' => false,
    'workflow' => false,
    'multisite' => false,
    'operations' => false,
    'production_proposal' => false,
) );
```

These opt-ins are effective **only on explicitly enrolled WordPress Dedicated local/development/Staging sites with HTTPS and governed actor read rights**. Do not expose any such configuration constant through conversation forms. Even if a future disabled component is set to true, the Gateway still denies its route until code and certification implement it. No flag creates a grant.

Secrets additionally require deployment-managed `MAD4B_CSO_SOURCE_SHA` (exact 40-hex HEAD), `MAD4B_CSO_PACKAGE_SHA256`, `MAD4B_CSO_RUNTIME_GENERATION` (each 64-hex), and a positive `MAD4B_CSO_RESTORE_EPOCH`. These should be *verified against* the installed ZIP and runtime by an external attester; supplying a shape-valid constant does **not** certify deployment. Never hardcode or recycle receipt identities across restored sites.

## CI and acceptance

The `cso01-governed-interaction-native` job lints all production CSO PHP, runs fake-provider and authorization tests on PHP 7.4/8.3, executes Node DOM validation and Spec Kit validation. **A queued job is not a PASS.** This is synthetic source-level acceptance, not a real WordPress/Host/DB/browser/ChatGPT transport receipt.

Before enabling a selected Staging canary, independently verify:
1. Exact installed plugin HEAD, version, package bytes, MCP Adapter pair and runtime generation/restore epoch.
2. Discovery in live MCP with current WordPress actor; no default exposure of secret, draft, bulk or write routes. Revocation and origin mismatch deny correctly.
3. Browser form from enrolled HTTPS origin with nonce/cookie, Arabic RTL, screen reader, keyboard and stale schema update. Validate arbitrary Unicode, multiselect, typed JSON and secret-key rejection.
4. A reviewed, independently certified provider's direct first-party secret configure, verify, rotate, cutover, revoke and independent readback with fault injection, replay denial and zero plaintext in logs/ChatGPT/database audit.
5. Any separate future write module must execute through *existing* MAD4B native approved write callbacks with exact target revision, replay protection, audit, compensation and independent readback. **Current CSO Gateway does not include such a certified module.**
6. Real MySQL/MariaDB concurrent sessions and crash-after-external-write recovery, multiple Blog/Network sites and object-cache isolation, privacy retention/export/erasure, accessibility, performance, rollback and full replay-resistant trust verification.
7. A final deterministic ZIP with matching source digest, native test receipts and separate Production owner authorization.

## Status and blockers

- **Source wired:** gated read catalog/form presentation/validation, first-party secure-secret ingress + native provider interface.
- **No live-certification:** runtime PHP/Node job execution, host/WordPress Staging, provider enrollment, MCP/REST round trip, browser and ZIP tests.
- **Not implemented by this source change:** general SQL/plugin option writes, `CSO_Changes`, durable drafts, `CSO_Bulk`, `CSO_Workflows`, `CSO_Triggers`, `CSO_Domain_Plans`, `CSO_Operations`, `CSO_Templates`. These remain disabled and unadvertised. Implement and review each as an isolated branch/slice before claiming full CSO closure.

**Release verdict:** non-authorizing implementation progress; no plugin publication, `master` merge, Staging/Production promotion or "fully closed" claim from source alone.

## Incremental P0/P1 source delivery — 10 October 2026

The current PR branch additionally includes source-level modules, **not** operational certification:

| Domain | Implemented source behavior | Still blocked |
| --- | --- | --- |
| Storage adapters | Explicit deployment-owned provider registry, typed descriptor, exact target/site scope, registered native Ability identity and permission-checked nonsecret read snapshot | Real provider certification, atomic target revision enforcement, independent post-write readback |
| Dynamic forms | Canonical descriptor, typed validation and bounded literal enum suggestions | Live CPT/taxonomy/user/relationship autocomplete, conditional mutations and rights-scoped search |
| Private drafts | First-party create/load/save/delete using bounded nonsecret values, actor/site scope HMAC, fixed option names and SQL CAS | Retention worker, GDPR export/delete evidence, real two-writer concurrency proof |
| Change plans | Private readback, typed diff digest, exact descriptor/revision, sealed nonexecuting plan | Native write executor, separate approval grant, durable effect journal, verified undo |
| Bulk preparation | Exact owner/site for each plan, 32-item cap, duplicate refusal, 1–5 Canary | Durable checkpoint, independent per-item executor, restart recovery and compensation |
| Workflows | Bounded DAG compiler, topological order, cycle and secret rejection | Native DAG runner, durable saga, signed triggers/webhooks, pause/resume |
| Privacy and UI | First-party RTL/English forms, no credential value in conversation | Real accessibility/browser/privacy/retention acceptance |

**Important:** `CSO_Changes::commit`, `CSO_Bulk::commit` and `CSO_Workflows::run` currently always refuse execution. Flags never confer write grants. Private plan is not an approval or a verified external effect.

### External operational proof required

Run exact-head PHP 7.4/8.3 native matrix with `cso-drafts-runtime.php`, `cso-changes-runtime.php`, `cso-orchestration-plans-runtime.php`, plus real Staging MySQL concurrency, crash-after-provider-COMMIT recovery, actor and plugin-version drift, replay refusal, independent write readback and rollback. Synthetic tests alone cannot certify a live provider.

Keep the plugin inside Feature 007, without independent plugin, `master` merge, Production release, arbitrary SQL, general plugin-option writing or unknown provider authority.

### Incremental template and diagnostics source

- `CSO_Templates::plan` validates nonsecret recipe values against an exact current form descriptor and binds a first-party, ten-minute HMAC recipe to Site/Brand and actor. It does not publish, apply a template or modify site content.
- `CSO_Operations::doctor_plan` returns bounded blockers tied to the current site. It does not attest runtime, execute repair, start monitoring or grant Production promotion.
- `cso-storage-adapters-runtime.php` and `cso-template-doctor-runtime.php` are synthetic native fixtures wired into the PHP 7.4/8.3 CI matrix; queued Actions checks are not a PASS.
