# Feature 007 — Brand Integrity Term Meta extraction, CI-only

**State:** independent, non-authorizing Draft source review on PR #977 at `20aab770d21f45442fe09b5ae840a90b7ff8fe5a`. No WordPress site or PR #977 production changes are implied.

## Review context

Feature 007 Hybrid Audit measures `class-mad4b-scp-brand-integrity.php` at **965 lines**, above the **900-line source maintainability budget**. This refactor extracts the entire read-only Term Meta and taxonomy analysis group into `trait-mad4b-scp-brand-integrity-term-meta.php`. The facade becomes **638 lines**, and the Trait contains **339 lines**; no code logic in the six extracted PHP methods changes. The existing class remains `final`, with the same public static API and the original helper methods/constant access. A single fail-closed PHP require/use binds the Trait before the class definition. The initial Term Meta runtime test passed with the original foreign promotional link blocks and canonical URL preflight rules.

## Immutable code contract

The original full facade Git blob SHA is `9169abd2dbce76dfbe087337799dd475eced8a66` (SHA256 `7a6f444a034a70418f2adf95d8f9d25e83f4f6cdba33d0c984687125826da58e`) on exact PR #977. The old source-only fixtures contain a distinct, older Brand Integrity SHA256 and must not be relabeled as current source. The new facade SHA is `188c7c00411834785f3d00fcd05541d10037f12a`, and the new Trait SHA is `dedbbb99e50d819009e994ad245a412c738d5f28`. `tests/test_brand_integrity_term_trait_exact_source.py` and the independent PHP source-only review reconstruct all **original file bytes** after removing exactly one Trait loader/use and reinserting the precise extracted 329-line block. The reconstruction is checked against the frozen original Git blob/SHA256. Unknown source bytes, a missing or duplicate loader, a modified Trait header, changed methods, or a mismatched original fail closed. No new WP hooks, HTTP, options, writes or shortcode registration are introduced.

## Known upstream constraints before integration

The two older source-closure fixtures already fail on **unrelated pinned source SHA mismatches in the unchanged PR #977 parent**: `tours-dev-content-quality-dependencies-source-only-runtime.php` fails for `class-mad4b-scp-readiness-workflow-options.php`; `tours-dev-scheduler-source-closure-runtime.php` fails for `class-mad4b-scp-brand-experience-quality.php`. Both failures are reproducible on the original PR #977 worktree. No historical pins have been rewritten to hide those baseline defects.

This split necessarily changes the Brand Integrity facade SHA and introduces a new Trait; it still requires an independent exact source successor review in QA31, the PR942 composite matrix, the frozen source provenance inventory, plus a same-PR capability review and a full Staging WordPress acceptance. This standalone Draft must **not be merged** solely because its focused source and security tests pass. The original 57 PR942 functional signoffs and exact-head owner approval remain open.

## Review axes

Correctness: byte-exact reconstruction, six unchanged methods and runtime link-preflight fixture. Readability/architecture: cohesive Term Meta scanning extracted into one owning Trait, not a new service or global writer. Security: same exact site-bound brand identity checks, foreign URLs denied, no raw WordPress write. Performance: one extra PHP `require_once` only when this already optional service is loaded, no extra network/DB path in the extracted method bodies. No site-specific flags, activation or runtime mode changes.

## Dynamic Readiness source coverage and executed test (additive)

The new PHP Trait introduces a `service:` capability in the existing catalog, and the exact facade/trait Git SHA changes are two independently reviewed behaviors. The append-only `governance/production-readiness/capability-coverage.json` now includes **one CI-only capability review and two CI-only exact blob change records**, all mapped to original AI, localization, provider and security controls and to the new exact reconstruction test. Every entry is `reviewed_for_ci_only`, `production_authorized=false`, `live_evidence_required=true`; the Registry's global non-authorizing flags remain unchanged. Prior historical review records were not rewritten.

On exact source head `129dc9a79e5fcd49de1ae236a0281d84dc367392`, with PR #977 as base, the Dynamic Readiness inventory returned `PASS_COVERAGE_ONLY` with **zero gaps**, followed by the actual trusted test executor reporting **`PASS_ALL_MAPPED_TESTS 1/1`** for `tests/test_brand_integrity_term_trait_exact_source.py`. This does not validate the two known pre-existing source-closure test SHA disagreements, PR942 composite signoffs, full WordPress behavior or Feature 007 new-parent baseline; those remain separately gated and are not considered resolved by this isolated proof.
