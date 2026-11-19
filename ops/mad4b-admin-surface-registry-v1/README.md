# MAD4B Admin Surface Registry v0.1 — read-only rollout

The registry adds an admin UI for current WordPress menu capability gates,
plus a strict read-only policy for five reviewed company navigation surfaces.
All unmapped, write, publish and approve operations are default-denied.
This is an observation and policy interface, NOT an authorization bypass or
replacement for the existing PHP, AJAX, REST, tenant and owner checks.

Roles and capability grants are edited with User Role Editor. ASE manages
menu titles, ordering and hiding. MAD4B is responsible for company/brand/
tenant authorization and policy validation in each source handler.

Source: wp-content/mu-plugins/012-mad4b-admin-surface-registry-v1.php
Route: wp-admin/admin.php?page=mad4b-admin-surface-registry

The route uses the existing Company parent on Tours.dev, or the Workspaces
MAD4B parent on Wayfatours. It reads current registered menu entries and
shows native menu capability pass/fail for the signed-in actor. Reports
never certify callback/REST access and never grant a role.

The source-only observed snapshot is in catalog/observed-menu-surfaces-20261119.json:
299 unique Tours.dev and 216 Wayfatours slugs, total 515. Its menu data
was collected from WordPress admin-context WP-CLI with Elementor Pro
excluded on both sites and WooCommerce/Astra Addon excluded on Tours.dev,
due to their admin-context CLI boot errors. The observed snapshot is NOT
an authorization matrix and every action requires real-handler review.

Before moving any surface from review to active authorization, identify
its native PHP/REST/AJAX handler, scope, read/draft/write/approve transitions,
independent owner/Host boundaries and a real regression test.

This version performs no role/capability write, no source deployment job,
no Host Authority operation, no data mutation and no external provider call.
