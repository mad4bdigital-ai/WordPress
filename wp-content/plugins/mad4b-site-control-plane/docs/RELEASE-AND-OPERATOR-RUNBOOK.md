# MAD4B Site Control Plane — Release & Operator Runbook

## Scope

This runbook covers the governed release, enrollment, write-authority, acceptance, recovery and rollback lifecycle for MAD4B Site Control Plane 0.4.0-rc.35 and later compatible builds.

A repository-green build is not a live-site release. The deployable unit is one exact package bound to one source commit, one package manifest and one build fingerprint.

## Release identity

Before deployment, record all of the following from the same successful package workflow:

- source commit SHA
- build fingerprint
- package manifest digest
- Control Plane ZIP SHA-256
- MCP Adapter SHA-256
- package workflow run ID

Never combine identity values from different builds.

The package workflow additionally emits a CycloneDX SBOM and a GitHub Artifact Receipt. The receipt binds the exact source SHA, workflow run, GitHub-issued outer artifact digest, Control Plane ZIP SHA-256, MCP Adapter SHA-256, build fingerprint, package manifest digest and SBOM digest. The outer GitHub artifact digest is intentionally external to the installed plugin because embedding an archive's own final digest inside itself would create recursive identity. Installed runtime truth therefore continues to bind canonical installed bytes through the package manifest digest and build fingerprint, while the external receipt binds those bytes to the distributed GitHub artifact.

## Staging deployment sequence

1. Require exact-head CI success.
2. Download the package produced by the exact-head `MAD4B Control Plane Package` workflow.
3. Verify the ZIP SHA-256 and embedded package manifest before installation.
4. Deploy to the enrolled Staging origin only.
5. Read back live provenance and require:
   - `SOURCE_COMMIT_SHA` equals the approved source commit.
   - `RUNTIME_MANIFEST_MATCH=true`.
   - `STALE=false`.
   - no provenance mismatch.
6. Verify MCP Adapter and Control Plane versions from the deployed runtime.
7. Verify provider isolation and the intended MAD4B transport catalog before any mutation authorization.

Do not reuse a previous ZIP after the source commit changes.

## Enrollment and governed write enablement

Site enrollment and write authority are separate.

The Site Profile must be exact-bound to:

- site UUID
- canonical origin
- environment
- revision
- profile digest
- enrolled OAuth administrator subject

Write enablement must remain explicit and non-Production by default.

OAuth establishes identity only. It does not create write authority.

## Exact grant reconciliation

Grant reconciliation is an explicit mutation surface, not a lifecycle side effect.

Before running `mad4b/staging-write-grant-reconcile`, read and bind:

- Site Profile revision and digest
- source commit SHA
- build fingerprint
- canonical agent public ID
- current write-tool count
- current write-inventory fingerprint
- exact missing-ability set

Use the literal confirmation required by the ability and run it once for the reviewed missing set.

After reconciliation require:

- exact grants equal current write-tool count
- missing exact grants = 0
- wildcard grants = 0
- grant blockers = []
- authority ready = true
- runtime reconciled = true
- write runtime certification = ready

Then execute independent read/status requests and prove the exact grant rows remain unchanged.

## Read/status immutability

The following surfaces are inspection only with respect to governance authority:

- connection status
- write authority status
- Live Truth authority status
- Live Truth write certification
- persisted write certification status
- Skills export status
- approval decision readiness

They must not create, revoke or rewrite:

- agents or subjects
- exact grants
- approval tickets
- Site Profile authority
- persisted write authority
- persisted write-runtime certification

CI contains a byte-stable authority snapshot regression for this boundary.

## Mutation acceptance

A release is not write-ready until one bounded reversible mutation proves the complete lifecycle:

1. exact approval plan
2. human approval
3. execute
4. readback
5. replay denial
6. governed undo
7. rollback readback
8. append-only receipt/audit evidence

No raw SQL or Breakglass authority is part of normal acceptance.

## Provider-native operation policy

Provider-native discovery and governed execution are separate.

For JetEngine:

- native provider routes remain externally isolated
- governed native operations are projected only after runtime eligibility/certification
- `import_configuration` and `export_configuration` remain unsupported while the installed JetEngine build exposes no reviewed native tools for them
- do not emulate those two operations with undocumented internal calls merely to reach a numeric coverage target
- `jetengine/update-post-meta` remains a bounded reversible MAD4B adapter capability and requires current capability/write certification before remote projection

When upstream provider contracts change, use behavioral recertification rather than version-based trust.

## Plugin lifecycle and data retention

Normal deactivation/reactivation must preserve governance state.

CI snapshots authority-bearing tables and options before deactivation, verifies them while the plugin is inactive, reactivates the plugin, and verifies the same state again.

Uninstall is intentionally non-destructive by default:

- there is no `uninstall.php`
- there is no registered uninstall hook
- governance data is not silently dropped

Any future destructive uninstall must be a separately reviewed explicit data-destruction feature with its own confirmation and backup/retention contract.

## Upgrade continuity

Upgrade handling is fail-closed.

Existing verified read/OAuth continuity may be recovered only when the previous evidence is exact-bound to the same non-Production site identity. Upgrade continuity must not silently restore:

- write authority
- Skills mutation authority
- acceptance authority
- Production authority

Schema upgrade tests must cover existing-table upgrades, not only fresh installation.

## Rollback

Rollback means package rollback, not database rewinding.

Before rollback:

1. preserve the current package identity and live provenance evidence
2. preserve the database and WordPress files using the hosting backup mechanism
3. verify the previous package SHA and manifest
4. install only the previously certified package
5. run read-only provenance and connection diagnostics
6. do not automatically restore write authority from an older package
7. reconcile write authority only through the explicit governed path if the rolled-back runtime inventory is intentionally accepted

If inventory identity changes, persisted authority must fail closed until explicitly reconciled.

## Catalog parity

For each accepted build, compare:

- registered WordPress Abilities
- `mad4b-write` runtime-eligible projection
- stable `mad4b-chatgpt` external catalog
- external MCP `initialize -> tools/list`
- ChatGPT client catalog evidence

State-only provider eligibility changes may change runtime execution eligibility without changing the stable external schema. Actual build/schema changes require fresh external catalog evidence.

## Live performance and rollback acceptance

Repository performance contracts prove architectural bounds and request-local catalog behavior, but they do not substitute for timing the real hosting/runtime stack. Before Ready for Review, capture live Staging evidence for MCP tools/list, write-runtime certification and Skills-runtime certification latency/query behavior. Treat those measurements as external acceptance evidence, not as a reason to introduce persistent authority caches.

Rollback retention proves the previous certified artifact still exists; it is not the same as a live rollback drill. Before Production promotion, perform one governed Staging drill: deploy the exact candidate, verify it, roll back to the retained certified package, verify provenance/read continuity with write authority fail-closed as required, then redeploy the candidate and re-run live acceptance. Record all three package identities and readbacks.

## Production safety

Production remains fail-closed unless a separately reviewed Production authority flow exists.

Release acceptance must include trusted external evidence that Production remained unchanged during Staging certification.

Never use:

- Staging grants on Production
- Breakglass as a normal deployment path
- raw SQL to repair ordinary authority state
- a caller-selected provider/resource override where the Site Profile owns the binding

## Completion definition

### Release complete

A build is release-complete only after:

- exact-head CI is green
- canonical package identity is proven
- exact package is deployed to Staging
- live provenance matches
- grant reconciliation is stable across independent requests
- write-runtime certification is ready
- execute/replay/undo acceptance passes
- Production-unchanged evidence passes
- the PR is reviewed and merged through the governed repository flow

### Product complete

Product-complete additionally requires:

- read/status immutability regression
- lifecycle data-retention regression
- upgrade continuity regression
- catalog parity regression
- documented unsupported provider operations
- operator deployment/recovery/rollback guidance
- current provider capability certification for every remotely projected write

Unsupported upstream operations may remain explicitly unavailable; they do not need unsafe emulation to qualify as a completed product.
