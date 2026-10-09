# IMP03 — Independent Import Contract, Immutable Approval and Provider Key Isolation

10 October 2026 · Feature 007 → PR #366 · Draft · read-only staging import governance, **not** a certified generic data writer.

## Addressed review objections

1. **Source-supplied policy override (P0):** validation, allowed currencies, identity, selected mappings, price-tier rules, historical-period checks, mandatory columns, relationships and WPML language groups now derive from a separately approved, revisioned **site-owned Import Contract**. If absent, import review rejects by default. Untrusted Apps Script/Make/CSV cannot lower these requirements.
2. **Unbound approval/source drift (P0):** Staging inbox persists a bounded, AES-256-GCM encrypted, nonautoloaded **exact source JSON snapshot**, with authenticated associated data bound to the site UUID, exact profile slug, original payload SHA-256 and validation policy hash. The snapshot and profile revision/authority hash must still match at readback and manual approval.
3. **Source impersonation (P1):** abandon one global `MAD4B_ACTIVITY_IMPORT_WEBHOOK_SECRET` for operational acceptance; each provider has its own host-managed `MAD4B_ACTIVITY_IMPORT_SOURCE_KEYS[key_id]` entry binding source mode, secret, site UUID, enabled state and an explicit profile allowlist. Clients pass `x-mad4b-key-id` plus HMAC signature. The request's `source_mode` must exactly match the trusted key; nonce uniqueness is scoped by key ID. Invalid/unknown key, cross-profile spoof, different mode or wrong environment fail closed.
4. **Raw source after review (P0):** no more content-free redacted-only receipt. The original reviewed JSON snapshot is available **only to an enrolled staging admin**, with authenticated decryption, matching hash and a separate explicit immutable approval record. A controlled CSV download exports that approved snapshot to a human-mediated existing WP All Import wizard. **No automatic WP post write.**
5. **Business Activity coupling (P1):** `Content Experience Profile.import_contract` is optional and independent of user account role/identity binding. Previously configured `activity_contract.import_modes.validation` may serve as an explicit transition fallback until migrated. Future CCT/JetEngine/E-commerce profiles do not require per-record WordPress user account links.
6. **WPML and price bypass (P1):** complete-group policies require the group/language columns and check configured language coverage; missing related IDs can be flagged when declared required. Price-tier review uses an *ordered site-configured list* of arbitrary price columns, works with 2 or more tiers, and refuses scientific-notation/oversized/negative monetary amounts via a bounded fixed-point decimal schema; no automatic repricing. Currency column is also chosen by the profile rather than hardcoded to a tour-specific name.

### New configuration sample (generic, **not automatically applied**)
```json
{
  "import_contract": {
    "enabled": true,
    "enabled_modes": ["admin_csv_upload", "google_apps_script", "signed_generic_webhook", "wp_all_import_wizard"],
    "preferred_mode": "admin_csv_upload",
    "fallback_modes": ["google_apps_script", "signed_generic_webhook"],
    "manual_review_required": true,
    "validation": {
      "identity_field": "ID",
      "required_columns": ["ID", "base_currency", "single_price", "double_price", "_wpml_import_after_process_post_status", "_wpml_import_translation_group", "_wpml_import_language_code"],
      "allowed_currencies": ["USD", "EUR"],
      "currency_field": "base_currency",
      "price_fields": ["single_price", "double_price"],
      "decimal_scale": 4,
      "field_mapping": {
        "base_currency": "base_currency",
        "single_price": "single_price",
        "double_price": "double_price",
        "_wpml_import_language_code": "_wpml_import_language_code",
        "_wpml_import_translation_group": "_wpml_import_translation_group"
      },
      "price_tier_policy": "review_monotonic",
      "review_past_intervals": true,
      "wpml_languages": ["en", "es", "fr", "it", "de"],
      "require_complete_wpml_groups": true,
      "required_relationships": [],
      "max_rows": 500
    }
  }
}
```
In this example `base_currency`, `single_price` and `double_price` must be explicitly permitted by the canonical parent `meta_keys`. This is an illustrative All Royal profile policy: no Meta field, CPT, WPML taxonomy or import job is presumed enrolled until site discovery and source approval. The input workbook has unresolved `ERU` currencies and `puplished` statuses and therefore must not be approved for publication without separate correction/authorization.

### Host-only source secrets, not committed
```php
// Illustrative shape only. Inject actual secrets through a managed host vault.
// Constant values are never provided in GitHub, WordPress options or ChatGPT.
define('MAD4B_ACTIVITY_IMPORT_DATA_KEY', getenv('MAD4B_IMPORT_DATA_KEY')); // 32+ random bytes
define('MAD4B_ACTIVITY_IMPORT_SOURCE_KEYS', array(
  'rates_appscript' => array(
    'mode' => 'google_apps_script', 'secret' => getenv('MAD4B_RATES_WEBHOOK_SECRET'),
    'site_uuid' => getenv('MAD4B_ENROLLED_SITE_UUID'),
    'profile_slugs' => array('approved_profile_slug'), 'enabled' => true
  ),
  'rates_make' => array(
    'mode' => 'signed_generic_webhook', 'secret' => getenv('MAD4B_MAKE_WEBHOOK_SECRET'),
    'site_uuid' => getenv('MAD4B_ENROLLED_SITE_UUID'),
    'profile_slugs' => array('approved_profile_slug'), 'enabled' => true
  )
));
```
All secrets must be independently generated; no shared-secret fallback is accepted. Rotate by adding a new key ID and disabling the old ID after proving the new signer, then reverify source identity, nonce and review receipts. Existing Apps Script client Script Properties: `MAD4B_WEBHOOK_KEY_ID`, `MAD4B_WEBHOOK_SECRET`, exact enrolled HTTPS URL, Site UUID, sheet ID/name, profile slug and optional local ID-field sanity check. Generic Node/Make/n8n client: `MAD4B_INTAKE_KEY_ID`, secret, exact URL/site UUID; HMAC raw identical JSON bytes.

### State machine delivered
`UNCONFIGURED_POLICY → PREVIEW (no data writes) → ENCRYPTED_REVIEW_PENDING → APPROVAL_REQUESTED → APPROVED_FOR_MANUAL_CSV_EXPORT → ARCHIVED`.

- Entry requires `Site_Profile.environment_allowed(['staging'])`, exact profile identity and policy.
- Signed entry requires scoped sender key, canonical HMAC bytes, bounded input, timestamp and anti-replay nonce; admin entry requires `manage_options`, WordPress nonce and a real uploaded CSV.
- Staging uses atomic `add_option` to prevent two reviews being silently overwritten and independent readback to confirm the stored encrypted value. This is **not a certified database transaction or complete concurrent queue**.
- Only `manage_options` may approve or read raw snapshot; approval reruns validation against decrypted source and ensures unchanged policy hash, profile authority/revision and plan SHA. Block reasons and truncated reviews prevent approval; warnings require explicit human confirmation.
- Approval is recorded as a separate no-autoload option with site, profile, snapshot, plan, policy, reviewer ID, time and immutable manual-export-only scope. No WordPress posts or WP All Import jobs are written by approval.
- Approved CSV export is a protected WordPress admin POST with nonce, fresh authority/readback checks, formula-injection checks and no-store cache headers; names contain only first 16 chars of snapshot digest.
- Archive records immutable audit metadata and removes the active encrypted review; if the archive cannot be saved, it must not destroy active review.

### Boundaries still open (do NOT certify)
- Exact `post_id` versus stable supplier key identity mapping and relationship/JetEngine data adapter (provider readback necessary).
- WP All Import configured job parity, plugin/WPML version compatibility, run receipts and external post-write locks with MSR02.
- Real CAS between standalone Sheets editors and writers, provider fencing and user review after source drift.
- Streaming >500 rows, row-level queue, paginated resolution UI, field-specific approval decisions, automatic rollback and production release gate.
- Native PHP 7.4/8.3 Staging acceptance, encryption key rotation across stored snapshots, multiple concurrent import worker/host failure tests.

An approved snapshot permits **manual CSV handoff only**, not automatic importer execution, Production writes or promotion. The controller MUST never relabel this milestone as full integration completion.

### Negative acceptance suite
- Missing import site policy, user supplied currency/price rule overrides, omitted mandatory status or WPML headers, duplicate identity, incomplete WPML group, fake related refs and formula cells.
- Valid HMAC wrong key ID, wrong mode, wrong profile, wrong site UUID, timestamp expiry, nonce replay, payload tamper.
- Corrupted ciphertext, wrong decryption key, stale profile revision, stale approved plan, approval during pending blocking errors, malformed encrypted envelope.
- Failed persisted option readback, concurrent attempts with same profile, missing auth/nonce, POST on Production, archive/approve race, huge source, orphan approval.
- Manual CSV handoff must reproduce the exact reviewed source snapshot; importing from a live changing URL rather than that snapshot is prohibited until independent source revision gates are implemented.

**Documentation:** `IMP01` remains intake and WP All Import option handoff; `IMP02` is the 23-mode dynamic transport catalog; `IMP03` owns independent business schema and immutable review-to-export authority. No plugin wizard automation is claimed.
