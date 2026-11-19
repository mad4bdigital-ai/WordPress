# MAD4B Company Workspaces — live-first source reconciliation

Applied 19 November 2026 to Tours.dev Staging and Wayfatours Production with standard install and WordPress WP-CLI readback. Root-only exact preimages and backups are saved in /opt/mad4b-company-provisioning/reports/master-admin-workspaces-20261119.

## Workspaces

Adds a native Workspaces overview plus a Content Jobs read-only page using the original site_uuid_brand_id_tenant_id-scoped list_jobs() service. If the existing Company menu is loaded, use it. Otherwise publish a standalone MAD4B menu, necessary for Wayfatours where the portfolio manager is not active. Do not broaden the scope of source publishing, host grants, approval or WordPress core settings.

Seven pricing modules retain their original WooCommerce admin branch and add a company-operations/task capability alternative. Both sites were verified to have those domain capabilities. No general manage_woocommerce capability is granted.

## Staff

Staff & Roles creates company-owned, quota-bound accounts with random passwords using an explicit nonce-protected POST. It allows promotions of existing eligible, unsuspended, company-owned staff only when every positive role capability is a subset of the actor's capabilities. Administrator, Master Admin, Operator and other system-level roles are forbidden. The derived MAD4B Company Content Editor role uses the editor capability intersection with Master Admin without manage_links and other system privileges.

The older createStaff path was constrained in the active company-portfolio-manager on Tours.dev as well as the copy in Site Control Plane. The separately installed company-portfolio-manager source is NOT tracked in the canonical repository; its exact two live minimal change patches are saved in live-reconciliation, for later first-party import without replacing divergent live plugin files.

## Validation

PHP lint passed for all changed PHP files in both runtimes. WordPress admin-menu simulation confirmed Workspaces, Content Jobs and Staff & Roles on both sites. Content Jobs retain the exact native scoped readback. Pricing Workspace permitted() returns true for Master Admin. The derived editor has no additional capabilities over the Master actor. Administrator and system-level privileges were not granted to the Master role. No test user was created or elevated during this change.

Production and Staging plugin files are not byte-identical. Canonical Git changes must preserve only the scoped predicate and not overwrite other newer live code. This branch does NOT represent an Original312 job, Original313 receipt, host grant, production release authorization or user invitation.
