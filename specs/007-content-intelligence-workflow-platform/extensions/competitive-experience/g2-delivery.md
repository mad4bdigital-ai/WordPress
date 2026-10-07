# G2 Delivery — Recovery + Identity + Consent + History

Status: **repository implementation in progress; live acceptance pending**

Integration target: PR #258 (`spec/007-competitive-experience-20261006`)  
Implementation PR: #277  
Implementation branch: `feat/007-g2-recovery-identity-consent-history-r2-20261007`  
Baseline Hub head: `a9561e6aae09743c5de42e003640c9695f293af8`

## Scope

G2 covers the canonical workstreams:

- **CPUNDO / recovery** — T3911–T3915 — CE006, CE007.
- **CPNHI / identity** — T3916–T3920 — CE008, CE009.
- **CPOAUTH / consent** — T3921–T3925 — CE010, CE011.
- **CPHIST / history** — T3951–T3955 — CE025, CE026.

All G2 tasks remain **PARTIAL**. No task is marked DONE by repository code alone.

## Repository implementation delivered

### Recovery

- Adds a read-only recovery preview over existing mutation evidence.
- Exposes mutation identity, provider/target, before/after digests, expiry, verification state and a deterministic plan digest.
- Detects current post-state drift for the supported core post path.
- Resolves the current enrolled subject/agent when available and reports same-agent/recovery-policy blockers.
- Never exposes rollback payloads and never invokes the undo executor from this G2 surface.

### Identity / My Access

- Adds a read-only per-agent workspace composed over the existing exact-grant authority model.
- Shows effective access, conditional/denied outcomes, bounded redacted subject bindings and recent mutation activity.
- Does not create, widen or infer grants and does not mount discovered tools automatically.
- Adds a reviewed authority-reduction plan/apply/readback workflow for exactly three operations: disable agent, disable subject, and revoke one exact allow grant.
- Apply is bound to the reviewed plan SHA-256, agent revision where applicable, explicit `APPLY_AUTHORITY_REDUCTION` confirmation, post-change readback and append-only audit.
- The reduction workflow intentionally cannot grant authority, create agents, bind/enable subjects, restore authority, or auto-mount tools.
- Live session/token invalidation, browser journeys and concurrency acceptance remain pending.

### Consent / client compatibility

- Adds explicit read-only and reviewed-step-up presets.
- Generic full access is not supported.
- Exceptional server scopes are excluded from presets.
- New scope expansion requires external re-consent.
- Projects PKCE, refresh/revocation and bounded MCP client-profile evidence without dynamic registration authority.
- Real external client initialize/list/call and replay/origin/audience denial acceptance remain pending.

### Governed change history

- Adds bounded, paged mutation-history search by agent, ability, target, status and date.
- Returns redacted identity hints and typed operation evidence with before/after integrity hashes.
- Links request, parent mutation, approval, verification and recovery evidence.
- Emits site-scoped/export digests.
- History explicitly does not create rollback authority.
- Raw rollback payloads, secrets and token values are not exposed.
- Legal-hold lifecycle and richer field-level visual diffs remain pending.

### Operator workspace

- Extends Governance & History with:
  - Mutations & Recovery
  - Consent & Clients
  - Change History
- Recovery remains preview-only.
- G2 inspection tools are private, admin-only and read-only. The permission apply tool is also private/admin-only but is explicitly destructive and authority-reducing; its paired plan tool remains read-only.

## Safety boundaries preserved

- no Production authority;
- no Breakglass widening;
- no raw SQL or generic shell;
- no authority-expanding mutation ability; the only new write path reduces existing authority;
- no automatic grants;
- no wildcard grants or wildcard scope presets;
- no automatic discovery-to-tool mounting;
- no OAuth consent bypass;
- no hidden scope expansion;
- no rollback payload disclosure;
- history is not rollback;
- no cross-site authority inference;
- no runtime parity or live-browser claim from repository evidence alone.

## Remaining acceptance

1. Complete exact-head child CI for PR #277.
2. Prove the recovery readback/uncertainty path against representative Staging mutations, including drift and stale/expired evidence.
3. Prove live subject, role, exact-grant and session invalidation for the implemented reviewed authority-reduction plan/apply/readback flow, including stale revision/plan, concurrent changes and audit/readback failure behavior.
4. Prove external OAuth/MCP initialize, tools/list, tools/call, PKCE, refresh rotation, revocation, Origin/resource/audience and stale-generation denials.
5. Prove browser journeys for recovery, My Access, consent and history.
6. Extend visual diffs to certified typed fields/providers without exposing secrets/private content.
7. Define and certify governed legal-hold behavior before claiming legal-hold support.
8. Pass exact-head governance for the final G2 child head, merge G2 into #258 only with the required separate authorization, then run cumulative Hub CI and Staging acceptance.

Production remains unauthorized.
