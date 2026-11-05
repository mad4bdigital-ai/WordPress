# MAD4B Content Creation Control — source ownership

This plugin was recovered *unchanged* from the live `tours.dev.mad4b.com` Staging runtime into `wp-content/plugins/mad4b-content-creation-control/` on 2026-11-05. Ten active source files are tracked; four local `*.bak` snapshots are deliberately excluded. Verify the pinned `source-files.sha256` manifest before deployment.

This enrollment is a **source-parity operation, not a logic fix or permission grant**. Do not install as a second plugin copy. Production remains unchanged.

## Known, independently verified defect

In `MAD4B_Content_Creation_Control_Bridge::recipe()` the runtime tests `$p['slug'] === $job['content_type']`. Translation jobs have `content_type=translation`, while the *real enabled and authoritative* `MAD4B_SCP_Content_Experience_Profiles` entry is `slug=tours-and-activities`, `post_type=tours-and-activities`. Hence a false `semantic_recipe_missing`.

The correction should match the **native target post-type** to profile `slug` and `post_type`, while keeping all `enabled`, `authority_current`, `runtime_post_type_ready`, `helper_catalog_match` and `migration_required` predicates. No logic correction is included in this source-enrollment commit. A previous Staging modification request was refused and must not be retried via an alternate write path.

## Separate outstanding dependencies

- 54 WPML Tour translation Content Jobs remain `QUEUED/INTAKE`; all semantic flights are Revision 2 and their `runtime_resolve` tasks are complete; `stage_work` still awaits a valid executor.
- Site Context Authority brand profile is `Wo Vacation` with **zero** enrolled sources/assets; `brand.core`, `audience.primary`, `voice.language`, Writer Profile and authentic draft evidence remain absent.
- Correcting the recipe lookup does **not** imply these data or writer prerequisites are met.
- Connected `mad4b/hub-chatgpt-work-pending` returned zero prepared AI-stage handoffs. No model work or publication can be inferred.
- PR #760 remains Draft and exact-HEAD owner attestation is independent.

This repo-source enrollment, its checksum and PHP lint do not authorize merge, publish or bypass.
