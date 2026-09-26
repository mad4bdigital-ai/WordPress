# Contract — Critical State-Machine Model Verification

Contract: mad4b.formal-model-critical-state.v1

## Scope

Feature 007 does not require formal verification of the entire platform.

It DOES require executable/model-based invariant testing for three critical state families:
1. authority / approval / commit guard;
2. worker lease / fencing / retry;
3. provider certification / release-ring / quarantine;
4. Artifact evidence supersession/invalidation monotonicity;
5. Publication verification verdict reduction.

## Required properties

Authority:
- revoked grant cannot commit;
- stale approval cannot commit;
- kill switch dominates allow;
- bootstrap transition cannot escape declared scope.

Worker:
- at most one fencing epoch can successfully commit at a time;
- expired zombie worker cannot overwrite newer owner;
- duplicate delivery cannot create duplicate effect.

Certification:
- quarantined capability cannot execute;
- promotion cannot skip required evidence states;
- revocation/demotion propagates to eligibility;
- Production authorization is never inferred from ring eligibility.

Artifact evidence:
- ordinary supersession/invalidation never deletes provenance records;
- superseded evidence remains reconstructable;
- dependent invalidation is bounded and marks downstream evidence stale;
- supersession edges are not destroyed by descendant invalidation.

Publication verification:
- PASS requires both origin and public-edge convergence;
- origin mismatch dominates to FAIL;
- edge mismatch within the bounded propagation window is PENDING_PROPAGATION;
- edge mismatch after timeout is FAIL;
- verification never creates publish, cache-purge, or indexing authority.

## Approach

Use deterministic model/property-based tests.
A model checker MAY be added where it materially improves assurance.

## Liveness

Models also test reachability of intended good states, not only denial invariants.

## Regression

Any discovered state-machine production bug becomes a permanent model/property fixture.
