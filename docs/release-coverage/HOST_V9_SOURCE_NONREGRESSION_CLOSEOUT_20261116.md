# Original Host V8/V9 source non-regression closeout — November 16, 2026

The former source.code.release V27 generated manifest is an **immutable
historical fixture**. Its SHA-256 remains
1b117e63e2cb762718378048e8e47707a97f906415e60f9d9a1bb319c54f85f3.
Rebuilding this file to mimic a newly approved signed provider would be a
regression; it could rewrite historical evidence without an original release.

The non-authorizing build_enrollment_candidate.py dynamically derives
current source hashes for the native_source_publisher.py and
company_code_release.py modules. New regression coverage requires:

- Historical V27 snapshot preserved exactly.
- Current dynamic descriptor verified against *actual current source bytes*.
- Current native publisher pinned by reviewed Host V8.
- Current company code release pinned by review-only Host V9 successor.
- Original V8 active manifest and first-party root Registry left unchanged.
- Source preview repinned only to its actual source manifest, still carrying
  installed_by_first_party=false and production_mutated=false.
- The existing portable native CI discovery includes V8 and V9 tests,
  automatically, under its existing source-only/HOLD result.

The old test_native_source_manifest_candidate and Unified Source tests
previously conflated an immutable historical snapshot with a current derived
candidate and consequently failed despite valid current pins. The fix
enforces both invariants separately rather than suppressing a test or
repinning historical data. CI workflow path filters also cover this unified
branch and its dynamic Host Provider source files.

**Release remains HOLD.** This document grants no Master Admin approval,
Original305 signed readiness, Host312 proof, Original313 receipt or live
WordPress release. Root-owned enrollment requires separate original
authorization and exact current preimages.
