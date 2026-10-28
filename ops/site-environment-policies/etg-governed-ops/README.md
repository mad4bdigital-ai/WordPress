# ETG Staging — governed provider egress and WP-Cron preview (2026-10-29)

**Target:** WooCommerce Commerce Staging, `tours.dev.mad4b.com`, database `etg_commerce_staging`, WordPress/Site Profile `staging`, active Site Control Plane `0.4.0-rc.118`. This is additive site-scoped work, not a downgrade to the older plugin source in the GitHub repository. Production is untouched.

## 1. Provider egress admission — source-backed integration

The MU plugin `015-mad4b-staging-runtime-admission.php` attaches a deny-only `pre_http_request` filter, but **only for HTTP requests marked by the existing `MAD4B_SCP_Egress_Policy::mark_request()` contract**. Existing OAuth, SERP and remote-media callers already use that native contract. No duplicate HTTP, DNS, redirect or TLS engine is introduced.

Before a marked request can continue, require exact native WordPress host environment, effective Site Profile environment, and explicit host environment to all agree on `staging`. If the marker names an external provider ID, require all of these: registered provider transport descriptor; native `capability_certification()` in the **certified** state (not `compatible_unattested`), at least one eligible capability and the exact current native certificate generation; a site policy data record with `state=certified`, matching `certificate_sha256`, purpose and HTTPS endpoint origin. Default: **zero authorized provider IDs**, no automatic certification or account linking. New providers can be added declaratively through the established provider catalogs and controlled site option `mad4b_staging_external_provider_profiles_v1`, with an independent certificate, rather than editing this admission engine. Reading an option does not grant an owner approval or a provider mutation ability.

The MU plugin vetoes `pre_wp_mail` if the runtime Site Profile subsequently differs from Staging. It also blocks mutating MAD4B-owned REST requests (POST/PUT/PATCH/DELETE) on an exact environment mismatch, without replacing original REST authentication or affecting unrelated WordPress REST paths. It does NOT grant email permissions: `005-mad4b-staging-mail-admission.php` and native Company Communication/verified SMTP admission must separately approve any mail. For unmarked third-party requests, the existing `000-sandbox-guard.php` private destination protection remains intact; **do not claim this filter universally governs every plugin's arbitrary public egress**. The separate Egress Policy handles marked requests only. Full interception of unmarked providers, DNS rebinding throughout redirects, and external network allowlist certification remain separate review work.

## 2. Governed Cron plan — no global backlog replay

The CLI entrypoint `mad4b-governed-cron-runner.php` uses the separate pure planning module `governed-cron-plan.php`. Deployed to Staging under `wp-content/mad4b-cli/` (not the MU autoload directory). CLI only, explicit `--preview` required. It reads native `_get_cron_array()` and the existing Control Plane Scheduler Backlog status, classifies due events and emits SHA256 fingerprints without revealing event arguments or PII. Output contains source environment, total due, up to 50 visible entries, classification, and a maximum future batch size of five.

`--execute` and `--apply` **always reject** with `EXECUTION_LOCKED` (exit 77): there is no unapproved or automatic dispatch, no `do_action()`, no event deletion, no `wp_mail()`, and no automatic WP-Cron activation. This is the safe preparation stage for a later owner-signed, tenant-scoped, exact single-hook executor that must integrate with `MAD4B_SCP_Scheduler_Admission` and `MAD4B_SCP_Scheduler_Backlog` for real leases/quotas and audit receipts. Those evaluators are non-authorizing, so their `ADMIT` result must never be interpreted as execution permission. The currently dormant WP-Cron backlog is NOT certified for execution, and `DISABLE_WP_CRON=true` remains untouched.

**Staging preview command:**

```sh
docker exec etg-commerce-staging-php php /var/www/html/wp-content/mad4b-cli/governed-cron-runner.php --preview
```

The staging database currently contains 40 overdue events, classified as 4 messaging/form, 1 Action Scheduler, 4 commerce/booking, 6 company/control-plane and 25 other/core events (time-scoped snapshot; counts will change). There is no scheduled timer for the new preview, and no active Cron worker added.
**Manual Staging systemd preview:** `systemctl start mad4b-etg-staging-cron-preview.service` followed by `journalctl -u mad4b-etg-staging-cron-preview.service -n 5 --no-pager`. It is a static, disabled-by-default one-shot **without any timer**, and `ExecStart` invokes `--preview` only. A manual smoke succeeded and recorded an anonymized plan. Running this service never executes WP-Cron due hooks.


## 3. Tests and source parity

- PHP hermetic `runtime-admission-contract.php`: native/profile mismatch denies, earlier HTTP denial preserved, unknown provider and purpose deny, draft/stale certificate deny, exact native certificate permits only the matching HTTPS origin, mail mismatch veto.
- PHP hermetic `cron-plan-contract.php`: excludes future events, safely categorizes due events, never prints event arguments, rejects environment mismatches, never authorizes execution.
- `verify-bundle.py --source`, `--runtime-mu ... --runtime-cli ...`, `--no-dispatch` validates source/deployment fingerprints and that no Cron dispatch primitives exist in this preview bundle.
- CI is triggered only for this bundle plus `.github/workflows/mad4b-etg-governed-ops-ci.yml`; exact pull request HEAD checkout and stable PR-scoped concurrency are used.

**Rollback:** delete only `015-mad4b-staging-runtime-admission.php` (new MU) and `wp-content/mad4b-cli/governed-cron-plan.php` / `governed-cron-runner.php` after moving them to a retained backup. Do not change `000-sandbox-guard.php`, Company Communication, WordPress database, `rc.118`, or Production. The two original new files are stored in this source bundle; no old plugin version is restored. Version-check all file hashes before any deployment.

**Limitations:** This stage closes the guarded-provider environment boundary and prepares a governed read-only Cron intake. It does not yet certify arbitrary third-party public egress, complete the migration of every legacy direct environment call site to the central resolver, or activate WP-Cron execution. Those require an audited source release that matches `rc.118`, approved provider-specific certificates and an explicit owner-attested single-hook execution contract. No emails, WooCommerce orders, real Cron callbacks, or Production mutations occurred.
