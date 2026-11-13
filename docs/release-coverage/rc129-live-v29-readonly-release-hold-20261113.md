# MAD4B Control Plane RC129 live source reconciliation — release HOLD

This is a **source-review branch, not a release**. It preserves the rc129
candidate and its previously staged 67 changes, then carries forward verified
live rc128 source changes. Nothing was directly changed on Tours.dev or the
company sites. Do not publish this branch to the update-channel Release as a
certified artifact without the original Release Verdict, signed owner
provenance and independent runtime readback.

## Exact candidate provenance

- Base rc129 source HEAD: `794e732af124eb4bfa2be5f00cb838e1b15f566a`.
- Preserved existing rc129 staged changes: `51d33a1ab3` (67 files).
- Reviewed rc128 live reconciliation: `3630dddac5` (51 classified paths).
- Live source RC128 signed package original SHA:
  `e18c8090ad217d467296bbfa327fc238799393e0`.
- Read-only V29 review source: `16bec2fa48a19cdccaf861700e018f3dc7c632f1`.
- Site plugin remains **rc129**; no downgraded rc104 runtime replacement.
- The Control Plane bootstrap receives exactly one read-only Central
  Original305 Job/Claim ability and two bounded native-source review abilities.
  No dynamic write provider, signing key, Claim or Grant is enrolled by source.
- The Google Measurement Bridge and GTM Consent MU integration were restored
  from exact verified live / first-party Git sources to preserve GTM Publish
  and incremental re-consent behavior; no OAuth credentials were exported.

All reviewed paths and SHA256 are pinned in this directory:
`control-plane-rc128-live-rc129-review-candidate-20261113.json`,
`google-measurement-bridge-gtm-live-parity-20261113.json`,
`gtm-original-mu-source-restore-20261113.json`, and
`control-plane-rc129-v29-readonly-overlay-20261113.json`.

## Remaining governed release conditions

1. Original306 signed association and Original313 finalizer code remains in
   its reviewed V29 branch. It must not be bulk-overlaid onto rc129 or the
   live controller before complete source/worker SHA reconciliation.
2. Resolve original historical provenance and owner release approval using
   canonical signing authority. Neither this source review nor a successful
   PHP fixture counts as a signed Original312/313 receipt.
3. Publish a new exact GitHub immutable package only through the protected
   Release Verdict on master; current public pointer still offers rc104 and
   correctly rejects rc128->rc104 downgrade as `target_behind`.
4. After a certified package is available, use existing backup/rollback and
   WordPress-native update planner with exact Plan SHA and independent
   readback. Original305 historical expired/duplicate Jobs must not replay.

**State:** `HOLD__SIGNED_RELEASE_AND_ORIGINAL313_NOT_VERIFIED`.
