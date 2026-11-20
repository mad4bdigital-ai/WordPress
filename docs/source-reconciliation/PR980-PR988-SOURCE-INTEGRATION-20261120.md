# PR #980 + #988 — site-bound CWV and Brand Integrity source integration

20 November 2026. **Review-only candidate. Neither a release nor Host/owner authorization.**

## Source composition

This candidate has a real two-parent source merge between Draft PR #980 at `ad4eefa106b8b18ef5748d5de3c5a2db9b3b3807` and Draft PR #988 at `0f7d553e10b278c380ae09411a6e17a279b7b7ad`. The merge was textual conflict-free except for the append-only Production Readiness Governance JSON, which carries independent source and capability reviews from both branches. Resolution validated that all fields outside `reviewed_changes` and `reviewed_capabilities` were identical across base and parents; both parents extended the base lists, without editing original records. The merged list holds **447 reviewed capabilities** and **789 reviewed source changes**, preserving existing records. Top-level `authorizing=false` and `production_promotion_from_ci=false` remain unchanged.

The company-bound CWV helper gates activation on exact enrolled identity, explicit company feature or previously observed same-origin configured/scheduled legacy CWV. The Brand Integrity Term Meta extraction preserves the original final-class public API and **reconstructs the original class byte-for-byte**. The new class has **638 lines**, its Trait **339 lines** (unchanged 900-line architecture ceiling). No new per-company options, WordPress writes, Cron tasks, content, SMTP, OAuth grants or live site changes occurred.

## Immutable source history and combined negative tests

The original PR942 collision matrix (57 paths, all manual functional signoff false) and master-v124 frozen 46-source inventory remain unmodified. The Composite Semantic gate accepts exactly one further Governance JSON Git blob, only if both CWV and Brand reviews are present, CI-only and non-authorizing, with exact source SHA for each facade/trait/helper and matching regression tests. The 46-source historical review now accepts the **full inverse reconstruction** of the exact original Brand Integrity byte stream; its original candidate SHA and live-mount provenance stay frozen, and all other files continue to require exact historic blobs.

Local isolated source tests against the merged candidate: Composite Semantic **17/17**, frozen 46-source matrix **5/5**, Brand exact refactor **4/4**, Original305 **9/9**, PR813 **6/6**, CWV inverse **5/5**, QA31 negative source contract PASS, and actual Dynamic Readiness **`PASS_COVERAGE_ONLY` with 0 gaps** relative to the latest #980 parent. The trusted execution runner separately completed **1/1 mapped Brand exact-refactor regression**, and isolated PHP passed the CWV 17-case admission and Brand Term Meta foreign-links scenarios.

## Outstanding release boundaries

The original #977 Feature 007 reviewed baseline older than present `master` still needs explicit review. Historic WordPress-source pinned fixtures unrelated to this exact refactor have separate pre-existing divergences, and there are no independent Staging, Production browser, rollback or user journey proofs for this combined candidate. **None** of the 57 source collision manual signoffs or exact-head Owner Attestation has been provided for this combined head. No merge into master or live site/Host execution is authorized from this source-only record.
