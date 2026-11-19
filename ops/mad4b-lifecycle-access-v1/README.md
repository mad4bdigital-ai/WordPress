# MAD4B Lifecycle Access / User Role Editor persistence

This source file was reconciled on 19 November 2026 against the same installed
003-mad4b-lifecycle-access.php bytes on Tours.dev Staging and Wayfatours
Production (preimage SHA-256:
9df0dba40678ccfb1de13d81ed3c31e7a70ffe030370c6f6e81d5ff1953703b1).

## Why the existing code is unsafe on future schema bumps

The original 003 file called roles() on init and also on staff role changes.
For a new schema version it added all bundled caps back, including broad
WooCommerce / theme settings caps copied from other roles; URE revocations
could be reverted. Additionally wpml() added direct translate and
manage_translations user grants on wp_loaded, independently of role state.

## Applied minimal design

- Retain public roles() as a read-only compatibility method because
  Company Portfolio Manager calls it directly.
- Keep VERSION and all definitions/all/cpt/financialMeta and URE grouping
  hooks unchanged for backwards compatibility.
- Do not seed any role/capability during init, user-registration or role
  assignment. Version mismatch produces roleSchemaStatus() for review; it
  never auto-applies changes.
- WPML language-pair synchronization may operate only when the user has
  effective translate AND manage_translations permissions. It never writes
  direct WP_User capability grants. This retains WPML pairing for existing
  permitted translators while respecting URE revocations.
- The independent 501 reducing-only sensitive role guard is untouched.
  Removing forbidden system caps remains a security boundary; URE is the
  editor for all capabilities inside the approved security ceiling.

New sites and future changes to lifecycle definitions need a separately
reviewed, exact preimage-bound role migration approved by an authorized
role editor. No broad grants are implied by a version bump.

Original and patched sources are inspected with two isolated CI tests,
PHP lints on both runtimes and live WordPress readback of role and direct
user capabilities. Do not modify actual live Master Admin caps merely for
a test. Site-specific root-only rollback copies are at:
 /opt/mad4b-company-provisioning/reports/master-admin-lifecycle-20261119/

## Explicitly deferred: FluentCRM broad permissions compatibility

The existing 003 lifecycle file also has a FluentCRM user_permissions
filter returning all 16 FluentCRM plugin permissions for any Master Admin.
The actual sampled Master users currently have no stored FluentCRM
manager_permissions meta record and no matching native fcrm_* role grants.
Removing that filter would immediately revoke existing CRM access.
This deployment preserves it to avoid an unapproved functional regression.
It is a separate P1 role/tenant reconciliation requirement; use the
FluentCRM native permission manager, approved role mapping and migration
readback before modifying this hook. Do not claim URE fully governs
FluentCRM until that separate policy has been reconciled.

The independent 501 reducing-only security policy still removes forbidden
system grants even if URE was used to set them. That deliberate
higher-priority security ceiling is not a role-definition editing bug.
