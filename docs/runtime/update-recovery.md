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
