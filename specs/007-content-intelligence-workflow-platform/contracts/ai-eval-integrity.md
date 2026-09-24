# Contract — AI Provenance, Reproducibility and Eval Integrity

Contract: mad4b.ai-eval-integrity.v1

## Reproducibility distinction

Provenance reproducibility:
the platform can prove which model/process/prompt/input artifacts produced a stored result.

Regeneration reproducibility:
re-running the same logical inputs produces byte-identical output.

Feature 007 guarantees provenance reproducibility for durable AI artifacts.
It does NOT claim regeneration reproducibility unless a provider/model contract explicitly proves deterministic execution.

## Stored result

The immutable original output/artifact is preserved as historical truth even if later regeneration differs.

## Eval partitions

Eval Registry distinguishes:
- DEVELOPMENT;
- REGRESSION;
- HOLDOUT;
- ADVERSARIAL;
- HUMAN_CALIBRATION.

Holdout/adversarial fixtures used for release decisions are access-controlled according to policy to reduce benchmark overfitting.

## Candidate separation

The same candidate under evaluation MUST NOT silently generate its own authoritative gold answer.

Model-based evaluators declare model/evaluator identity and limitations.

## Goodhart controls

Track:
- repeated tuning against same fixture;
- threshold changes;
- fixture exposure;
- metric drift;
- human disagreement.

A rising aggregate score cannot override hard factual/security/rights blockers.

## Fallback

A fallback model requires independent eval/data-processing eligibility; "same provider family" is not equivalence.
