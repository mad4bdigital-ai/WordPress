# CWV must remain per-company after Control Plane source unification

Date: 20 Nov 2026. This is **non-authorizing source-only** review stacked on Draft PR #977, **not** a live WordPress change or a Release Verdict.

## Read-only ground truth from both installed sites

| Property (boolean only) | Tours.dev Staging | Wayfatours Production |
|---|---|---|
| CWV class already loaded | Yes | No |
| Existing daily CWV Cron | Yes | No |
| Existing configured monitored page | Yes | No |
| Saved CWV field-data record | Yes | No |
| `Site_Profile::feature_enabled('core_web_vitals')` | No | No |
| Site Profile exact origin/deployment verified | Yes | Yes |

Current integration source unconditionally `require_once`s the CWV implementation, which invokes its own `boot()` at file scope. A universal rollout would therefore register new REST/Ability/Cron/admin handlers on Wayfatours without explicit per-company opt-in. This is distinct from the previously corrected historical source-only broker assertion: the routes have admin checks, but *site-specific activation* also matters.

## Guard design

`MAD4B_SCP_CWV_Site_Admission::admitted()` performs no writes and fails closed without enrolled Site Profile, matched site URL, `authority_ready` and exact `deployment_binding_match`.

- New installations must explicitly enable the `core_web_vitals` Site Profile feature. A global Control Plane deployment never enables this feature implicitly.
- Legacy Tours.dev continuity is admitted only when there is **all** of: configured monitored-page URL on the same origin including scheme/port, an actual saved CWV report, and an existing daily Cron schedule. No hostname-specific allowlist or blank/default deployment bypass exists. A cloned Wayfatours environment with copied Tours.dev CWV settings does not pass the canonical-origin check.
- The original CWV class, hooks, API key encryption, REST handlers and permissions remain unchanged. If a company's identity binding becomes invalid, the CWV implementation is not included at plugin startup. No scheduled job is newly created by the admission decision.

`cwv-site-admission-hermetic.php` supplies 17 positive/negative tests: old active instance admitted; new company denied; explicit site-scoped feature accepted; unbound/mismatched deployment denied even if opted in; stale settings, Cron-only, wrong scheme, host-prefix forgery, cross-company copied URL, changed port and URL credentials rejected.

## Remaining operational gates

This code needs staging WordPress acceptance on an exact candidate SHA (including actual bootstrap hooks, daily Cron behavior, REST permissions and load/performance). The historical `tours-dev-scheduler-source-closure-runtime.php` and source-pinned release policies must be reviewed independently before any promotion. Do **not** override the 57 original PR #942 semantic signoff holds, owner exact-head attestation or rollback requirements.

No WordPress Site Profile option, Cron, database record, company feature flag, OAuth session, SMTP configuration, plugin state or Production deployment was changed by this review.

## Historical PR813/Original305 source proof (additional)

The original frozen certificate and its negative tamper checks remain unchanged. The existing `qa31_original305_source_overlay` pipeline now calls `cwv_site_admission_exact_overlay.strip_exact_site_admission` **before** the old signed Host and Original305 source layers. It accepts only the exact CWV-added Bootstrap Git blob `97887a449a3abf473a043d2da0737a3c2f2ab7ae`, checks the isolated helper Git blob `366b5b085976f87a633aa4a7737596eea14dcd6b`, verifies the two reviewed CI-only Governance source rows and rejects any promotion flags. An inverse of **only** the one reviewed include block must produce the exact historical candidate blob `2c497ec72c57f420d1abf1eef242748be40f7bb2`. Unknown bytes, changed helper, missing provenance and unauthorized flags fail closed.

Locally executed: historical Tour Highlights **9/9**, PR813 destination preview **6/6**, exact overlay negative checks **4/4**, and 17 isolated CWV tenant/identity cases. The dedicated CI workflow also checks the three historical suites with full Git history in PHP 8.3/8.5. Passing these is still not live Staging/Production release admission.

## Source convergence with current PR #977 and QA31 Readiness review

After source-only two-parent integration of PR #977 at `20aab770d21f45442fe09b5ae840a90b7ff8fe5a`, the merged candidate retains the Host v124/Original318 v83 readback corrections, the PR942 57-collision review ledger, and the per-site CWV guard. The new Service source path gets an explicit independent `reviewed_capabilities` ID, in addition to its earlier `reviewed_changes` blob record. The registry leaves `authorizing=false`, `production_promotion_from_ci=false`, and the new entry in `reviewed_for_ci_only`.

The original QA31 legacy source data remains unchanged. `tools/qa31_release_source_gate.py` recognizes **only** exact CWV bootstrap blob `97887a449a3abf473a043d2da0737a3c2f2ab7ae`, matching helper blob `366b5b085976f87a633aa4a7737596eea14dcd6b`, exact CI-only Registry rows, and the inverse-source reconstruction to the **unchanged** PR942 prior blob `2c497ec72c57f420d1abf1eef242748be40f7bb2`. The prior Ollama-activation denial is separately enforced by the existing historical QA31 proof. Unknown Bootstrap bytes, forged capability review, missing helper and changed release authorization fail closed.

The Composite Semantic PR942 test separately pins the newer full Governance inventory blob and enforces both the historical Reconnect/Artifacts CI-only source reviews and the newer CWV Service review. The original PR942 release and semantic manual signoff flags remain **false**; no approval is inherited.

Source-only tests executed locally against this integrated tree: QA31 original negative regression contract PASS, Original305 9/9 PASS, PR813 6/6 PASS, CWV exact inverse/tamper 5/5 PASS, Composite Semantic 12/12 PASS, 46-source inventory 5/5 PASS. Production Readiness compared against both the original fork `594b2521...` and current PR #977 `20aab770...` and returned `PASS_COVERAGE_ONLY` with **0 missing capability reviews**. This is not a full GitHub Release Verdict or site-specific Staging/browser/performance/rollback acceptance.
