# Governed runtime update recovery

Runtime release-set status and planning distinguish a failed transaction from an
active transition. Reads never cancel, retry, finalize or install anything.

A fresh exact plan can select `reconcile_failed_transaction` only for a known
failure before component replacement, with all original Control Plane identity
fields and Adapter version unchanged, no prior component success, no active
maintenance lease and no active continuation. Explicit apply preserves the signed
failure as cancelled and records append-only reconciliation evidence. It returns
`failed_transaction_reconciled_replan_required`; a new plan and independently
admitted apply are required to install a package. Unknown outcomes, pending
workers, partial replacements, changed installed identity and corrupt signatures
remain blocked. A failure label or old timestamp is never enough proof.

Transaction transitions use exact database compare-and-swap with readback. A stale
worker cannot overwrite or delete a newer worker's transaction. Finalization
checks the Control Plane disk identity as well as the Adapter runtime evidence.

The existing `mad4b/runtime-release-set-bootstrap-apply` operation also has one
explicit first-party Enrollment broker policy. Clients can discover `runtime
update`, obtain current operation/schema/policy digests and invoke the fixed
Enrollment transport when the optional direct tool was omitted from tools/list.
The census verifies the original Runtime Release Set permission/execute callback
pair, including genuine first-party wrappers. Copying its metadata onto another
callback is denied. Enrollment retains exact OAuth scope, ChatGPT client, subject,
Staging origin, confirmation, plan and child execution-fence validation. Errors
return a bounded reason and require independent status readback, never replay.

Inside an exact Runtime Release Set apply, a private request-local component
context selects the coordinator's bootstrap admission after download and again
before replacement. Standalone candidate-drift bootstrap keeps its original
eligibility rules. Current authority follows the existing post-update continuation
policy; no new authority is created by the component context.

These changes do not certify a live deployment. An older installed package still
needs its own supported update route before these recovery paths become available.
Legacy failed transactions lacking matching identities or premutation proof need
independent operator investigation; this implementation does not clear them by age.

CI exercises recovery and broker admission on PHP 7.4, 8.3, 8.4 and 8.5, including
unknown outcomes, signature corruption, worker races, changed identities, audit
failure, missing scope, foreign client and Production rejection.

## Native Apply visibility and host safety rejection

The native plan returns nonauthorizing execution guidance outside its package
plan digest. Current Write Authority selects `mad4b/control-plane-native-apply`,
using the existing `mad4b/write-execute` transport when the direct tool is absent.
The original exact approval, schema/classification receipt and execution checks
remain required. Server mounting never proves host tools/list visibility. A
missing normal transport does not select candidate-drift Bootstrap; the plan
provides the authenticated native Runtime Update workspace instead.

A client-reported platform safety rejection is distinct from a WordPress
permission denial. The router offers no alternate remote executor or automatic
retry, and does not infer that delivery stopped before mutation. Read independent
update status and receipts first. The native administrator handoff still requires
a fresh plan, nonce, backup and exact installed identity verification. The plugin
cannot remove a host safety decision. No such rejection was reproduced or
bypassed by these hermetic source tests.

## Manual package replacement: independent readiness layers

Explicit Write Authority current-status reads provide binding-only repair advice
when the deep grant snapshot and persisted authority are clean and candidate
identity drift is the sole blocker. They never recreate grants or authorize a
rebind: existing exact-plan, sealed-baseline, environment, actor and transport
preflights remain mandatory. Missing, revoked or drifted authority is not
binding-only. No grant count or site name is hard-coded.

Explicit Skills live inspection distinguishes healthy current-build runtime from
historical certification. A healthy inspection requests recording current-build
evidence through the governed lifecycle without reconciling files. Historical-only
or build-unbound evidence cannot prove live readiness. Actual seed/provider drift
still requires inspection before managed reconciliation.

Operation discovery preserves bounded certification level, activation stage,
declared release ring and artifact binding when present. Missing ring information
remains unknown. Valid Write grants and a repaired candidate binding do not certify
a discovered/shadow provider or promote its ring. Source Scan retains all original
provider transport gates and evidence requirements.

Drive connection guidance separates pending refresh, refresh failure/backoff,
credentials requiring owner reconnection, and pending revocation. Read/write scope
availability does not prove refresh success, remote continuity or provider write
certification. Status reads never refresh credentials or make provider requests;
governed renewal and an independent provider read remain necessary.

These generic read-only diagnostics have hermetic PHP regression coverage in the
runtime CI matrix. They are not live acceptance evidence or Production approval.

## Brand Authority diagnostic transport incident

Live discovery of Brand Core abilities succeeded on both connected Staging sites,
while several identity/status/info requests returned opaque MCP `-32603` errors.
The connected tool-info signature omitted `prepare_execution`, although the
installed repository implementation required opting into preparation to obtain
all read-execute pins and its signed receipt. Tool-info now prepares a single
eligible read by default, including legacy callers sending only ability_name.
An explicit false retains metadata-only inspection. Preparation never executes
the target, bypasses read permission, or grants write authority. The WordPress
runtime projection regression verifies receipt dispatch, altered-pin refusal,
write-target refusal and recursive-dispatch refusal.

Read registration now contains exceptions in declared MAD4B/Context read permission
and execution callbacks before they escape into the MCP protocol handler. Normal
permissions, WP_Error denials and results remain unchanged. Unknown/mutating and
unrelated provider registrations are untouched. Each callback fault returns a
WP_Error with stage, bounded code and per-invocation correlation in the message,
because the adapter drops WP_Error data when constructing tool error content.
Server diagnostics record the same correlation and hashed origin, excluding
inputs, messages, credentials and paths. Faults are never retried by this boundary.
Existing final execution-admission wrappers still run outside it.

Site Profile now explicitly declares a closed empty-object input schema instead
of exposing an absent schema as an empty array in tool metadata. The inspected
adapter already normalizes no-schema empty arguments; schema absence alone is
therefore not proof of the observed Internal Error cause.

Limits: hermetic TypeError/exception regressions establish the callback escape
mechanism and containment. The actual live exception origin remains unproven
without the correlated server log. Failures before registration, inside the outer
execution fence, process/resource termination, response serialization, proxy or
session transport remain separate. No Brand Authority scan, asset acceptance,
registry mutation or live runtime repair is claimed from these source changes.

## Remote MCP and WordPress administrator recovery parity

The canonical read-only `mad4b/recovery-route-matrix` now describes the
administrator navigation route and MCP Ability for each bounded operational
recovery area. Open **Control Plane → Operational Remediation → Recovery
routes — WordPress vs OAuth/MCP** to see the same live registry inspection.
Use `mad4b/operational-remediation-status` to identify open gates and
`mad4b/operational-remediation-prepare` to produce a nonauthorizing,
exact-source review handoff.

A registered WordPress Ability is **not** proof of MCP mounting, ChatGPT
tools/list visibility, a successful gateway prepare, a permission grant or
a completed mutation. The matrix keeps these separate. Administrator route
registration similarly does not prove an executable POST handler or a
successful write. Unknown coverage is shown as unverified, never ready.

### Exact local recovery of a failed runtime transaction

**Control Plane → Runtime Components → Maintenance** now exposes a dedicated
recovery path **only** when the signed transaction, unchanged original pair,
known pre-replacement failure, inactive maintenance lease and inactive
continuation justify `transaction_recovery.eligible=true`. Ordinary update
remains blocked while the transaction is active or uncertain.

1. Choose **Review exact failed-transaction recovery plan**. This is an
   explicit read/planning action, not a package update. Passive page views
   avoid the potentially expensive release-plan read.
2. Review the fresh `reconcile_failed_transaction` plan SHA-256 and the
   current identity. Only then use the separate nonce-protected POST.
3. The native handler requires `update_plugins`, Staging safety, an exact
   plan hash, release-set hash, installed source SHA, original signed
   failure and an independent fresh replan. The existing audit/CAS executor
   preserves the failure, cancels metadata once, and requires **another**
   plan and approval before any package installation.
4. If the transaction is partial, unknown, corrupt, under a lease or has
   changed installed components, no local or remote shortcut is offered.
   Inspect the transaction journal and disk identity independently.

The admin route and remote `mad4b/runtime-release-set-plan` / governed
`mad4b/runtime-release-set-apply` share the same runtime coordinator and
transaction guard; they do **not** share identity or consent automatically.

### Scope of the expanded matrix

| Recovery area | Read/plan surface | Existing governed operator handoff |
| --- | --- | --- |
| Runtime update, failed transaction | Runtime Release Set status/plan | Runtime Components native exact review; separately admitted MCP apply |
| Current build Candidate Binding | Write Authority and Staging convergence | Exact enrolled owner intent plus OAuth step-up; never recreate healthy grants |
| Skills | Live Skills Runtime Certification | Reconcile Managed Skills only after proving actual drift |
| Provider/Google OAuth | Provider Connection Discover/Plan | Owner OAuth browser consent or certified refresh, followed by independent read |
| Google Drive inventory | Context source onboarding preflight | Prove selected folder and complete bounded scan before Scan Apply |
| Brand Authority | Brand Core remote recovery status/prepare | Hash-bound asset review and separate owner approval |
| Browser/AJAX/SEO | Work queue, browser acceptance and acceptance evidence | Verified external executor, target, lease and independent receipt |
| Rate Limiting/Object Cache | Operational acceptance evidence | Host/worker/TTL/atomicity evidence; cannot infer from a cache drop-in |
| MCP transport errors | Tool projection recovery plan and remediation status | Correlate gateway, registration, execution-fence and callback faults |
| Production recovery | Production readiness and host identity reads | No automatically inferred Production write, secret provisioning or restore |

A host safety denial or unsupported host capability (process isolation,
Resource Limiter, network isolation, SSH, external executor) is not bypassed
by changing transport, generating arbitrary PHP, invoking raw SQL or
switching to Breakglass. Where only a human host/operator can supply a
capability, the matrix returns the original route and the missing proof
instead of claiming remote remediation.

### CI and live acceptance

The PHP 7.4, 8.3, 8.4 and 8.5 Runtime Release Set CI matrix tests exact
admin recovery intent, rejected stale/unknown plans, read-only recovery
route inventory, missing mounts, and Production/Staging separation.
Source tests must **not** be recorded as live ETG/ARE acceptance.

For each installed site, separately verify package source and manifest,
100% current grant inventory, Candidate Binding, Skills live evidence,
provider transport certification, completed Drive inventory, Brand asset
decisions, Browser executor receipts, Apply → Verify → Undo, rate limiting,
cache backends, AJAX/Canonical/WPML, and independent audits. Preserve the
existing owner attestation and exact environment scope. No Production
mutation or unattended generic remote repair is provided here.
