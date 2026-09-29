# Constitution

1. **Fail closed** — ambiguity, stale state, missing ownership evidence, missing provider coverage, or unverifiable recovery blocks mutation.
2. **Exact binding** — approvals, recovery plans, acceptance receipts, and publication handoffs bind to exact state and policy digests.
3. **Human edits win over compensation** — compensation never overwrites state that drifted after the orchestrator's last owned write.
4. **Authority is independent from discovery** — discovering a model/provider never creates a grant.
5. **No executable persisted configuration** — callbacks originate only from trusted code.
6. **No false atomicity** — external/irreversible effects are declared and surfaced.
7. **Read-only explainability** — traces, simulation, diffs, and visualizers cannot mutate or authorize.
8. **Bound everything** — loops, terms, meta sampling, events, diffs, metrics, provider callbacks, TTLs, and recovery attempts have explicit limits.
9. **Recover from evidence, not guesses** — recovery requires journal + current readback + ownership/CAS evidence.
10. **Staging before Production** — certification proves behavior on Staging and does not authorize Production writes.
