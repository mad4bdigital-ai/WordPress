# MAD4B Admin Surface Registry v0.2 — live domain boundaries

Deployed 19 November 2026: Tours.dev Staging and Wayfatours Production.

## Source of permission authority

- User Role Editor (URE) owns stored WordPress role and direct user capabilities.
- MAD4B registry declares reviewed surface capabilities and scope. It
  authorizes read for mapped surfaces, and exactly one operation called draft:
  bounded company Content Job intake. Generic write, publish, approve, role
  editing and Host Authority operations remain denied.
- Native PHP/REST/AJAX handlers remain the final authority for nonce,
  per-post ACL, site/tenant identity, original runtime gates and approvals.
- Admin and Site Enhancements (ASE) controls menu presentation, not access.

The Admin Surfaces page is served from:
 wp-admin/admin.php?page=mad4b-admin-surface-registry

Historical source-only inventory:
 catalog/observed-menu-surfaces-20261119.json
515 site-specific observed slugs (299 Tours.dev, 216 Wayfatours), not a
capability grant configuration or certification of backend handler security.

## Domain integrations

| Area | Read/navigation | Backend boundary |
|---|---|---|
| Company Workspaces and Content Jobs | Scoped company permissions | Existing job tenant/site checks, audit and queue workflow |
| Pricing Workbench | Company pricing permission OR native WooCommerce administrator | Read-only; Master additionally needs edit_post on selected product; cannot publish prices |
| Content Pipeline | Company operations + content proposal | Read-only stage table; actual save still requires manage_options and nonce |
| Company Content Hub | Company operations + content proposal | Site-local overview only; does not expose central publisher, REST producer or Host Authority |
| Fluent Forms New Form | Native fluentform_forms_manager | Fluent Forms original per-form ACL |
| Fluent Forms Entries / Reports | Native fluentform_entries_viewer | Fluent Forms original per-form ACL; global settings/Stripe remain protected |
| FluentCRM | URE-owned company CRM, operations and policy caps | Permission mapping respects explicit native per-user manager meta |
| Company Content Job Draft | Registry draft permission | Authenticated POST + nonce + exact site/brand/tenant, strict review, live runtime write gate, original transaction and audit |

## Content Jobs release boundary

Tours.dev Staging has an explicitly scoped Content Job intake page and
an additive guard in the current live ContentJobs service. Source tests do
not create real jobs. The existing service remains responsible for NEW/INTAKE
creation, schema, transactions, integrity and independent event auditing.
The extension does not grant generic MCP, REST, publish or approval access.

Wayfatours Production has a different ContentJobs service source AND a
disabled production runtime mutation gate. No Draft Post route or ContentJob
backend patch was installed there. Do not overwrite its service using
Tours.dev code. The Wayfa-specific small patch is preserved at:
 ops/mad4b-company-content-jobs-v1/live-reconciliation/wayfa.patch
It needs separate preimage reconciliation and real enabled production
authority before it may be activated.

An existing brand asset store callback subscribes to ContentJob created
events and may enqueue a bounded context recovery tick on a successful real
intake. This does not advance the ContentJob stage or publish content.

## FluentCRM compatibility

The old lifecycle filter always returned all 16 FluentCRM permissions to
every Master Admin. The replacement uses an allowlist plus effective company
role caps maintained by User Role Editor. A revocation is effective; stored
native per-user manager permissions (including an empty explicit record)
are an upper bound. No future/unknown FluentCRM rights are auto-granted.

Readback verified 16/16 currently assigned rights retained for four
Tours.dev Master accounts and one Wayfatours Master account. No role caps
or native manager meta were changed.

## Evidence

Site-specific code rollback preimages and before/after readback:
 /opt/mad4b-company-provisioning/reports/master-admin-policy-completion-20261119/

Tests include PHP lint, source regression, isolated PHP native-form and
FluentCRM permission mocks, and direct WordPress admin menu readback.
A menu gate pass never substitutes for an actual backend authorization.
