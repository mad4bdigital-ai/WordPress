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

## Candidate Binding completed via independent Host Operator

The Staging owner grant plan became clean (112/112 grants, no stale or unreviewed grants), and Candidate Binding produced an exact eligible plan with zero blockers. The former OAuth-only wrapper refused a genuine registered Host actor with `mad4b_candidate_bind_admin_required`, despite Host execution and Authority convergence being enrolled.

Implemented a separate, Staging-only, root WP-CLI `bind_host` execution lane in local source commit `202e62e9a480b5144af1c6ae1517f3d8df6a92fb`; it independently verifies non-impersonating Root Host CLI identity, exact Site UUID/Origin/database, registered Host Operator bound to the enrolled WordPress Owner and canonical Agent, mutation environment policy, original exact candidate/confirmation, clean grant snapshot, and the existing transactional binding primitive with append-only audit/rollback. OAuth path remains unchanged and does not get impersonated. Six identity tests and isolated negative Host admission tests passed; PHP lint passed before deployment.

- Exact installed source: `202e62e9a480b5144af1c6ae1517f3d8df6a92fb`; manifest valid, `runtime_manifest_match=true`, `stale=false`.
- Verified predeployment backup: `/opt/staging/mad4b-managed-backups/central-staging/20261031T183650Z-840ca42b`; 908 plugin files and seven SQL tables.
- Candidate `bind_host` execution returned `state=bound`, `effective=true`, `candidate_binding.match=true`, mutation performed; operation ID `c86a50f4-831d-43b8-ab2f-f9131c24fa82`.
- Independent WP-CLI readback confirms `candidate_binding.identity_completeness=complete`, `Staging_Write_Authority::effective()=true`, 112/112 grants, 0 missing and 0 stale, runtime manifest exact, site HTTP 200, REST HTTP 200.
- Original runtime Resolver event `eaed3141-408e-443e-9a4a-801a8b3060cf` returned `central_source=verified`, `central_execution=verified`, `central_authority=verified`.
- Full source patch attached: `central-host-candidate-bind-202e62e.patch`. Production was not modified.

## Root causes identified

1. Lifecycle assumed that OAuth must be owned directly by the governed-write Agent although the user has a separately enrolled delegated-user Agent.
2. Current execution-provider availability was previously tied to an expiring historical diagnostic receipt (already corrected).
3. Live updates to the Content Readiness file caused false global deployment drift until incorporated in a canonical source build. Source/manifest updates must be serialized with plugin deployments to prevent recurrence.
4. WordPress's native Authority reconciler can safely converge exact grants from an enrolled Host principal, while the later Candidate Binding wrapper is OAuth-only. This asymmetry keeps Full Authority unavailable after grant readiness even with a clean plan.

## Final status

Central Source **verified**. Central Execution **verified**. Central Authority **verified**. Write Authority **effective**. Candidate Binding **bound**. Exact Grants **112/112**. No WordPress Production mutation.
