# Feature007 — Coverage of all WordPress Control surfaces vs MAD4B UI / MCP

Date: 12 October 2026. PR #258 source audit. **Not a 100% visual/control-interaction PASS.**

## What "covered" means

| State | Truthful meaning | What it never proves |
| --- | --- | --- |
| `REGISTERED` | A WordPress Ability, route, setting, hook, entity, block or scheduled job exists in the **current request** registry | That MAD4B owns it, that a matching button exists, or it can be invoked |
| `UI_ROUTE_REGISTERED` | The current user can navigate a declared MAD4B admin page | That every form/button on it works |
| `RENDERED_CONTROL_OBSERVED` | A disposable WordPress PHP DOM smoke captured a form, link, button or field on a particular tab | That JavaScript behavior, screen reader, mobile, persistence or side effects passed |
| `MCP_ELIGIBLE` | A specific typed Ability is registered, projected and exactly authorized in a verified request | That there is a matching wp-admin form or approved write grant |
| `END_TO_END_CERTIFIED` | A distinct, executed browser + WordPress/DB + PHP/runtime + operation readback/rollback receipt exists | Never inferred from code text, source count, or queued CI |

## Confirmed source baseline

- `MAD4B_SCP_Admin_Route_Registry` and `MAD4B_SCP_Admin_Workspace::inventory()` enumerate only properly registered, role-visible pages. Existing `tests/admin-pages-wordpress.php` enumerates **17 expected admin page slugs** and refuses missing/unexpected routes in a disposable, logged-in WordPress setup. This test is **not executed** at the new PR head.
- `MAD4B_SCP_Admin_Surface_Coverage` previously summarized 11 kinds, bounded to 96 per kind / 512 overall, with a `registered_surfaces_only_not_all_rendered_buttons` disclosure. This is valuable but not an exhaustive per-control UI index. Its scanner did not supply a direct, pageable WordPress operator screen.
- The site may load additional third-party WordPress Abilities, plugins, forms, CPTs and AJAX endpoints at runtime. The new catalog explicitly marks these as *site registered, ownership not verified*.
- Source contains adapters, APIs, write executors and other non-admin/automated effects with **no guaranteed equivalent wp-admin button**. An exact runtime ability can be used via MCP after governance without inventing unapproved admin callbacks. The absence of a matching admin widget remains a coverage gap to verify rather than an auto-authorizing operation.

## Changes made to the source

1. Added `MAD4B_SCP_Admin_Surface_Coverage::CONTROL_CENSUS` / `control_census()`, a registered **read-only** WordPress Ability `mad4b/admin-control-census`, loaded and booted from the real plugin main file. It has strict administrator permissions, typed bounded `kind/offset/limit` input, no action invocation, no credential values, and per-kind cursor-like `next_offset`.
   The server read universe now explicitly includes `mad4b/admin-control-census`, `mad4b/admin-surface-coverage` and `mad4b/admin-adapter-blueprint` as separately governed read tools; this does not imply a direct ChatGPT fixed tool, successful live projection, or authenticated execution.
2. Its 12 dynamically paginated registries cover **WordPress Abilities, MAD4B workspace pages, role-visible menus, governed operations, admin-post, authenticated AJAX, Settings API, REST routes already registered, UI-enabled post types, taxonomies, registered blocks, and cron job hook names**. The list is *not cut off after 96 entries*: use additional pages until `next_offset=null`. The original bounded 11-kind `inventory()` and blueprint remain available without changing their semantics.
3. Added `Controls Coverage` tab in `MAD4B_SCP_Admin_UI` and `Admin_Workspace`. Every listed row explicitly labels its registration and incomplete *per-control UI/MCP execution evidence*. Pages can link to their exact admin route. Admin tab rendering stays usable even when governance DB/schema is unavailable, just like the existing read-only Tasks tab.
4. Extended disposable `tests/admin-pages-wordpress.php` to include `controls` and `tasks` in the exhaustive tab smoke, record a per-screen DOM census (form/button/field/link counts) and preserve prior form nonce/action registration checks. `js_events_tested=false`, `field_write_execution_tested=false` and `live_browser_acceptance=false` remain explicit.
5. Added `tests/admin-control-census-runtime.php`: 231 fake WordPress Abilities over three pages, no silent truncation, 12 kinds, masked sensitive identifiers, no cross-user leakage, no-priv AJAX exclusion, input/permission denials, owner-uncertainty and main plugin bootstrap wiring. Included it and the touched PHP files in `tests/feature007-manual-preflight.py`.

## Coverage matrix

| Surface | Discovery | Operator UI | Real operation / effect | Evidence still required |
| --- | --- | --- | --- | --- |
| MAD4B wp-admin routes, 17 known from disposable smoke source | Dynamic registered-route inventory | Navigation / new Controls Coverage | Each page's own forms and typed handlers | Complete PHP/WordPress + browser/RTL/mobile/keyboard |
| WordPress and MAD4B Ability registry | Pageable via `wp_get_abilities()`, only if registry loaded | Pageable informational index | Ability's own governed MCP/write lane | Exact mapping for **each** claimed UI action |
| Menu controls and registered settings | Dynamic metadata only, capability filtered | Menu/field metadata view | WordPress's individual capability + nonce + adapter | Per-field validation, persistence, role denial |
| admin-post and authenticated AJAX hooks | Dynamic registered hooks; nopriv not treated as admin | Hook metadata index | Existing bound WordPress callback, **not invoked** by census | End-to-end click/request + nonce/readback |
| REST endpoints | Registered routes from existing REST server only | Endpoint index | Exact REST method/args/permission adapter | REST lifecycle and explicit typed UI/MCP bridge |
| Post types, taxonomies and blocks | Registered UI-eligible entities and block type names | Entity/block index | Existing Content Experience/CPT/media/adapters | Editor data schema, published status, WPML, rollback |
| Cron and governed operation jobs | Scheduled hook names / registered operation states | Control index | Separately authorized job/executor | Idempotency, timing, retry, terminal result, compensations |
| Third-party JS-injected forms/widgets and arbitrary DOM control handlers | **Not discoverable from registry** | Only disposable DOM observation, no universal adapter | **Not automatically enabled** | Browser click/keyboard/network/audit evidence for each relevant control |

## How to verify after exact-head source checkout

```sh
php -l wp-content/plugins/mad4b-site-control-plane/mad4b-site-control-plane.php
php -l wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-admin-surface-coverage.php
php -l wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-admin-ui.php
php wp-content/plugins/mad4b-site-control-plane/tests/admin-control-census-runtime.php
# Full disposable WordPress only, never live:
# php wp-content/plugins/mad4b-site-control-plane/tests/admin-pages-wordpress.php
```

For a real installed Staging runtime: verify `mad4b/admin-control-census` is discoverable through the governed MCP read lane, inspect **all pages for all 12 kinds**, compare `pages` with role-visible Admin Workspace and per-tab DOM controls, test POST/AJAX and JS dynamically with a logged-in browser, capture disabled/permission-denied cases, verify exact write permission/approval/AI delegation/audit, then independently verify installed exact-head SHA and reversible effects. No auto-registration of arbitrary hooks as executable remote admin actions is permitted.

## Release decision

**INCOMPLETE until independently tested.** Source-level coverage/discovery and pagination exist on PR #258, but exhaustive per-Control UI parity, source ownership for third-party abilities, native PHP 7.4 / 8.3, real WordPress and browser acceptance, actual Staging deployment and Production certification remain unproven. This report must not be promoted to `100%` by counting registered surfaces alone.
