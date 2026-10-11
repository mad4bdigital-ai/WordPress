# Remote Brand Context review via governed MCP — Feature007

## Objective

Approve or reject a **site-bound, normalized, first-party Context asset** remotely without opening the WordPress admin UI. This is a governed alternative to the human review screen, **not** permission to import a foreign/unbound legacy source, auto-approve generated authority, or grant an AI agent human identity.

## Discoverable capabilities

1. `context/review-queue`: read-only current review candidates (including exact `asset_id`).
2. `context/remote-owner-review-plan` with `asset_id`: read-only, binds current site UUID, authoritative `source_id`, asset content hash, registry revision and authority manifest into `plan_sha256`. It provides the title/excerpt and reports no authorization.
3. `context/remote-owner-review-apply`: governed mutation requiring the same `asset_id`, exact `expected_plan_sha256`, a decision of `approve` / `needs_changes` / `reject`, and an explicit `confirmation=I REVIEWED EXACT BRAND SOURCE`. A human reviewer must first inspect full first-party material, not merely the excerpt.
4. `context/review-audit`, `context/brand-core-coverage` and `context/brand-core-control-loop`: independently read back the exact reviewed hash, actor, postconditions, category coverage, and pending recovery work.

The write follows the **existing exact one-time approval ticket** dispatcher; no extra OAuth scope and no Breakglass are granted. A matching authenticated WordPress administrator bearer is required: `auth_method=oauth2_bearer`, a locally mapped and consistent WP user ID, `manage_options`, exact Site Profile Staging origin/environment binding and a fresh approval ticket. AI-only bearer tokens cannot impersonate owners. Every decision is handled through the existing `review_asset_with_actor` registry lock, content/revision/manifest CAS, audit and persistence logic with `identity_method=oauth2_bearer_remote_owner`; the remote adapter verifies post-write asset readback.

**Hard exclusions:** no categorical or authority reclassification in this remote endpoint, no required-scope change, no automatic owner confirmation, no stale generation evidence override, no automatic human attestation. Low-confidence automated classifications remain blocked for manual human classification through the separately reviewed route. Staging only until independently certified future policy, never Production by default.

Existing `mad4b/context-ai-review` is a **different** remote action for a specifically delegated AI agent. It preserves category, authority and quality and denies suspicious operational documents, low-confidence classifications and changed evidence. Do not present AI review as human review.

## Current All Royal Egypt quarantine

At 2026-10-11 live readback, `context/review-queue` contained **zero** authorized candidates, `context/brand-core-coverage` had 0/3 approved categories, and `context/legacy-owner-transfer-discover` reported one foreign/conflicted legacy record. The exact owner-transfer routes already exist as MCP Abilities, but proof of original file identities, independent host crash-safe rollback certification and separate owner consent are mandatory. This remote owner-review implementation **does not** lift that quarantine. The first recovery step is to establish unambiguous first-party ownership, register/scan that approved source under a separate exact governed operation, then review its eligible normalized assets. No Memphis Tours publication is permitted until all three categories pass and commercial/media rights are separately verified.

## Acceptance and current scope

- PHP 7.4 and PHP 8.3 syntax check for Context Authority, Context Adapter and Brand Core loop.
- `php wp-content/plugins/mad4b-site-control-plane/tests/context-remote-owner-review-runtime.php`
- Regression tests: missing/malformed ID; unbound/task source; AI bearer and anonymous caller; wrong WP user/capability; missing exact one-time ticket; wrong Staging profile; missing confirmation; changed asset content/revision/manifest; stale/low-confidence source; denied review; valid complete first-party asset; independent readback and audit attribution.
- Verify dynamic MCP discovery, write grant, owner OAuth mapping and one-time human approval ticket independently on a **certified exact-head** Staging package. Confirm full Native E2E with a disposable non-live asset before relying on this code.
- This commit does not deploy a plugin, attest legacy ownership, approve an asset or modify Production. The presence of the remote API in source is not proof it is active on the installed Staging binary.
