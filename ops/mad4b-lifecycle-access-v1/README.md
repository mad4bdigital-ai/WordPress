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

## 19 November 2026 — FluentCRM compatibility resolved

The previous lifecycle filter returning all sixteen rights to any Master
Admin now delegates to the reviewed allowlisted company capability policy
in MAD4B_Admin_Surface_Registry_V1::fluentcrm_permissions. Revocations
of URE-owned company CRM/operations/policies caps now reduce rights, and
native explicit per-user manager permissions also narrow them. Existing
16/16 rights remained for the five tested Master accounts, without modifying
the stored WordPress role/user grants or native FluentCRM manager metadata.
The separate 501 security capability ceiling remains unchanged.

Evidence: /opt/mad4b-company-provisioning/reports/master-admin-policy-completion-20261119/
