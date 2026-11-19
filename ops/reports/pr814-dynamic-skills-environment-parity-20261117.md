# PR #814 — dynamic environment-aware provider review for Dynamic Skills

Date: November 17, 2026. Earlier PR head: 96bcd51d0443e12ed3d66f2ec1f7b3ab0bd1b474.

## Source of the failure

The previous Staging Dynamic Skills smoke reported write_grant_source_policy_preflight_blocked with 18 unapproved missing ability/provider pairs. The runtime inventory knew their actual providers; the separate source-reviewed exact-grant eligibility did not. The same reviewed provider must be exposed to an enrolled Staging or Production site; Site Profile governance determines actual write authorization.

| Ability | Reviewed exact provider |
|---|---|
| context/native-owner-transfer-apply | google_drive_context |
| mad4b/approval-plan | core |
| mad4b/content-job-cancel | core |
| mad4b/content-job-create | core |
| mad4b/content-job-transition | core |
| mad4b/content-update-post | core |
| mad4b/data-processing-record-decision | core |
| mad4b/database-update | core |
| mad4b/draft-apply | core |
| mad4b/filesystem-patch | core |
| mad4b/filesystem-write | core |
| mad4b/intent-registry-reconcile | core |
| mad4b/mutation-undo | core |
| mad4b/plugin-activate | core |
| mad4b/plugin-deactivate | core |
| mad4b/provider-canary-execute | core |
| mad4b/translation-link-posts | translation |
| mad4b/translation-set-post-language | translation |

Provider provenance: the enrolled mad4b-write runtime inventory, first-party MAD4B_SCP_Servers::provider_for_ability() mapping and the translation bridge provider key.

## Scope and safeguards

This patch defines sixteen new exact reviewed provider pairs and corrects the two WPML translation-provider values from core to translation. These eighteen pairs have one source mapping for Staging and Production; historical Staging-only operations are unchanged.

The shared exact-provider list is an **eligibility policy**, not an issued exact Grant, signed owner approval, deployment, or payment authority. High-impact filesystem, plugin, database and mutation actions must still pass their original runtime capability, subject, Site Profile, one-time authorization, CAS and auditable rollback gates. No raw SQL breakglass, wildcard, generic dispatcher, or future unregistered ability is added. Production source eligibility for the eighteen is intentional, but it does not enable Production write, issue a grant, or bypass site-scoped approvals.

The permanent negative contract checks all eighteen exact providers, and verifies they resolve identically in Production, do not leak to Development, and rejects Breakglass/dispatch grant authority. The Dynamic Skills CI workflow runs this immutable source contract before the mutable disposable Staging smoke. Production activation requires its own confirmed Site Profile, exact approved grant plan, owner consent, and original execution gates. CI must prove actual Staging reconciliation; passing a static test alone is not enough.

Do not deploy these new source eligibility settings to either live site or mint an Owner attestation based on source tests. Do not assume already-provisioned Production grants are affected by this review.
