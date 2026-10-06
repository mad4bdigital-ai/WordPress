# G1 Delivery — Evidence + Runtime Graph + Semantic Classifier

Owned tasks: **T3901–T3905, T4001–T4010**. Owned capabilities: **CE001, CE002, CE041, CE042**.

## Repository implementation

- Reproducible competitor evidence snapshots preserve immutable archive identities, evidence classes, source ranges, task ownership, capability-local status and acceptance requirements without promoting static or marketed evidence to runtime proof.
- Competitive evidence history is revisioned and tamper-evident through a generation chain plus `previous_entry_sha256` / `entry_sha256` hash chaining.
- The packaged Competitive Evidence projection is generated as an ABSPATH-guarded PHP resource, not a directly readable JSON asset, and its canonical `summary_sha256` is verified before operator display.
- Operator comparison supports bounded search, status filtering, evidence-class filtering and expandable per-capability details for evidence IDs, task ownership, MAD4B foundation and acceptance boundaries.
- Runtime Candidate Graph v2 is provenance-bound to site/environment and verifies the canonical generation digest before accepting a prior snapshot for diff.
- Runtime graph discovery remains bounded and non-executing: registered abilities, provider/component contracts, operation registry, plugin headers, initialized REST routes, CPTs, taxonomies, registered meta, hooks, cron hook counts, admin routes, MCP descriptors, loaded MAD4B symbols and descriptive database table identities.
- Every collector has a declarative internal registry entry plus exact `observed_count`, `emitted_count`, truncation and lifecycle evidence. Truncated or not-yet-initialized kinds are not trusted for absence.
- Graph diff rejects fabricated, oversized and cross-site snapshots; uncertain add/remove states are separated from trustworthy absence.
- Provider/component/schema/ability/operation edges support reverse transitive impact. Provider/plugin drift reuses `MAD4B_SCP_Dependency_Impact_Graph` for certification/dependency revalidation evidence.
- Runtime graph snapshots expose bounded elapsed/memory metrics and request-level memoization without changing authority.
- Semantic Policy Proposals separate observed runtime facts from reviewed policy overlay. Confidence, names, HTTP methods, schemas and annotations remain evidence only.
- Automatic classification eligibility is restricted to a conformance-bound, zero-effect, public-bounded read with both input/output schema digests, no secret-bearing schema, verified execution boundary, non-privileged declared capability and non-sensitive data classification.
- Conformance receipts are bound to ability, exact graph generation, descriptor generation, input/output schema digests, provider contract digest, current provider capability ID, runtime artifact fingerprint and capability contract digest. The verifier callback must originate from the MAD4B code root; untrusted verifiers, stale artifacts/capabilities, forged receipts or observed effects fail closed.
- Owner review is revision-fenced, graph/proposal-bound, audit-required and append-only. Stored review records are digest-verified; tampered review stores, stale revisions, lock loss or missing audit readiness fail closed.
- Owner review remains evidence-only and cannot create grants, mounts, scopes, provider certification, Production authority or Breakglass authority.

## Fail-closed coverage

The exact-head tests deny:

- altered/unsafe competitor archives, stale source hashes and documentation-only parity claims;
- fabricated graph generations, cross-site snapshots, oversized snapshots and silent absence claims after truncation;
- REST lazy discovery before lifecycle initialization, callback/endpoint execution, secret meta/cron disclosure and raw database introspection;
- provider drift without downstream ability/operation/workflow impact propagation;
- confidence-only promotion, unsafe GET/name inference, schema disappearance, secret input/output schemas, privileged reads, sensitive data classification and risk downgrades;
- forged/untrusted conformance receipts;
- review writes without audit readiness, stale graph/proposal/revision, lost lock ownership and tampered review history/store;
- direct execution of the packaged competitive evidence resource disclosing evidence.

## Status and external acceptance

All 15 G1 tasks remain **PARTIAL**, not DONE. Repository implementation and exact-head CI are necessary gates, but live acceptance remains separate where the task contract requires real provider/browser/runtime evidence.

Invariant gates before integration:

1. The final exact HEAD must pass the complete G1 child CI after delivery-manifest and change-slice binding.
2. Representative Staging runtime must prove bounded discovery against installed providers without executing unknown code or leaking secret values.
3. Any zero-effect read relied upon for automatic classification must carry a trusted conformance receipt for the exact graph/provider/schema generation.
4. Browser/operator acceptance must prove filtering, evidence boundaries, stale review handling and revision conflict behavior.
5. Integration Hub cumulative CI and post-merge Staging acceptance remain required.

`runtime_parity_claimed=false`. Production and Breakglass remain unauthorized.


## Hardening closure after repository review

The G1 repository pass additionally requires and tests:

- provenance-bound before-snapshot validation with same-site/environment rejection;
- explicit observed/emitted/truncated completeness metadata and lifecycle-aware absence semantics;
- transitive dependency impact with the existing dependency-impact foundation;
- output/data/capability sensitivity in read classification;
- trusted, artifact-bound conformance receipts;
- append-only review history with mandatory audit, digest verification, bounded commit time, token ownership and revision fencing;
- guarded competitive evidence summary/history resources with append-only retention and drift acknowledgement lifecycle;
- collector contracts that declare lifecycle, sensitivity, provenance, budget and completeness semantics;
- operator filtering by status, evidence class, workstream and runtime-parity claim, plus explicit remaining-task/acceptance drill-down.

Exact-head CI is an integration invariant; its terminal verdict is recorded as external GitHub evidence rather than self-referential in-commit state.
