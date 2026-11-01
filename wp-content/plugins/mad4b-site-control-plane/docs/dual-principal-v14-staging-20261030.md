# Dual Principal v14 — Staging implementation receipt

Date: 2026-10-30. Target: `https://tours.dev.mad4b.com`.

## Confirmed design

- **Default**: authenticated WordPress user, including permissions inherited by registered Developer, Host, SQL and Production Deployment Abilities.
- **Optional**: independent NHI / Service Agent for scheduled and detached operations.
- A WordPress session is not permission to execute arbitrary operations: the original Ability's capability/target checks, site mutation gate, Breakglass/Production runtime gate, peer/restore binding and execution evidence remain applicable.
- No automatic registration of synthetic agents, no automatic content publication and no extra independent Writer review as a default prerequisite.

## Completed Staging work

1. Schema v14 adds `principal_type` to the mutation journal, makes `agent_id` nullable and indexes `(principal_type,wp_user_id,created_at)`. Readiness receipts and schema integrity are versioned separately from v13.
2. A private SQL backup of the 24 original journal rows exists on the Staging host before migration.
3. A copy of the original mutation table was migrated and tested. All 24 legacy actors remained `independent_nhi`; a test `wordpress_user` row was accepted with NULL `agent_id`; the isolated test table was dropped.
4. Real Staging schema migration completed, and `MAD4B_SCP_Schema::is_ready()` and `critical_ready()` passed. All 24 historical journal records remain `independent_nhi`, none with NULL `agent_id`.
5. Typed actor helper `MAD4B_SCP_Mutation_Principal` supports NHI records, native WordPress identity verification and same-user/site checks for reversible post/taxonomy mutations.
6. The reversible adapter journal and undo child journal now record `principal_type`, real `wp_user_id`, and nullable `agent_id`. Same principal is rechecked under the undo scope lock.
7. Delegation supports registered MAD4B Abilities using WordPress administrator permission for unmapped privileged routes. Existing SQL/Developer/Production runtime gates and nested dispatch restrictions are retained.
8. PHP lints and isolated dual-principal identity/undo regressions passed; Staging public site returned HTTP 200.

## Actual permissions observed (no dangerous execution)

| Ability | Native WordPress principal result |
| --- | --- |
| `mad4b/developer-execute` | Accepted as a registered WordPress-managed Ability |
| `mad4b/host-operation-apply` | Accepted as a registered WordPress-managed Ability |
| `mad4b/developer-breakglass-shell` | Denied because the Developer Breakglass runtime flag is disabled |
| `mad4b/write-execute` | Denied with empty target because a bounded nested target is mandatory |
| `mad4b/deployment-binding-production-status` | Accepted; **read/status only** |
| `mad4b/content-create-post` | Denied with empty input because the exact authorized draft target is required |

## Not yet certified

- Live native post create → mutation journal → rollback was not verified: tool execution blocked the test invocation before it ran.
- `MAD4B_SCP_Authorization::claim_mutation()`, budget reservations and approval tickets still use NHI-specific `agent_id` contracts. **They must adopt the typed WordPress principal before generic Shell/SQL/Production write execution is declared complete**.
- `MAD4B_SCP_Mutation_Manager` (legacy post-update/undo) separately persists `agent_id` and requires the same type migration.
- Browser cookie, OAuth, Application Password, cross-site and revocation round trips are not fully certified.
- No Production deployment or SQL/Shell operation was executed.

## Follow-up acceptance

Migrate generic execution claims and budget attribution to a typed principal with readback, replace direct NHI lookups in legacy post mutations, and verify all registered abilities using real authenticated WordPress requests. Then certify live reversible create/undo and isolated negative tests before considering the draft PR releasable.
