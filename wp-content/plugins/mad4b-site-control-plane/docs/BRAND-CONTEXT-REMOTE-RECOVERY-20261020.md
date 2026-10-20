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
