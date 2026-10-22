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

## Scenario and failure-mode coverage

The controller supports three **targets** and four **modes**. Profiles apply
only to vetted optional operator notices, never to mandatory safety controls.

| Target | Input | Behavior |
| --- | --- | --- |
| One notice | `policy_id=developer_lane` | Change exactly that notice |
| Explicit subset | `policy_id=selected_optional_notices` and a unique `policy_ids` list | Change those notice IDs atomically |
| Entire catalog | `policy_id=all_optional_notices` | Change every registered optional notice, including validated extension notices |
| Mixed configured profile | Per-notice modes, or separate approved edits | Persist exact individual settings |
| Site default | Bulk Off on first install | No option mutation until explicitly saved |
| Existing legacy saved settings | Missing per-notice IDs inherit | Never overwrite an installed site's configuration on upgrade |

The four modes: `off` (mute only optional operator warnings),
`enforced` (optional warnings count as degraded), `advisory` (notice only),
and `inherit` (compute from the site's authoritative operating profile).
A bulk or selected profile is **not a bypass of a core gate**.

### End-to-end behavioral scenarios

| Case | Expected behavior / safety invariant |
| --- | --- |
| Fresh install without saved option | Off effective and configured for optional notices; raw mandatory blockers unchanged |
| Existing options or upgrade | Preserve revision and each saved mode; missing keys continue legacy inheritance |
| Single notice | Modify only that policy, not other configured values |
| Entire catalog | Update all current optional notices in one revision and one audited transaction |
| Chosen subset | Update an explicit unique, validated list; leave unselected notices untouched |
| Partial subset where some entries already match | Count affected and changed independently; skip unchanged entries |
| Empty, duplicate, unknown, reserved, malformed, non-list or over-limit IDs | Fail before authorization or option mutation |
| Extra fields, list with non-selected selector, unsupported mode | Reject with typed failure; never interpret as an implicit wildcard |
| Different ID order, same selected set | Canonicalize selection so plan digest remains stable |
| No-op selected, single or all change | Refuse Apply; use read-only Verify without spending approval |
| Mixed effective profile | Show mixed rather than incorrectly claiming all Governed or Off |
| Admin partial edit | Keep individual changes when bulk selector is Keep |
| Admin bulk change | Explicit save overrides individual selectors only at that submission |
| Admin invalid bulk, stale revision or catalog fingerprint | Reject and require fresh page/plan |
| Non-administrator admin POST/AJAX, invalid nonce | Deny before persistence |
| Anonymous or unenrolled remote actor | Deny Apply; no bypass through Plan/Verify |
| Invalid bearer, missing authority step-up or wrong OAuth client | Deny Apply before a ticket is claimed |
| Missing governed Write or inactive execution fence | Deny Apply |
| Remote direct PHP callback (without Ability wrapper) | Reject even when its inputs resemble an approved request |
| Stale plan after another local/remote save | Reject based on revision/hash and require re-plan |
| Wrong site origin, multisite blog, actor or catalog identity | Reject exact plan replay; no cross-tenant change |
| Staging authorized owner + valid ticket | Apply through native Ability fence, audit, CAS lease and persisted readback |
| Production remote Apply | Denied by existing policy; Production read-only inspection may remain available |
| Existing Production local administrator settings | Continue using protected wp-admin capability/nonce and site audit only; no remote Production mutation |
| Lease lost while recording audit | Refuse mutation; release owned lock |
| Persisted readback fails or audit recording fails | Attempt CAS-compensated rollback; otherwise return uncertain outcome and request inspection |
| Transport timeout after Apply | Do not blindly retry; call Verify to discover persisted state and revision |
| Invalid options file, bad catalog, unknown operating mode | Keep raw warnings, return strict/invalid status; never grant privileges |
| Dynamic optional extension | Only a validated namespaced advisory definition is configurable; core safety reasons cannot be shadowed |
| Reinstall / import / removal of a notice | Re-evaluate the source catalog; do not auto-import untrusted policy IDs |
| Two sites with different profiles | Independent WordPress option state, plan site hash and blog ID |
| Repeated read or admin page GET | Do not write options, advance revision, or imply an approval |
| Staging package upgrade / rollback | Revalidate schema, catalog digest, source build and independent WordPress runtime |
| GitHub CI or release approval missing | PR remains unmerged and does not change running sites |

### Remote selected-batch request

Read the site status first, then use the following **shape** to obtain a plan:

```json
{
  "policy_id": "selected_optional_notices",
  "policy_ids": ["developer_lane", "machine_ingress"],
  "mode": "advisory"
}
```

The response carries an exact `selected_policy_ids_sha256`,
`affected_optional_notices`, `changed_optional_notices`, `revision`,
`catalog_sha256`, `site_origin_sha256`, and `plan_sha256`.
The caller must review that exact plan and obtain a centrally authorized
one-time Write ticket. Apply reuses the *same* `policy_ids`, mode and
`expected_plan_sha256`; Verify reports `mismatched_policy_ids`.

A temporary MCP outage does not prove whether an Apply succeeded.
Resume with a read-only status/Verify; if uncertain, **never** replay an old
approval ticket or infer that the original mutation rolled back.

### Protected governance inventory

The following are deliberately **not** controlled by Off, Governed, selected
batches, optional extensions, or any remote preference Apply:

- OAuth authentication, access-token scope validation and WP user/capability
  permissions;
- exact owner operation approval, GitHub branch policies, CI and release verdicts;
- Write/Developer/Breakglass grants, Production authorization and execution
  fences;
- provider certification, transport endpoint admission and rate/abuse limits;
- audit integrity, CAS/rollback, backup/recovery, incident kill switches, and
  exact environment/runtime identity checking.

A future *operating model* selector for these capabilities must be separately
designed and security-reviewed. This PR does not secretly introduce it.

### Test evidence boundaries

CI's hermetic PHP behavior suite and trusted Python launcher exercise source
contracts without live WordPress credentials. Passing CI does **not** prove
external OAuth/MCP transport, installation, hosting reachability, per-site
Production approval, or a signed Staging acceptance result.
