# Central Staging Authority — Live Reconciliation Readback (2026-10-31)

Target: `tours.dev.mad4b.com` (UUID `e5e64bca-ed62-48d2-b2b3-dff693cd702a`), only Staging. All changes via authorized `registry.infra.mad4b` Root SSH and enrolled WordPress WP-CLI.

## Live source and design corrections

- Installed source commit `cb3faca67c64b4747ee2293c3589a690dfb066e9`; runtime manifest matches, stale=false.
- Backup made before installation:
  `/opt/staging/mad4b-managed-backups/central-staging/20261031T182559Z-59efa753` — 908 plugin files + 7 selected governance SQL tables.
- Original Staging Source Code preserved concurrently installed Content Readiness improvements and its isolated test.
- Repaired delegated OAuth identity consistency in Write Authority preflight/finalize, Grant Reconciliation Plan/Apply and Candidate Binding owner resolution. Accepted the exact enabled, same-user, same-environment `mad4b-delegated-user-1` without transferring its OAuth Subject to `chatgpt-governed-write`. Caller still needs its own original authorization.
- Native Host context successfully enrolled/bound by `MAD4B_SCP_Authority_Auto_Reconciliation::reconcile_safe()` using exact Site UUID, origin, database, Root WP-CLI non-impersonating mode, registered host actor, append-only audit, and Staging-only write environment.
- Original `MAD4B_SCP_Staging_Write_Authority::reconcile()` returned:
  `state=ready, ready=true, exact_grants_created=20, stale_allow_grants_revoked=9, grant_blockers=[]`.
- Independent readback: 112/112 exact current-environment grants, 0 missing, 0 stale, 0 unreviewed, 0 subject blockers, `current_ready=true`; original delegated OAuth Subject remains enabled and owned by `mad4b-delegated-user-1`.
- Public site and REST both HTTP 200. No Production mutation.
- PR #670 includes the source patch `central-delegated-oauth-grant-binding-cb3faca.patch` and runtime reconcile changes.

## Open blocker (do not pretend closed)

Candidate binding plan is `execution_eligible=true`, no blockers, source matches installed `cb3faca`; persisted binding is still `unbound`.

The exact Host CLI can authenticate to the same site and execute native authority convergence, but `MAD4B_SCP_Staging_Write_Candidate_Binding::can_execute()` rejects this legitimate principal with `mad4b_candidate_bind_admin_required` (OAuth-only path). MCP Full Staging Authority handshake in this session returned an internal tool error. There is **no verified host-native candidate-binding completion receipt**.

Design fix needed: first-class `bind_host` lane that validates exact Root WP-CLI Host Operator, registered Site/Agent/Owner, mutation scope, unique source/build/manifest/artifact identity, clean 112/112 grant snapshot, plan digest, operation-specific authority, append-only audit and rollback, and then calls the original `bind_candidate_identity` primitive. Preserve OAuth / step-up authorization path as a parallel original lane. Do not emulate OAuth with `--user=1`, modify WordPress options directly, invent audit evidence, or widen grants. Candidate binding and Host execution availability must remain separate state dimensions.

## Root causes identified

1. Lifecycle assumed that OAuth must be owned directly by the governed-write Agent although the user has a separately enrolled delegated-user Agent.
2. Current execution-provider availability was previously tied to an expiring historical diagnostic receipt (already corrected).
3. Live updates to the Content Readiness file caused false global deployment drift until incorporated in a canonical source build. Source/manifest updates must be serialized with plugin deployments to prevent recurrence.
4. WordPress's native Authority reconciler can safely converge exact grants from an enrolled Host principal, while the later Candidate Binding wrapper is OAuth-only. This asymmetry keeps Full Authority unavailable after grant readiness even with a clean plan.

## Final status

Central execution verified. Central source verified. Exact write grants complete and ready. Candidate binding still unbound; full effective governed write **not yet claimed**.
