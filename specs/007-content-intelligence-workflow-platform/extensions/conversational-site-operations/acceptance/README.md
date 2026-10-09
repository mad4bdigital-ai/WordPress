# CSO01 dynamic acceptance suites — extension contract

This **supplementary** acceptance pack adds seven dynamically composed evidence suites without replacing the frozen 11 parent gates (CSO-G0–G10) or changing the 27 requirement / 81 task denominator.

## Composition

- `registry.json`: add suites, bounded checks, required negative cases, evidence class, parent gate references, dependency DAG, and non-authorizing OPEN states. Registration of a check is never a live test.
- `profiles.json`: inherit `baseline` and tighten a threshold, or add optional checks. The resolver rejects weaker min/max thresholds, missing dependencies, cycles, duplicate IDs and unknown checks. Multiple profiles may share the same suite registry.
- `site-overlay.example.json`: optional instance-level scope (enrolled site UUID + environment + Blog ID) and **tightening-only** settings. No passwords, authorization tokens, endpoint credentials, operator claims or runtime grants.
- `validate_dynamic_acceptance.py`: validates the configuration and prints resolved requirements; no WordPress calls, no evidence verification, no success certification.
- `test_dynamic_acceptance.py`: verifies defensive scenarios, inherited defaults, non-removable suites, false PASS denial and site-overrides. It is a repository test, not Browser/Host/Staging proof.

## Seven extension suites

| Suite | Name | Parent gates |
|---|---|---|
| CSO-A01 | Schema Resilience | CSO-G2 |
| CSO-A02 | Discovery at Scale | CSO-G1, CSO-G9 |
| CSO-A03 | Provider Lifecycle | CSO-G1, CSO-G7 |
| CSO-A04 | Identity & Authority | CSO-G1, CSO-G6 |
| CSO-A05 | Client Experience | CSO-G2, CSO-G9 |
| CSO-A06 | Independent Staging Read | CSO-G8, CSO-G10 |
| CSO-A07 | Independent Generalization | CSO-G6, CSO-G10 |

Each suite is **OPEN** by default and stays independent of parent release certification. No profile can disable a suite. New plugin types, languages, catalog sizes, and access modes extend data-driven registry entries and profiles, not hardcoded site exceptions. Additive checks remain OPEN and should not silently reduce an already required check.

## Dynamic resolution rules

1. Choose a versioned profile, then optionally overlay it with the enrolled site + blog scope. Do not auto-trust a site-supplied profile.
2. Merge from baseline through parent profiles to the selected profile and the optional site overlay.
3. An increase to `min_*` is permitted; a decrease is rejected. A decrease to `max_*` is permitted; an increase is rejected. Values are **proposed configurable targets**, not measured numbers.
4. Required check IDs are unioned, never removed. Suite prerequisites are a DAG. Parent CSO-G0–G10 remain untouched.
5. Resolve separately for every site, provider generation, schema digest, runtime / PHP matrix and actor context. Registry declarations, comparisons and simulations cannot authorize any read or write.
6. External verification must bind evidence to **exact HEAD, artifact hash, site UUID, environment, source generation, observation time, verifier identity and verified signature**. Mock or unverified user-supplied receipts never close a gate.
7. Until an independent verifier is implemented, the resolver reports `CONFIG_VALID_ONLY` and `NOT_RUN` for operational acceptance. No claim of Staging execution, native PHP or CI PASS is made.

## Example commands

```sh
python3 acceptance/validate_dynamic_acceptance.py --profile baseline --json
python3 acceptance/validate_dynamic_acceptance.py --profile large_catalog --site-overlay acceptance/site-overlay.example.json --json
python3 acceptance/test_dynamic_acceptance.py
```

Run from the CSO01 extension directory. These commands validate configuration, not production readiness. Thresholds must be calibrated and independently approved with representative site benchmarks; there is no implicit SLA guarantee. Site binding and same-origin clone proof require Host/WordPress readback, not a JSON declaration.

## Future implementation boundary

A future verifier should accept only authenticated, non-replayable evidence receipts from trusted native test runners and external signed Host/Browser/MCP providers; enforce expiration, actor/site/branch generation, signature trust roots and independent proof provenance; compare results to resolved check IDs; then request separate owner approval. It must never infer approval from valid JSON, a dry run, a mocked adapter, a configured profile or a test count.
