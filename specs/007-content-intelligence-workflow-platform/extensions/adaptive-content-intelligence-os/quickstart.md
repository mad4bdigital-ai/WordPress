# Quickstart — evidence-only first, Staging gates later

Contract: `mad4b.aci-os.quickstart.v1`. Commands below validate **specification**; they never install plugins, fetch paid SERPs, grant authority, update WordPress or publish.

1. Checkout the exact PR #258 Integration Hub HEAD (or reviewed child branch). Record HEAD and clean worktree.
2. Run `python specs/007-content-intelligence-workflow-platform/extensions/adaptive-content-intelligence-os/validate.py`.
3. Run `python specs/007-content-intelligence-workflow-platform/extensions/adaptive-content-intelligence-os/test_validate.py`.
4. Run existing `python specs/007-content-intelligence-workflow-platform/validate_spec.py` and exact-head changed-path/owner checks.
5. Inspect all `OPEN` ACI tasks and confirmed parent/CE01 dependencies. A passed spec validator does not advance task status.
6. For a future disposable WordPress/WPML native profile, prepare isolated posts, terms, locale groups, translation-field policy and mapper settings. Perform before/after reads; do not use the historical Staging sample as a write target.
7. Verify provider mapping, consent and budgets independently before any paid operation. Observe the result class `EXTERNAL_EFFECT_UNKNOWN` using a fake provider fixture.
8. Run the declared gate matrix in `acceptance.md`; use separate status per repository/disposable/live/browser/host/release layer.
9. Advance no release/Production gate without signed exact artifact and owner approval.

## Example review-only case

- Target: one draft for a localized tour topic on a disposable site.
- Inputs: one approved site/brand+locale scope, an existing typed native post+term snapshot, one signed/allowed ContextPack, synthetic SERP evidence.
- Outputs: one OpportunityHypothesis, EvidencePack, InformationGainPlan, Blueprint, no-write Draft and blocked/accepted QualityVerdict.
- Expected denial: the draft/plan alone cannot resolve an unknown property translation or enable the Publish button.

## Troubleshooting

`NO_EVIDENCE` → re-fetch scoped provider evidence; `PROVIDER_UNAVAILABLE` → select eligible alternate or pause; `SEMANTIC_UNVERIFIED` → inspect local entity equality and WPML mapper; `AUTHORITY_NOT_CURRENT` → owner exact Staging handshake/reconcile outside this kit; `SKILLS_RUNTIME_NOT_READY` → managed Skills reconciliation; `EXTERNAL_EFFECT_UNKNOWN` → journal-based reconciliation without retry; `PUBLISH_MANIFEST_STALE` → regenerate plan and seek new approval.
