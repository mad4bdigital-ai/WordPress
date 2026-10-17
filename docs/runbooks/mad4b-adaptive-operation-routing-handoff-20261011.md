# MAD4B Adaptive Operation Routing — Source Delivery / Operational Handoff
**Date:** 11 October 2026  
**Scope:** WordPress Site Control Plane, PR #258, All Royal Egypt Staging readback and all compatible plugin/providers  
**State:** Source delivered; **NOT** native PHP CI certified, **NOT** deployed to Staging at this new HEAD, and **NOT** acceptance of an approval ticket or production mutation.

## 1. Objectives and boundaries
Discover/select the appropriate existing WordPress Ability, semantic operation, provider adapter, MCP lane, schema-pinned dispatcher and safe fallback **dynamically**. Do not hardcode one workflow or automatically turn an unknown WordPress admin button into a remote write. Discovery and routing are **read only**; any write still needs the original canonical permission check, NHI grant, OAuth client/step-up, source/package match, one-use approval ticket, budget, execution boundary, CAS/readback and independent verification as applicable.

### Existing components reused
- `MAD4B_SCP_Operation_Registry`: provider-neutral semantic operation planner/executor catalog with Capability Descriptor bindings.
- `MAD4B_SCP_Unified_Capability_Gateway::search/prepare`: live registered WordPress Abilities API metadata search, classification, schema/authority hashes, preparation receipt and transport negotiation.
- `MAD4B_SCP_ChatGPT_Tool_Projection`: optional explicit schema-pinned dynamic tool projection. It never creates execution authority and needs host tools/list refresh; a projected server-side tool is not necessarily available in the current client UI.
- `MAD4B_SCP_Servers`: actual current original MCP read/write/enrollment/developer lanes; transport selection is derived from this inventory, not from proposed names.
- `MAD4B_SCP_Adaptive_Operation_Router`: `mad4b/operation-route-discover` and `mad4b/operation-route-plan`. Both read only and registered on the governed read surface.

## 2. Dynamic route selection
A request must contain exactly ONE of `task`, `operation` or `ability_name`; it can optionally include `target_kind`, `intent=auto|read|write`, `phase=plan|execute`, and bounded transport failure diagnostics. All inputs are bounded; the metadata index uses declared read/write annotations and cannot infer execution permission.

- `task`: rank live abilities by name/label/description/aliases. For `auto`, prefer safe read planning even when a higher-scoring writer exists; request a write explicitly for effectful work. Natural-language `phase=execute` with `intent=auto` is rejected rather than escalating by implication.
- `operation`: require a unique registered semantic operation and its exact planner/executor from the operation registry. A dynamic `exact_executor_from_plan` cannot be fabricated until the independently reviewed planner has issued one.
- `ability_name`: use exact registered Ability; never fabricate a provider or reinterpret the Ability's declared lane.
- `intent=read` / `intent=write`: filter declared read-only or mutating Abilities **before** top-N ranking. A missing readonly annotation is `unknown`, not implicitly granted read/write.
- `plan`: returns the selected candidate, original execution lane, canonical dispatcher, schema/authority/classification SHA, preparation receipt, alternatives and blockers; **no execution**.
- `enrollment`: return `ENROLLMENT_OPERATION_ID_AND_DIGESTS_REQUIRED`. Client must separately resolve the registered operation ID, registration digest and dispatch policy digest.
- `developer`: return `ISOLATED_DEVELOPER_STEP_UP_REQUIRED`. Never fall back to ordinary write or breakglass.
- `admin/content/write`: use the original governed write lane, never a read annotation, no bypass of original ticket and approval.

### Transport options
A successful plan reports `transport_options`: canonical governed dispatcher, a direct tool **candidate** if present in server-side catalog, and an explicit schema-pinned projection option if structurally permitted. `verified_in_host_tools_list=false` means the host UI can still return Tool Not Found; ask for host tool refresh/reconnect. The list is **advice**, not an authorization or direct execution guarantee.

## 3. Failure handling and fallback, safely generalized
| Observed failure | Route decision | Side effect |
|---|---|---|
| `tool_not_found` at direct client surface | `DIRECT_CLIENT_VISIBILITY_UNVERIFIED`; refresh host tools/list and re-prepare the original governed dispatcher | No automatic mutation; same original one-time approval required |
| `authorization/approval/permission/grant/scope/policy_denied` | `AUTHORITY_DENIAL_NO_ALTERNATE_EXECUTION` | Never try a less governed lane |
| Timeout/internal exception during write/enrollment/developer dispatch | `TRANSPORT_RESULT_INDETERMINATE` | Independently read the original mutation/approval receipt before any new attempt |
| Read dispatch unavailable | Re-discover and prepare a fresh exact read receipt | No authorization or write claim |
| No registered Ability or unique semantic operation | `NO_REGISTERED_MATCH` or explicit registry refusal | Never guess an Adapter or call admin endpoints by name |
| Site/Profile/ABI/Package hash drift | Fail closed at canonical dispatcher | Reconcile current identity/grants and replan |

**Critical:** the caller-provided error label does not certify absence of side effects. For any potentially delivered write, the selector sets `selected=null` and returns an independent-readback requirement. `automatic_retry_allowed=false` for every failure category.

## 4. Approval-plan incident and its limit
Observed on prior Staging version: `mad4b/approval-plan` is registered and listed as a governed write Ability. The direct ChatGPT tool call returned `Tool not found`, while nested `mad4b/write-execute` returned an internal error. The current router can diagnose these paths and recommend current host refresh, but **it does not create an approval ticket through another lane or certify ticket delivery**. A direct candidate is not necessarily materialized in ChatGPT's current tools/list. Repairing the underlying approval bootstrap transport is a separate execution-acceptance task; never mark it DONE solely because routing metadata works.

## 5. Source changes in this delivery
1. `includes/class-mad4b-scp-adaptive-operation-router.php`: exact failure taxonomy, mutable-task phase guard, safe alternative transport enumeration, route selection evidence and the requirement for fresh original execution authorization.
2. `includes/class-mad4b-scp-unified-capability-gateway.php`: new `declared_writeonly_only` filter with strict boolean validation and mutual exclusion with `declared_readonly_only`; filters known mutations **before** ranked top-N.
3. `includes/class-mad4b-scp-chatgpt-tool-projection.php`: typed read/write filters available through existing dynamic MCP gateway.
4. `tests/adaptive-operation-router-runtime.php`: read/write, exact semantic operation, Enrollment and Developer, missing tool, policy denial, unknown write completion, no authorization bypass, unknown adapters and bounded query/refusal.
5. `tests/adaptive-capability-filter-runtime.php`: real gateway metadata index with 30 read candidates, one declared write, unknown annotations, search crowding and invalid filter refusal.
6. `.github/workflows/feature-007-spec-ci.yml`: PHP runtime matrix includes both fixtures.
7. `specs/007-content-intelligence-workflow-platform/extensions/competitive-experience/g9-delivery.json`: pin the **updated workflow SHA-256 and UTF-8 byte size**, preserving all 22 G9 source integrity checks. No weakening of `g9-delivery-contract.py`.

## 6. Source checks vs live acceptance
All changed source/test/CI/manifest files have been fetched/read back via GitHub at the exact PR branch SHA. Static marker checks confirm that the routing, refusal and test wiring exist. This is **not** a PHP execution PASS. The user's previous Build Kit G9 SHA drift was repaired in an earlier commit; this delivery re-pinned the workflow because a new runtime fixture was added.

**Last observed installed All Royal Egypt Staging**: `MAD4B Site Control Plane rc.96` at `b53efafc149874455ab713498ba0399a1b43fd2f`, HEALTHY, 95/95 governed write grants and current Managed Skills ready after reconciliation. The current branch source has advanced beyond that SHA. No installation, WordPress data mutation, approval ticket, protected deployment binding, plugin ZIP signing or production modification was done as part of this routing change.

### Acceptance sequence (Staging only)
1. Run the user's exact PR #258 Windows Build Kit against latest HEAD: PHP lint/runtime matrix, real G9 delivery verification, package manifest and bundle SHA, no skipped gates; report pass/fail independently of 61 previously queued GitHub Actions.
2. Install the **verified exact-HEAD** ZIP on Staging using approved update delivery, with rollback and post-install SHA readback. Do not downgrade or install a stale manifest.
3. Reconcile current Staging write candidate/grants and Managed Skills on the newly installed SHA; preserve no-Breakglass/no-Production.
4. Call `mad4b/operation-route-discover` and `mad4b/operation-route-plan` for real `approval-plan`, `approval-ai-decide`, `deployment-binding-native-plan`, trip/media, Enrollment and Developer use cases; verify original lane/schema hashes and host tools/list state.
5. Exercise an **owner-approved one-use approval ticket** on Staging, using exact current operation input and proper authorization—not a made-up ID. Verify pending ticket + decision + claim + finalization and independent readback before declaring approval/bootstrap E2E.
6. Execute the one-approved native host binding only if the current approved ticket and exact Site Profile are verified. Repeat `deployment-mode-status`, `context/legacy-reconciliation-census`, `operational-acceptance-evidence` and independent host identity limits.
7. Record actual transport errors, latency/capacity and read/write parity. Validate `tool_not_found`, unavailable source, stale schema, unknown permissions, timeouts, replay and partial failure. No background retries for uncertain mutations.

## 7. Remaining blockers and handoff decision
- **Code delivered:** generalized discovery, evidence-based route selection, typed mutation-aware metadata ranking, safe transport alternatives and failure taxonomy.
- **Not proven:** real PHP CI execution on this HEAD, signed ZIP/Staging deployment, host client tools/list refresh, and end-to-end `approval-plan → ai-decide → native-binding-apply` on Staging.
- **Explicit non-goals:** silent self-approval, unbounded filesystem/browser automation, arbitrary WordPress Admin control with no certified Adapter, Production or Breakglass access, supplier image rights inference or unconsented publication.

Release verdict: **SOURCE_DELIVERED / LIVE_ACCEPTANCE_PENDING**.
