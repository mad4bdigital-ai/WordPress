# PR #258 — per-file audit batch 01: boundary verification and triage

**Source snapshot (immutable):** `77beb9ad8e954bee5743e10187eb75e90ccc1f67`
**Audit metadata ledger:** `docs/reviews/feature007-pr258-file-ledger-20261011.json`
**Snapshot size:** 803 changed files: 648 added, 155 modified.
**Patch visibility:** 709 patch bodies with 112,326 added lines examined by candidate-pattern scanner; 94 no patch bodies (78 PHP, 8 Python, 3 JSON, 4 ZIP, 1 MO).
**Risk queues:** P0-first 27, P1 98, P2 266, P3 412. These are **routing scores**, not verified defects, exploitability grades or completion labels.

## Method and limits

This first round produced an exact-source **per-file metadata/evidence backlog** across all nine GitHub changed-file pages, then sampled sensitive source modules, including direct fetch of four absent-patch files. The patterns include dynamic execution, deserialization, database writes, filesystem writes, outbound network calls, authorization decisions, and insecure transport candidates.

The batch has **not** completed 803 manual reviews or executed the PR-wide native PHP suite. An absent patch is marked unavailable, never silently treated as examined. Existing main-branch behavior and complete cross-call contract consequences still need targeted diff and native scenario validation.

## Focused source checks from this batch

| File | Actual targeted check | Finding / classification | Additional acceptance |
| --- | --- | --- | --- |
| `includes/class-mad4b-scp-cso-secrets.php` | Reviewed temporary-token creation, first-party browser/session identity, provider certification, native permission probe, serialized-record decode/CAS, and response headers | First-party handoff includes explicit CSRF/session/environment fences, `unserialize(..., allowed_classes=false)`, MAC unsealing, and `Referrer-Policy: no-referrer`. **Residual privacy risk:** an opening token is placed in a query-string URL, which can be captured by some intermediary access logs independently of browser referrer policy. Not an established exploit; threat-model/logging verification required | Proxy redaction and POST bootstrap alternative; native secret handoff negative tests |
| `includes/class-mad4b-scp-cso-storage-adapters.php` | Reviewed native provider class allowlist, descriptor matching, read Ability permissions, scope recheck and output boundedness | No universal WP options writer observed in this module; snapshot is read-only and discovered adapters require an explicit registered class and Ability. No authorization inference from discovery | End-to-end provider-native write/readback and multi-site tenant isolation |
| `includes/class-mad4b-scp-context-authority.php` | Sampled REST permission boundaries, existing-state mutation functions, and registry/lock operations | Sensitive, ~214KB module has several controlled writes and a registry lease; only selected regions inspected, no complete behavioral certification | Full source review, race/CAS tests, tenant isolation and rollback |
| `includes/class-mad4b-scp-content-experience-media-storage.php` | Checked media read permission check and source file structure from a missing-patch entry | Uses `current_user_can('read_post', $id)` for the inspected attachment read path. Other storage paths remain unreviewed | Media ownership, WPML locale access and write permission tests |
| `includes/class-mad4b-scp-wp-native-build.php` | Reviewed source-commit pin, path validation, blob hashes, private staging, ZIP, owner Step-Up, WP-Cron locking and 8KB chunk transport | Existing source includes fail-closed content hashes and staged file protections. **P0 residual:** no site-global atomic `start()` admission fence; worker lock is scoped per source and is not an exactly-once start guarantee. **P1:** ~150 Cron iterations for 599 files and >1,500 MCP chunks for source-sized ZIP | Atomic admission + operation identity; exact-head PHP/Cron/ZIP tests and signed archive transport |
| `includes/class-mad4b-scp-staging-write-authority-convergence.php` | Reviewed can_apply vs apply entry points, exact plan/binding checks, audit checkpoints and blocked error exits | Stage-based, rollback-aware approach observed; complete authority race and failure recovery proof not established | Negative ticket expiry, token scope, owner drift, simultaneous applies |
| `includes/class-mad4b-scp-local-oauth-server.php` | Sampled consent nonce, PKCE/redirect validation path, issuer/key readiness, and output header CSP boundaries | Sensitive large module; no complete OAuth code/token lifecycle audit or cryptographic acceptance yet | OAuth threat-model matrix, replay/expiry/redirect URI fuzz tests |
| `includes/class-mad4b-scp-cso-wp-post-driver.php` | Inspected post edit permission boundary, direct SQL row locks and native WordPress post mutation hooks | Transactional post writer candidate; must verify combined revision check, native post API side effects, and rollback across failures | DB transaction/isolation level integration tests and write-readback |
| `includes/class-mad4b-scp-selected-head-update.php` | Inspected source resolver, candidate evidence, read-only plan, consent/authorization and actual package update callback | Review supports separate plan/apply with trusted candidate checks; no certification without signed package and post-install readback | GitHub CI vs offline Ed25519 manifest equivalence and wrong-site refusal |
| `tests/g9-resilience-state-runtime.php` | Inspected automated pattern finding for `chmod(0777)` | **Test-only** scenario, not evidence of production directory permissions. Check fixture's sandbox and teardown before changing it | CI sandbox provenance |

## Next audit batches — evidence sequence

1. P0-first source and permission boundaries: 27 candidates, manual diff+source review and negative cases.
2. Resolve **78 PHP files with no GitHub patch body** by verifying source blob identity and locally running `php -l`; omitted patch text alone is never treated as success.
3. PHP 7.4/8.3, Python AST, JSON, JS syntax and ZIP traversal checks on clean immutable checkout, tied to file ledger identities.
4. Runtime transaction/authorization/outage/rollback tests and an independently signed Staging acceptance report; no reliance on queued Actions but no skipping gates.
5. Compare Staging installed SHA with audited source and check web/MCP/admin/cron/third-party acceptance after authorized deployment.

No Production mutations; no G9 bypass, branch-protection bypass, or claim of complete human certification.
