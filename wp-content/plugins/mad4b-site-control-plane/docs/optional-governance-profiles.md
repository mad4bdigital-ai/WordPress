# Optional governance profiles — local options and remote MCP

This feature controls **optional operator advisory diagnostics only**. It is not an
"unrestricted" execution mode. Authentication, OAuth scopes, WordPress role
permissions, one-time Write approvals, exact runtime identity, provider
certification, audit, rollback, kill switches, staging/production separation,
repository CI and release gates remain mandatory.

## New-install default

An installation without a persisted `mad4b_scp_optional_governance_options_v1`
value starts in **Off** for all optional operator notices, including validated
future advisory extensions. The raw diagnostic evidence remains available and
mandatory degradation reasons cannot be suppressed. Sites that already have a
persisted option retain their configured modes; missing keys on such legacy
configurations still inherit the operating profile.

No option is written merely by loading a page, calling Status, or resolving the
fresh-install default.

## wp-admin

Open **MAD4B Control Plane → Optional Governance** and select **Apply to all
optional notices**:

- **Keep individual settings** — save only the per-policy values shown.
- **Off** — disable optional warning severity everywhere in the optional catalog.
- **Governed** — use enforced severity for optional warning presentation.
- **Advisory** — show warnings as nonblocking operator notices.
- **Inherit** — defer each warning to the operating profile.

Save using the existing administrator capability, WordPress nonce, typed
revision, catalog SHA, CAS lease, audit trail, persisted readback and rollback.
The bulk selector does not alter the protected-gates table or the grants held by
the current subject. Existing individual selectors still work.

## Remote MCP

The existing native abilities are used. For a whole-profile change, set
`policy_id` to `all_optional_notices`, and choose `mode` from
`off`, `enforced`, `advisory`, `inherit`.

1. Call `mad4b/optional-governance-plan` with `policy_id` and `mode`.
   Review `plan_sha256`, `site_origin_sha256`, `revision`, the number of
   affected notices and `change_required`.
2. On an enrolled **Staging** owner context with a verified ChatGPT bearer,
   authority step-up, a centrally approved one-time Write ticket and active
   execution fence, call `mad4b/optional-governance-apply` with the same ID
   and mode, `expected_plan_sha256`, the existing exact confirmation text
   and the source-owned ticket field. The client cannot grant itself Write.
3. Call `mad4b/optional-governance-verify` with the requested ID and mode.
   `verified=true` means each optional notice's **configured** mode matches;
   the response also reports the effective profile and revision.

A no-op must use Verify, not Apply. A stale plan must be re-planned. An
unauthenticated client, non-enrolled actor, Production runtime or direct PHP
callback cannot use Apply.

## Release and compatibility

No site configuration is changed by merging this PR. Staging validation and
the independently governed plugin release/installation workflow remain
separate. Remote mutation of the underlying real authorization/governance
engine is **not** part of this feature.
