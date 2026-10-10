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
