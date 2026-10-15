# IMP07 — Post-IMP06 Source Hardening, XLSX Intake and Conversational Operator Plan
**MAD4B Feature 007 · 10 October 2026 · PR #366 · Draft · Exact-source changes; runtime not certified.**

## Actual gaps addressed after IMP06

1. **Imported Profile round-trip**: `Import_Contract::normalize_contract()` previously rejected its own returned `auto_execute=false` field when the Profile was edited/reapplied; it now permits only false and explicitly rejects any attempt to turn auto-execution on. A disabled Import Contract remains authoritative over obsolete Activity fallback.
2. **Hardcoded dates**: price interval review previously referenced `tour_rate_start_date`/`tour_rate_end_date` and applied floating point operations to arbitrary date strings. Site-owned validation now optionally declares `period_start_field`, `period_end_field` and `period_format:iso_date|unix_seconds`. Strict UTC validation rejects noncalendar ISO dates and reversed intervals; historical review is derived from the configured end field. No implicit date or timezone guessing. Older Profiles without explicit period settings continue without hardcoded rate-name heuristics until separately migrated and approved.
3. **Partial CSV export**: previously HTTP download headers were sent before every row was checked for formula/control injection, risking a broken file. The exact approved source is now fully revalidated, encoded and capped in an isolated `php://temp` stream, before *any* CSV response headers are issued. The post-approval policy/source hash is checked again. This is a **bounded manual CSV export**, not a provider import.
4. **XLSX path previously an inert Mode**: an optional native converter now requires an already-present `PhpOffice\\PhpSpreadsheet\\IOFactory` reader plus ZipArchive and the encrypted Staging source gate. The server requires `MAD4B_IMPORT_XLSX_PARSER_APPROVED === true` set through a host-approved configuration **in addition** to an available, vetted PhpSpreadsheet and ZipArchive. A class being present in another plugin does not by itself grant permission. The server checks genuine .xlsx upload ≤1 MiB, a bounded ZIP structure ≤8 MiB expanded, no suspicious external ZIP entries or OOXML `TargetMode=External`, exactly one worksheet, ≤500 data rows × 80 columns, bounded plain scalar cells, and no formulas. It never executes macros, formulas, adds dependencies, imports WordPress posts, creates provider jobs or accepts arbitrary filesystem paths. It stages converted rows through **the same site-owned validation and AES-GCM review path** as CSV. If PhpSpreadsheet is not installed/loaded, the Mode remains unavailable with clear CSV fallback. Formats XLS/XLSM/ODS and multi-sheet workbooks are **not** certified.
5. **Source-mode policy bypass**: an empty `enabled_modes` allowlist previously meant "all allowed" on the server despite the UI hiding Modes. This now denies all transports. Existing CSV admin POST and incoming HMAC REST enforce the *actual* Profile allowlist, not just the rendered dropdown.
6. **Authenticated REST is not WordPress admin**: the signed Webhook is authenticated using a dedicated, site/profile/mode-bound HMAC key, not a WordPress user session. A previously inserted call to the admin-only Mode catalog was removed from signed intake. A pure `Import_Authority::mode_allowed(profile,mode)` is used **only after HMAC authorization**; admin CSV/XLSX still use WordPress capabilities, nonces and Staging Mode plans.
7. **Conversational read plan**: new `mad4b/business-activity-import-experience-plan` read-only MCP ability reports selected Profile, permitted/ready Modes, whether there is an existing review, blocking/warning totals, next safe action, and the WordPress admin handoff path. It never emits raw supplier values, HMAC secrets or import execution grants. The wizard optionally reveals approved identity/required price and currency columns to reduce reuploads.

## Non-mutating site-owned configuration example

```json
{
  "import_contract": {
    "enabled": true,
    "enabled_modes": ["admin_csv_upload","admin_xlsx_convert","google_apps_script"],
    "preferred_mode": "admin_csv_upload",
    "fallback_modes": ["admin_xlsx_convert"],
    "manual_review_required": true,
    "auto_execute": false,
    "validation": {
      "identity_field": "supplier_record_key",
      "required_columns": ["supplier_record_key","currency","price","start_date","end_date"],
      "allowed_currencies": ["USD","EUR"],
      "currency_field": "currency",
      "price_fields": ["price"],
      "decimal_scale": 4,
      "field_mapping": {"price":"approved_price_meta"},
      "price_tier_policy": "none",
      "review_past_intervals": true,
      "period_start_field": "start_date",
      "period_end_field": "end_date",
      "period_format": "iso_date",
      "wpml_languages": [],
      "require_complete_wpml_groups": false,
      "required_relationships": [],
      "max_rows": 500
    }
  }
}
```
`approved_price_meta` must be in that Profile's explicit Meta allowlist. This is a **contract illustration**, not installed All Royal site configuration. For ISO format, date strings must be `YYYY-MM-DD`; future/current validity is still evaluated at review time in UTC. An empty enabled Mode list means no intake route. `auto_execute=true` must be refused.

### Site-only XLSX authorization

The host administrator must explicitly verify which plugin/vendor provides the loaded PhpSpreadsheet reader, its installed version, compatible PHP runtime, licensing, update source, and isolation before setting `MAD4B_IMPORT_XLSX_PARSER_APPROVED=true` via an authorized non-public host configuration. Do **not** store this flag or any credential in uploaded worksheets. If the constant is absent/false, the XLSX Mode stays disabled and the user may safely use CSV. This is operator attestation, not a remote auto-approval.

## Operator simulation and negative gates

- Re-normalize an already normalized independent Import Contract with `auto_execute=false`: allowed. `auto_execute=true`: denied.
- Profile with `enabled_modes=[]` cannot accept CSV, XLSX or signed push. A specific Mode cannot be introduced by manipulating a GET/POST parameter.
- HMAC-only signed sender with no WordPress admin cookie succeeds if its own key is valid, fresh and bound to a permitted Mode and Profile. No source is accepted merely because the same Profile has some other Mode enabled.
- Invalid ISO date, start>end, missing declared date columns and malformed currency/price are rejected/flagged under the exact site policy.
- XLSX with missing vetted parser, macros, external relationships, multidata sheet, formulas, excessive ZIP ratio, oversized sheet and malformed/unuploaded file must fail closed. Values rendered by Excel formatting (e.g., numeric date serials) are **not silently interpreted** as locale dates.
- CSV response remains uncommitted until the full source passes validation and encoding. No partial downloaded file should be labeled approved.
- New runtime fixture `imp07-import-source-runtime.php`, static contract `imp07-import-source-contract.py` and PHP linter target `class-mad4b-scp-activity-import-xlsx.php` enrolled in `feature007-manual-preflight.py`.

## Remaining non-code proof required

1. Native PHP lint/runtime fixtures at PHP 7.4 and 8.3 on exact HEAD; 8.4 host availability alone does not prove the branch works.
2. Live Staging installation, PhpSpreadsheet version & ZipArchive presence, genuine XLSX/CSV acceptance and browser keyboard/mobile/RTL tests.
3. WP All Import installed-version/template/job status mapping, actual post-write source identity and WPML/JetEngine readback.
4. Managed production-safe Host source keys & encryption key with vault rotation/recovery; no key value in git.
5. Resumable 500+ row encrypted queue, multi-source CAS, transactional WP All Import/MSR02 fences and recovery, Brand Core human review and Production release policy.
6. **No merge, no automatic source or post mutation, no Production promotion** within IMP07.

**Decision:** source hardening and *conditionally available* direct XLSX review are implemented. Availability on a given site is false unless all host and library prerequisites pass. End-to-end certified import is still gated by native Staging tests.
