# MAD4B JetEngine Hub Add-on

**Plugin:** `mad4b-jetengine-hub-addon`  
**Contract:** `mad4b.jetengine-hub-native-addon.v1`  
**Required:** JetEngine REST API Listings, MAD4B Site Control Plane, WPML for multilingual canonical taxonomy mappings.

## Purpose

This is a small **native JetEngine extension plugin**, not a replacement for JetEngine. Its hooks run with the existing Query Builder REST API Query and existing Listing/Grid template, and translate *local canonical taxonomy identifiers* into *source-owner taxonomy identifiers* only for an explicitly configured and Control Plane-approved signed REST endpoint. It does not copy site databases, write terms, create parallel Elementor Containers/Shortcodes, expose the internal Hub CPT, set Elementor Kit colors, import source media, or authorize historical prices.

### Core algorithm

1. Read already-approved company-owned source/category records (currently Tourism Nile) and require their canonical Content Hub hash, ownership, publish state, non-bookability and admission-gate result. Never use a bare numeric source ID as authority.
2. Read the **exact** destination `wp_terms`, `wp_term_taxonomy` and `icl_translations` rows rather than WPML-filtered `get_term()`: the WPML filter can return the *EN translation* when given an AR term ID under an EN request context.
3. Compare **source WPML translation groups internally**, and separately compare **destination WPML translation groups internally**. The source and destination group numbers need not match across WordPress sites. Enforce a unique EN/AR pair, exactly one canonical target per source/language and a matching parent **translation group**, while accepting a root term's EN parent as the parent of a translated AR child when WordPress data is configured that way.
4. Distinguish source and destination slugs. Translated slugs legitimately differ and cannot be used as the primary cross-site key. For the approved existing Tourism fixture, EN canonical slugs match exactly; AR slugs need not.
5. At the JetEngine `jet-engine/rest-api-listings/request/query-args` hook, look up an approved **endpoint ID** and matching exact source URL from a server-owned Control Plane binding, then transform `canonical_term_id` into the corresponding source `category`. If no endpoint binding exists, leave the unrelated JetEngine query unchanged. If a bound request contains a raw source-category ID, unsupported locale or ambiguous mapping, add a rejected argument that is not an approved API parameter. The Control Plane network fence and server then deny access.
6. At `jet-engine/rest-api-listings/response/body`, validate source ownership and language, convert the response's source taxonomy terms into local canonical terms, and **construct a new allowlisted display object**. Never forward arbitrary remote fields (pricing, customer email, payment links, account keys, etc.) to a Listing.

### Control Plane ownership

`MAD4B_SCP_Hub_JetEngine_Consumer_Governance::admit_runtime()` now requires this add-on to be loaded and the native REST module enabled, in addition to server-side signed source identity and endpoint/listing/query proof. The addon does not itself grant runtime access. Its required endpoint-binding option `mad4b_scp_hub_jetengine_bindings_v1` is **empty by default**, and no endpoint is automatically created or activated.

The signed Hub REST distribution endpoint itself remains a *future managed contract* and must supply verified response provenance and per-company grants before this addon can fetch anything live. The current Staging deployment is deliberately **`unpaired`**.

### Administrator-only diagnostics

- `GET /wp-json/mad4b/v1/jetengine-hub-addon/status`
- `POST /wp-json/mad4b/v1/jetengine-hub-addon/resolve` with `canonical_term_id` and `language`.

Both require `manage_options`. The resolve endpoint is read-only and returns source/canonical identifiers, language and WPML translation group IDs; never media, prices, credentials, customers or booking data. These are diagnostics, **not public data feeds**.

### Staging acceptance (2026-10-30)

- Installed and activated on `tours.dev.mad4b.com`, where JetEngine REST API Listings was already active.
- **18** source category records map to **9 bilingual WPML pairs**.
- Source-vs-target AR slugs differ in actual Staging data; addon successfully resolves them through the signed source category records + exact destination WPML identities.
- Source parent `2103` for Arabic translated children may map to destination's canonical EN parent `193`; those parents belong to the same WPML translation group, so the group check passes **without changing terms**.
- Preinstallation tests passed: unknown term, wrong source tenant and unsupported language denied; bound native category filter translated; unbound JetEngine query preserved; malicious direct source ID rejected; foreign response rejected; body projector stripped price, sale price, checkout URL, account key and customer email.
- Actual plugin: activated; anonymous admin-status REST returned **401**, administrator status returned **200**, AR term resolution returned approved source/canonical linkage.
- Control Plane recognizes the add-on and still reports default runtime admission **deny**. No new public Hub catalog pages, templates, live REST endpoint or pricing/checkout activated.
- Homepage HTTP 200; **417** internal Hub records retained. Temporary rejected shortcode catalog remains rolled back.

### Remaining for production-ready distribution

Implement server-side signed collection route, per-company grants and nonce protection, server-owned binding registry for endpoint IDs/listing/query IDs, authorized outbound signature injection and JetSmartFilters provider bindings. These must be independently tested before enabling a customer-facing collection. Source-local attachment/media IDs and historical prices remain excluded. The existing plugin currently validates the Tourism Nile fixture; other companies must supply their own verified mapping manifests, language contracts and ownership permissions before this adapter can be generalized.

Rollback the staging add-on by deactivating `mad4b-jetengine-hub-addon`. It has no private database tables or created content to uninstall. The Site Control Plane will then deny runtime Hub distribution without the required adapter.
