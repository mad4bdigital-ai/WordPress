# MAD4B Production Readiness Matrix — Dynamic CI

This directory contains the **non-authorizing** development source for the 170 controls / 21 domains and 11 independent signoff requirements from the 2026-10-18 acceptance workbook.

## Source and generated artifacts

- controls.json: stable ID, priority, owner, acceptance condition, source references, initial historical observation.
- tools/production_readiness_matrix.py: validates identities/source references and reads live repository policy definitions for 14 production stages, 23 workstreams, 13 core-required gates.
- .github/workflows/mad4b-production-readiness-matrix.yml: on PR changes, development-branch pushes, master changes, or manual dispatch; read-only token.
- GitHub Action artifact: readiness.json and readiness.md, plus Excel-compatible UTF-8 CSVs: controls.csv, production-stages.csv, feature-workstreams.csv, core-required-gates.csv, required-ci.csv and independent-signoffs.csv, all projected for the exact source commit.

## Usage

python3 tools/production_readiness_matrix.py validate
python3 tools/production_readiness_matrix.py self-test
python3 tools/production_readiness_matrix.py render --head-sha "$(git rev-parse HEAD)" --repo mad4bdigital-ai/WordPress

## Trust boundaries

1. Historical spreadsheet observations are NOT authoritative. GitHub workflows are evaluated for the exact SHA only.
2. Missing, queued, cancelled and failed checks are NOT a successful repository CI verdict.
3. A passing matrix contract only means the data and binding are consistent; it does NOT certify WordPress runtime or sites.
4. Staging live receipts and signed, site-bound evidence come only from trusted producers. No CI outcome substitutes for these receipts.
5. Production is NEVER automatically marked Ready or Authorized; it needs an independent promotion decision, exact package/adapter identity, protected backup/restore verification, and owner approval.
6. This workflow has no release, SSH, WordPress write, secrets write, Developer, Breakglass, or Production deploy permissions.

## Editing

Edit source JSON in reviewable pull requests. Keep the original 170 IDs; new controls get new IDs.
The validator rejects duplicate IDs, missing referenced files, source-policy weakening, and removal of the baseline controls.
CI reports are generated outputs, not source or authority. For hard branch protection, an authorized ruleset administrator must separately require the status named Production readiness matrix contract.

## Dynamic Capability Registry & Coverage Enforcement (stacked feature)

Whenever a PR adds an Operation Registry ID, an Adapter family/provider, a WordPress domain overlay, a functional-gap family, a Feature 007 workstream, a literal MAD4B Ability in registrar PHP, a new PHP service or a new Admin JS/CSS asset:

1. CI compares the **exact PR base** against the **exact checked-out SHA**. No stale cached head or mutable master approximation is accepted.
2. A deterministic source-diff collector records new and retired IDs; every changed MAD4B plugin file is classified by potential impact (authorization, deployment, MCP, admin, database, automation, content, platform). It does not claim that static scanning proves runtime registration.
3. The developer adds one `reviewed_capabilities` record to `capability-coverage.json` for every new ID: `id`, `control_ids` (at least two, including an affected domain), `regression_tests` (at least one *changed in the same PR*), `owner_role`, `review_rationale` (at least 24 characters), `status: reviewed_for_ci_only`, `live_evidence_required: true`, `production_authorized: false`.
4. Removing a capability needs a `lifecycle` record with `state: deprecated` or `superseded`, reviewed rationale, and explicit replacement for supersession. Historical 170 control IDs may not be deleted; a superseded control keeps its identity with a `superseded_by` target.
5. Uncovered additions, unreviewed retirements, missing tests, stale/fabricated coverage, or self-authorizing entries make the **Production readiness matrix contract** CI check **FAIL**.
6. The generator writes `capability-impact.json`, `capability-impact.csv`, `capability-impact.md`, `capability-history-event.json`, plus `control-history.json` and `control-history.csv` (per-Control-ID exact-head observations; no CI-based live certification) in addition to the original eight readiness artifacts. Each CI artifact is run-scoped; its configured retention is 30 days. Long-lived definition history is preserved in Git commits and PR review history, **not** asserted as a permanent evidence archive.
7. Regardless of CI results, actual WordPress runtime and Staging tests plus owner-governed Production promotion remain separate. The dynamic registry only enforces review coverage; it never grants an Ability, WordPress permission, Site Profile promotion or Production deployment.

### Negative-test examples

- New Ability with no coverage: fail.
- New Ability with a preexisting untouched test: fail.
- New Ability with claimed `production_authorized: true`: fail.
- Removed capability without reviewed deprecation or supersession: fail.
- Deleted historical Checklist control: fail.

### CLI

```bash
python3 tools/production_readiness_registry.py self-test
python3 tests/production_readiness_registry_contract.py
python3 tools/production_readiness_registry.py evaluate \
  --base-sha "$(git rev-parse HEAD^)" \
  --head-sha "$(git rev-parse HEAD)" \
  --out artifacts/production-readiness
```

**Scope of static detection:** registry entries, literal `mad4b/...` references in PHP files containing Ability registration patterns, newly introduced PHP service files and Admin JS/CSS assets. Dynamically assembled Ability names, feature behavior changes without new identifiers, and external plugins still require additional runtime/reviewer evidence. The impact matrix lists changed code, but a passing static scanner is not a full code review.

## Growing readiness — three enforced directions

**1. Wider coverage (new capabilities).** Source-diff discovery now includes operation,
adapter, overlay, gap, workstream, static WordPress Ability, REST, AJAX and Cron
identifiers, plus new PHP services/Admin assets. A capability review must explicitly
choose `needs_new_control: true` and add mapped `new_control_ids` in the same PR,
or choose `false` with a substantive `coverage_reuse_rationale`. New control
definitions are proposed by impact analysis but never silently approved.

**2. Deeper behavioral coverage (existing code changes).** Every added or modified
runtime PHP source, tracked configuration JSON and Admin JS/CSS in supported
subtrees requires a persistent `reviewed_changes` record keyed by
`path` and the exact `source_blob_sha` from `git rev-parse HEAD:path`.
Include two or more mapped `control_ids`, `regression_tests` changed in the
same PR, `owner_role`, `review_rationale`, `status: reviewed_for_ci_only`,
`production_authorized: false` and `live_evidence_required: true`. Renamed
or removed entrypoints require lifecycle review. Editing implementation
without changing an Ability name still requires a new source review.

Example, when retaining existing controls:
```json
{
  "id": "ability:mad4b/feature-new",
  "control_ids": ["SECURITY-01", "MCP-01"],
  "regression_tests": ["tests/new-feature-contract.py"],
  "owner_role": "Security and Feature Owner",
  "review_rationale": "Permission boundary and capability contract were tested together.",
  "status": "reviewed_for_ci_only",
  "production_authorized": false,
  "live_evidence_required": true,
  "needs_new_control": false,
  "coverage_reuse_rationale": "Existing permissions and MCP controls already cover this feature."
}
```

**3. Historical control revisions.** In addition to `control-history.json/csv`,
the renderer emits `control-delta.json/csv` with a SHA256 fingerprint of every
control definition at the immutable comparison base and exact PR HEAD.
Per-control states are `NEW`, `REVISED` and `UNCHANGED`; these describe
source definition changes, *not* release readiness or signed live acceptance.
Run-scoped CI artifacts contain these projections, and Git history preserves
the reviewed definitions; long-lived signed evidence is a separate governed
store, not implemented by this workflow.

The comparison uses the immutable merge-base when an upstream PR base has
advanced. A source-coverage review is bound to the resulting PR tree and cannot
self-authorize Production or replace a real Staging runtime verification.
Static analysis may miss dynamically constructed WordPress registrations;
the source-change review gate still requires human judgment and same-PR tests.

## Long-term readiness architecture and policy lifecycle

Four deliberately separate responsibilities:

| Layer | Implemented source | Authority boundary |
| --- | --- | --- |
| Control/Capability Registry | `controls.json`, `capability-coverage.json` | Defines review questions; no Production authority |
| Diff and Coverage Engine | `tools/production_readiness_registry.py` | Exact-head candidate discovery; CI coverage only |
| Evidence Envelope Boundary | `tools/readiness_evidence.py` | Validates IDs/metadata and hashes; **does not verify cryptographic signatures or trust** |
| Read-only Release and KPI Projection | `tools/readiness_quality.py`, `quality-policy.json` | Reconciles CI facts and unresolved live evidence; always NO-GO without external authorization |

### Five operational KPIs

The read-only workflow generates `quality-scorecard.json` and
`quality-scorecard.md` at the exact PR SHA. KPI definitions/targets live in
`quality-policy.json`: 100% P0 source-review coverage; <5% adjudicated CI
false positives; <=10 minute p95 evaluation time; 100% independently
verified site-bound live evidence; and zero unauthorized Production promotions.

Unknown denominators or unavailable trusted sources become
`INSUFFICIENT_EVIDENCE`, **never zero or PASS**. Source-review coverage
is only a proxy for CI review quality, not site certification. Human-adjudicated
false-positive decisions, completed workflow timing, independently signed
Staging receipts and Production audit evidence are not connected by this
workflow. It does not pretend that manually supplied JSON can sign itself.

### Governed impact rule migrations

Previously reviewed impact rules cannot be modified silently. If a rule
requires correcting, add one `policy_migrations` record under
`capability-coverage.json` with a unique `id`, exact `rule_id`,
`from_sha256` and `to_sha256` calculated over canonical JSON with sorted
keys and compact separators, `owner_role`, a meaningful
`review_rationale`, a regression test changed in the same PR, and
`status: reviewed_for_ci_only`, `live_evidence_required: true`,
`production_authorized: false`. Historic P0 risk and linked controls
cannot be reduced. The migration only allows reviewable source evolution,
not independent owner approval or site mutation.

### Rollout and evidence maturity

**Phase A:** fix failing tests and preserve nonauthorizing CI/negative tests.
**Phase B:** build a reviewed baseline capability map and risk-weighted
regression inventory; verify detection against a real set of PRs.
**Phase C:** connect an external *trusted* Staging verifier and immutable
Evidence Ledger; verify signature, package hash, site identity, expiry and
revocation outside of this reporter.
**Phase D:** use authoritative completed workflow/audit samples to measure the
remaining four KPIs, and add a separately authorized Release Decision
service and approval workflow.

GitHub Actions artifacts have 30-day retention and are not a durable,
tamper-evident Evidence Ledger. This PR does **not** create signed runtime
receipts, merge a PR, change Branch Protection, grant permissions or deploy.

### Historical baseline inventory

The workflow also runs `tools/readiness_baseline.py` on the exact checked-out
SHA. It produces `baseline-inventory.json` and `baseline-inventory.csv`,
enumerating source catalog IDs and static PHP registrations, their possible
risk/Control mapping and the gap between `OBSERVED_UNREVIEWED` and
`REVIEWED_SOURCE_COVERAGE_ONLY`.

This replaces the misleading assumption that an empty historic reviewed
registry equals fully covered existing features. Even a source-reviewed item
has `live_site_verified=false` and `production_authorized=false`. The
inventory is a review backlog, not a migration that silently certifies all
older features. Dynamic WordPress registration still needs authenticated
read-only runtime census when external trust is independently available.

## Remediation: guarded risk mapping, executed regressions, safe reports

The mandatory regression policy locks the minimum runtime root,
`includes/`, `assets/`, `config/`, root PHP, and PHP/JS/CSS/JSON
extensions independently from editable coverage rules. Widening coverage
is possible, but silently shrinking the minimum source perimeter is not.
Six source classification probes must remain correct on every pull request.
Rules cannot add a generic shadow before existing domains.

Source-only review is not sufficient for new capabilities: the
`tools/readiness_test_execution.py` executor reads the exact-head
`required_regression_tests` plan and actually executes mapped standalone
Python regressions in the CI job; PHP requires a trusted PHPUnit runner.
Unsupported executors fail closed. A trivial test passing is *not* a proof
of correct behavior, and independently governed review is still needed.

All exported CSVs use `readiness_csv.safe_writer` to protect Excel users
against formula injection from untrusted descriptions and paths.

The repository workflow snapshot is provisional and nonauthorizing.
The GitHub Actions query uses bounded pagination and latest-attempt
selection; missing/truncated API data must never be reported as successful
final release CI. Completion reconciliation and authenticated Staging
evidence remain distinct release gates.

Neither passing CI nor a changed test file represents Production approval.
