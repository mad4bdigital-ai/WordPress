# WPML Meta ID Mapper - Source Recovery v1.1.0

Site-neutral extension of the existing WordPress plugin. No hard-coded company URL, source ID, product type, target language or business pricing field appears in the engine.

Use WPML ID Mapper -> Source Recovery to configure per-rule source-evidence fields, a derived mirror field, expiry field and optional stop-sale key. Preview always comes first. The source is resolved through native WPML group provenance, stored source-pointer metadata, or an explicitly authorized original source post. Independent WPML groups require at least two matching configured source fields and a source in the site's current WPML default language. The Mapper's existing WPML conversion hook resolves the target locale.

Per-record repair requires WordPress manage_options, an admin nonce, a valid rule, source and language verification, idempotent readback and an audit entry. Publication state, currency, prices and rate dates are never rewritten. Expiry enforcement uses the current site's configured end-date and stop-sale keys; it never extends rates.

The tool uses WordPress home_url and admin_url, so each company's trusted WordPress origin configuration determines its URLs. It never trusts an arbitrary request Host header. No generic REST write endpoint or blanket bulk catalogue mutation is exposed.

Test with: php tests/source-recovery-contract.php. The standalone test covers two business-domain configurations, absent/mismatched source evidence, fail-closed behavior, idempotency and an origin change.

Unresolved lineage must be referred to manual source verification, never inferred from adjacent IDs, title similarity, close prices or a domain string.
