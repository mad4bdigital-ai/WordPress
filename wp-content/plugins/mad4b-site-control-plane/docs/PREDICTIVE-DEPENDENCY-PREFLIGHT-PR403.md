# MAD4B Predictive Dependency Preflight — PR #403

## Why this gate exists

The previous pattern was repair → build → upload to two Staging sites → uncover a
different MCP/owner/provider failure → repeat. Every green local unit-test suite
covered only its immediate source edit. This gate makes **transitive blast radius**
and its required regressions visible *before* a new release candidate is packaged.

The gate is read-only and non-authorizing. It never contacts live WordPress,
issues owner attestations, deploys archives, grants Write, or mutates Production.

## Executable architecture

1. Derive exact changed paths from Git base/head; include deleted PHP files.
2. Extract first-party class identities from both the changed PHP and original
   base blobs, including renamed/removed class names.
3. Discover reverse dependencies among current first-party includes and hermetic
   test consumers (bounded file scan, no hard-coded plugin-version pin).
4. Classify impacted capabilities, then traverse the dependency graph:
   MCP transport → provenance and OAuth → owner Step-up → exact write binding
   → database reservation / backup and rollback → package identity / Production.
   Provider and Skills changes pull in owner, transport, adapter and readback.
5. Require an existing hermetic regression for every impacted domain. Missing
   or failed probes return nonzero and block Pre-Staging CI and package build.
6. Repeat mandatory probes on PHP 7.4 (legacy compatibility), 8.3, and 8.5
   (the current observed Staging PHP major/minor), with exact head scoping.
7. Write a JSON evidence manifest including changed classes, reverse consumers,
   transitive edges, predicted failure modes, mandatory tests, actual results,
   and required live checks. **Live evidence is not generated from CI.**

Command (PR branch checkout):
```bash
python3 tools/mad4b_predictive_dependency_gate.py --self-test
python3 tools/mad4b_predictive_dependency_gate.py \
  --base BASE_SHA --head HEAD_SHA --execute --enforce \
  --output dist/mad4b-predictive-dependency-gate.json
```

With no trusted Git base (manual dispatch), use `--full-scan` rather than
assuming the candidate has no impact.

## Live acceptance debt — deliberately not auto-closed

| Dependency | Why a green CI is insufficient | Staging acceptance requirement |
|---|---|---|
| Host / MCP transport | The two sites showed ~17s beyond WordPress request time, intermittent opaque failures | Per-site serial read probes and host correlation IDs; see issue #409 |
| ETG Elementor | Single-ability closure matrix failed >30s even after the general handshake fix | Repeat limited reads with gateway + origin trace; no shadow activation |
| Owner intent / write candidate | Installed head differs from bound head; earlier receipts expired | Fresh owner OAuth step-up, exact plan digest, per-site apply and readback |
| Backups / DB concurrency | Hermetic locks do not prove live restore | Staging restore evidence, race checks and no-replay audits |
| MCP Adapter | Bundled historical adapter differs from runtime active 0.7.0 | Ensure runtime 0.7.0 and no unapproved downgrade |
| Skills / Providers | Skills v13 READY does not certify Elementor writes | Separate bounded canary, behavioral parity and rollback receipt |
| Web/PHP performance | Request time and connector wall time differ; shared hosting | Exact prior-build baseline comparison and PHP 8.5 evidence |

## Correct gate semantics

- `ci_regression_coverage_ready=true`: code-impact probes passed.
- `release_ready=false`: no live Staging, owner or rollback certification
  was silently substituted for code evidence.
- `missing_regressions` or `failed_regressions` nonempty: nonzero CI
  exit and no new reviewed package candidate.
- Unknown, oversize or unresolvable historical source cannot be
  accepted as safe: the scan fails closed.
- The predictive dependency DAG is extensible by adding domain matchers
  and edges, **not** additional hard-coded per-Ability exemptions.

This intentionally does not claim that the MCP host latency has been fixed.
That work is tracked in issue #409; exact candidate authority in issue #292.
