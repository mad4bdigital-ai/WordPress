# CE01 — Competitive Experience and Governed Capability Breadth

Optional Spec Kit extension of Feature 007, prepared from the four user-supplied
competitor archives and two accompanying Arabic comparison/operations proposals. Reviewed baseline:
`32e32a94ddb1b7864f8924ad22cb0b03199856be`, after PR #255 merged.

This extension contains **53 capability requirements, 29 workstreams and 145 OPEN
implementation tasks (T3901–T3980 plus T4001–T4065)**. Its phase numbers 0–28 are local to CE01.
The parent release remains phases 0–38, 837 tasks and 34 closure workstreams.
CE01 does not increase the frozen rc.95 release scope or its readiness denominator.
Future implementation requires separately reviewed, evidence-backed slices.
`extension.json` declares its optional scope without creating a second Feature 007
identity for the baseline-owned feature-boundary verifier.

Start with [comparison.md](comparison.md), then [spec.md](spec.md),
[plan.md](plan.md), [tasks.md](tasks.md) and [traceability.md](traceability.md).
`capability-matrix.json` maps each feature to exact competitor source evidence,
existing MAD4B foundation, required delta, owner, acceptance and denial cases.
Both original Arabic reports are retained byte-for-byte in
`source-comparison-report.ar.md` and `source-adaptive-operations-proposal.ar.md`; its scores, live counts and conclusions are
user-provided historical claims, not new measurements or certification.

## Original archive snapshots

- [AI Engine 3.7.6](artifacts/ai-engine-3.7.6.zip)
- [Royal MCP 1.5.0](artifacts/royal-mcp-1.5.0.zip)
- [miniOrange Secure MCP Server 1.4.10](artifacts/miniorange-secure-mcp-server-1.4.10.zip)
- [Easy MCP AI 1.7.17](artifacts/easy-mcp-ai-1.7.17.zip)

`artifact-manifest.json` records original filenames, archive SHA-256, header
version/license, every member hash and inspection limits. No package was installed,
activated or executed. These are comparison references outside plugin runtime
and release-package inputs. Original licenses and notices remain in the ZIPs.

`source-index.json` binds static findings to one-based inclusive member line
ranges and raw-byte hashes. `BUNDLED_STATIC` means source exists in this archive;
`CLAIMED_UNVERIFIED` means the README advertises it. Neither means runtime-safe,
available, licensed to the current site or accepted on Staging. AI Engine RAG,
AI Forms and realtime-audio Pro claims stay distinct; miniOrange broad security
claims require independent proof.
`USER_PROPOSAL_UNVERIFIED` identifies the added Adaptive Operations design ideas;
it is neither bundled competitor behavior nor measured runtime acceptance.

## Reconcile and validate

Run from repository root:

```bash
python specs/007-content-intelligence-workflow-platform/extensions/competitive-experience/validate.py --write-ledger
python specs/007-content-intelligence-workflow-platform/extensions/competitive-experience/validate.py
python specs/007-content-intelligence-workflow-platform/extensions/competitive-experience/test_validation.py
python specs/007-content-intelligence-workflow-platform/reconcile_task_ledger.py --check
python specs/007-content-intelligence-workflow-platform/validate_spec.py
```

The parent validator invokes the extension validator. CI also runs tamper/path,
ownership, false-completion and missing-boundary denial tests. The CE01 generated
ledger is independent from the frozen release ledger. All 145 tasks remain OPEN
under the initial `SPEC_BACKLOG_ONLY` contract; a future implementation revision
must introduce actual closure evidence rather than check boxes in this snapshot.

[Adaptive Operations Fabric v2](adaptive-operations.md) adds runtime graph/classifier, declarative manifests, operation/workflow synthesis, shadow/canary certification, signed packs, ownership-aware reconciliation, update acceptance, host/provider registries and an Operator Action Center. L0–L5 boundaries are machine-validated in `adaptive-operations.json`.
