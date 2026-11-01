# Environment-scoped Company Manager assignment (central Staging only)

This is a guarded, owner-authenticated lifecycle for the **existing** MAD4B Company Manager, bound to the verified Staging Site Profile.

## Problem and exact fix
Legacy Company Manager `MAD4B_Company_Portfolio_Manager_V1::company_binding(company)` requires a company-wide legacy registry record. The separately verified `all-royal-egypt:staging` identity cannot safely be inserted into the legacy record, which points to Production. Existing `316` properly reads `company + staging` signed V4 identity and a separate assignment store, but there was no approved write lifecycle for that store.

`317` adds a Staging-only REST **proposal → owner approval → activation → revocation** process to the existing `316` namespace. It requires an exact signed current Site Profile, the original enrolled WordPress owner who is an active member of the existing Manager, an actual authenticated browser REST nonce or verified original administrator principal, exact binding digest, exact revision, and exact proposal SHA.

- Staging endpoint: `/wp-json/mad4b-central/v1/company-environments/{company}/staging/manager-assignment`.
- GET: admin-only read-only plan.
- POST: owner-authenticated `action=propose|approve|activate|revoke`, `expected_revision`, `expected_binding_sha256`, and the expected proposal SHA for actions after propose.
- WP-CLI offers **read-only** `wp mad4b environment-manager-plan <company>`. WordPress user overrides are not owner approval.
- Database scope: `mad4b_company_environment_manager_assignments_v1`, keyed by manager ID / company / Staging.
- Assigned rights are limited to `view_company` and `operations_manage`. Owner approval never grants a remote SSH credential or activates a Native Executor.
- Production and the legacy company-wide Portfolio registry remain unchanged. No arbitrary credentials, generic command endpoint or grants are exposed.

## Separate next engineering stage
This authorizes only the **scoped Staging Portfolio assignment**. Existing `300/301/305/312` still use legacy company-wide binding and must explicitly consume the newly approved `company + environment` assignment via a reviewed, narrowly scoped compatibility update. Until that is certified, original enrollment remains fail-closed; a successful `317` activation is not evidence that the entire Broker or 313 receipt path is ready.

## Test
```sh
php -l ops/mad4b-environment-manager-assignment-v1/317-mad4b-environment-manager-assignment.php
php ops/mad4b-environment-manager-assignment-v1/tests/assignment_lifecycle_hermetic.php
```
