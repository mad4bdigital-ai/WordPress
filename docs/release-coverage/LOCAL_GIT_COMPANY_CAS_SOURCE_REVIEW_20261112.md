# MAD4B — Local Git source review for Company Reconcile and Original CAS

Date: 2026-11-12. Scope: local Git only; no GitHub fetch, remote push, or forced release.

## Source selection

- Approved local source for a candidate release: integration/company-cas-local-branches-20261112, starting from integration/full-wordpress-source-reconcile-20261111 at 87f1e9a0a7, followed by two conflict-free real Git merges.
- Merged fix/shared-product-core-live-parity-20261108, retaining its actual ancestry and rate-query/facet contract tests.
- Merged fix/unified-date-v039-source-20261111, retaining its actual ancestry and WPML/date/currency-origin cache isolation fixes.
- The original Company Reconcile, Start Company, Repair Jobs, resource evidence, baseline review, Executor-time CAS, Original 305 delegation-lane fix, Original 306 Preclaim and Original 313 independent source bridge are all Git ancestors of this branch. Their local source is not replaced by an older worktree.
- No Git merge strategy that suppresses conflicts or overrides one side was used. No detached worktree edits were incorporated without their own reviewed Git commit.

## Exhaustive local branch coverage

The machine-readable snapshot in LOCAL_GIT_BRANCH_COVERAGE_CAS_20261112.json is authoritative for the audited refs at capture time. It enumerates SHA-1 commit IDs of all other local heads, actual ancestor inclusion and the maximal unmerged tip conflicts. At capture after two safe merges:
- Included ancestry: 20 local branch refs, including the original integration branch.
- Unmerged refs: 41, represented by 18 independent maximal tips.
- 17 maximal tips have genuine Git merge conflicts; one is an unrelated Git history.
- Several other worktrees contain uncommitted changes, which are explicitly excluded from source approval until saved and tested. Their counts and paths are in the snapshot.
- Full branch coverage is false. It would be incorrect to claim all local branches were merged.

## Reviewed tests

- PHP syntax and JS parsing passed for source files brought by the two merges.
- The Shared Product Core runtime activation matrix passed 8/8.
- Unified Date currency/date cookie/query-origin isolation passed 9 cases in real Central WordPress WP-CLI. Independent HTML cache admission stayed private/no-store because the census contained unresolved dependency nodes; this is the correct fail-closed outcome.
- Existing Original 306/313 and CAS source Python suites passed: 60 + 43 + 5 + 90 tests. Source/live CAS verifier passed, with code.release operational and CAS unenrolled.
- All live Wayfatours commercial prices and Tour Rate metadata were preserved. Original 318/313 signed release authority was NOT created.

## Approval boundary

This is acceptance of the locally reconciled Git tree **as a candidate source** for the next governed release. It is NOT a signed Original 318 publisher record, Original 305 job issuance, broker-grant approval, Original 313 signed postcondition, or certification that the 41 unmerged branch refs are included.

The next stage must compare unresolved branch content in small compatible groups and independently approve every conflict; continue to prohibit force merge and automatic fallback that silently prefers one branch.

The previous CAS release payloads remain immutable. A new release package must bind the final Git commit and report its incomplete full-branch coverage honestly.
