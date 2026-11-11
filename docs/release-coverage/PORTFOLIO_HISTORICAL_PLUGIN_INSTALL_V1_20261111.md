# MAD4B Company Portfolio Manager — historical installation and intent-only journal

Date: 2026-11-11. Environment: isolated local full WordPress Git reconciliation branch. Source-only. HOLD.

## Root issue

Source site Tours.dev Staging contained a newer bounded read-only reconciliation of an already completed Wayfatours Experience plugin installation. The previous Git candidate lacked resource-state detection, so an owner UI could continue to offer an unnecessary new installation job even when trustworthy historical installation evidence and recent inventory were present. A separate company-plugin-sync planner also returned ambiguous queue status, despite being an intent-only source plan rather than an original 305/306/312 execution.

## Source recovery

Recovered three source PHP files verbatim, pinned by Git preimage and current live SHA-256 in portfolio-historical-install-intent-provenance-20261111.json:

- Exact Wayfatours Experience installation owner: classifies historic completion only when original stored job ID, site UUID, canonical origin, command hash, company/environment, historical operation, independent historical readback and recent plugin inventory match. It reports INSTALLED_HISTORICALLY_RECONCILED, expressly says original_313_certified=false, and never signs a new certificate. This existing Wayfatours-specific owner is not a global mandatory path for other companies.
- Native Plugin Install Provider: returns ALREADY_INSTALLED_NO_NEW_JOB_REQUIRED as a resource state, with new provider readiness, native 305 issuance and Original313 certification all still false. It does not activate plugins or change credentials.
- Company Plugin Sync: planner response and operation journal now disclose INTENT_ONLY_NOT_NATIVE_JOB, PLANNED_INTENT_NOT_ORIGINAL_305_JOB, and original_305_job_issued=false, original_306_claimed=false, native_312_executed=false, independent_313_verified=false; separate dedicated provider is still required for direct MU deployment.

## Acceptance

- Five new exact preimage/source and authority contract tests passed.
- Fifteen offline PHP scenarios passed for matching historical job/inventory, scoped company, missing history, wrong-site record, stale readback, original313 false, no installer authority and no duplicate 305 job.
- Full local Python source suite passed 129 tests at this checkpoint, including preservation of all 52 unmodified historical original plugin source conflicts.
- The three PHP sources passed syntax checks. No WordPress runtime plugins were activated or claims created by this reconciliation.

## Remain withheld

The other Company Portfolio Manager differences involve explicit Production delegation, credential/owner email flows and remote operation handlers. They are not part of this import and should not be assumed merged. Site Control Plane's governance and signed Original318/313 release remain separate HOLD gates.

The historical installation state is not evidence for a new Original313 cryptographic receipt, a new Production mutation, a policy bypass or an issue/revoke grant. No live site or database was mutated.
