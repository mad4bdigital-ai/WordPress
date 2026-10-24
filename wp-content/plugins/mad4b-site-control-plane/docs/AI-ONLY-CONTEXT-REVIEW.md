# AI-only Context Review — MAD4B Source Feature

**Policy:** human_only remains the default; human_and_ai and ai_only are explicitly selectable in Brand Context > Approval workflow. Selecting ai_only is a one-time administrator policy delegation to the canonical Site Profile Agent, and must be confirmed on the form. It is NOT automatic credential issuance, production publication, or approval of existing documents.

**Authentication:** the AI Agent review Ability requires OAuth2 Bearer identity, the exact delegated public ID and bound subject, an enabled canonical Site Profile Agent (staging or reusable all identity), an exact staging mad4b-write grant for mad4b/context-ai-review, and Staging runtime site binding. Direct Basic authentication or Application Password is not a replacement for this OAuth identity in the review operation.

**Decisions:** approve, needs_changes, reject. ai_only approval requires a reviewer note and bounded review_evidence describing source, classification, integrity and content evidence. Incomplete content or low-confidence automatic classification cannot be approved. needs_changes/reject can record deficiencies without modifying category, authority class, required scope or quality. classification_proposal is recorded in audit only; it NEVER reclassifies the document.

**Integrity:** exact content SHA-256, current registry revision and authority-manifest fingerprint are compared under the existing registry lock. Generated evidence freshness and existing Brand Core source restrictions remain intact. Audits contain actor and OAuth fingerprints, decision, rationale, optional evidence and classification proposal; no human identity is impersonated.

**Publication:** brand approval is not consent to publish site content. Ordinary content publication retains its independent authorization and operational checks. No webhook silently publishes on ai_only approval.

**ETG three imported drafts:** documents 66145 (Brand Strategy), 66146 (Tone of Voice) and 66147 (Editorial Guidelines) have not been approved by this source implementation. They must be rescanned on the enrolled Staging runtime, reviewed using live OAuth Bearer Agent identity, and submitted with exact current hashes/revision and sufficient evidence. needs_changes is appropriate where important strategy, source or WooCommerce publishing policy gaps remain. Source CI does not certify those documents or an external live ChatGPT session.

**No Ruleset expansion:** Production Readiness Matrix is informational relative to the existing master required checks. This PR changes no site grant, Production authorization or live WordPress setting.
