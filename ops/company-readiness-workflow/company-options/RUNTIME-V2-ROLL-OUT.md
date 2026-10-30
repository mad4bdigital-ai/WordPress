# Company Configuration Registry v2 — rollout and acceptance

## Goal

One central registry for the **definition**, **classification**, and **JetEngine provider mapping** of company configuration fields. Each company keeps **its own values** inside its own WordPress JetEngine Options Pages, with no cloning of another company's brand or pricing terms.

The registry is built dynamically from **actual live JetEngine options-page registrations**, rather than the underlying database tables alone. Platform-managed, approval-required, and company-editable pages have distinct lifecycle rules. WordPress/Woo core values are derived rather than copied.

## Live source-backed result, 30 Oct 2026

| Contract check | Source Staging | Wayfatours tenant |
| --- | ---: | ---: |
| Runtime JetEngine Options Pages | 12 | 12 |
| Runtime page fields | 271 | 271 |
| Field type/key differences | — | **0** |
| Source company values imported | — | **0** |
| Missing Elementor dynamic-tag instances in templates | — | **304** |
| Published templates affected by missing providers | — | **30** |

Registry v2 fingerprint is stored as `mad4b_company_configuration_registry_v2` on both sites and reverified with identical hash. It is generated from source runtime and maps each key to an existing tenant JetEngine page. The generator does not read, export, or update source option values. Metadata includes field names, types, labels, provider presence and classification.

- **Company editable:** `contact-and-help-info`, `look-and-feel`, `global-offers`, `company-communication-operations`. Values may remain blank until owner entry.
- **Platform managed:** `service-activation`, `destination-activation`, ABK date formats/selling options. No cross-tenant policy changes.
- **Approval required:** pricing/booking controls, company operations policies, and separately registered `mad4b-company-policy-library` with 65 policy/revision/language fields.
- **Derived:** site name/URL, Woo sender name (from current local blogname when not configured), favicon and WordPress logo (from owner-selected local JetEngine media, never foreign source IDs).
- **Runtime providers:** Elementor/JetEngine tag registrations and query providers, verified independently of raw-schema parity.

Tenant MU files:
- `243`: Company Configuration UI + secure metadata REST, scoped to `mad4b_manage_company_operations`.
- `245`: brand option fields only, no seeded values. Converts 45 live Look & Feel fields into 76.
- `246`: brand/presentation dynamic tags. Uses only the current tenant's JetEngine values and attachment IDs; blank if absent. Also supplies derived Woo sender name.
- `247`: eight missing service field definitions, without enabling pricing/checkout.

Scoped role test (in-memory simulated master_admin): access to all 4 company-editable JetEngine pages **yes**; global `manage_options` **no**; direct pricing-controls editing **no**. The protected company field REST returns **200** to the scoped role and **401** when unauthenticated. Website GET returns **200** with valid TLS.

Live `company-runtime-acceptance-v2.py` writes `reports/company-runtime-configuration-v2-wayfatours.json`; central readiness workflow now includes runtime field parity PASS, company capability PASS, and provider readiness BLOCKED where unsupported.

## Remaining high-priority work

Do **not** report a fully operational design system merely because templates are Published or their original source schema matches:
- **304 unresolved dynamic-tag occurrences** across templates; **30 already Published** templates are affected. Most relate to prices, Rates, product navigation, CCT and transport. Restore their real, compatible providers and quote authority before considering them ready. Do not register empty fake prices just to pass a count.
- Resolve static old media URLs, IDs and internal links by mapping them to actual tenant-owned JetEngine fields. Preserve verified existing dynamic tag bindings.
- Revalidate Elementor Header/Archive/Single display conditions, WPML translation page URLs, mobile/desktop visuals, and commercial Content Hub readiness.
- For policy pages, never imply approved customer terms until tenant-specific text, revision and approval are present.

## Rebuild / verify

On the authorized enrolled VPS, with both sites running:

```bash
python3 /opt/mad4b-company-provisioning/bin/company-runtime-registry-v2.py --write-metadata
python3 /opt/mad4b-company-provisioning/bin/company-runtime-acceptance-v2.py
python3 /opt/mad4b-company-provisioning/bin/company-readiness-workflow --refresh
```

Central data file is *generated evidence*, not an independently hardcoded field authority. Repo owns the generator and MU implementations. Do not auto-apply this registry to an unfamiliar tenant without independent company identity verification, source/tenant schema proof, backups, and WordPress native access checks.

The source code lives in Draft PR #655. No PR merge or general Production rollout was claimed.
