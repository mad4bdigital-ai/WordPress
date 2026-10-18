# Dynamic Optional Governance — proposed source delivery (18 October 2026)

This control is **not a bypass of security or release governance**.

## Existing platform components
The built-in `MAD4B_SCP_Policy_Resolution` operates with SINGLE_OWNER_HARDENED / ENTERPRISE_MULTI_OPERATOR / EMERGENCY_RECOVERY; `MAD4B_SCP_Governed_Runtime_Gates` handles exceptional high-risk runtime grants. Neither should be changed by advisory options.

## New controls
- WordPress Admin → MAD4B → Optional Governance, for administrators only, nonce-protected, audited, source-schema-bound, with persisted readback.
- Read-only `mad4b/optional-governance-status` Ability for dynamic interface catalogs.
- Four settings: `inherit` (follow current operating profile), `enforced` (preserve DEGRADED warning), `advisory` (show an optional notice without degrading health), `off` (mute optional notice; preserve raw evidence).
- Built-in options cover noncritical developer lane, developer breakglass-lane availability, external machine diagnostic ingress and provider closure reminders.
- New `optional_*` advisory definitions may be registered through `mad4b_scp_optional_governance_definitions`, and status signals through `mad4b_scp_optional_governance_observations`, subject to bounded schema and namespace checks; trusted source-owned built-in reason bindings cannot be overridden.
- Missing preference storage inherits the verified operating profile; malformed options, catalog or operating-mode evidence preserve the original diagnostic severity.
- Each policy displays a reason code, persisted mode and catalog digest. The options setting never changes any actual mutation/CI/certification policy.

## Explicit nonoptional gates
No setting here disables OAuth, WordPress `permission_callback`, owner identity or per-operation approval, exact-head SHA, CAS/drift protection, kill switches, audit, rollback/compensation, backups for real mutation, Production boundaries, GitHub required checks, Release Verdict, or provider behavioral write certification. A user expressing a clear intent is not itself a security authorization.

Staging/Production permissions remain separately managed by the existing authority control plane.

## Integration and acceptance
The operator reducer consumes the optional projection **only for DEGRADED advisory reasons** and includes `raw_degraded_reasons`, `optional_advisories`, `muted_optional_reasons`. Blocking/recovery arrays remain unchanged. A disabled warning must never be represented as completed certification. Test PHP 7.4/8.3, WordPress Admin nonce and audit, malformed saved configuration, dynamic extension identities, and unchanged hard blockers.

## Mode-aware optional policies
The shared `MAD4B_SCP_Policy_Resolution::current_operating_mode()` is the **only** source for the operating profile: SINGLE_OWNER_HARDENED defaults optional operator warning severity to `advisory`; ENTERPRISE_MULTI_OPERATOR and EMERGENCY_RECOVERY default it to `enforced`. Unsupported/missing profiles preserve the original warnings. Per-optional-policy modes are `inherit`, `enforced`, `advisory`, `off`. Dynamic extensions remain limited to `optional_*` advisory reasons.

The Admin Options Control renders non-optional security and Release Verdict gates as locked guidance and exposes effective mode/current profile. A read-only `mad4b/optional-governance-status` Ability is mounted into `mad4b-read` and `mad4b-chatgpt`; WordPress Options do not change GitHub CI or owner approvals.

Action Center renders optional advisory reasons separately and muted reasons in a collapsed evidence list, with a link to Options Control. Core blockers, recovery reasons, and independent release scopes remain unchanged.

## Phase 1 integration and risk corrections
Options Control now reuses the existing atomic CAS managed lease, including atomic expired-lock reclamation and fenced release; no unprotected expired lock delete. Registered dynamic policies cannot duplicate a diagnostic reason. The canonical Admin Navigation Manifest explicitly describes Options Control. Provider closure is no longer treated as a globally optional operator signal: whether a provider is optional depends on the live operation, so the current source preserves the original provider closure warning until an independent operation-specific classification is established. Existing security and release gates remain mandatory.

## Phase 2 — Remote Operation Parity
Read-only `mad4b/optional-governance-plan` computes one-policy intended mode, actor, exact site-origin digest, current operating profile, revision, policy catalog SHA and deterministic plan SHA. `mad4b/optional-governance-verify` checks persisted configured/effective modes. `mad4b/optional-governance-apply` is a first-party, bounded, Staging-only WordPress Ability mounted on the **existing** governed Write authority; it explicitly requires enrolled admin, current mutation gate, verified owner ChatGPT OAuth step-up, independently approved mutation ticket, exact typed confirmation, and fresh same-plan SHA. It calls the exact same CAS/audit/persist/readback service used by wp-admin. This is *not* remote arbitrary settings or Production authority. The named operation is advertised through the existing `Remote Operation Parity` catalog.

## Phase 3 — Operator dashboard parity
The local Options Control now shows the three remote abilities separately, current settings revision, full catalog digest and a conspicuous note that a registered tool is **not** an authorized Write session. The hermetic regression includes success/readback under a simulated enrolled Staging owner, repeat-SHA rejection and explicit Production denial. These are source and fixture tests, not ETG/ARE real-world execution evidence.

## Phase 4 — Unified Admin Settings UX
Options Control now opts into the existing `MAD4B_SCP_Admin_Settings_Persistence` browser workflow rather than building a second frontend. A narrow, explicitly allowlisted enqueue loads the standard assets on this one MAD4B admin screen. The nonce- and admin-capability-guarded AJAX handler and original `admin-post.php` fallback use the *same typed input validator* and `persist_modes` CAS/audit service. The browser must refresh the real options panel and match the source-issued `mad4b.admin-settings-view-readback.v1` field snapshot before reporting success; failed refreshes mark the form stale to prevent accidental repeated writes.
