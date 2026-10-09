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

- `tests/admin-workspace-runtime.php`: exact-route/capability denial, the original 14-page fixture plus the Browser Acceptance page (15),
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
- `tests/adaptive-search-wordpress.php`: real WordPress discovery, CAS and setup
  semantics; named navigation and scoped table headers are checked in the DOM,
  independent of HTML attribute ordering. CI collects every disposable suite's
  result and keeps any failed suite blocking.
- Exact-head feature-owned CI executes these suites and publishes visual fixtures
  and screenshots. Disposable fixtures are not live Staging browser acceptance.

`ui-delivery.json` binds code and tests by digest. Five tasks remain PARTIAL:
T3906–T3909 and T4061. Their wider backend, per-field receipts, current-user live
journeys and per-plan autonomy evidence are still required. No task is marked
DONE, no competitor runtime parity is claimed, and no automation percentage is
inferred from the task ledger. The extension has 170 OPEN and five PARTIAL tasks.

Package identity must be recaptured after merge, and live acceptance must run
against that exact installed package. This implementation performs no deployment.


## October 9, 2026: Cross-workspace interaction and objection matrix

**Scope and limits.** This section reviews source-level UI wiring and disposable
WordPress/browser acceptance contracts. It is not an authenticated screenshot
audit of All Royal Egypt wp-admin and not a live provider, PHP 7.4/8.3, Host,
Browser or Production certification. The deployed site still needs exact
candidate installation and independent readback.

**Consistent journey for any registered page:**
1. Enter through the capability-filtered directory, grouped into
   Action Center, Connection, Content Pipeline and Runtime Components. Do not
   assume URL prefixes imply permission or page registration.
2. Select a page, then the exact tab/section and observe current identity,
   effective-versus-raw environment, revision and evidence provenance.
3. Treat missing observations as *Not checked*, denial as *Blocked*, and
   executed evidence as *Ready* only on authoritative readback. An unknown state
   is never a success.
4. A write submits its exact nonce, capability and revision; conflicting
   concurrent writes stop with a reload instruction, not silent overwrite.
5. A success notice requires actor/site/view-bound short-lived signed evidence;
   a GET flag alone is never a save receipt.
6. External browser/host/provider steps must show which system owns the next
   action; providing a name or saving a preference cannot confer execution.
7. Retain keyboard/RTL/mobile access and local no-JS navigation; do not perform
   network provider discovery merely to render the menu.

| Workspace | Intended journey | Negative / objection to reproduce in disposable WordPress |
|---|---|---|
| Action Center | Identify next owned blocker and jump to exact page | Unknown vs false; multiple blockers; forged automatic repair; revoked actor |
| Site Profile | Inspect identity and environment | Host says Production while Site Profile says Staging; cloned origin; no mutation from GET |
| Connection | Read OAuth, endpoint, isolation and certification | Missing issuer, invalid site binding, stale evidence and deferred outbound handshake |
| ChatGPT Connection | See client setup/consent instructions | Invalid grant/subject and disconnected client must not show success |
| OAuth Check | Inspect browser OAuth canary | Expired state, missing PKCE and wrong client; never infer browser acceptance |
| Brand & Context | Connect Google, scan sources, review assets | Expired consent, duplicate source, mismatched revision, reject/undo/partial review |
| Search Intelligence | Set providers, markets, profiles, budgets | Blank keys, changed profile, quota exhaustion, stale save, mismatched market |
| Content Pipeline | Select stages with prerequisites | Missing context, uncertified provider, partial job, canceled job and unsafe policy |
| Governance & History | Read agents, approvals, changes, audit | Missing schema, stale change and inaccessible agent, no direct write |
| Approval Decisions | Inspect exact pending ticket | Replayed/expired approval, conflicting decision, missing authority |
| Provider Coverage | Find installed, active and eligible adapter | Unknown plugin, ambiguous mapping, structural-only vs behavioral readiness |
| Runtime Components | Inspect core/plugins/themes/maintenance | Foreign package, current release mismatch, failed/uncertain update and rollback |
| Managed Skills | Review seed/provenance and reconcile safely | Old checkpoint, user-owned conflict, missing export/snapshot and stale grant |
| Performance | Inspect samples and maintenance | Insufficient baseline, query spike, missing telemetry and uncertain DDL |
| Browser Acceptance | Select registered site provider and external runner | Forged saved=1, two stale tabs, missing signing key and external identity, no adapter |
| Growth Providers | Inspect G5 provider readiness | Read-only vs high-risk write, absent secret, dry run without mutation |
| AI Knowledge Workspace | Inspect G6 context/AI DAG dependencies | Missing LLM/vector supplier, unsatisfied context, unreconciled journal |

The code and disposable tests cover navigation membership, form nonces,
malformed query input, no-op rendering, actor denial, signed notice semantics,
Browser revision/CAS, keyboard/no-JS directory, Arabic/English fixtures and
375/768/1024/1440 viewports. **Behavioral execution of every table row remains
a separate acceptance requirement**: test with deliberately missing
dependencies, expired credentials, remote timeouts, concurrent users,
provider version changes and rollback in an isolated disposable site. Do not
reinterpret source fixture success as operational provider certification.

### Reusable acceptance gate

A new core or add-on screen is complete only when its registered route appears
in the exact capability-scoped directory, supplies a non-fabricated status and
clear next step, has canonical deep links, rejects unsafe inputs and stale
write evidence, works via keyboard and without JavaScript, and has both a
negative disposable native scenario and an exact-build Staging readback.
Browser/Host/provider actions additionally require independent execution
and rollback attestations. Keep the PR Hub Draft until those live gates are
actually met.
