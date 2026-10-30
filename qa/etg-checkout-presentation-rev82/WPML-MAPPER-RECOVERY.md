# ETG Lab: WPML Meta ID Mapper — native recovery of missing Tour Rate owner

Date: 2026-10-28. Scope: local ETG WooCommerce Lab database only (`etg_woocommerce_lab`). Do not copy source mappings to a live WordPress origin.

## Prefer the installed owner

The existing active `wpml-meta-id-mapper-ui` v1.0.0 has a configured post-ID rule:
`tour-rates.related_tour_id` -> translated `tours-and-activities` ID. The
built-in `wpml_sync_custom_field_copied_value` hook converts exact source
values, rather than running another duplicate language resolver. The normal
bulk scanner *skips empty source fields* and never invents the source link.

Four local imported Rates 61052, 61054, 61056, 61058 had empty source meta,
`_etg_last_synced_tour_id=0`, and independent WPML TRIDs without a source
Rate member. The adjacent original Rate 61050 was published and linked to
Tour 60439, with matching date windows and verified per-target translated
Tour IDs. `repair-with-existing-mapper.php` requires those exact conditions.

| Local Rate | Registered WPML locale | Mapper-derived Tour |
| --- | --- | --- |
| 61052 | es | 60609 |
| 61054 | en | 60439 |
| 61056 | it | 64853 |
| 61058 | de | 60563 |

The native mapped value was saved as `related_tour_id`. Existing
`ETG_Lab_Season_Catalog_Sync` updated `_etg_last_synced_tour_id`, as
required by Commerce Composition's Tour Rate eligibility checks. The original
Publish statuses were then restored. Financial values and currencies were
verified against full pre-mutation metadata snapshots: unchanged. All four
relationships and their mirrored source IDs match. No additional published
Rate has a missing owner meta row in this local copy.

**Open data-quality issue**: Rate 61054 is currently labelled `en` by WPML,
although the sequence suggests it may originally have been `fr`. Changing a
WPML language is *not* an ID-mapping operation and was not attempted. The
Rates' end dates are 2026-09-30; their restored post status does **not**
certify an active sale, availability or a future tour quote. The rates in
question also lack a configured season term in the inspected local copy.

## Audit / rollback

Original post status snapshot:
`/opt/staging/chatbotx-deploy/backups/orphan-tour-rates-20261028/original-status.json`.
Full before/after meta snapshots and the preflight/apply script live in the
same VPS backup folder. The repair script uses a hard exact local database
and WordPress environment guard, rejects missing or changed evidence,
performs native mapper conversion and reads back the mirrored relation; it
never changes prices or WPML language.

For future missing references, always use WPML Meta ID Mapper first. If its
source value and the WPML source translation group are both absent, flag an
**unresolved source**, establish a documented source Rate/Tour anchor and
validate the exact target translations *before* mutating links or publication
state. Do not globally run bulk sync for a one-record anomaly, and do not
silently move published products to Draft merely due to an unmapped ID.
