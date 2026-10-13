# ETG Staging — MAD4B Admin Surface & Action Drift Audit

Date: 2026-10-13. **Scope: read-only ETG Staging inventory + source review on PR #258.**
No Production write, no arbitrary wp-admin POST, no live button clicks, no
certification or browser session was used.

## Exact live census

The WordPress Admin Control Census returned **18 registered page routes**,
**82 registered admin-post hooks** (45 name-prefixed `mad4b_`; 37 external),
and **470 registered AJAX hooks** (12 name-prefixed `mad4b_`).
Provider/third-party controls must not be claimed as MAD4B-owned solely
because the site-wide census discovered them. The census explicitly marks
`wp_admin_ui_binding=route_registered_not_browser_certified`,
`mcp_transport_verified=false` and `write_verified=false`.
It cannot see rendered JavaScript-only buttons or prove click outcomes.

The passive Surface Coverage inventory separately reported 961 observed
surfaces across multiple classes, highest reported coverage level 2,
and `ui_discovery_proves_execution=false`. Its AJAX and block inventories
are truncated by the per-kind snapshot bound; use the paginated control census
for registered AJAX counts.

## All 18 registered WordPress page routes

| Registered page slug | Screen | Audit observations / next gate |
|---|---|---|
| `mad4b-control-plane` | Governance & History | Source render is read-oriented; inventory/authority proof is distinct from live mutation |
| `mad4b-operator-control-center` | Action Center | Status summary; acceptance requires next-action links to follow exact fresh blockers |
| `mad4b-control-plane-site-profile` | Site Profile | **FIXED IN PR:** unsigned historic `mad4b_site_profile` query notice, now signed and fresh site-state-bound; verify actions with new request |
| `mad4b-control-plane-connection` | Connection | Read-only light snapshot and JS-only deep endpoint test; validate deep-worker POST/nonce, expiry and feedback in browser |
| `mad4b-control-plane-chatgpt` | ChatGPT Connection | OAuth/Production read-only admin form must retain separate capability/nonce and owner step-up |
| `mad4b-control-plane-oauth-canary` | OAuth Check | Browser challenge loop requires real browser acceptance, not just registered route |
| `mad4b-control-plane-context` | Brand & Context | **FIXED IN PR:** stale query error, pending-revoke cross-panel false green, native retry action snapshot/confirmation/cooldown; real OAuth 400 remains pending |
| `mad4b-search-intelligence` | Search Intelligence | Source profile save uses CAS, nonce, signed success; search controls have typed preconditions but no browser click certification |
| `mad4b-control-plane-content-pipeline` | Content Pipeline | **FIXED IN PR:** unsigned historical error query string; now short-lived settings revision/digest-bound |
| `mad4b-approval-decisions` | Approval Decisions | Existing exact payload-derived nonce + signed result; replay/race browser acceptance still required |
| `mad4b-adapter-coverage` | Provider Coverage | Read-only provider closure display; availability is not write certification |
| `mad4b-runtime-components` | Runtime Components | **FIXED IN PR:** older release channel (rc.95 vs installed rc.96) no longer offers misleading generic update button; handler still independent |
| `mad4b-control-plane-skills` | Managed Skills | **FIXED IN PR:** unsigned historic export error; export/edit/reconcile need actual artifact and filesystem readback |
| `mad4b-control-plane-performance` | Performance | Index apply is separate admin-post action, must verify exact schema, approval and rollback independently |
| `mad4b-ai-knowledge-workspace` | AI Knowledge Workspace | Registered live, but no specialized definition in Admin Workspace presentation metadata; inspect rendered controls |
| `mad4b-assistant-workspace` | Assistant Workspace | Registered live with generic fallback presentation; verify handoff links/state against workflow |
| `mad4b-growth-providers` | Growth Providers | Registered live with generic fallback presentation; source ownership and controls need deeper provider-level review |
| `mad4b-operational-remediation` | Operational Remediation | Registered live with generic fallback presentation; action replay/rollback warrants high-priority browser test |

The repository's Admin Workspace additionally declares
`mad4b-browser-acceptance`, which was *not returned* among these 18 ETG
registered pages. This is a registration/activation difference to diagnose,
not proof of a broken page.

## Priority defects versus proof boundaries

**Confirmed and patched in source**:
- Context Authority: pending Google revoke was simultaneously presented as
  usable Google sign-in/connected; historic unsigned error query persisted.
- Site Profile, Content Pipeline and Managed Skills: historic unsigned admin
  action feedback could outlive the state. Signed receipts are now bound to
  the actor/session/site/time and where relevant exact persisted state.
- Runtime Components: stale cached release pointer could display a generic
  update CTA pointing toward an older Control Plane release.
- Native Retry revoke: nonce/capability already existed, but state snapshot,
  explicit confirmation and retry interval were missing; now guarded.

**Not concluded**:
- The 45 `mad4b_` admin-post hooks and 12 AJAX hooks are inventoried, not
  individually exercised. Every actionable UI must be traced from event to
  callback, permission, nonce or bearer authorization, exact revision/CAS,
  provider outcome, readback, and replay/rollback safety.
- Controls of WPML/Elementor/Rank Math belong to those plugins even if they
  appear on the same wp-admin screens. WPML taxonomy sync and Rank Math
  licensing require independent reviewed provider actions.
- The ETG installed source remained `b27c3169...` in the last readback;
  PR #258 source commits are **not** installed or accepted.
- Browser view and PHP lint/runtime/CI were unavailable here; registered
  routes, screenshots and source markers must not be described as complete
  interactive acceptance.

## Minimum acceptance matrix for every state-changing button

1. **Visibility:** consistent live state; no active CTA when the operation
   is pending, unavailable, stale or points toward a downgrade.
2. **Transport:** exact POST/AJAX or certified MCP ability, no GET mutation.
3. **Authority:** manage-options is not equivalent to OAuth Step-Up;
   check the actual operator capability, nonce/bearer and specific ticket.
4. **Identity:** exact Site Profile, source, plan/manifest, revision and
   freshness; replay old forms must fail rather than act on new targets.
5. **Side effect:** typed allowlisted operation, bounded retries/cooldown,
   no second uncertain external effect.
6. **Result:** provider response is evidence, not automatically success;
   persisted readback, request-independent proof and audit receipt.
7. **Feedback:** short-lived signed notice; stale query messages disappear
   without deleting real provider/ownership/translation blockers.
8. **Update:** active update installs only a certified compatible candidate,
   respects rollback, reruns readiness and never silently expands grants.

## Regression artifacts

Source-only `tests/admin-action-notice-source-contract.py` checks 7
representative admin files against notice and stale update CTA regression.
More precise Context behavior tests:
`tests/context-live-post-update-reconciliation-runtime.php`,
`tests/context-google-retry-admission-runtime.php` and
`tests/context-oauth-lifecycle-runtime.php`.

Execute on the **exact candidate SHA**:
`python wp-content/plugins/mad4b-site-control-plane/tests/admin-action-notice-source-contract.py`;
`php -l` on touched PHP; run PHP test files, Staging ZIP builder, full
CI or signed independent attestor, and post-update browser acceptance
against every applicable page/action. The source-only tests cannot
certify browser controls.

## Outcome

GitHub source remediation is in-progress; **site acceptance is NOT complete**.
Do not declare all 18 pages operationally tested or clear Google pending
revoke / Brand Context ownership quarantines automatically. Production
mutations, Developer, Breakglass and unreviewed provider actions are
outside scope.

## Deep verification addendum — 2026-10-13, read-only live + exact PR source

### Evidence and scope limits

The live `mad4b/session-safe-diagnostics` response returned
`state=subject_not_ready`, `operator_summary.state=BLOCKED` and
`write_authority_not_effective`. Adapter basic lifecycle is ready and
Site Profile is Staging, correctly enrolled with `revision=6`; **this
is not an effective Write approval**. Source installed at the live site:
`0.4.0-rc.96` / `b27c316967270ee980e774a081e1beef084f07e3`,
Adapter `0.7.0`.

`mad4b/staging-write-authority-convergence-status` isolated the precise
effective-Write blockers: 95 mounted exact abilities, 0 missing currently
mounted grants, **2 stale grants** for `mad4b/wp-native-build-start` and
`mad4b/wp-native-self-approve-install` not in installed runtime inventory,
and **unbound candidate identity**. The exact reconciliation *plan* is
read-eligible; executing it requires independent owner authority. Do not
silently restore stale write authority on plugin update.

`mad4b/runtime-release-set-status` returns target `control_plane=[]`,
`release_set_sha256=""`, `control_plane_current=false`,
`runtime_readback.state=target_profile_mismatch`. The actual control
plane `rc.96` and adapter `0.7.0` exist, but the stable update channel
does **not** prove an exact compatible control-plane target. The UI guard
now hides the one-click update CTA if target version/SHA/release digest is
missing or older. Do not interpret `update_or_refresh_required` as an
authorization to downgrade or install an undefined target.

`mad4b/google-revocation-status`: `PENDING_SAFE_RETRY`,
`google_revocation_http_400`, `remote_revocation_confirmed=false`.
`mad4b/context-authority-status`: `ready=false`,
`governed_context_source_missing`, `governed_context_assets_missing`,
1 quarantined source and 4 quarantined assets. Those remain genuine
provider/human review blockers irrespective of UI notice cleanup.

`mad4b/skills-runtime-certification` returned `ready=true` and no
blockers. However the **Operational Remediation** live page advertised
a stale `mad4b/skill-runtime-certification` readback (singular), the
sole unregistered readback in 17 action paths over 13 blocking gates.
Canonical code itself already uses the registered plural form elsewhere.
Corrected its mismatched remediation action on the PR source.

`mad4b/browser-acceptance-capabilities` is **read-only discovery**:
external browser agent required; it does not issue certification or execute
the Chrome/WordPress admin session. Remote Desktop Commander was offline
at the time of this audit. WordPress **registered page census** returned
18, excluding the Browser Acceptance Setup page, although its plugin boot
and menu class are present. Cause in source: its route was registered only
inside `admin_menu`, which MCP/read-only requests need not execute.
Declaring the exact `manage_options` route outside `admin_menu` fixes
remote inventory discoverability without adding an admin execution path.
After a certified Staging update, fresh census should return **19 pages**
if there are no unrelated changes.

The **Governance & History** overview labeled the *global mutation gate*
as `Mutation effective for request`, even though live exact Staging Write
is blocked. It now distinguishes global mutation configuration from
`Exact Staging Write checkpoint ready` and clearly marks per-ability
authorization as **not evaluated**.

### Source changes made during this deep review

- Declare Browser Acceptance Setup exact authorized route in the static
  registry outside wp-admin menu callbacks.
- Register specialized Admin Workspace navigation descriptions for AI
  Knowledge, Assistant Review, Growth Providers and Operational Remediation;
  this is presentation only and does not certify those pages.
- Runtime Components: deny the update CTA for a missing Control Plane SHA,
  version or release-set digest as well as a clear downgrade.
- Source-owner Site Profile `handle_disable` and legacy migration now
  require HTTP POST. Disable-authority form must supply the exact decimal
  expected revision, instead of dropping a missing value to `null` and
  bypassing the domain's optional revision comparison.
- Context administrative forms and AJAX settings now require HTTP POST.
  OAuth provider redirect callbacks remain independent GET callbacks with
  their dedicated state checks.
- Browser Acceptance save, Content Pipeline save, Search profile save and
  Search control mutation now each require explicit POST in addition to
  pre-existing owner/nonce/typed-state checks.
- Operational Remediation references the exact registered plural Skills
  certification ability.
- `tests/admin-post-and-route-boundary-source-contract.py` records
  permanent source-level guards for **eight** POST handlers and page
  declaration/revision/update conditions.

### Performance evidence, not benchmark certification

A live `session-safe-diagnostics` observation reported 6,631 ms total
request elapsed, 468 DB queries, 6,659 included PHP files, ~58 MiB peak
memory and 10 ms plugin bootstrap. This is **one request**, not an
attributed MAD4B slowdown and not a valid broad performance PASS/FAIL
without a previous exact-release baseline and repeated samples.

### Critical release blockers / closure evidence

**P0**: exact source/manifest integrity — the G9 bootstrap evidence row
continues to say **49,114 bytes**, while a fresh head showed **49,362
UTF-8 bytes**. A moving PR branch is causing integrity drift; the v9
builder must **remain fail-closed**. Freeze/finalize the source first,
run the existing exact-inventory fingerprint repin explicitly as an
authoring step, review diff, independently run G9 verifier, then build an
immutable HEAD with PHP lint and full test suite. Do not auto-repin during
verification, do not install partial ZIP.

**P0**: exact candidate/95-grant reconciliation and retirement of 2 stale
grants under reviewed owner approval; no write, developer or breakglass
expansion.

**P0**: runtime release-set target digest/version/commit missing; **no
generic update CTA** or implied certification.

**P1**: Browser Acceptance real authenticated browser execution and
independent evidence receipt; 18→19 registration check is necessary but
insufficient. Test page display, submenu access and URL navigation using
an authorized WordPress administrator.

**P1**: Code-source checks are not PHP parsing/runtime or browser
acceptance. Run lint, source-contract, dynamic fixture tests, exact-head
Staging build, and actual button POST/GET/replay tests for each owned
action with controlled test data and independent DB state readback.

**P2**: performance repeated exact baseline sampling; third-party WPML
taxonomy synchronization and Rank Math account status use their own
provider controls and cannot be marked resolved by MAD4B upgrading.

**No real WP mutation, OAuth revoke, Production mutation, Developer or
Breakglass grant was performed during this audit.**
