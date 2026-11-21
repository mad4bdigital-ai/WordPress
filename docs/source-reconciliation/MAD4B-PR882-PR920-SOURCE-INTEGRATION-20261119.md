# MAD4B source integration candidate — 2026-11-19

**Scope:** Source-only integration and review. This is NOT a release acceptance, security approval, owner attestation, deployment authorization or full source-continuity verdict.

## Ancestry and merge approach

- First parent: PR #920 exact head e1b8c8aa83fd72bea87cfe6511f8200b57420b12 (includes #925 and stacked destination work).
- Second parent: PR #882 exact head 65bb2791f7b46c5887f3e814feb8b48604a3e0ae.
- The independent no-write merge-tree preview found **57 conflicting paths**: 46 Control Plane code/tests and 11 workflow/spec/governance.
- A disposable worktree performed a three-way merge with -X ours from #920, retaining non-overlapping changes from #882 while prioritizing #920 on overlapping hunks. This **mechanically resolves** textual conflicts; **it is not a semantic release decision**. Per-path provenance is in merge-conflict-review-matrix-20261119.json.
- Removed two newly duplicated require_once entries in plugin Bootstrap (unified-gate-funnel and native-settings-locator); retained preexisting bootstrap behavior.
- No production site, Staging site, master, owner grant, credentials or original Draft PR branch was changed.

## Reviewed source-safety corrections

1. From on-disk Tours.dev site-copy, imported narrowly scoped changes to Company Master Admin: staff roles cannot be broader than the caller; deny broad Operator role.
2. Imported higher Impact Policy classification for commercial metadata (prices/deposits/payments), already-published content and explicit Content Experience publication routes.
3. Imported read-only Host signed-source preflight readback in Company Code Release Readiness; no grants issued.
4. Restored site-neutral default post for provider-field discovery; tour callers must explicitly specify their CPT.
5. Preserved the newer #920 WP-CLI recovery workflow: versioned official PHAR and SHA-256 check, versus #882's older unpinned fallback. Updated contract verifies stronger behavior and real disposable WordPress test retention.
6. Newer Skill registry has no legacy 500-item scan ceiling: successor fixture validates 501 records and bounded pagination without a false truncated count.
7. Admin route contract accepts equivalent whitespace/control-flow while still requiring exact manage_options route and scheduled parent-menu registration.
8. Added PHP 7.4 fixture-only string function polyfills normally provided by WordPress; no runtime polyfill injection.

## Read-only source preservation

- Verified Tours.dev frozen archive (18 Nov): 995 files, SHA-256 9379c9f16e8e5b352170589b1f430245ab6c162114a4ca771797b27018ed789e.
- Candidate versus 991 corresponding Tours.dev on-disk site-copy paths: **785 byte-identical, 205 divergent without signed review, 1 missing without signed review**; SOURCE_REVIEW_REQUIRED.
- Two filtered local site-copy inventories: Tours.dev 993 eligible files, Wayfatours 976. They are **not independently authenticated current runtime readbacks**. Wayfatours has four legacy Host Media PHP modules absent from candidate; actual usage/deprecation remains unverified. Do not blindly copy them.
- Detailed local private hash inventories are retained on VPS; no raw settings, user data, secrets or production bindings are published.

## Test state and hard release blockers

- Modified PHP source syntax: PHP 7.4 and PHP 8.5, zero parser failures (lint run on all modified PHP files).
- Isolated regression tests cover privilege containment, commercial high-impact classification, Term Meta reads/save checks, read-only FCP and Skills pagination.
- Historical source-fidelity assertions from #882 do not accept the later refactored source layout. Do not change attested SHA evidence or disable tests just to report green CI.

**Blocked pending review:**
- Seven distinct legacy PHP source-fidelity/activation fixtures still reject newer source decisions (Agent Authority, readiness, bootstrap activation, Host/JET lifecycle, scheduler).
- One Python Feature007 Admin successor fixture pins an older Admin SHA; do not silently rebind it.
- 205 divergent plus one missing site-copy paths still have no complete paired and approved source-retention/deprecation decisions; determine active runtime dependencies and site overrides.
- 57 overlapping collision paths still require component-level semantic review beyond a clean merge.
- Complete exact-head CI, Release Verdict, owner attestation, Staging behavior/readback and rollback verification are outstanding.

**No authorization:** release_authorized=false, deploy_authorized=false, owner_attested=false, source_continuity_certified=false. Keep Draft and do not merge to master or deploy.
