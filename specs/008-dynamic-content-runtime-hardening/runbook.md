# Operational Runbook

## Normal operation
1. Discover model in summary mode.
2. Expand only required taxonomy/meta sections.
3. Plan or simulate.
4. Inspect semantic diff and side-effect coverage.
5. Bind approval if required.
6. Apply governed bundle.
7. Read exact state.
8. Run convergence.
9. Accept and mint receipt.
10. Publish using exact accepted state.
11. Verify full state and consume receipt.

## Interrupted operation
1. Read operation status and journal.
2. Run recovery-inspect.
3. Exact-read current state.
4. Run recovery-plan.
5. If blockers or human drift exist, stop and mark manual_required.
6. Otherwise obtain required approval and execute recovery-apply.
7. Readback and close only after exact verification.

## Provider with irreversible/external side effect
- The plan must display the effect.
- Full rollback must not be claimed.
- Approval class escalates.
- Recovery may require manual/provider-specific action.

## Lock nearing expiry
- Refresh before the configured threshold.
- If hard deadline is reached, stop mutation and enter recovery classification.

## Staging certification
Execute eight scenarios defined in tasks T110-T117 and retain machine-readable evidence tied to exact build/site/policy digests.


---

## Post-deploy Runtime Convergence Addendum

Feature 008 now includes a provider-neutral runtime convergence layer for post-deploy drift.

### Dependency graph

```
deployment
  -> schema
     -> managed_skills
        -> provider_closure (conditional)
  -> authority_binding (owner gate)
  -> external_acceptance
  -> performance_advisory
```

The graph is extensible through `mad4b_scp_runtime_convergence_phases`. Add-ons may contribute bounded phases but cannot obtain authority from metadata.

### Automatic safe phases

Only these lifecycle operations may converge automatically on enrolled Staging:

- additive schema migration with physical readback;
- MAD4B-managed canonical Skill seed reconciliation;
- MAD4B-managed provider Skill reconciliation.

### Explicit gates retained

The convergence engine MUST NOT automatically perform:

- Control Plane release installation;
- candidate binding or Full Staging Authority widening;
- provider behavioral write certification;
- provider canary activation;
- Production mutation;
- raw SQL or Breakglass.

A successful governed self-update records a restart-bound convergence checkpoint and schedules one bounded cron continuation. The continuation verifies exact installed identity before running safe phases.
