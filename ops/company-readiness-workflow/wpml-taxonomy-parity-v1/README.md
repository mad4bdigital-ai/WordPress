# MAD4B WPML Tour Taxonomy Parity — governed repair (2026-11-04)

## Source of truth and scope

The canonical **English** taxonomy term defines meaning through WPML `trid`. Every translated `tours-and-activities` post must be assigned the corresponding **same-language** `tour-types` term from the **same `trid`**. Never map by translated name or slug. Never reinterpret Packages as Day Tours.

This source patch has three related parts:

1. **Data repair (executed on Central Staging only):** 36 published tour records reconciled; 14 redundant English/native translated relationships removed; independently verified **0 mismatches** across EN/AR/FR/DE/ES/IT. Backups and receipts are stored on the authorized VPS, not in GitHub.
2. **Recurrence prevention:** `wp-content/mu-plugins/096-mad4b-wpml-term-parity.php` maintains parity on `save_post` and `set_object_terms` after WPML has finished saving, writes bounded receipts, and runs hourly WordPress cron to catch direct database imports. A repair uses exact WPML `trid`, guards concurrent writes, uses one DB transaction and checks native readback. Unresolved translations are left unchanged and reported. Additional registered post type / taxonomy pairs may be added only by the `mad4b/wpml-taxonomy-parity/pairs` filter.
3. **Company Reconcile evidence:** `company-manager-class-overlay.php` is the source overlay for the actual class `plugins/mad4b-company-portfolio-manager/includes/class-mad4b-company-contentjob-skill-preflight.php`, modified on Central Staging. It adds a source parity snapshot and a **separate, unverified target parity requirement** to the existing original ContentJob preflight. No target grant is created; a source PASS never certifies Wayfatours or another company.

## Correct packaging and launch

- Include the MU guard in the provisioning **source file inventory** and retain it when cloning the company's WordPress application. Do not treat a GitHub-only file as a live deployment.
- Reconcile the Company Manager overlay with its **actual plugin source**, retaining the original skill contract, dynamic skill selection, job permissions, company/environment binding, and authorized 305→313 native executor. This overlay is **not** an auto-loaded WordPress plugin and must not be copied to the MU plugin directory under a random name.
- On each target, confirm WPML + taxonomy availability, exact site identity, active runtime policy and ability to run the native preflight. The target must run its **own** readback; target reports remain `UNTESTED_TARGET_NATIVE_READBACK_REQUIRED` until then.
- Run `wp eval-file tests/wpml-taxonomy-parity/native-acceptance.php` only after deploying the provider and adjusting environment-specific label fixtures. No Production changes were performed.
- The Staging-only data migration is recorded in `/opt/mad4b-company-provisioning/backups/wpml-tourtypes-parity-20261104/tourtypes.atomic-before.json` and its receipt is `/opt/mad4b-company-provisioning/audits/jetengine-native-qa-20261104/wpml-tourtypes-36-atomic-apply-receipt.json`. Rollback is supported using the snapshot-backed migration; do not replay to another site.

## Label and SEO handling

The German `Packages` translation `#203` was renamed to **Reisepakete**; the French `Combined Tours` translation `#741` was renamed to **Circuits combinés**. Existing slugs `tagestouren` / `tours-combinados` were left unchanged to avoid silently breaking URLs or redirects; review those separately in a controlled SEO change.

## Acceptance and exclusions

- Central Staging: 36 corrected, 14 duplicates eliminated, 0 native `tour-types` language mismatches, provider audit PASS, hourly event executed, repeat-error canary PASS, WPML archives 6/6 with 15 facets each, JSF v6 JavaScript SHA unchanged.
- Wayfatours Production: **not audited or modified**. Company Manager reports target parity `UNTESTED_TARGET_NATIVE_READBACK_REQUIRED`, correctly refusing to treat Central evidence as target evidence.
- **Separate unresolved issue:** nested JetEngine Listing context may still cause an incorrect **first Grid card**, regardless of correct taxonomy relationships. Do not mark multilingual Grid/List card-content parity PASS on the basis of these taxonomy tests.
- **Not a release attestation:** this source branch is a review candidate; it has not been auto-deployed to companies or merged into master. No optional certificate/signature bureaucracy is introduced beyond ordinary WPML, WordPress permissions and existing signing.

## Key live evidence

- Central Staging original v6: `ee2a935c18df0bf180b64dd79d981232641c219bd7038d785978a1b347bd6432`
- Atomic source backup: `bd8d24ca5e0095ba2abd59070e62f4549ca0386f0605afd52e4e1789822fa0f1`
- 36-record update receipt: `f1e6d32ffe942219e81c52ff8fc95ced04172cb42dc0993b3cf8b08085175401`
- Native Staging MU guard: `eb84db7d19c80f932141a3948fc5283755bf818a97114704c2060107fc3550fd`

