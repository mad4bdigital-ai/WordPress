# Company Portfolio Master Admin — owner-scoped role patch v4

Source origin: installed first-party mad4b-company-portfolio-manager plugin on
https://tours.dev.mad4b.com, Staging. This addon is the **actual loaded owner**
of MAD4B_SCP_Company_Master_Admin on Tours.dev. The duplicate class shipped in
Control Plane is not the active class there; Wayfatours does not have the addon
active.

The 0001-role-capability-drift.patch is intentionally exact and narrow:
- Moves the original owner's migration version from 20261028_v3 to 20261117_v4.
- Includes manage_woocommerce in the remove capability list for master_admin.
- Retains original owner code, including additional commerce staff roles,
  Staging browser owner authorization, Local/Staging-only mutation guards and
  Company Portfolio manager task and user policies.

The manifest.json pins original Staging preimage and patched SHA256. Deny
modified or unrecognized owner files; never run the patch against another
company or addon version blindly.

The patch's code-only owner change was applied on Tours.dev Staging with a backup
and readback. It does not execute applyOwnerApprovedMigration, change the
stored WordPress role, or grant release authority. The stored role version
remains 20261028_v3 until original browser-approved owner activation invokes
migration and verifies role, audit and rollback receipts.

Wayfatours Production was not modified. It requires independent addon ownership
and release admission; never copy the Staging owner addon to Production.

Validation: python3 -B tests/test_company_portfolio_role_v4_owner_patch.py
For exact original source also set MAD4B_PORTFOLIO_ROLE_V3_FILE to the v3 file.

CI source-only evidence is not deployment or role approval.
