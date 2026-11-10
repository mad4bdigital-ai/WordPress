# Media Rights: ingestion lifecycle (Wayfatours)

**Canonical storage:** JetEngine CCT `media_rights` (`jet_cct_media_rights`).
The legacy WordPress option `mad4b_licensed_media_credits_v1` remains a read-only rollback snapshot.

1. `add_attachment`, `_wp_attachment_metadata`, and source-evidence metadata changes enqueue **one per-attachment WP-Cron job** for `mad4b_media_rights_extract_attachment_v1`.
2. The event checks that the attachment is a real, locally stored image no larger than 15 MiB, hashes the file and deduplicates by `discovered_attachment_<id>` and any *published* rights record with the same source SHA. It writes a **draft** CCT entry only. No license, author, or source is invented. Drafts are never included in the public archive.
3. Extraction and matching jobs may find candidate media with unknown copyright; they stay `draft`. A matching filename or AI guess cannot prove licensing. Source data must be authenticated/reviewed against the original source before using the signed apply path.
4. The existing reviewed, signed `mad4b.media.apply.v1` term image route writes a published, idempotent CCT rights record (`licensed_<SHA prefix>`) only after review and preimage checks. Gallery, Repeater and scalar image writers retain their existing typed contracts; their availability does not by itself authorize new public credits.
5. CCT `publish` entries appear in the Elementor EN/ES rights page, 12 rows per page. Updates invalidate only these two pages and their Elementor shortcode caches. Each footer uses `[mad4b_footer_legal]`, enhanced at render time with the translated rights-page link.

**Recovery and manual backfill:** `wp mad4b media-rights-intake status`; `wp mad4b media-rights-intake scan --after=0 --limit=25`. The scan has a maximum limit of 25 and returns a cursor (`last_id`); run bounded scans to backfill older attachments if desired. The scheduled hook never modifies published tour images, term relations or licensing approvals. This path intentionally does **not** auto-approve images lacking independently confirmed rights.

**Pending cases:** External images extracted into a content proposal but not yet registered as WordPress attachments cannot enter the CCT until the approved media step creates/reuses a local attachment. Build richer lineage and usage mapping separately; never substitute guessed original author/license metadata.

## External Media Import Bridge (2026-11-10)

The current Site Control Plane's `media/remote-import-apply` / recovery staging
creates or reuses WordPress attachment IDs and writes provenance metadata
(`_mad4b_import_source_url`, `_mad4b_remote_media_source_sha256`,
`_mad4b_remote_media_manifest_sha256`, `_mad4b_remote_media_recovery_stage`).
The `MAD4B Media Rights Extraction Intake` observes these **native attachment
meta events** in addition to `add_attachment`, and schedules a bounded,
idempotent CCT synchronization for the exact attachment. External imports that
reuse an attachment are therefore covered as well.

The draft CCT record retains the HTTPS original source URL after it is saved
in the database, even when this metadata arrived after `add_attachment`.
Author/license values remain unverified and empty; the registry never
infers rights from scraped pages or importer metadata. The **only** path to
public `publish` rights remains the already signed and reviewed Media Apply.
Existing published rights take precedence over new draft candidates by file
SHA-256. Do not download arbitrary external sources solely to populate this
registry, and do not issue a second Media Import or bypass its existing
provenance/approval checks.

Source URL readback intentionally queries the exact WordPress postmeta rows,
not persistent object-cache values, to prevent stale metadata after failed
import transactions. Regression test:
`ops/media-rights/tests/test-remote-extraction-provenance.php`; it tests the
actual local CCT inside a rolled-back SQL transaction without persistent test
rows or live imports.

**Boundary:** Media proposals that remain outside WordPress (no imported/reused
attachment) continue in their original Content Job / Remote Media Manifest.
They cannot be treated as licensed or attached, and no extra table is added.
