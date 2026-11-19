# Master Admin role policy: User Role Editor as authority

The original 009 Company Workspaces MU plugin re-granted two Master Admin caps on every WordPress init. This prevented URE revocation from surviving the next request.

## Remediation, 20261119.1

The version 1.1.0 Company Workspaces module no longer writes roles, or registers any role reconciliation on init. Its existing Workspaces/Content Jobs menu and handler access checks are preserved.

User Role Editor (URE) edits actual WordPress roles. MAD4B applies read/write/tenant/approval authorization in server handlers. Admin and Site Enhancements (ASE) arranges/hides menu links, not grants permissions.

adopt-existing.php is a write-once, explicit, authorized WP-CLI adoption receipt for the CURRENT pre-existing Master Admin role. It never changes role capabilities. The receipt option is mad4b_company_workspaces_role_seed_v20261119_1, and it captures both the reviewed Master role-cap SHA-256 and the full WP role-store SHA-256.

READ-ONLY: wp --user=AUTHORIZED_ADMIN_ID eval-file adopt-existing.php

EXPLICIT ADOPTION:
MAD4B_ROLE_POLICY_ADOPT=1 MAD4B_ROLE_POLICY_EXPECTED_MASTER_SHA256=REVIEWED_SHA MAD4B_ROLE_POLICY_EXPECTED_ROLES_SHA256=REVIEWED_SHA wp --user=AUTHORIZED_ADMIN_ID eval-file adopt-existing.php

Adoption requires BOTH manage_options and ure_manage_options. Master Admin cannot self-adopt. Missing/changed role preimage, a missing approved capability, a Master role with unrestricted ure_edit_roles, or an already-recorded version fail closed.

This migration is informational; later manual URE edits are NEVER reverted. A new deployment without these capabilities must be reviewed via URE or a separately approved one-time policy migration, not silently seeded by 009.

The independent 003 lifecycle-access plugin seeds roles only on its own schema-version changes. Review its changes before future schema upgrades to avoid a separate URE override path.

Regression tests: Python unittest, PHP mock bootstrap with no role access, WordPress per-site readbacks and full raw role-store SHA pre/post. Save exact preimages and rollback evidence outside the repository.

No new WordPress admin role grants, Host authority, signed release, user invite, plugin update or global cache purge occurs in this change.
