# Company Reconcile — exact owner review and readiness boundary

The central **Company Reconcile & Deploy** page is source-owned and its display authorization is the existing company-operations capability `mad4b_manage_company_operations` (granted to the authorized `administrator` and `master_admin` roles), **not** `manage_options`/`activate_plugins`. This does not grant WordPress settings/plugin installation or permission to submit Native jobs.

## Exact, content-addressed review

A browser owner confirms only the exact enrolled company, environment, origin and Site UUID **and** the current advisory file-diff manifest. The review binds:

- Original 305 exact request SHA256;
- each allowlisted source path, source SHA256 and target SHA256, sorted canonically;
- enrolled target company/environment/origin/Site UUID;
- original authenticated browser owner user ID.

A scan with identical bytes does not require a new review. Changed files or request bytes, a different target or another actor automatically invalidate the previous review. Legacy unbound reviews do not qualify as current reviewed scope. The owner cannot confirm a scope without a valid **fresh** host file-diff inventory. An identical reviewed scope can remain valid beyond one hour, but submission still requires a host scan newer than **60 minutes**. A future timestamp is explicitly rejected.

The original 305/306 grants, signed host binding, execution lock, operation provider, Original313 receipt and rollback/release checks are unchanged. In particular, a reviewed scope does not authorize any write. The CLI itself cannot impersonate the authenticated owner browser.

## Evidence and known remaining boundaries (2026-11-17)

The original central runtime discovered **7 company environments**, of which **5** had valid Site UUIDs. Egypt Tour Gates Production and Tourism Almallah Group Production lacked completed target bindings. All seven general `content.write` target projections remained `execution_allowed=false`; Wayfatours Production had a narrower exact-job preflight but did not have blanket grant. The source owner file-diff inventory was last fully refreshed on **2026-11-16 23:53 UTC** and was marked **STALE_LAST_ADVISORY_READBACK** on 2026-11-17; more recent child evidence does not make that full scan fresh. CPU/WP-CLI admission correctly defers the optional full scan when host resources are constrained. Do not bypass the scheduler solely to make a readiness badge green.

The `Admin Experience` file-diff catalog covers a bounded presentation asset pack; it is **not** a comprehensive WordPress/JetEngine/WPML/pricing/booking database reconciliation. It must not certify those other areas by implication. Master Admin legacy role overprivilege (e.g. `manage_woocommerce`/`fluentform_full_access`) is a separate role-governance task: this UI change does not modify roles or issue Grants.

Original-owner browser acceptance, a fresh complete host inventory, an actual Original305/312/313 write and rollback receipt, and the cross-company semantic checks are independent conditions; none is manufactured by these read-only tests. Production site code is unchanged by this exact-owner UI patch.
