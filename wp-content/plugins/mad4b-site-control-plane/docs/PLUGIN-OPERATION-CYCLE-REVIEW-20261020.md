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
