# Quality Model

Release-blocking dimensions:
- Safety: exact binding, no authority expansion, fail-closed recovery.
- Correctness: exact readback, CAS-safe compensation, single-use receipts.
- Recoverability: durable checkpoints and verified recovery closure.
- Observability: complete correlation without sensitive-value leakage.
- Performance: bounded large-site discovery and provider callback budgets.
- Maintainability: adapter decomposition and explicit internal interfaces.
- Extensibility: provider SDK without executable persisted configuration.
- Operability: explainable simulation, semantic diff, visualizer, runbook.
- Certification: eight-scenario Staging canary tied to exact provenance.

No aggregate score can override a failed release-blocking invariant.
