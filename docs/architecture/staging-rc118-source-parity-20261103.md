# Staging rc.118 vs GitHub rc.104 source parity

This is a source inventory, NOT an installation plan, release certificate or approval. The original site at https://tours.dev.mad4b.com is unchanged.

## Measured source scope
- Original: MAD4B Site Control Plane 0.4.0-rc.118 on staging Docker PHP.
- Reviewed source: PR #749, 0.4.0-rc.104, exact tree 46f0f7276bce346cd7fcb2dd34bae3d6f08ee435.
- Scope: includes/**/*.php; the original bootstrap has a separate source SHA. MU-plugins, other plugins, assets and DB are excluded.
- Staging PHP: 720; reviewed GitHub PHP: 655; exact SHA-256 matches: 569.
- Same path with different bytes: 83; Staging-only: 68; GitHub-only: 3.
- Classification assigned for ALL 154 source deltas: central Control Plane 67; host-dependent 1; provider/site feature 29; portability candidates NOT VERIFIED 57.
- Classified means attributed to a preliminary source family, NOT reconciled, compatible, portable, safe or accepted. All 154 retain HOLD_FOR_ORIGINAL_CODE_OWNER_SOURCE_MERGE.
- Exact source and GitHub fingerprints plus provisional category and risk per path are in staging-rc118-source-parity-20261103.json.

## Operational order
1. Preserve the original Staging deployment; never replace its complete plugin from older GitHub rc.104.
2. Reconcile original CWA, ContentJobs, Authorization, Company identity, Admin registry and other central source contracts first. Inspect Git diff, native providers, inputs and full source/owner context individually.
3. Inspect host-dependent deployment code with the original VPS owner, and inspect portability candidates for less obvious host coupling. The single file detected by filename is NOT a claim that only one file depends on a host.
4. Reconcile media, Elementor, WPML, JetEngine, pricing and Woo providers with exact capability and WordPress role checks. The 3 GitHub-only files must not be deployed until their own boot owners are verified.
5. Re-run PHP lint, live original provider smoke, source SHA/ledger readback, browser locale interaction and mapped regressions. Require independent owner approval for Production.

The historical 1,096/1,096 Content Readiness issue parity remains valid for its scoped observation and cannot certify the entire 720-file plugin. This inventory is source-only and gives no runtime mutation authority.
