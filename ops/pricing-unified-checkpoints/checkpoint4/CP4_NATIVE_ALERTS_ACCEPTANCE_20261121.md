# Checkpoint 4 — MAD4B native supplier-cost alerts
Date: 21 November 2026. Applies to Tours.dev Staging and Wayfatours Production.

## Routing, ownership and evidence
Existing original authority: `MAD4B_SCP_Content_Readiness_Alerts`, option `mad4b_content_readiness_issues_v1`; no new alert database, plugin Settings page, authorizing service or external notification path.

Read-only producer: `MAD4B_CP4_Cost_Alerts_V1`, source `mad4b-package-cost-readiness`. It scans the original Package source plans and verified stage-binding meta `_mad4b_package_stay_verified_room_program_links_v1`, normalizes WPML language siblings to their canonical Tour, and generates one stable high-severity alert per **unqualified stage**. Alert IDs obey the original native `sha256(blog_id|post_type|post_id|code)` identity. The original SCP scanner accepts only re-generated source rows with verified native Tour identity, post status, severity and fully unmodified evidence.

Alert categories are stage records, not detached suppliers:
- 18 hotel stages → **Hotel Supplier Contracts**
- 7 cruise stages → **Cruise Contracting**
- 2 sleeper-train stages → **Rail Supplier Contracting**
- 1 resort stage → **Hotel Supplier Contracts**

The `owner_group` is a functional responsibility label, **not a named human or a board assignee**. Registered user/board selection remains governed by original workflow settings. Each alert links back to the native Tour object through the original Content Readiness UI. Its source evidence records `stage_key`, kind, grouped required action, source SHA-256 and the missing-requirement codes. No bank details, amounts or supplier invoices are copied into the alert record.

## Fail-closed lifecycle
A missing supplier receipt, incomplete hotel/unit assignment, missing vessel/cabin/sailing, wrong train class, absent date/currency/occupancy/inventory proof, unverified supplier identity or absent signed approval is never called commercial-ready.

The same original `MAD4B_Package_Cost_Receipt_Gate_V1::validate()` is invoked against the stage evidence, including an exact requested service date, service-night window, party occupancy, and original WordPress property/Unit or train station identity. The gate's external independent authority callback defaults to `false`. **Self** is not an exemption.

Native auto-resolution requires all of:
1. The scanner completed the whole canonical source stage census for that tenant.
2. The original stage is **still present**, with the identical stage key and kind.
3. The stage source digest is unchanged since that scan began.
4. The linked receipt actually passes the source gate and independently signed commercial approval.

Deleting a stage, changing its name, losing WPML/source access, a failed partial scan, or temporarily disabling the producer does not auto-resolve a historical issue.

## Live deployment evidence
SCP live plugin versions are not identical:
- Tours.dev Control Plane original monolithic `class-mad4b-scp-content-readiness-alerts.php` SHA-256 `882744ce7ac4...`
- Wayfatours original monolithic class SHA-256 `a70830ddb0dd...`

Each was selectively patched in place with SHA-checked baselines and rollback copies under `/opt/mad4b-company-provisioning/reports/cp4-content-readiness-alerts-20261121/backups`. **Do not replace either live Control Plane with an older monolith or cross-company file**. The source PR instead targets the GitHub **Scanning and Reconciliation Traits** that are already refactored on the newer source branch, retaining all unrelated updates.

Real live results after deployment:
| Metric | Tours.dev | Wayfatours |
| --- | ---: | ---: |
| Canonical Package tours evaluated | 8 | 8 |
| Source stage bindings | 28 | 28 |
| Pending CP4 cost alert candidates | 28 | 28 |
| Open CP4 native alert records verified | **28** | **0 before next native due scan** |
| Source class / PHP lint | Pass | Pass |
| Original source commercial approvals created | 0 | 0 |

Tours.dev's **existing** scheduled/native scan ran at 2026-11-21 13:17:38 UTC and wrote the 28 open issues into the original ledger. No forged login or manually altered scheduling was used.

Wayfatours' next native scan was not yet due at the last readback; its already-enabled site policy uses a 60-minute interval after 2026-11-21 12:47:22 UTC, so the earliest due time was **2026-11-21 13:47:22 UTC**. Respect the original `mad4b readiness plan/run` admission and do not bypass it to obtain a green report. Raw source candidates are not recorded alerts until the authorized scheduled scan actually runs.

Both sites have `email_notifications=false`, and no external email was sent. Board workflow is available, but no named assignee was assigned and source issue creation alone does not create a FluentBoards task.

## Repeatable read-only test
`verify-content-readiness-cost-alerts.php`: source census deterministic, 28 stable source IDs, signed-receipt false-closure prohibited, spoofed severity and owner-group rejected, no ledger or customer/pricing mutation. Both site-native runs PASS.

## Status
**Alert producer: deployed, checked both tenants. Staging: native alerts persisted. Wayfatours: waiting for its pre-existing trusted scan cadence. Commercial Cost Receipts: 0/28 on each site; Tour Rates generation and checkout remain unapproved.**

To inspect the original board:
- https://tours.dev.mad4b.com/wp-admin/admin.php?page=mad4b-content-readiness-alerts
- https://wayfatours.com/wp-admin/admin.php?page=mad4b-content-readiness-alerts


## Follow-up closure review — 21 November 2026, 13:43 UTC

**Production Readiness Matrix corrected without weakening the gate.** The exact-HEAD registry evaluation in the isolated VPS worktree returned `PASS_COVERAGE_ONLY`, zero uncovered changes, for the three original code paths (native scanning, reconciliation, and native owner-routing). Every source blob is SHA-reviewed with non-authorizing, append-only `reviewed_changes` records and the same-PR executed `tests/cp4_content_readiness_source_regression.py`. GitHub PR #1020 remains Draft, and exact new-HEAD workflow checks must complete before any merge.

**Owner classification correction:** all 28 CP4 issues on each tenant now resolve to the original FluentBoards `pricing` group, not `general`, through a narrowly scoped original `MAD4B_SCP_Readiness_Workflow_Options::group_for_issue` condition requiring the exact CP4 source, stage code pattern and contract. This does not grant permissions, assign a person or create a task. Original source `owner_group` retains the Hotel Supplier Contracts / Cruise Contracting / Rail Supplier Contracting operational teams.

**Ownership and external task gates:** Staging Board #2 / Stage #9 is valid; production Board #1 / Stage #6 is valid. A specific named assignee is not configured on either tenant and original `assigned_user()` resolves to no unique valid user. Production `MAD4B_SCP_Policy::can_mutate()` returned false for the inspected WordPress user. Therefore no 28 unassigned FluentBoards tasks were automatically created, no arbitrary personal identity selected, and no email was dispatched. The original dashboard alerts remain the working record; task creation is a separate governed write.

**Supplier evidence check:** Read-only searches of the connected Drive and Gmail for actual Abela, Nile cruise contracted net rates and hotel cost invoices found no independently evidenced source receipt suitable for commercial approval. The only adjacent email was an Egypt rail API/data feed access enquiry to a third-party integration provider, not a supplier cost contract. The original quotation sheet remains an unapproved quote source; source-signed receipt count remains 0 / 28 per site. Do not convert any quote into a signed cost.

**Production source scanner:** Wayfatours real native Content Readiness `discover()` accepted 28 / 28 unique CP4 issues with complete owner group and original stage links, but the last observed persisted CP4 issue count was still zero at 13:43 UTC. The original production scan is eligible but not yet due until 13:47:22 UTC. Only an original due scheduler scan, not a direct ledger insert, can certify persisted Production alerts. No override of the scheduled operation or governance fence has been attempted.
