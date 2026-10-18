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
6. The generator writes `capability-impact.json`, `capability-impact.csv`, `capability-impact.md` and a SHA-bound `capability-history-event.json` in addition to the original eight readiness artifacts. Each CI artifact is run-scoped; its configured retention is 30 days. Long-lived definition history is preserved in Git commits and PR review history, **not** asserted as a permanent evidence archive.
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
