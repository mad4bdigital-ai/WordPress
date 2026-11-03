# MAD4B shared test-environment sandbox

This is a **company-neutral** MU policy for Local, Development and Staging WordPress installations. Production requires an explicit matching host declaration and receives no test-only hooks. Missing or contradictory host environment evidence is quarantined. The policy preserves earlier HTTP denials/interceptions, blocks unsafe private/metadata destinations and allows native same-origin public REST where safe.

**No company or container hostname is part of the source.** The Local peer review exception is discovered from the site-scoped `mad4b_local_review_endpoint_registry_v1` option. Only `state=certified`, exact URLs with the two known MAD4B review API paths, matching Host headers, a Basic auth header and the independent `MAD4B_LOCAL_CLIENT_PROVISION_ENABLED` flag can bypass ordinary private-address denial. Unknown and draft records fail closed. This is deliberately Local-only; Staging and Production do not accept internal peer bypasses. Existing Local model-router guards continue separately.

The Staging mail admission policy remains a **veto** until native Company Communication and independently verified SMTP transport both authorize mail. A prior `pre_wp_mail=true` cannot fake success. This package does not enable sending, Cron execution, checkout, or provider mutation.

A site's deployment profile determines whether this reusable MU package is installed. WooCommerce clone-only checkout restrictions, sandbox-specific schedules and host credentials are **deployment data**, outside this common package. Historic local peer hostnames, source-site domains and Docker container names are not to be copied to other tenants.

## Safe migration

1. Check matching native `WP_ENVIRONMENT_TYPE`, current Site Profile and source hashes. Keep a backup of the old MU policy.
2. Install the new generic MU guard as `000-mad4b-environment-sandbox.php`. Disable the previous company-named guard (ensure only one guard remains active).
3. Confirm valid same-site REST and unapproved email remain blocked on Staging, plus private IP denial and prior WP_Error preservation.
4. Preserve the existing Staging mail veto and site-specific commerce isolation without changing database options, Cron configuration, or Production.
5. Retain the previous nonautoloaded file for a measured rollback. Review and explicitly enroll any Local peer exceptions in the existing site's configuration before expecting private peer calls to succeed.

## Validation

`php policy-contract.php` in Local, Development, Staging, explicit Production and conflicting/implicit host scenarios; `python3 verify-bundle.py --source --brand-neutral` checks source fingerprints and company neutrality. The previous per-site PR is deprecated; the common source does not include tenant-specific overlay files. Provider network approvals and a Cron executor remain separate governed capabilities.
