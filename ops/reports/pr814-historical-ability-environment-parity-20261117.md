# PR #814 — environment-aware historical Ability parity audit (2026-11-17)

## Scope and result

- Reviewed **all 66 originally Staging-only literal Ability/provider pairs** in the Staging Write Grant Reconciliation source.
- **50 ordinary company operations** now have identical source provider eligibility on enrolled Staging and Production. 48 are content, context, taxonomy, filters, media and general company operations; two are hardened Media Library transfer and Optional Governance.
- **18 previously shared pairs** remain source-consistent, including WPML translation-provider identity.
- **16 source-owned Staging facilities** retain their exact operation-specific restrictions. Their original code does not currently implement a certified Production execution contract. Their retention is a safety requirement, not a blanket denial of Production company operations.
- Two additional enrolled-central diagnostic queue abilities are conditional on registered site identity, not universally available.
- In both environments this is **source eligibility, not execution authority**. No live Grant, Owner approval, payment, Production enablement or runtime release is created by this PR.

## Shared operations (50 of original 66)

| Exact Ability | Reviewed provider |
|---|---|
| `context/brand-profile-save` | `google_drive_context` |
| `context/governed-source-upsert` | `google_drive_context` |
| `context/remote-owner-review-apply` | `google_drive_context` |
| `jetsmartfilters/update-filter-meta` | `jetsmartfilters` |
| `mad4b/artifact-append` | `core` |
| `mad4b/attachment-transfer` | `core` |
| `mad4b/business-activity-import-approve` | `core` |
| `mad4b/business-activity-import-batch-append` | `core` |
| `mad4b/business-activity-import-batch-approve` | `core` |
| `mad4b/business-activity-import-batch-archive` | `core` |
| `mad4b/business-activity-import-batch-begin` | `core` |
| `mad4b/business-activity-link-apply` | `core` |
| `mad4b/business-activity-sync-advance` | `core` |
| `mad4b/business-activity-sync-archive` | `core` |
| `mad4b/business-activity-sync-begin` | `core` |
| `mad4b/business-activity-sync-cancel` | `core` |
| `mad4b/business-activity-sync-finalize-reconciled` | `core` |
| `mad4b/business-activity-sync-recover` | `core` |
| `mad4b/content-delete-meta` | `core` |
| `mad4b/content-experience-profile-apply` | `core` |
| `mad4b/content-experience-profile-clone-apply` | `core` |
| `mad4b/content-experience-profile-delete-apply` | `core` |
| `mad4b/content-import-bundle` | `core` |
| `mad4b/content-set-meta` | `core` |
| `mad4b/content-trash-post` | `core` |
| `mad4b/deployment-binding-native-apply` | `core` |
| `mad4b/host-operation-apply` | `core` |
| `mad4b/market-growth-policy-update` | `core` |
| `mad4b/optional-governance-apply` | `core` |
| `mad4b/page-r1-create-apply` | `core` |
| `mad4b/page-r1-publish-apply` | `core` |
| `mad4b/page-r1-update-apply` | `core` |
| `mad4b/post-r1-create-apply` | `core` |
| `mad4b/post-r1-publish-apply` | `core` |
| `mad4b/post-r1-update-apply` | `core` |
| `mad4b/properties-r1-create-apply` | `core` |
| `mad4b/properties-r1-publish-apply` | `core` |
| `mad4b/properties-r1-update-apply` | `core` |
| `mad4b/taxonomy-update-term` | `core` |
| `mad4b/tours-and-activities-r1-create-apply` | `core` |
| `mad4b/tours-and-activities-r1-publish-apply` | `core` |
| `mad4b/tours-and-activities-r1-update-apply` | `core` |
| `mad4b/tours-and-activities-r2-create-apply` | `core` |
| `mad4b/tours-and-activities-r2-publish-apply` | `core` |
| `mad4b/tours-and-activities-r2-update-apply` | `core` |
| `mad4b/transportations-r1-create-apply` | `core` |
| `mad4b/transportations-r1-publish-apply` | `core` |
| `mad4b/transportations-r1-update-apply` | `core` |
| `media/import-external` | `media` |
| `media/remote-import-apply` | `media` |

## Staging-specific operations (16 of original 66)

| Exact Ability | Original boundary / safe alternative |
|---|---|
| `mad4b/acceptance-target-provision` | Disposable Staging acceptance fixture; never an actual Production deployment |
| `mad4b/business-activity-wpai-observation-arm` | Staging importer observation requiring a separate Production original event contract |
| `mad4b/control-plane-selected-head-apply` | Staging candidate source update; Production requires certified Original release lane |
| `mad4b/deployment-binding-setup-apply` | Staging bootstrap; separate native deployment binding is reviewed for site profiles |
| `mad4b/fleet-intake-auto` | Fleet runtime currently requires explicit Staging-enrolled source binding; Production engine proof unavailable |
| `mad4b/fleet-intake-plan` | Fleet runtime currently requires explicit Staging-enrolled source binding; Production engine proof unavailable |
| `mad4b/fleet-intake-save` | Fleet runtime currently requires explicit Staging-enrolled source binding; Production engine proof unavailable |
| `mad4b/hub-source-sync-request` | Enrolled central Staging supplier/dataset source sync; never a general company import |
| `mad4b/native-recovery-cleanup` | Host-free scratch recovery rehearsal; not Production recovery |
| `mad4b/native-recovery-start` | Host-free scratch recovery rehearsal; not Production recovery |
| `mad4b/provider-connection-refresh` | Legacy scoped Staging credential refresh; Production original OAuth reconnect is separate |
| `mad4b/runtime-release-set-apply` | Staging release-set transition; Production needs certified release/owner admission |
| `mad4b/staging-offline-attestor-enroll-apply` | Offline CI-outage signing key explicitly restricted to Staging evidence |
| `mad4b/wordpress-native-candidate-opt-in-apply` | Staging candidate build/install opt-in, not Production source certification |
| `mad4b/wp-native-build-start` | Uncertified build-only Staging path |
| `mad4b/wp-native-self-approve-install` | Staging self-certification cannot replace independent Production release verification |

## Test and operational safeguards

- Current environment is derived from the enrolled Site Profile. Production writes require explicit feature-enabled and production_write_confirmed state, as well as actor/WordPress capabilities, exact site origin, current runtime provider admission, approved one-time ticket, rollback and audit.
- Media transfer is bounded to the site UUID plus canonical origin for temporary storage and transfer state. It checks upload_files, mad4b_attachment_transfer, the global kill-switch, exact enrolled identity and confirmed Production Site Profile write. It does not publish.
- Optional Governance can change advisory preferences only: source-validated catalog, exact actor, OAuth owner scope, one-time execution claim, CAS/audit and a plan digest that now binds the Site Profile environment and revision digest.
- The 16 exceptions cannot be safely made Production-ready merely by renaming Staging; for each, the original signed or isolated executor is a different workflow. Their original fail-closed callbacks and review conditions stay intact.
- Hermetic parity test checks 68 exact shared pairs, 16 Staging-purpose exceptions, denies Developer/unknown/Breakglass and does not issue a Grant.
- Original attachment-transfer and optional-governance tests exercise Production denied without explicit Site Profile write confirmation, then verify source permission eligibility with confirmation, without executing a live write.

## Sources

- Source reviewer: `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-staging-write-grant-reconciliation.php`
- Per-tenant authority: `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-site-profile.php`
- Media: `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-chatgpt-file-transfer.php`
- Advisory governance: `wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-optional-governance-remote.php`
- Original Staging-only executor contracts: provider connection, Fleet enrollment, native recovery, self-certified install, CI-outage attestor, selected-head and release-set source modules.

**Release status:** Source-only candidate pending GitHub CI and exact release Owner decision. Do not apply this source patch directly to sites or mint Production Grants as a workaround.
