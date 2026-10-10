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
| Change plans | Scoped exact native Ability executor with pending/approved ticket claim, one-use execution permit, pre-effect journal and provider readback | External auditor/key enrollment, executed native DB and Staging acceptance, conflict/undo verification |
| Bulk execution | Exact owner/site, 32-item cap, per-item native tickets, durable CAS checkpoint and 1–5-item Canary; resume now checks current mutation-approver policy | Real concurrent-writer acceptance, independent Canary review receipt, unknown-effect reconciliation and compensation |
| Workflows | Static cycle-safe DAG of sealed write-only intents may run via native-ticket bulk journal; mixed nodes cannot execute | Persisted cross-provider saga, signed triggers/webhooks, independent recovery and compensation |
| Privacy and UI | First-party RTL/English forms, no credential value in conversation | Real accessibility/browser/privacy/retention acceptance |

**Important:** `CSO_Changes::commit`, `CSO_Bulk::commit` and write-only `CSO_Workflows::run` now delegate to bounded native admission. They remain **OFF without explicit feature opt-ins, current write-runtime certification, separately approved exact native tickets, enrolled WordPress provider, durable journal and Staging acceptance**. The direct gateway/MCP never becomes an independent write authority. Missing conditions must deny with no effect. A private plan or provider readback is not independent approval or external-effect proof.

### External operational proof required

Run exact-head PHP 7.4/8.3 native matrix with `cso-drafts-runtime.php`, `cso-changes-runtime.php`, `cso-orchestration-plans-runtime.php`, plus real Staging MySQL concurrency, crash-after-provider-COMMIT recovery, actor and plugin-version drift, replay refusal, independent write readback and rollback. Synthetic tests alone cannot certify a live provider.

Keep the plugin inside Feature 007, without independent plugin, `master` merge, Production release, arbitrary SQL, general plugin-option writing or unknown provider authority.

### Incremental template and diagnostics source

- `CSO_Templates::plan` validates nonsecret recipe values against an exact current form descriptor and binds a first-party, ten-minute HMAC recipe to Site/Brand and actor. It does not publish, apply a template or modify site content.
- `CSO_Operations::doctor_plan` returns bounded blockers tied to the current site. It does not attest runtime, execute repair, start monitoring or grant Production promotion.
- `cso-storage-adapters-runtime.php` and `cso-template-doctor-runtime.php` are synthetic native fixtures wired into the PHP 7.4/8.3 CI matrix; queued Actions checks are not a PASS.

### Unicode consistency at the native post write boundary

The WordPress post driver now validates strict UTF-8 and uses Unicode code
points for the 200-character title and 500-character excerpt bounds,
consistent with the CSO form validator. Arabic text is not rejected merely
because UTF-8 uses multiple bytes per character. Raw inputs are separately
bounded by 8192 bytes; unsafe tags and empty titles still fail closed.

### Dual WordPress / Site Profile environment fence

The private Secrets handoff applies the same physical WordPress environment
check when preparing its bounded session and immediately before invoking a
native credential-store callback. An apparent Staging profile must not turn
a production WordPress runtime into a Staging secret-write surface.


Native CSO write admission and the core Post driver independently require
\`wp_get_environment_type()\` to match the enrolled Site Profile's
\`environment\`, and both must be exactly one of local, development or staging.
An implicit WordPress production default with a Staging Site Profile is a
**write blocker**, never a signal to auto-edit WordPress configuration. A
trusted Host deployment must set and independently verify the correct
\`WP_ENVIRONMENT_TYPE\` before canary acceptance; MCP discovery and
profile switches cannot set this trusted execution fact. This is source
hardening only, not a live Staging acceptance receipt.

### Recovery observation and canary authority

- `CSO_Native_Executor::reconcile_inspect` is a first-party, scope/actor-bound read-only diagnostic. It reports the native provider's current values/revision relative to an exactly sealed plan, even for an expired execution plan. It **never** repairs an uncertain journal, finalizes a ticket, replays a write, or calls provider observation independent third-party attestation.
- `CSO_Bulk_Runtime::commit` does not accept a mere `canary_reviewed: true` as authority. Resuming an existing `paused` checkpoint additionally requires current `MAD4B_SCP_Policy::can_approve_mutations()`, while every write still requires its own original approval ticket.
- An interrupted `inflight`, `reserved` or `needs_reconcile` journal is **not** permission to retry. Operator reconciliation must establish native/host result and separate grant evidence before any new operation.
- Only explicitly tagged, permitted WordPress `post_title` and `post_excerpt` fields have a source-owned driver. CPT relationships, Meta, WPML, SEO, media and commerce remain unsupported until independently enrolled drivers and tests are added.
- No claim of completed PHP 7.4/8.3 native test execution, Staging/Host/browser acceptance, deterministic ZIP, rollback or Production approval follows from source presence or queued GitHub Actions.

## CSO claim-uncertainty and signed recovery hardening — 10 October 2026

**Scope:** Feature 007 PR #258 only, not a runtime deployment. This replaces the
older statement that only `reconcile_inspect` exists. The branch also has
`reconcile_approval_plan` and `reconcile_finalize`; they are not self-authorizing
and are disabled until an external auditor is independently enrolled.

### Invariant and error windows

1. The unique pre-effect Journal key is persisted before any approval claim.
   If `claim_exact` reports an error, it may have committed its DB update
   before an acknowledgment was lost. The Journal must remain
   `needs_reconcile` or `reserved`, **never terminal `claim_denied`**.
   There is no second native write attempt.
2. If the original ticket remains `approved` after a reservation/interruption,
   a signed **ABSENT** proof, current unchanged provider revision/values,
   externally fenced writer and separate recovery approval may revoke that
   unused original ticket. An `approved` original can never be declared
   `used` or `applied` merely because recovery was requested.
3. If the original ticket is `executing`, independent `APPLIED` proof may
   finalize it as `used`; independently certified `ABSENT` proof may
   finalize it as `failed`. An already-terminal original is accepted only
   with a congruent signed outcome.
4. The recovery ticket is separate, payload-bound, claimed once and finalized.
   Journal CAS pins its identity, exact external proof digest and outcome
   before terminalization. A retry may only resume those pinned identifiers
   and must re-check signed proof, scope, provider values and ticket states.
5. A lost ticket-finalization acknowledgment or terminal-Journal CAS conflict
   must remain **nonreplayable**, with an idempotent same-proof recovery route.
   Changed proof, mismatched actor/target/site, missing auditor key, invalid
   signature and concurrent revision drift fail closed.

### Source regression coverage

The existing `tests/cso-native-executor-runtime.php` test now exercises:
- a native callback throwing after effect, independently signed APPLIED proof,
  pending recovery denial, separate approval, and both tickets finalizing;
- a crash before the original approval claim, signed ABSENT proof and revocation
  of the unused `approved` original without a native effect;
- claim status committed but acknowledgment lost, signed ABSENT proof and
  terminal `failed` original without write replay;
- forged-signature denial, lost recovery-finalization acknowledgment,
  terminal Journal CAS failure and exact-proof restart.

The fixture generates an **ephemeral RSA key at test runtime**. It does not
contain a production signer, assert that an independently operated auditor is
live, or substitute for a real WordPress/MariaDB race/fault experiment.

Existing `.github/workflows/feature-007-spec-ci.yml` runs PHP lint and the
fixture on PHP 7.4 and 8.3. A queued job is still **NOT PASS**.

### Non-waivable operational acceptance

| Gate | Evidence required | Current classification |
| --- | --- | --- |
| Native language matrix | Executed PHP 7.4 and 8.3 lint/fixtures on exact candidate SHA | Pending external runner |
| DB claim collision | Two connections, shared MariaDB/MySQL, one winner, no replay | Not witnessed |
| Crash after durable reservation | Process termination before and after Claim COMMIT; final Journal/ticket readback | Not witnessed |
| Crash after provider effect | Real WordPress write and hooks, lost COMMIT acknowledgment, independent readback | Not witnessed |
| Independent signer | Private key outside WordPress/MCP, audited trust enrollment, rotation/revocation | Not enrolled by source alone |
| Staging MCP | Exact build/site/environment bindings, read/approval/write/readback, rejection tests | Not witnessed |
| Advanced connectors | CPT/Meta/SEO/WPML/media/commerce per-provider authority and rollback | Not certified |
| Distribution | Exact-head ZIP, clean install, downgrade/rollback, independent hash match | Not certified |

Never simulate an external auditor by signing inside WordPress itself. Never
derive `WP_ENVIRONMENT_TYPE` from a Site Profile, replay an uncertain write,
lift Production gates, or mark the whole PR ready based on this source patch.
