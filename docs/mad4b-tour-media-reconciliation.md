# MAD4B Tour Media Reconciliation

Contract: mad4b.tour-media-reconcile-plan.v1. Live on Wayfatours Production and Tours.dev Staging. This is a read-only, provenance-aware image selection planner, NOT a media publisher.

## Public APIs
- WP-CLI: wp mad4b media-reconcile --post-id=60473 --limit=18 --skip-themes
- Bounded scanning: wp mad4b media-reconcile --batch=1 --limit=3 --after=0 --skip-themes
- Programmatic: MAD4B_Tour_Media_Reconcile_V1::plan($post_id, ['limit'=>18]).

## How it works
1. Exact Site Profile, Brand Context and canonical WPML post family.
2. Read live JetEngine fields and storage format from Provider Field Intelligence.
3. Search tour-local attachment sources by exact tour-title prefix and WordPress parent; never use the contaminated shared Gallery as evidence of relevance.
4. Resolve canonical attachment records and each of their WPML translations. Reject translated sets without matching file hashes and language coverage.
5. Verify local uploads root, filename/parent evidence, image metadata, visual dimensions and image SHA. Group identical physical files; detect false labels from other tours.
6. Propose separate Featured, Gallery, Grid and Vertical values with correct per-language local attachment IDs and JetEngine URL formats. Keep missing portrait fields in NEEDS_ADDITIONAL_MEDIA_ASSET.
7. Candidate scoring uses filename/title activity hints ONLY as review priority. AI-generated or real photography cannot be proven from extension or filename.
8. Return immutable snapshots and a deterministic plan fingerprint; no Post Meta writes.

## Original Content Job and Host Agent
Wayfatours integration enriches the existing MAD4B Media Review Plan with tour_specific_media_reconciliation. The signed-host media_reconcile operation checks exact Site Profile, original Fact Ledger, existing WAITING_REVIEW Job, prior active media_plan Artifact, Gallery hash and candidate fingerprint. It appends a versioned original Artifact; it cannot publish. Replaying identical proposals returns already_proposed.

Live Wayfatours v2 Media Plan Artifacts: Dolphin House #60473 fa9e6278-4caa-4adb-8351-0d0fa6e07234; Philae #60465 52ac68bf-9c19-4f48-b2d6-dbdd60cc54fb; Abu Simbel #60467 69d78c73-d7e8-4582-be24-8c318d358885.

## Review gate before Apply
- Match each photo to itinerary and destination; verify genuinely photographic subject and rights/provenance as available.
- Inherit each company's existing Review Policy. Never infer approval from a candidate score.
- Governed Apply is NOT implemented by this module. It must use exact old-field SHA compare-and-swap, locale-specific WPML attachments, verified Alt/Caption, backup/rollback, cache invalidation and frontend readback.
- No actual Gallery, Featured, Grid, Vertical or Alt edits were made in this iteration.

## Regression
Readback logs: /opt/mad4b-company-provisioning/reports/tour-media-reconcile-20261108/. Twelve smoke checks pass on each site. Signed replay is idempotent, unsigned writes rejected, both homepages HTTP 200.

## Git integration
The feature branch includes the new engine and signed reconciliation proposal class. Existing MU Media Origin Integrity and Host Agent Bridge integration is live but not yet completely reconciled to the canonical Git main tree. Keep PR draft until those exact-file integrations and CI are covered.
