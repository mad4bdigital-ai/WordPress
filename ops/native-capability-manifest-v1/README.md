# MAD4B Native Ability Manifest Trust v1

Goal: company-specific WordPress Abilities enter the Control Plane catalog through dynamic discovery plus an independent, owner-pinned source manifest rather than hardcoded provider Class lists.

## Runtime
- Registered core Control Plane Ability: mad4b/native-capability-registry. It uses the existing MAD4B_SCP_Policy::can_read permission policy, private metadata, and bounded optional prefix/limit/offset input.
- Discovery works without a manifest; every unknown entry is DISCOVERED_REVIEW_REQUIRED. The catalog never executes any provider callback.
- A separate owner/operator manifest outside the WordPress root and WP_CONTENT_DIR must be pinned with an exact SHA256 in the trusted runtime constants MAD4B_SCP_NATIVE_ABILITY_MANIFEST_PATH and MAD4B_SCP_NATIVE_ABILITY_MANIFEST_SHA256.
- Each reviewed entry binds company host, ability name, scope=read, exact PHP source path relative to WP_CONTENT_DIR, PHP source hash, and Ability input schema hash.
- The verifier inspects callback provenance through only the three already reviewed Control Plane wrapper files: execution fence, error-contract registry and connector resilience. No callback is called during verification. Unknown wrapper and source drift are rejected.
- Only private, nonpublic, readonly and nondestructive read abilities qualify. Exact manifest binding yields OWNER_PINNED_READ_SOURCE_VERIFIED, not an extra execution grant or MCP route. Native WordPress permission checks continue to apply.
- The legacy bundled Adapter Class allowlists and external plugin trust system stay unchanged. No per-company provider name is added to shared PHP source.

## Tenant onboarding
1. Discover abilities using mad4b/native-capability-registry with prefix/limit/offset. All unreviewed abilities are inventory only.
2. Independently review source and schema of each proposed read ability for the company's Staging environment.
3. Place a reviewed Manifest outside WordPress files and pin its exact path and SHA256 through the owner-controlled runtime. A manifest inside wp-content is rejected even if its SHA matches.
4. Verify Staging reports OWNER_PINNED_READ_SOURCE_VERIFIED and check the original ability's user-level permission policy. Read-source proof alone is not an owner release certificate or a Write permit.
5. Scope manifests, secrets, company host, and attestation separately for each company. No Production mutation until a distinct approved release.

## Regression checks
- php -l for both Control Plane source files.
- Python static test ensuring tenant neutrality and no mutation primitives.
- Standalone PHP fixture matrix: valid, source_drift, schema_drift, scope_write, wrong_host, wrong_callback, pin_mismatch, manifest_inside_content, pagination.
- Actual Staging: 927 native abilities discoverable through paginated result; Company Performance Scan is a candidate in normal operation. A process-only test manifest verified its source via the existing execution wrapper chain. Any mismatch remained candidate-only.
- Source review and exact-head owner approval required before installing a persistent owner pin or deploying to Production.

## Limitations
This adds a trusted capability SOURCE REGISTRY, not an automatically executable read dispatch endpoint, MCP provider mapping, or a replacement for all legacy Adapter Classes. Existing Ability permission callbacks remain the execution authority. Review a separate change for a governed dynamic read dispatcher when needed.

## Rollback
Restore the prior Control Plane Adapter Registry file from a trusted release and remove the newly added native trust class; clear a configured owner manifest pin only by an authorized runtime operator. Do not remove existing performance presets, bookings, WooCommerce data or installed adapters.
