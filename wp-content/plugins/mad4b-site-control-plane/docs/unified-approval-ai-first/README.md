# MAD4B Control Plane — Unified Approval & Agent Authorization (Core Merge 0.2.1 — AI first)

**Artifact:** source-level core merge for the existing `mad4b-site-control-plane` WordPress plugin. This is **not a separately installable WordPress plugin**. It replaces the older standalone `MAD4B Unified Approval` pilot distribution.

## Scope

The seven PHP files under `includes/` become core classes, sharing the original Site Profile, Brand Context policy, Agent Registry, Identity Context, Approval Tickets, Impact Policy, append-only Audit and original content-owner receipts. No new independent login, grant engine or Audit storage is introduced.

| Core class | Function |
| --- | --- |
| `MAD4B_SCP_Unified_Policy` | Global / environment / module / operation / resource review-policy overrides; mode and finality rules; mandatory original authority floors |
| `MAD4B_SCP_Unified_Approval_Workflow` | Exact source-bound review stages, AI receipt verification, human nonce approvals, replay and drift rejection |
| `MAD4B_SCP_Background_Authorization` | Resolve original authenticated Agent using supported existing methods; require exact domain review grants |
| `MAD4B_SCP_Unified_Gate_Bridge` | Read-only projection of original Impact Policy and approval requirements, including protected operations |
| `MAD4B_SCP_Unified_Approval_Admin` | Local WordPress Admin UI and protected REST routes |
| `MAD4B_SCP_Unified_Approval_Extensions` | Optional certified transport/browser **descriptor** registration (never executes or mints credentials) |
| `MAD4B_SCP_Unified_Approval_Queue_Bridge` | Non-authorizing read-only result for future Background Agent / queue integration |

### Review policy compatibility

Original Brand Context `human_only`, `human_and_ai`, `ai_only` are not modified. **All hybrid workflows are AI-first.** The supported decision rules are `human_final`, `ai_final`, `all_required`, `ai_then_human`, and `risk_adaptive`. `human_and_ai` defaults to `ai_then_human` (`PENDING_AI` → verified AI approval → `WAITING_HUMAN` → human decision → `REVIEW_APPROVED`). `all_required` and `risk_adaptive` also enforce AI first, not just both approvals. The earlier `human_then_ai` and `either_authorized` rules are retired for new writes; **previously saved experimental policies are projected read-only as `ai_then_human`**, without silently rewriting their stored records. Existing open reviews with an older policy fingerprint fail closed as stale and must be reopened on the current exact source. A human cannot preempt AI in a hybrid workflow. `human_only` still starts with a human and `ai_only` still ends on a valid AI decision.

Any commercial or protected operation still requires original provider gates and human authorization floors. **Review approval never grants publishing, price updates, inventory mutations, deployment or privileged execution.**

### Installation into *existing* Local MAD4B Core

1. Confirm that this is the enrolled ETG **Local Lab**, back up its WordPress database and plugin tree, and reconcile the current plugin against source-control changes from ongoing PRs.
2. **Deactivate the old separately installed `MAD4B Unified Approval` pilot**, if present; otherwise its REST registration can duplicate the core routes. Preserve its options until the local migration has been verified.
3. In a machine with access to the existing plugin directory, run read-only verification:

   ```bash
   python3 tools/apply_core_merge.py --plugin-root /path/to/wp-content/plugins/mad4b-site-control-plane
   ```

4. Only after checking the current source and that `wp-config.php` explicitly defines `WP_ENVIRONMENT_TYPE` as `local`, install with:

   ```bash
   python3 tools/apply_core_merge.py --plugin-root /path/to/wp-content/plugins/mad4b-site-control-plane --environment local --apply
   ```

5. The installer creates a timestamped backup of the original bootstrap and copies the seven files under the **original** `includes/` directory. It inserts a guarded `require_once` block immediately after `class-mad4b-scp-ai-only-local-activation.php` in `mad4b-site-control-plane.php`. Re-run installer only after explicit reconciliation: it rejects duplicate merges.
6. Use **Tools → MAD4B Approval Policies** in the Local WordPress admin. Only the original enrolled owner, with nonce and revision CAS, may change policy.
7. Run tests and verify the five original owner receipts via actual WordPress REST and WP-CLI before promoting source to Staging.

The installer is deliberately fail-closed when the WordPress environment is not explicitly local. It does not change the database, create users or assign Agent grants.

### Local protected REST routes

`/wp-json/mad4b-unified/v1/{policy,gates/plan,reviews/open,reviews/ai,reviews/read,browser/plan,queue/gate,extensions}`

These are registered by the **core** module only on Local. They reuse the original Agent's verification. `browser/plan` returns `WAITING_AUTH`, not a browser session. `queue/gate` can confirm review completion but always reports `execution_authorized=false` and requires original approval-tickets/owner verification for mutation.

### Tests

```bash
php tests/runtime-contract.php                  # 37/37 isolated mock contract
php tests/core-smoke.php                        # 12/12 isolated core security checks
php -S 127.0.0.1:18928 tests/http-functional.php # 32/32 HTTP-like mock checks; query with curl
python3 -m py_compile tools/apply_core_merge.py
php -l includes/class-mad4b-scp-unified-policy.php
```

All PHP files must pass lint. A real WP/REST authenticated end-to-end test is **required** before claiming operational integration.

### Original gates / limitations

- The first five content QA owners can issue source-bound review receipts. The Brand Context source has its own original review endpoint. Other modules are **read-only planned**, not execution-enforced by this patch.
- No policy update in a background task; no interactive OAuth impersonation or CLI identity substitution.
- No executable API key/mTLS/browser provider ships with this package. An optional provider must pass independent source pinning, original identity verification, site-binding and grants.
- Source-control PR and exact-head CI, Local acceptance, Staging acceptance, rollback, Production governance and approval remain separate.

## Rollback

Restore the backed up `mad4b-site-control-plane.php` and remove only the seven new core includes after verifying they were added by this patch. Existing original MAD4B classes and grants remain intact. Keep `mad4b_unified_review_policies_v1` and `mad4b_unified_review_workflows_v1` options for forensic readback until owner decides to archive them. Do not delete unrelated Context or Audit events.
