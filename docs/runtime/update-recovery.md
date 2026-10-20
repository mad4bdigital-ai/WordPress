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
