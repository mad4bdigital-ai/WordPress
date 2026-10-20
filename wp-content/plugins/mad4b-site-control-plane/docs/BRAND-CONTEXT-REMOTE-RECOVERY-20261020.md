# Brand Context remote recovery — Issue #446

**Status:** source-code remediation candidate only. Not live site acceptance; no automatic Brand approval, WordPress content publication or Production grant.

## Reported Staging observations

- ETG: governed Drive source was already enrolled. `context/source-scan-plan` had a complete inventory with four new documents. A canonical `mad4b/approval-plan` ticket was approved by the existing AI lane, but `mad4b/write-execute` stopped before the source-scan callback. There was no mutation receipt and Registry Revision remained 6.
- ARE: `context/source-scan-plan` returned an opaque governed read failure. `mad4b/context-source-onboarding-preflight` reported `GOOGLE_CONNECTION_RECOVERY_REQUIRED`; exact folder membership for new Brand Strategy/Tone documents was not proven. Registry Revision was 6. The operator's Google Drive connector and the WordPress site's Google OAuth connection are not interchangeable.

## Implemented in the PR branch

1. **Permission-denial trace without secrets.** The Authorization boundary records *only* the sanitized error code for the exact active target and PHP request. The dispatcher captures the code before clearing its request-local observation. Cross-target and cross-request reuse is denied.
2. **Typed pre-target failure.** `mad4b/write-execute` now preserves a bounded underlying error code even if the resilience adapter omitted it. Where WordPress normalized a permission failure to `ability_invalid_permissions`, the dispatcher may recover the actual scoped admission code. It reports `permission_callback_denied`, `pre_target_failure_code` and the proper safe `client_action` without exposing raw provider messages. A denied ticket is **not** treated as approval or permission; no automatic retry was added.
3. **Governed Drive source diagnostics.** `mad4b/context-authority-status` now exposes only *site-owned* governed sources, a SHA-256 digest of the configured Drive root, scan-completeness/status and a bounded read-only Google connection health projection. It does not display the raw root/file IDs, credential bytes, grant secrets or quarantined foreign records. It never mints grants, creates assets or triggers OAuth refresh.
4. **Runtime regression.** A hermetic PHP fixture exercises request-local denial capture and cleanup; a Python contract asserts fail-closed dispatch and read-only status shaping. Both are part of Governed Runtime Gates CI.

## Step-by-step governed operational runbook (after deployment)

### ETG Staging

1. Inspect `mad4b/context-authority-status`: confirm enrolled Site UUID, provider root digest and Google connection. Verify that the same **owned** source ID is still present.
2. If refresh is required, use the existing exact `mad4b/google-connection-refresh` plan and governed ticket; if disconnected, request an explicit Google OAuth reconnection by the site owner. Never substitute the chat Google Drive token.
3. Read a **fresh** `context/source-scan-plan`, verify inventory completeness, expected registry revision and exact source/provider digest. Do not reuse ticket or plan from a previous build/attempt.
4. Submit the canonical `mad4b/approval-plan` for `context/source-scan-apply` with no invented `target_fingerprint`. Use only the policy's declared AI or human approval lane. Apply the **new** one-time ticket through `mad4b/write-execute`.
5. If pre-target failure occurs, inspect `pre_target_failure_code` and `client_action` and fix the source, OAuth identity, runtime mount or owner claim. **Do not blindly retry the ticket**. Compare registry revision/asset hashes from a new read before planning again.
6. Read back the new asset IDs/categories/content hashes and `context/review-queue`; keep Tone of Voice unapproved until an actual owner review. Editorial Guidelines require the previously approved Tone of Voice.

### ARE Staging

1. First restore/verify the **site's own** Google OAuth connection; read the new health state and owned source root digest.
2. Verify the planned Google Drive folder and file membership against the exact enrolled source. An independently created document or a chat-scoped Drive listing is not proof of site ownership or governed source membership.
3. Import the approved review pack as **draft context records only** after fresh source plan and exact source identity proof. Do not transfer ownership of legacy operational workbooks or reclassify them as Brand Strategy.
4. Review Brand Strategy, then Tone of Voice, then Editorial Guidelines through exact source hash, registry CAS, `context/remote-owner-review-plan`, the human owner's independent decision and `mad4b/context-ai-review` where policy mandates both. No automated owner attestation.

### Acceptance

- In a deployed build, run genuine enrolled ChatGPT OAuth -> exact-ticket approval -> `context/source-scan-apply` -> fresh readback on both sites. Confirm no unauthorized grant widening or cross-brand documents.
- Run negative cases: wrong source, missing/expired ticket, changed registry SHA, foreign folder, disconnect/refresh failure, wrong owner and replay. No provider mutation may succeed in these cases.
- Verify site-specific `context/brand-core-coverage` becomes READY only after exact three-category approval with correct review sequence. Staging publication and all Production operations require separately evidenced authority.

**Residual:** These changes improve observability and safe recovery. They do not assert that the original pre-target admission defect or ARE Google connection has been fixed on a live Staging site. A certified new package and real OAuth/site acceptance remain required. A closed Issue #446 needs both independent site receipts, not CI alone.

## Independent cross-site closure checklist (read-only)

The opt-in `mad4b/brand-core-remote-recovery-status` input `include_operational_diagnostics=true`
now returns `operational_diagnostics`, a **non-authorizing** synopsis. It does not
perform a source scan, Google token refresh, grant convergence, owner approval or
any publication. Its negative `*_certified=false` flags reflect **evidence not
collected in that particular read**, not proof that the live feature is broken.

Observed site-local evidence on 20 October 2026 (re-observe after installing a
new certified package; never hardcode these facts as product behavior):

| Check | ETG Staging | ARE Staging | Required independent acceptance |
| --- | --- | --- | --- |
| Installed release | `0.4.0-rc.102`, SHA `a0ec8e00` | same | install new exact-head Staging-only release and read back identity |
| Write Grants | 101/101 current | 96 current plus **5 stale** not in active runtime inventory | owner-reviewed fresh exact `staging-write-authority-convergence-plan` and same-request + later readback; never silently revoke/regrant |
| Google Drive OAuth | `healthy`, read/write | `refresh_failed`, Google `invalid_grant` HTTP 400 | reconnect **WordPress site's own** Google OAuth interactively; confirm exact source folder membership, scopes and subsequent scan |
| Brand Core | Strategy approved, Voice/Editorial unapproved | all three categories unapproved | exact review plan + owner receipt per category + source SHA + human policy; no inferred review |
| Registry | revision 6 | revision 6 | fresh `context/source-scan-plan`, one-time ticket, append-only audit and independent hash/category readback |
| Skills | live certification READY; build-bound historical checkpoint stale | same | verify or rebuild **separate** current-build checkpoint, not merely seed existence |
| Provider closure | 29 operational actions; 22 require owner review | 31 operational actions; 26 require owner review | closure by actual provider applicability, behavioral/canary/rollback receipts; no mass auto-enablement |
| Public HTTP | sampled routes HTTP 200; homepage `hreflang=0` | homepage HTTP 200; sampled ~4.1s | repeated independent browser, locale, canonical, AJAX and budget acceptance; HTTP 200 alone insufficient |

**Order and hard boundaries**

1. **Source integrity:** pass release CI and owner gates on the *new exact
   PR head*. PR #447 carries protected root-only workflow changes and cannot
   be absorbed into PR #443 without violating the baseline-owned
   `verify_feature_boundary.py` root-of-trust rules. Merge the protected
   governance prerequisite independently before finalizing the runtime PR.
2. **ETG Source Scan:** refresh the exact inventory and digest (previous
   plan `9395cd45...`, revision 6 is NOT reusable as authorization).
   Use the existing one-time approval path and a bounded `write-execute`
   result; if permission denied or target not entered, retain the error code,
   do not blindly replay, inspect journal/readback and re-plan. Scan never
   implies a human Brand approval.
3. **ARE OAuth:** `invalid_grant` must not trigger automated Google network
   retry, grant substitution from the chat connector or file adoption from a
   different Drive account. Interactive owner reconnection and file membership
   evidence come first. Only after the source is verified, do a fresh scan.
4. **ARE Write:** stale grants for five old Google Context abilities are not
   the same as missing active grants. Keep the operational readiness
   fail-closed; use exact current reviewed grant scope and owner-approved
   reconciliation only. Never enable unrelated Developer/Breakglass/Production.
5. **Human review:** ETG Tone of Voice -> Editorial Guidelines; ARE Brand
   Strategy -> Tone of Voice -> Editorial Guidelines. Do not turn
   operational notebooks into approved strategy. Verify file hashes and
   signed Owner intent independently.
6. **Separate closure:** after Brand is ready, certify Skills checkpoint,
   provider closures, browser/SEO/locale/response-time budgets and staging
   rollback. Sign-off and publication are independent; neither CI nor
   the closure matrix mints a PASS receipt.

**What cannot be solved by code alone:** a provider-rejected OAuth grant, a
human review decision, exact-head owner attestation, an uninstalled build,
third-party canary/rollback evidence, and actual independent browser/Host
acceptance. Do not report these as closed merely because read-only unit
tests pass. Issue #446 requires site-specific source-scan/owner readbacks.
