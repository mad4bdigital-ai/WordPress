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

## All Royal Egypt operating audit — 2026-10-05

Read-only observations from 10:51–11:16 UTC were fenced to runtime generation
`a24cdee69036dfd3b09907832cd41c2eb6ab844fe5dbacc550a204838cba1dd3`.
The installed candidate was `a3ef595a5a8e6f8e41566c6c59073cc43d07affb`,
Control Plane `0.4.0-rc.92`, Adapter `0.7.0`. These observations precede PR #253
deployment and cannot certify its repaired runtime.

| Operating path | Live observation | Required closure |
|---|---|---|
| Connection and profile | Registered transport; exact-bound Staging profile. WordPress separately reports its default `production` environment. | Preserve exact profile binding; evaluate effective environment separately from WordPress's default. |
| Task search | 372 registered definitions searchable through the unified gateway. | Search visibility does not prove preparation or execution. |
| Traditional read/write discovery | `Rate-limit writer topology is unavailable`. | Deploy the certified observer/topology fix; recheck durable rate storage. |
| Selected read preparation | No preparation receipt; `mad4b_abuse_rate_storage_unavailable` for valid content, context, Skills and Search targets. | Obtain a fresh receipt after writer readiness; do not bypass its dispatcher binding. |
| Full catalog mirror | `mad4b_catalog_build_time_budget` (503). | Page-bounded live classification; verify cold and warm mirrors after deployment. |
| Adapter definitions | `media/search`, `media/get`, adapter inventory and Content Experience targets unavailable on this connection. | Verify canonical ChatGPT definition registration independently from provider-server materialization. |
| Write grant plan | 44 expected abilities; 24 missing outside the installed reviewed allowlist; three missing dispatch grants. | Deploy the reviewed exact grant policy, then inspect the new complete provider universe and converge only its reviewed pairs. |
| Candidate binding | Stored authority bound to `deb28052ab6e89b87f6154557e2d0f78f2b122c6`; current candidate differs. | Exact Write-only candidate convergence after runtime prerequisites pass. |
| Provider closure summary | Returned `write_authority_ready=true` while candidate binding was false. | Use checkpoint plus current exact grants plus current candidate binding; never accept the old summary alone. |
| Developer execution | Authority flags enabled; process execution blocked by unavailable resource limiter and network isolation. | Keep host execution unavailable until a certified backend supplies both capabilities. |
| External acceptance | Browser/sampling/queue operations registered, but external executor waiting. | Register and certify the actual executor; registration alone is insufficient. |
| Skills | Candidate checkpoint matches; live filesystem evaluation explicitly deferred. | Deep managed-Skills certification remains separate live evidence. |
| Update channel | Session diagnostic reports no cached manifest. | Read an exact native package plan explicitly; absence of cached evidence is not a failed download. |

The bounded report made no outbound requests or deep integrity scans. Its total
request time was 1.136–1.156 seconds, with 425 database queries, 6,040 included
files and a 48 MiB peak. Compare these measurements against an exact deployed
release baseline before making a performance acceptance claim.

Repository regression coverage includes a 400-capability mirror with two-item
pages, immutable deltas, fresh page classification and isolated classification
failures; dependency-ordered recovery and unknown readiness; real WordPress URL
sanitization for contextual media; generated Content Experience plan denials;
and managed-Skills lease ownership/CAS fencing. Contextual source/link URLs must
be explicit absolute HTTP(S), credential-free and at most 8 KiB. A link target
requires a non-empty URL and focal coordinates must be finite within `[0,1]`.

After deploying the exact reviewed PR package, obtain a fresh generation-fenced
report, prepare and execute one bounded read per content, media, taxonomy,
context, Skills, Search and provider family, then perform separately authorized
reversible canaries with readback/rollback. No live publishing, provider spend,
authority widening, deployment or mutation was performed by this audit.

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

## Production readiness certification

Production readiness is a **non-authorizing** certification state. It does not grant Production write authority and it never implies automatic promotion.

The machine-readable source of truth is:

- `config/production-certification-plan.json` — ordered certification stages and expected evidence contracts.
- `config/production-readiness-policy.json` — profile scope, required gates and fail-closed optional capabilities.
- `tests/production-live-evidence-contract.py` — exact-candidate evidence reducer/verifier.
- `mad4b/production-certification-readonly-evidence` — read-only Staging evidence producer exposed on `mad4b-read` and `mad4b-chatgpt`.

Use the `control_plane_core` profile for the first Production promotion. Optional Feature 007 capabilities listed by that profile stay disabled and fail-closed until separately live-certified.

### Certification sequence

Before collecting any evidence, call `mad4b/production-certification-status` on the exact deployed Staging candidate. Treat its result as the canonical operator worklist: local read-only canaries are recomputed immediately, while signed external or reversible-Staging stages remain explicitly pending. The status surface is non-authorizing and always reports `production_ready=false`; only the canonical `mad4b/production-readiness-evaluate` verdict over a complete trusted bundle may report readiness.

Operator flow:

`production-certification-status → collect exact stage evidence → production-readiness-evaluate → separate Production promotion authorization`



1. Freeze one exact candidate identity: source commit SHA, build fingerprint and package-manifest digest.
2. Require repository CI for that exact head to pass. Repository evidence is bound by the current CI runtime; do not persist a historical run ID as current readiness truth.
3. Deploy that exact candidate to enrolled **Staging** through the governed deployment path.
4. Run the baseline-owned ETG Deployment Readiness check and prove the deployed runtime identity matches the candidate exactly.
5. Capture runtime root-trust readback for the exact deployed package.
6. Run the read-only Production certification stages through `mad4b/production-certification-readonly-evidence`:
   - `provider_side_channel_inventory`
   - `multi_authority_canary`
   - `policy_resolution_canary`
   - `operator_doctor`
7. Execute the Host Runner parity canary on Staging using only registered semantic operations. No generic shell or caller-supplied executable path is permitted.
8. Create a protected Staging backup with `tools/mad4b_recovery_plane.py`, verify it, and execute a governed restore rehearsal bound to the exact backup receipt.
9. Execute the out-of-band recovery drill with the normal WordPress/Control Plane route intentionally unavailable and bind the recovery receipt to the same candidate identity.
10. Execute the governed consistency/fencing canary and require rollback/postcondition evidence.
11. Execute the request → approval/receipt → reversible mutation → rollback vertical slice on Staging and bind all receipts to the exact candidate.
12. Collect one `mad4b.production-live-gate-evidence.v1` envelope for every stage in the certification plan. Every envelope must use the same candidate identity.
13. Build one `mad4b.production-live-evidence-bundle.v1` and verify it:

```bash
python3 wp-content/plugins/mad4b-site-control-plane/tests/production-live-evidence-contract.py \
  --bundle /path/to/production-live-evidence-bundle.json \
  --output /path/to/production-live-evidence-verdict.json \
  --enforce-ready
```

A passing verdict may report `production_ready=true`, but it must still report:

```text
production_authorized=false
promotion_required=true
authorizing=false
```

### Promotion boundary

After the exact-candidate Production-readiness verdict is green:

- obtain the exact-head owner attestation required by the repository release verdict;
- obtain the separate one-time Production promotion authorization;
- re-check that the candidate identity has not changed;
- keep Breakglass, generic shell and generic raw SQL excluded;
- apply only the reviewed Production promotion plan;
- perform immediate Production readback and retain the pre-promotion rollback package.

Any candidate change, evidence identity mismatch, failed live gate, missing rollback proof, or optional-capability drift invalidates readiness and requires re-certification. Production readiness never self-authorizes mutation.

After the final Feature-owned change, treat every earlier CI run and owner attestation as historical only. Re-run the complete exact-head repository certification on the new SHA, then obtain a fresh exact-head owner attestation before merge or any Staging/Production promotion evidence is considered current.

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


## Release-closure operating model

PR #236 is frozen for release closure. New capability families move to a separate PR. Allowed changes are defect fixes, test hardening, evidence binding, Staging certification, release-readiness work, documentation accuracy and maintainability decomposition.

Operator state is reduced to four non-authorizing states: `HEALTHY`, `DEGRADED`, `BLOCKED`, and `RECOVERY_REQUIRED`. The canonical read-only surface is `mad4b/operator-control-center`. Missing external evidence is never projected as success.

Production readiness is profile-specific. Do not use the aggregate Feature 007 task-ledger DONE/total ratio as a readiness percentage. Use `release-closure-readiness.json` for separate Control Plane Core, optional fail-closed, full Feature 007 blocking, and long-term maturity views.

External machine diagnostics follow `config/external-machine-diagnostic-policy.json`. A CDN/hosting 403 challenge is `INCONCLUSIVE_FAIL_CLOSED`; do not bypass it by disabling site protection or by unrestricted IP/User-Agent rules. Prefer a rule scoped to a machine identity and the bounded read-only diagnostic endpoint classes.

Future capabilities follow `CAPABILITY-GOLDEN-PATH.md`: Define → Register → Certify → Plan → Execute → Evidence → Reconcile.

Protected backup, restore rehearsal, recovery drill, exact-runtime deployment/root-trust readback and request → receipt → rollback remain live gates. Repository metadata cannot mark them DONE.


### Three-layer performance proof

Release closure separates performance evidence into three layers. Repository/runtime CI must keep **MAD4B Admin Performance** and the SLO contract green. Disposable runtime evidence must exercise real database/runtime contention and fault semantics. The final ETG layer remains external live evidence bound to the exact deployed candidate and must cover server latency, query pressure, peak memory, concurrency/backpressure, and provider latency/timeout behavior.

Repository CI cannot substitute for the live ETG performance layer, and documentation cannot mark that layer PASS. The live layer is read-only/non-authorizing with respect to Production.
