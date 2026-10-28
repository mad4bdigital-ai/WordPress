# MAD4B — Company-neutral governed runtime

This package supplies **reusable, company-independent** admission policy and a read-only scheduler preview. It does not identify any tenant by company name, brand, website hostname, VPS container, or database name. Site-specific deployment identity belongs to the established `MAD4B_SCP_Site_Profile` (site UUID, canonical origin, environment and revision) and the operator's runtime registry.

## Architecture

- **Runtime Admission:** `015-mad4b-runtime-admission.php` uses native WordPress environment, host `WP_ENVIRONMENT_TYPE`, `MAD4B_SCP_Environment`, and `MAD4B_SCP_Site_Profile` to ensure exact binding before annotated provider HTTP, mutating MAD4B REST, or email is allowed to continue. Matching environment is **not** authorization; the existing read/write/provider/SMTP admission remains mandatory. It never registers test hooks on Production.
- **Provider Profiles:** Source-of-truth is the centralized Provider Transport Registry and native Provider Compatibility Certification. A site-scoped WP option `mad4b_provider_egress_profiles_v1` may hold origin/purpose/certificate bindings, with read-only fallback to the previous Staging option for an additive migration. Even a `certified` data row is insufficient unless a native current-generation certificate verifies it. New companies/providers are enrolled through data and approval, not class edits.
- **Cron Preview:** `governed-cron-plan.php` reads only WordPress's due-hook ledger and central Scheduler Backlog; no dispatch. Hooks are categorized by **verified, site-scoped `mad4b_scheduler_hook_registry_v1` records**, not company-specific prefixes. Unknown hooks are quarantined as `unreviewed_unregistered`. The globally known Action Scheduler queue remains high-risk. A `--execute` or `--apply` request always returns `EXECUTION_LOCKED`.
- **Deployment:** `mad4b-governed-cron-preview@.service` is a generic systemd template. The instance identifier is supplied by the operator's site deployment registry (the existing registered PHP container name) and is *not* compiled into common source. No timer is provided. The PHP preview independently rejects non-test/mismatched sites even if the template is invoked with a wrong container name.

## Migration without downtime

1. Verify the original and new file hashes and confirm exact Site Profile environment/origin binding, and an empty set of external consumers of legacy class names.
2. Install the generic files next to the existing site-specific files. Cut over the MU loader atomically by disabling only the old MU filename; avoid two active HTTP admission filters during the transition.
3. Switch the Cron plan/CLI source to generic classes; start a **manual preview only** using the generic systemd template; confirm no Cron events were removed.
4. Disable the previous site-labelled one-shot service after the generic instance succeeds. Keep previous runtime files in a secure, nonautoloaded backup until rollback confidence is established.
5. No source database or user/brand content migration is required. Previous provider profile option remains readable, and can be copied into the generic option through a separate approved data migration; no current options are overwritten here.

## Verification and restrictions

Run `php runtime-admission-contract.php` and `php cron-plan-contract.php`, then `python3 verify-bundle.py --source --no-dispatch --brand-neutral`. For a staging runtime, also verify `--runtime-mu`, `--runtime-cli`, and `--runtime-service` against exact paths. The tests prove class names, hook classification, explicit Site Profile binding, provider certification, prior HTTP denials, and no execution capability.

**Important:** This is a generic preview and policy adapter, *not yet a shared Control Plane core release*. Incorporating it within the correct source release requires merge/certification; installing a site adapter does not make Production ready. Global public egress controls for all unannotated third-party HTTP and a signed, single-hook Cron executor are separate tasks. Do not auto-send mail, execute overdue hooks, or modify Production while adopting this package.
