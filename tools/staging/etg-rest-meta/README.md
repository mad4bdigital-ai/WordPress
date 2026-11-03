# ETG Staging — Code Snippets #24 REST metadata scan (review-only)

## Verified evidence, 2026-10-29

Site: ETG Commerce Staging (staging.egypttourgates.com), not All Royal Egypt. Code Snippets #24 is active, global, title REST Meta Exposure for JetEngine + WPML. The database-held PHP source SHA-256 before any modification is bedb685b8e2de8734f5af8bc4d8f9aac63644a8194f55bf68fb332699006b5bf. A read-only wp eval-file audit reproduced SELECT DISTINCT pm.meta_key JOIN wp_posts WHERE p.post_type='attachment' with 38 distinct keys, 0 accepted by current private/public allowlists, in 55.22 ms. This query was observed through rest_api_init, lazily called by MAD4B_SCP_MCP_Peer_Governance during admin discovery.

## Narrow remediation

The PHP source file is the exact prior database-held snippet plus one skip for attachment in the generic postmeta scanner and a comment, with a version bump. The explicit six Elementor registrations remain and other public CPT/term discovery logic is unchanged. This eliminates the unnecessary attachment meta query when the corrected snippet is actually deployed, but DOES NOT cure the wider DB-driven schema inference design.

## Non-authorizing staging change gate

This repository file is NOT installed automatically. Do not copy/paste its leading PHP tag into Code Snippets. A later, independently authorized, site-bound Staging operation must:

1. Record immutable backup of current snippet #24 code, active state, and database backup/rollback identity; verify installed target domain and exact old SHA-256 and snippet id/name.
2. Independently recheck that no currently permitted attachment keys exist. If any exist, abort and review their registered REST schema and consumers.
3. Apply only the reviewed replacement code (without PHP opening tag), via the governed WordPress mutation path, under exact operator/owner approval; no direct Production or generic SQL alteration.
4. Read back installed code hash and active state; compare /wp-json/wp/v2/media, /wp-json/wp/v2/types, tour/destination REST fields, Elementor/WPML behavior, admin_init requests, and Query Monitor. Assert no DISTINCT pm.meta_key query for attachment.
5. Roll back the original verified snippet if the API contract, CORS, performance or plugin compatibility regresses.

### Follow-up architectural work

Replace global postmeta and termmeta enumeration and per-key sample type inference with a versioned, site-configured explicit schema registry populated from reviewed JetEngine/WPML source definitions, with exact capability/object checks and negative tests. Do not auto-expose arbitrary public-prefix keys or infer a stable REST schema from live content. Review real REST consumers before disabling the old mechanism.

Hermetic test: php tools/staging/etg-rest-meta/contract-test.php; source includes no site/runtime deployment operation.
