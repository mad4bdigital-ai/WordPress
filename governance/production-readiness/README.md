# MAD4B Production Readiness Matrix — Dynamic CI

This directory contains the **non-authorizing** development source for the 170 controls / 21 domains from the 2026-10-18 acceptance workbook.

## Source and generated artifacts

- controls.json: stable ID, priority, owner, acceptance condition, source references, initial historical observation.
- tools/production_readiness_matrix.py: validates identities/source references and reads live repository policy definitions for 14 production stages, 23 workstreams, 13 core-required gates.
- .github/workflows/mad4b-production-readiness-matrix.yml: on PR changes, development-branch pushes, master changes, or manual dispatch; read-only token.
- GitHub Action artifact: readiness.json and readiness.md, plus Excel-compatible UTF-8 CSVs: controls.csv, production-stages.csv, feature-workstreams.csv, core-required-gates.csv and required-ci.csv, all projected for the exact source commit.

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
