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
