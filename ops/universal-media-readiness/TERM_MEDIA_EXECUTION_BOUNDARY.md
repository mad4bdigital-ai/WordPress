# Term-media execution boundary diagnosis — 2026-11-10

Verified at Wayfatours Production:
- Host Ed25519 binding works; `mad4b-wayfa-host-agent status` reports exact site UUID `a3a7cc36-db7e-4a14-8456-b41857def05c`, `review_grant_ready=true`, `effective_review_mode=human_only`, and `execution_authorized=false`.
- Existing signed host operation `media_associate` only handles **published tours-and-activities** and does not accept arbitrary taxonomy-term media fields. There is no registered signed `licensed_term_media` operation. Therefore the generalized Media Resolver/Intake writer has no authorized corresponding executor.
- No preserved rejection receipt for an Esna attempt was found, so the exact previous execution-channel denial is not proven. Do not attribute it solely to OAuth, certificates or Staging conditions.
- The approved Esna source asset is held separately at `reports/media-universal-20261110/approved-assets/esna-marc-ryckaert-original.jpg`; its SHA256 is `2ada210f9e7a83861e190be9b9b4f6249b9d40bb5f17b17abc751600864824d8`.

## Implemented now

`mad4b-licensed-term-media-apply-v1.php` validates a versioned exact-schema preflight contract, pinned site UUID, public taxonomy/term identity, expected pre-image, bounded file path, SHA256 + JPEG size, and HTTPS source/license allowlists. It is read-only and explicitly reports `human_approval_verified=false` and `write_executed=false`. Directly invoking its internal `execute` without signed Host Agent returns `licensed_term_media_signed_host_required`; even an active host attestation cannot write until a scoped, separately approved dispatcher is explicitly implemented. The production plugin cannot be used as an arbitrary WP-CLI write backdoor.

`mad4b-universal-media-readiness.php` now raises a **site-level** `signed_term_media_transport_missing` Configuration Alert while images require a taxonomy-term writer and the signed operation is absent. The alert will not disappear merely because a proposal file exists.

## Remaining authorized implementation boundary

1. Register a **new typed signed `licensed_term_media` operation** in Host Agent and WordPress Bridge, with exact-body allowlist and anti-replay, not arbitrary shell/SQL commands. Do not alter `human_only` policy implicitly.
2. Bind the operation to a genuine approved Media Intake review/artifact. A caller-supplied `approval_reference` string is not evidence of approval; check the approved object in the existing workflow, and the photo source, license and subject.
3. Implement an atomic attachment + term-meta apply with rollback on failure, language-family scope, exact pre-image, visible attribution, selective cache invalidation, and immutable readback receipt. Ensure both source and tenant licenses and data ownership are respected.
4. Run an independent negative authorization test; then authorized test with signed receipt; then frontend QA for EN and ES (including CSS `background-image`), and only then resolve media alerts.
5. Do **not** run historical `/tmp` scripts as a workaround; do not represent the unexecuted Esna proposal as a published asset.

The current module intentionally stops at the safety boundary rather than bypassing it.
