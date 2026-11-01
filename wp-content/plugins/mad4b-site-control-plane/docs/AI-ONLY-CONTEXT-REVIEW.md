# AI-only Context Review — MAD4B Source Feature

**Policy:** human_only remains the default; human_and_ai and ai_only are explicitly selectable in Brand Context > Approval workflow. Selecting ai_only is a one-time administrator policy delegation to the canonical Site Profile Agent, and must be confirmed on the form. It is NOT automatic credential issuance, production publication, or approval of existing documents.

**Authentication:** the AI Agent review Ability requires OAuth2 Bearer identity, the exact delegated public ID and bound subject, an enabled canonical Site Profile Agent (staging or reusable all identity), an exact staging mad4b-write grant for mad4b/context-ai-review, and Staging runtime site binding. Direct Basic authentication or Application Password is not a replacement for this OAuth identity in the review operation.

**Decisions:** approve, needs_changes, reject. ai_only approval requires a reviewer note and bounded review_evidence describing source, classification, integrity and content evidence. Incomplete content or low-confidence automatic classification cannot be approved. needs_changes/reject can record deficiencies without modifying category, authority class, required scope or quality. classification_proposal is recorded in audit only; it NEVER reclassifies the document.

**Integrity:** exact content SHA-256, current registry revision and authority-manifest fingerprint are compared under the existing registry lock. Generated evidence freshness and existing Brand Core source restrictions remain intact. Audits contain actor and OAuth fingerprints, decision, rationale, optional evidence and classification proposal; no human identity is impersonated.

**Publication:** brand approval is not consent to publish site content. Ordinary content publication retains its independent authorization and operational checks. No webhook silently publishes on ai_only approval.

**ETG three imported drafts:** documents 66145 (Brand Strategy), 66146 (Tone of Voice) and 66147 (Editorial Guidelines) have not been approved by this source implementation. They must be rescanned on the enrolled Staging runtime, reviewed using live OAuth Bearer Agent identity, and submitted with exact current hashes/revision and sufficient evidence. needs_changes is appropriate where important strategy, source or WooCommerce publishing policy gaps remain. Source CI does not certify those documents or an external live ChatGPT session.

**No Ruleset expansion:** Production Readiness Matrix is informational relative to the existing master required checks. This PR changes no site grant, Production authorization or live WordPress setting.

## Cross-environment AI review runtime (opt-in, no publication authority)

The AI review worker is no longer intrinsically restricted to `WP_ENVIRONMENT_TYPE=local`. Local's existing `MAD4B_LOCAL_CLIENT_SITE_UUID` and managed vault remain backward-compatible. For Staging or Production, an operator must explicitly configure **all** of the following in the site's trusted server configuration:

- `MAD4B_AI_REVIEW_CROSS_ENV_ENABLED` must be boolean `true`.
- `MAD4B_AI_REVIEW_SITE_UUID` must equal the enrolled canonical Site Profile UUID.
- The canonical site URL must use HTTPS, with TLS correctly reported on inbound REST requests.
- `MAD4B_AI_REVIEW_VAULT_PATH` must reference `ai-only-review.json` inside a non-public, restricted vault directory; the file must be owner-only (0600).
- Credential creation is **separately** opted into through `MAD4B_AI_REVIEW_PROVISION_ENABLED=true`, and still requires a verified enrolled administrator policy-save action (trusted host-operator reconciliation remains Local-only).
- The original `ai_only` delegation, enabled registered Agent, exact `mad4b/context-ai-review` grant, WordPress-authenticated Application Password, audit and content CAS remain mandatory. Native content-domain reviews additionally require their own exact environment-matching grants and file-pinned adapters.

The existing `mad4b-local-ai/v1` route identifier is retained solely for compatibility; it does not imply that Local environment confers privileges. Managed reviewer credentials are denied on unrelated REST/admin routes, even if the opt-in is revoked or the main Control Plane runtime is bypassed. Non-Local self-calls use HTTPS with `wp_safe_remote_post` and disallow local loopback override.

This enables a *review capability*, not Production deployment, publishing, protected configuration changes or site mutation outside the original exact authorization. Test-only CI records must remain non-authorizing. Revalidate WordPress end-to-end authentication on each site before enabling credentials.
