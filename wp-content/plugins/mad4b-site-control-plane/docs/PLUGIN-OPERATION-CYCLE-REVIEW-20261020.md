# Plugin operation cycle: source review and target experience

Reviewed against master `6614091c297f7ded20dc86682f205a7d19a655c7` and the
20 October Staging observations. This review grants no authority or live acceptance.

## Product boundary

MAD4B is a general WordPress control plane. The site owns its business model,
content, templates, languages and provider configuration. Provider adapters own
typed integration and compatibility. The connector discovers and invokes those
registered operations. Vendor repair recipes belong to separately reviewed
development tooling, not an automatic core plugin patcher.

Tour creation is one site's configured content operation; it must not define the
core lifecycle. Retail, education and service sites use the same cycle with their
own registered post types, providers, policies and exact targets.

## One user intent, explicit internal states

| Stage | Existing entry point | Required behavior |
|---|---|---|
| Identify | `mad4b/environment-journey`, Site Profile | Resolve the exact site and environment from the enrolled runtime. Block conflicting identity. |
| Observe | `mad4b/session-safe-diagnostics` | One bounded request; separate check execution, subject readiness and release acceptance. Deferred is unknown, not passed. |
| Route | Operation/Enrollment discovery | Select the live registered transport. Optional direct MCP tool absence is not missing capability. |
| Prepare | Single-ability capability gateway | Pin schema, lane, authority scope and generation; provide an exact proposed request. An omitted object input means an empty object, not null. |
| Decide | Existing owner/ticket/batch policy | No prompt for reads or already-current state. One exact decision when new authority or a batch is required; never copy an old plan digest. |
| Execute | Existing read/write/Enrollment dispatcher | Validate every pin and permission again. Persist the mutation claim before side effects. Never replay an uncertain write. |
| Verify | Fresh independent readback | Check actual content/package/grants and required provider behavior. An HTTP 200 or target callback completion alone is insufficient. |
| Recover | Owner intent, operation journal, exact backup | Show current authority and prior operation outcome separately. Reconcile uncertainty before another write; restore only the exact affected target. |

The client can automate discovery, preparation and verification. It must retain
one in-flight request per site and avoid flooding sites sharing one host. An
authorization decision can cover a finite set of exact operations under an
existing bounded policy; repeated low-risk steps within that scope should not
ask the same question again. Identity drift, expired authority, changed payload
or a new privileged scope requires fresh evaluation.

## Findings and source corrections

1. `MAD4B_SCP_Abilities::read_execute()` passed an omitted `input` as null even
   when the selected Ability requires an object. The source correction supplies
   only the empty object for an omitted object target. Explicit null/scalars,
   required fields, permission failures and stale preparation remain rejected.
2. The guided Enrollment path returned `already_current` before reading the
   durable journal. Current authority cannot certify a previous invocation's
   outcome. The corrected path inspects the site journal even when authority is
   current, without computing the full convergence plan a second time. Uncertain,
   held-lock and unavailable journal states produce read-only recovery guidance,
   no prepared mutation and no redundant owner-approval request.
3. Optional projection status reported `binding_match=false` and effective count
   zero for both an empty selection and a stored mismatched selection. Explicit
   projection state/next-action fields now distinguish them. They never claim
   fixed-dispatch health or change grants. Dynamic tool selection remains optional.
4. Public HTTP smoke tooling previously recorded HTTP status and SEO metadata
   without detecting PHP diagnostics in a successful HTML response. That is a
   separate tooling/CI repair, not a reason to alter WordPress content or suppress
   all errors inside MAD4B. No public response body or filesystem path should be
   retained in its evidence.

## Environment and release cycle

Local and Development use their native policies and disposable test fixtures.
Staging exercises the exact package and provider versions with real OAuth,
readback and rollback. Production uses independently authorized exact batches,
current artifact-bound signed test evidence, expiry/revocation and the existing
durable deployment fence. Staging authority is never promoted to Production.

An upgrade should preserve compatible configuration and previously authorized
scope through the existing continuation policy. It must recompute affected
capabilities and invalidate changed artifacts or evidence. It must not turn a
version number into standing approval for arbitrary future writes.

## Acceptance before claiming closure

- Read-only/already-current flows request zero new owner decisions. Uncertain
  flows show recovery evidence and never issue a replacement Apply proposal.
- No-argument object reads execute through the real WordPress validator with a
  valid prepared receipt. Explicit invalid inputs and missing permissions fail.
- Core diagnostics, live Skills and external client snapshot verification remain
  separate facts. Local runtime certification does not install a ChatGPT snapshot.
- Every reported write success has an exact result and fresh independent readback.
  Every uncertain write remains unreplayed until its journal is reconciled.
- Public home/listing responses have no observed PHP diagnostic output; functional
  search/pagination/AJAX, locale, SEO and template checks run on configured routes.
- MCP `-32603` requires correlated origin/host evidence. The defects repaired here
  do not establish the root cause of every opaque transport error. No live closure
  is inferred from fixture tests, a green PR, or package publication.

Code/CI, owner merge approval, package deployment and per-site live acceptance are
four separate completion states. The user should see the current stage, exact
blocker, next supported action and evidence rather than one ambiguous green badge.

## Approval decision and Source Scan incident recovery

The user-reported ETG sequence is evidence of two different boundaries, not proof
of a common root cause: an AI decision returned an internal error before a later
decision succeeded; Source Scan then stopped before its protected target callback.
Do not retry a decision merely because its response was lost. The existing ticket
SQL transition is conditional on pending status and exact payload/expiry; this
prevents a second transition but does not make a lost response a failed decision.
An error after entering approval-ai-decide now reports an unconfirmed outcome and
routes to the read-only approval-decision-handoff for the exact ticket. Inspect
its ticket fields; its human-console action fields do not grant AI authority.

| Observation | Required next action |
|---|---|
| Ticket read unavailable | Keep outcome unknown; stop further decisions and writes. |
| Pending | Verify exact payload, candidate, expiry, classification, delegation and decision audit before considering another decision. Pending alone is not permission to replay. |
| Approved | Do not approve again. Verify exact binding and audit; approval alone does not prove target execution. |
| Executing, used or failed | Read execution/audit and affected data; never replay a claimed mutation. |
| Revoked, expired or mismatched | Do not reuse this ticket or its previous preparation. |
| Source Scan target not entered | Preserve the safe original denial code in the MCP-visible message. Read the exact ticket, audit and Registry revision/content digest against the pre-operation baseline before any new preparation. |

The dispatcher failure proves only that this target callback was not entered; it
does not prove ticket state or absence of changes by concurrent operations.
Permission, schema, transport and mounting failures need their precise codes;
the generic message cannot identify which occurred. No automatic write retry,
ticket reset, Registry mutation, new approval lane or grant is added here.

The independent ARE invalid_grant observation belongs to WordPress's Google OAuth
connection. Healthy MAD4B Write Authority does not repair a revoked/expired Google
grant. Reauthorize the exact provider connection through its existing owner flow,
then verify Drive reads before creating a fresh Source Scan plan. Never expose
OAuth credentials or promote this connection repair to a Production grant.

These are source-based recovery corrections and local simulated boundary tests.
Neither reported live incident is marked resolved without fresh site readback.

## Shared runtime follow-up review

Reviewed shared read/write resilience, dispatcher boundaries, Enrollment error
reconciliation, approval transition/replay admission and Source Scan provider
flow for error masking and unsafe outcome/retry claims. This is source review,
not a declaration that every installed provider or live path passed.

Reproduced and corrected in the shared resilience service:

- A provider topology blocker array/object was cast to string while building error
  metadata. This produced a PHP warning/exception and replaced the actual provider
  error with a second runtime failure. Non-scalar blocker values are discarded.
- Error metadata cast the string "false" to boolean true for authorizing/retry
  hints. Error recovery now always remains nonauthorizing and cannot enable blind
  retry; normal read retry admission remains controlled by its existing policy.
- Mutation wrappers discarded the safe root denial and bounded read/inspection
  action on a second wrap. A diagnostic-only recovery whitelist now preserves
  those across wrappers, and read/write messages include the safe code even if
  the MCP client drops structured data. Outcome, retry and permission rules are
  not inferred from nested metadata; uncertain outcomes still need reconciliation.
- Postcondition recovery flags used truthiness, so strings such as "false" could
  be reported as a certified reader/reclaim candidate. Only boolean true certifies
  the reader; reclaim eligibility additionally requires a certified reader. This
  is diagnostic eligibility only and never performs a reclaim or retry.

Behavioral fixtures reproduced metadata masking and false certification before
fixing the source. They also cover nested single-shot dispatch, safe messages,
secret omission, valid boolean certification and uncertified recovery denial.
The CI lifecycle runner executes these fixtures and fails on PHP diagnostics.
No site-specific defaults, new authority, live request or deployment is added.

## Automatic per-operation readiness and original-scope diagnostics

The live verification found that a plugin candidate risk tier can be L0 while a
reviewed adapter has registered reads. Candidate tier remains unchanged; the
existing plugin-capability-graph now includes an independent operation_readiness
projection per package, also displayed in the existing Capability Graph admin tab.

The projection is computed from current registered ability names and the approved
adapter's per-capability evidence. It classifies individual reads/writes, missing
integration, ambiguous mappings, inactive dependencies, excluded packages,
parallel transports, missing registration and incomplete discovery. Missing read
evidence is verification_required, not a proven permission denial. A ready
contract still requires fresh preparation, original permission checks, identity,
site/environment policy, exact grants, and any required approval at execution.
It is never authorization or a claim that a live operation succeeded.

For each operation the graph provides the dispatcher, blockers, next inspection
or preparation step, independent readback requirement and declared rollback
contract. Other registered reads are only candidate readers: the exact target
binding is explicitly unverified. Availability of rollback is not proof that a
Restore drill succeeded. No provider callback is invoked by this reducer.

Discovery is automatic on each graph read, using the existing bounded runtime
inventory. Its evidence digest changes with plugin version, capability contract,
artifact fingerprint, eligibility or registration changes. There is no persisted
readiness flag that can silently survive an upgrade. Unknown plugins generate an
integration action, never executable code, registration, certification or grants.
No business type or site name is required by this mechanism.

Operator Doctor intentionally retains its site-wide operational visibility, while
Content Job details retain site+brand+tenant isolation. All selected SQL job/event
queue rows now carry a nonauthorizing scope relation, using the same trusted
Operational Integrity checkpoint as Content Jobs. Empty legacy tenant/brand
bindings, foreign brand/tenant and unavailable trusted scope route to original
ownership review. They are not silently treated as current-scope jobs, erased,
restarted or repaired. Newly selected brand/tenant values are stripped from the
returned diagnostic rows. Aggregate site counts remain site counts.

The user's follow-up acceptance report is separate evidence: local canaries and
Skills/Write Runtime success do not satisfy external-client, browser, performance,
Brand Authority or Restore acceptance. Doctor now explicitly states that no
findings is not release acceptance. The reported Google token-refresh failure still needs independent resolution.
The later user-provided follow-up reports HTTP 200 for both homepages and REST,
eight ETG full_semantic cases passed, and Browser Acceptance queued. These are
separate evidence stages: queued is not completed browser acceptance, and HTTP
reachability does not prove JavaScript/AJAX behavior or authorize Production.

Validation includes hermetic per-operation graph and admin-model tests, scope
classification across current/foreign/legacy/unresolved jobs, drift/revocation,
missing evidence and partial inventory. The existing MariaDB Doctor fixture also
asserts scoped annotations, no scope identifier leakage and no durable mutations;
that database-backed fixture is for CI, not a locally executed MariaDB test.

## Provider transport precedence denial

The later user-provided Audit observation identifies the ETG Source Scan refusal
as mad4b_provider_transport_precedence_denied, before target execution, with the
Registry still at revision 6. This identifies the guard, not which of its six
independent conditions failed. An approval ticket never substitutes for those
conditions.

The guard now adds the exact blocking gate and bounded reason code to its error
message and metadata, including not_started, no blind retry and fresh-plan-required
recovery. The write dispatcher preserves this reason/action instead of replacing
it with a generic mounting repair, and nested resilience retains the same safe
reason. Gate order and every allow/deny condition remain unchanged. Runtime
fixtures exercise real preflight/final-breaker refusals for all six gates and
verify that only safe denial identifiers enter the visible message.

No Source Scan, job repair, Browser Agent execution or Restore drill is replayed
by this change. Previously reported site acceptance, queue and Registry values
are user-supplied evidence, not readbacks performed by this code review.
