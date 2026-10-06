# Admin workspace UI implementation

The implementation branch `feat/007-admin-workspace-ui-20261006` is based on the
reviewed Spec PR #258 at `a9a4b337faca8da01892576a7a59647b05cf758f`. It delivers
the presentation layer over existing runtime services in a separate implementation
PR. The frozen parent release scope and its 837-task ledger are unchanged.

## Delivered interface

| Surface | User-visible behavior | Runtime owner |
|---|---|---|
| All registered administrator pages | Exact-capability-scoped workspace directory, page/section links, active page, keyboard skip and local filtering | Existing Admin Route Registry |
| Setup shortcuts | API credentials, Google connection, pipeline and Action Center are reachable before and after configuration | Existing Search, Context and Pipeline handlers |
| Search provider forms | Native field labels and persistent help, blank secret values, separate save/test/removal, current-view signed feedback | Existing encrypted CAS connections; no new provider or HTTP path |
| Content Pipeline | Context/provider/browser prerequisite handoffs, stage controls and labeled advanced JSON | Existing registered stages and bounded settings policies |
| Action Center | Human-readable approval, external action and reconciliation cards, explicit unknown checks, setup path and bounded provider observations | Existing operator reducer and stored adaptive observations |
| External notices | Taxonomy hierarchy and domain/license review instructions, with installed-provider handoff | Native language/license provider and owner |
| Automation view | L0–L5 policy reference with required boundaries; repair coverage stays **Not measured** | No dispatch, repair claim, grant or tool mount |
| Arabic and accessibility | Arabic catalog, inherited WordPress fonts, logical RTL borders, visible focus, responsive layouts, native disclosures and reduced-motion/forced-colors support | Local PHP/CSS/JS presentation only |

The directory also includes future **registered** pages, filtered by their exact
required capability. An unknown prefix, nested page query, frontend request or
AJAX request does not receive workspace assets. JavaScript adds filtering and
table scrolling; links and disclosures remain usable without it.

## Design basis

Applied UI/UX Pro Max from `nextlevelbuilder/ui-ux-pro-max-skill`, pinned at
`477bcb28c9812b385cb51a4605ddf30d7b2266e2`, including its complete SKILL.md and
web quick reference. Verified local UX searches returned keyboard navigation,
visible focus, error recovery and helpful empty states. Both automated product
direction queries suggested marketing/IoT patterns inappropriate for this
WordPress console; those matches were rejected. The implemented visual direction
uses WordPress controls, native fonts, compact spacing and semantic colors.
No external fonts, runtime libraries or design-skill code are shipped.

## Acceptance and evidence

- `tests/admin-workspace-runtime.php`: exact-route/capability denial, all 14 pages,
  canonical credential handoff, unknown-versus-false checks, no inferred repair
  metrics, escaping and action ordering. Optionally renders disposable fixtures
  from the actual PHP presentation classes.
- `tests/admin-workspace-contract.py`: presentation cannot execute persistent
  writes, grants, tool registration or outbound discovery. Arabic PO/MO readback
  and format placeholders must match.
- `tests/admin-workspace-browser.cjs`: English/Arabic at 375/768/1024/1440px,
  translated page headings and escaped navigation text, keyboard directory,
  search/empty/restore states, visible focus, no document overflow,
  JavaScript-disabled navigation and zero network requests. Directory and
  Action Center screenshots are captured separately on mobile and desktop.
- `tests/adaptive-search-provider-enrollment-runtime.php`: actual registered
  setup links and feedback bound to the current revision, operation and session;
  forged flags and stale receipts cannot manufacture successful feedback.
  Hermetic render coverage also verifies blank secrets and no outbound request.
- `tests/adaptive-search-profile-admin-runtime.php`: typed profile navigation
  retains the actual registered API setup handoff after a profile is configured.
- `tests/admin-pages-wordpress.php`: existing real disposable WordPress full-page
  matrix plus exact workspace coverage and forged provider-success denial.
- Exact-head feature-owned CI executes these suites and publishes visual fixtures
  and screenshots. Disposable fixtures are not live Staging browser acceptance.

`ui-delivery.json` binds code and tests by digest. Five tasks remain PARTIAL:
T3906–T3909 and T4061. Their wider backend, per-field receipts, current-user live
journeys and per-plan autonomy evidence are still required. No task is marked
DONE, no competitor runtime parity is claimed, and no automation percentage is
inferred from the task ledger. The extension has 170 OPEN and five PARTIAL tasks.

Package identity must be recaptured after merge, and live acceptance must run
against that exact installed package. This implementation performs no deployment.
