# PR #814 — Native Tour Highlights + Destination Discovery (PR #813)

**Source-only integration, release HOLD, no JetEngine migration or site changes.**

Integrates source branch `feature/tour-highlights-native-v1-20261113`
(`91b1501ab72aee085f6166dce03ec30e20aeecbf`) into the currently
reviewed, policy-governed Control Plane. The 9-file canonical source delta is cleanly
merged without clobbering existing Elementor native templates, Company Term
Eligibility, WPML source, company-specific look-and-feel or dynamic Company
Release CAS checks. The original PR813 head is retained as a second Git
parent. Original 313 signed Host authority is still required before publish.

## Exact Control Plane provenance — no historical SHA rewriting

The original reviewed Bootstrap from PR #808 retains SHA256
`4b70ca42120f6dc5842b139cebe253d66503977d2b2d3921feb82164808caa3b`.
PR813 adds exactly **three lines** after the existing `MAD4B_SCP_DIR`
definition to load the Tour Highlights consumer before the optional foreign
REST/CLI fastpath, so Elementor/JetEngine AJAX can use the source-owned
callback. The resulting source SHA256 is
`fdc0a6c7be27604b531a8fc80f5dc08891f8b1e9c5aa6c67c749ae189f328d64`.
The source-only test restores the **exact old binary bytes** by stripping only
that pinned addition, then verifies both historical and new SHA256. All four
historical Control Plane tests keep their original evidence records and still
fail on any unreviewed edit. The immutable review snapshot in
`pr814-pr813-tour-highlights-reconciled-20261113.json` pins nine source
files including Bootstrap, native JetEngine resolver, presentation, JS/CSS,
WPML configuration, single canonical migration script, read-only QA and documentation.

## Rendering and content safeguards

- Meta fields remain `tour_`-prefixed and scoped to the actual Tour CPT.
- `manual` retains canonical legacy editorial copy even when the JetEngine
  AJAX callback passes an empty value; `assisted` only displays approved rows,
  otherwise preserving the legacy copy; `auto` requires both locale-wide and
  per-item approval; `hide` does not suppress verified destination links.
- Suggestions created on an authorized Tour editor save are drafts
  (`needs_review`), not public facts. Current company activation and source
  linkage are revalidated for destination/program/property references.
- Unverified optional services, facilities and payment promises are never
  auto-inferred or rendered as approved facts.
- WPML translates text; repeater/behavior can copy only once, with review
  status independent per language. The original `highlight_rows` source is
  not deleted or rewritten by this Git merge.
- Replaced one PHP 8-only `str_contains` call with PHP 7.4-compatible `strpos`
  to preserve the declared plugin minimum version.

## Local acceptance evidence

- 4/4 Tour Highlights source-provenance tests, 14/14 prior Control Plane
  source checks and isolated PHP runtime acceptance of Manual, Assisted,
  Auto, Hide, destination-activation isolation, service-denial and zero
  frontend postmeta writes.
- 103/103 Company Release core, 94/94 Dynamic Registry and JS syntax passed.
- Full-root Python suite: 298 tests, **0 new failures**, 14 pre-existing
  signed Original313 historical postimage errors still unverified.

This does **not** certify live Elementor widget placement, mobile/desktop
visual QA, translated content, a successful one-time data migration, or a
signed Production release. Closing the source PR after merge requires exact
ref containment, current CI acceptance and an independent source/release
policy verdict. Deletion of branches is not authorized by this document.

**Follow-up PR813 heads:** the original review branch advanced to `f36e39f1575563854851165e2ffb36263c68df10` with a CAS role migration and read-only QA. The duplicated byte-identical migration path was consolidated into the canonical `scripts/migrations/tour-journey-role-migration.php`; no migration was run. The QA script now uses PHP 7.4-compatible `strpos` and optionally enforces a site-supplied group count rather than a fleet-wide hardcoded `5`.

## Latest PR813 head reconciliation — 4c3d737a4a8c (Nov 13)

Incorporates the verified new Elementor/JetEngine widget localization
using first-party native container/widget hooks. Only an actual descendant
of a `tour-place-group` container may have its in-memory JetEngine Listing
ID localized via WPML; absent, unlinked or unpublished localized Listings
remain untouched. PHP 7.4 support is preserved by using `strpos` rather
than `str_contains`. The isolated acceptance covers FR success, DE draft
denial, unrelated containers, nested scope exits, missing locale and no
postmeta mutations.

The source review now pins **12** exact files: the previously approved nine
plus the read-only Playwright Journey acceptance and two separately scoped
Tours.dev one-off WPML migrations. Both migration scripts are **not runtime
Provider handlers** and were **not invoked**. They require independent source
readback and governed deployment acceptance before any `apply` action;
source inclusion is not permission to write Elementor or WPML data. No
visual screenshot or live production verification was performed.

The Original306 ephemeral Registry source candidate unit test is executed
with root ownership in CI; its real first-party trusted-root check remains
unchanged, and a source-only fixture cannot authorize a new Grant.
