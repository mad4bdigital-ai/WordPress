# MAD4B Control Plane — Native JetEngine Content Hub Consumer Policy

**Contract:** `mad4b.hub.jetengine-native-consumer.v1`  
**Owner:** MAD4B Site Control Plane; scope is all future Hub→company WordPress distribution.  
**Implementation:** `includes/class-mad4b-scp-hub-jetengine-consumer-governance.php`, loaded by the Control Plane main plugin.

## Normative distribution policy

1. **Hub is a signed source of data, not a secondary site renderer.** A company site consumes bounded, company-owned Hub catalog items using Control Plane authorized REST. The source owner's site UUID, company ID and destination UUID must be verified at the server, not inferred from a slug or request parameter.
2. **JetEngine is the presentation provider.** Use its installed **REST API Listings** module and **Query Builder REST API Query**, with existing JetEngine Listing Grid / Elementor listing templates. Existing JetSmartFilters, their selected query IDs, taxonomy context and Elementor brand Kit remain local. Never create public Content Hub CPT pages, a parallel shortcode section, or custom company-agnostic cards as a distribution substitute.
3. **Don't treat REST and native WP_Query as identical.** JetSmartFilters connected to a *native post query* will not magically filter remote REST items. A valid integration must bind filters to the correct JetEngine REST API Query provider and map each field, response items path, query argument and pagination explicitly. An existing filter unrelated to the new source must not be silently retargeted.
4. **Tenant-provenance and taxonomy binding first.** Each item carries source company, source record ID, language, version and ownership. Taxonomy IDs are site-local. Resolve source/target WPML language, canonical term, parent, taxonomy, translation group and company policy before using a category; no duplicate terms or cross-tenant fallback.
5. **Company brand and media stay company-owned.** Templates may be portable but Elementor Kit colors, logos, images, Trustindex account, Google Tag Manager IDs, LiteSpeed/QUIC.cloud connection, forms, emails, sender and SEO configuration must never cross tenants. Keep personal/account credentials outside reusable manifest and REST responses. Media references require separate governed source-owner handling, never a raw WordPress attachment ID import.
6. **Pricing is a separate authority.** Historical Tourism Nile rates may be read as references within the Hub but must not appear as live offers or checkout-compatible rates unless Pricing Registry independently validates dates, currency, occupancy, market, current status and sale authorization.
7. **Fail closed, with reversible activation.** Drafting and validating a proposal is not approval to connect. A successful plan always says `runtime_approved=false`. Runtime admission must additionally receive true *server-side* signed company and native binding proofs from the Control Plane hooks `mad4b_scp_hub_consumer_signed_authority` and `mad4b_scp_hub_consumer_native_binding_authority`. Both default to false. Client payload flags such as `signature_verified=true` are not proofs.
8. **No bulk import or automatic publication.** This pathway may read existing sources remotely; it must not alter `wp_posts`, media, term relationships, options, Elementor content, WooCommerce rates, order/customer data or source company metadata. A future catalog cache needs explicit, scoped owner/time-to-live/version policy and must not become a second authoritative database.

## APIs available on Staging

Authentication requires `manage_options`, and both routes are **read-only with respect to publication and configuration**:

- `GET /wp-json/mad4b/v1/hub-jetengine-consumer/policy`: reports policy, native module availability and default denial.
- `POST /wp-json/mad4b/v1/hub-jetengine-consumer/validate`: validates a proposed consumer contract; returns structural plan or `WP_Error`. **Does not register an endpoint, create a query, connect to Hub, publish, or run a remote request.**

The future runtime caller must use `MAD4B_SCP_Hub_JetEngine_Consumer_Governance::admit_runtime($plan)` and enforce its result before any approved Hub REST-to-JetEngine bridge can run. A vendor or custom integration that bypasses this API is **not certified**, even if it happens to render.

The allowed central URL origin is `https://tours.dev.mad4b.com` with exact endpoint path
`/wp-json/mad4b-central/v1/hub-distribution/{source_company}/{catalog|categories}`. This is a **future API contract**; no production-ready distribution endpoint is asserted to exist. URL query strings, alternate hosts/IPs, embedded credentials, wrong path or ports fail closed.

## Migration incident: Tourism Nile temporary display rollback (2026-10-30)

The previous manual REST-snapshot-to-Shortcode Elementor solution was rejected as the wrong distribution architecture and has been **rolled back on Staging**:

- Restored Elementor `Home Tours Section #25917` exact pre-change SHA256 `d9059be305b86668e9dfbadb7d917b081b7ba96758a2a3cb13ae800e45b73f2f` (heading, JetSmartFilters and JetListingGrid intact).
- Homepage #35138 was already free of the standalone widget.
- Temporary English/Arabic catalog pages `67883` and `67884` are drafts; publicly 404.
- Removed Staging MU `220-mad4b-tourism-nile-catalog.php` into a private VPS rollback folder, and deleted the adapter from the PR source to prevent redeployment.
- Cleared Elementor and LiteSpeed caches; publicly confirmed no nested Nile shortcode section and no forced container, while the original filter/listing persist.
- The **417 private Hub records** and taxonomy reference mappings remain available; no Production or Tourism source DB mutation.
- Existing separate Esmerada fallback presentation/redirect in `210-mad4b-tourism-source-view.php` was **not disabled in this scoped rollback** to avoid breaking a pre-existing public redirected demo URL. It is a **legacy transitional exception**, not the approved distribution method; retire under a separate dependency-safe replacement.

## Rollout checklist — still required

The new Control Plane checker is in **planning-only** state on Staging. The signed company-source REST distribution route, source-side entitlement and per-request signing check, destination JetEngine REST Endpoint configuration, original Listing & Query binding, JetSmartFilters query-provider adaptation, cache invalidation/versioning, media resolution and mobile/desktop acceptance **are not installed or certified** by this policy change. Tests must prove source identity and separation, correct language and taxonomy, no staging/test contamination, no price activation and exact rollback before enabling distribution.

**Production promotion requires CI and owner exact-head approval on PR #631.**
