# QA39 — Tenant-bound Commerce Management REST v1

**Deployed/tested:** 2026-10-28, ETG Woo Lab only (`wp_get_environment_type()=local`, DB `etg_woocommerce_lab`). No Staging or Production mutation.

## Ownership and design

- **MAD4B Site Profile v2** remains the canonical company/site identity source, including site_uuid, environment, origin/deployment bindings, revision and profile digest.
- Per-company non-authorizing definition: WordPress option `commerce_management_company_definitions_v1`, contract `commerce.management.company.v1`, with a strict **exact-site** binding to site_uuid, Site Profile revision and digest, and environment.
- Definition metadata includes domain visibility booleans (never capability grants), existing Fluent Forms IDs by company-specific role, and permitted native CPT service/Rate source types. No domain IDs are built into the reusable REST facade.
- Original domain owners are reused: Fluent Forms, CPTs, Rate providers, RequestReview, LifecycleReadiness, FluentBoards, WooCommerce HPOS, workflows, CRM, supplier and accounting provider contracts.
- Missing, foreign, stale or malformed company definitions **fail closed**. Managed company REST starts read-only; writes require both the exact site-bound definition and a healthy Site Profile authority/origin/deployment binding.
- Changes to site definitions are **not** exposed as arbitrary REST writes; identity and company governance remain under their enrolled Control Plane source.

## Administrative REST endpoints

All endpoints are below `/wp-json/commerce-composition/v1`, require authenticated `commerce_review_requests` or administrator capability and existing RequestReview site policy.

| Method | Route | Scope |
|---|---|---|
| GET | `/management/definitions` | Site Profile binding, company-specific form/source registry, native provider route catalog, authority blockers |
| GET | `/management/resources?domain=...` | 14 allowlisted domain source owners and disabled state |
| GET | `/management/catalog?domain=services&type=...&page=1&page_size=20` | Bounded published services from company source-type list; no prices |
| GET | `/management/catalog?domain=rates&type=...&page=1&page_size=20` | Bounded published Rate identities; no prices or sales authorization |
| GET | `/management/requests?page=1&page_size=20` | Existing RequestReview paginated listing |
| GET | `/management/requests/{request_id}` | Existing 12-gate LifecycleReadiness inspection |
| GET | `/management/requests/{request_id}/actions` | Dynamic native review transition list; cap/role and source binding evaluation |
| POST | `/management/requests/{request_id}/commands` | Strict delegation to *existing* RequestReview::decide or FluentBoardsTaskBridge::ensure only |

All responses have explicit v1 schema and Cache-Control private/no-store. Errors are bounded WP_Error codes. Pagination max 50; arbitrary post type, provider, callback, SQL, path or resource names are denied.

### POST security contract

Requires `Idempotency-Key` (16–128 characters) and exact matching `site_uuid`, `site_profile_revision`, `expected_review_revision`, `action` and action-specific fields. Review transitions use RequestReview's own native revision/actor/idempotency/audit transaction; task creation uses the existing unique request-to-native-task claim and FluentBoards provider capability. This REST layer never creates alternate task/order/quote records. Never use the management route as a generic arbitrary executor. User roles must still pass the original provider's native capability checks.

**Important:** Woo Lab's Site Profile reports `origin_match=false`, `authority_ready=false`, `write_enabled=false`, and `profile_authority_quarantined=true`. Consequently **all management POSTs are correctly rejected (HTTP 409)**. There is no successful-write certification for this Lab; do not bypass or rotate the identity binding.

### Domain operations not authorized by this release

Commercial Quote approval, traveler acceptance, Woo order creation, payment capture, supplier booking, accounting posting, arbitrary WP user/role changes, and company-definition mutation. None is enabled merely by a true domain visibility flag, a logged-in administrator, or a green lifecycle diagnostic.

## Verification evidence

- QA39 management REST suite: **29/29 PASS** (site binding, reviewer/admin/guest roles, invalid company definition and no-definition fail closed, request listing and state, native transitions, blocked POST, no business mutations).
- QA39 source catalog suite: **11/11 PASS** (paginated source identities, blocked WP post types, bad domain/size rejection, read-only prices, guest denied).
- QA38 lifecycle regression: **17/17 PASS**.
- PHP lint and installed/source file parity pass; replay of QA39 patch onto exact pre-change snapshots pass.
- GET public HTTP without credentials: **401**; Woo Lab homepage HTTP **200**.

## Source and release

Current source: `/opt/commerce-components/composition`. Installed Lab copy:
`/opt/hostinger-migrations/hosting-3/etg-woocommerce-lab/site-copy/wp-content/plugins/commerce-composition`.

Reversible snapshots and tests on VPS:
`/opt/commerce-components/evidence/etg-management-rest-qa39-20261028/`.

The GitHub files in this PR are a **scoped source patch plus test evidence**, not a complete deployable Commerce Composition release. Proper multi-tenant rollout requires onboarding the complete plugin source under an authorized repository/build, separate Staging provider certification, approved Site Profile/origin reconciliation and independent Production release authority.
