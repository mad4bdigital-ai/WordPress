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

### Exact Staging admin browser survey (opt-in; not a release certificate)

\`tests/admin-live-readonly-browser.cjs\` discovers capability-authorized
wp-admin pages and bounded tab/section/view links from the *current installed*
directory, rather than hardcoding All Royal or ETG provider names. It refuses
non-HTTPS origins, unsafe WP admin paths, mismatched site origins and
non-GET/HEAD requests; all external requests are blocked. It records bounded
page/route, active-page, H1, duplicate ID, field label, raw WordPress environment
drift, JavaScript exception and 375/768/1024/1440px overflow findings.
The test never submits, clicks, grants, uploads or modifies provider/business
state. No screenshots, credentials, cookies, origin or HTML are logged.

An already-authorized **Staging-only** operator may run, on an isolated
external browser runner with a reviewed Playwright installation:

\`\`\`bash
MAD4B_UI_STAGING_ORIGIN=https://staging.example.com \
MAD4B_UI_ADMIN_PATH=/wp-admin/admin.php \
MAD4B_UI_AUTH_STATE_FILE=/secured/staging-storage-state.json \
MAD4B_UI_NODE_MODULES=/opt/audited-node-modules \
MAD4B_UI_EXPECTED_COUNT=<verified-visible-route-count> \
node tests/admin-live-readonly-browser.cjs
\`\`\`

Never create \`storageState\` from Production. Do not commit state, tokens or
screenshots. A result of OBSERVED_UNCERTIFIED is only a navigation observation:
the script explicitly returns release_certified=false,
source_manifest_verified=false and browser_attestation_verified=false, and
requires separate native PHP/DB, Host, Browser signed oracle, deployment identity
and rollback evidence. Exit 2 means blocked. Unknown expected route count also
blocks, rather than fabricating coverage. Run the static fail-closed contract
with \`python3 tests/admin-live-readonly-browser-contract.py\`.


## WordPress Guided Operator Experience — source delivery (2026-10-09)

The `MAD4B_SCP_Guided_Operator_Experience` model adds a **task-first** journey to
the existing Action Center using exact Site Profile, persisted current-build
Managed Skills and the already-computed Operator Control Center snapshot. The
model never manufactures provider execution/certification evidence, and it does
not invoke deep/browser network discovery on every admin GET.

The next task is prioritized deterministically: uncertain prior mutation (no
blind retry) → site identity or stale Skills/write authorization → exact
provider gaps → Browser configuration or externally owned Host prerequisites.
Each item binds an existing canonical, capability-filtered WordPress workspace,
a responsible role, independent verification Ability, and one of
`OBSERVED_READY`, `NEEDS_ACTION`, `NOT_CHECKED`,
`NOT_APPLICABLE`, `WAITING_FOR_SITE` or `EXTERNAL_ACTION`.
It never marks Browser test acceptance, Production readiness, mutation execution
or approvals as complete because a settings page or preference is available.

The page keeps Advanced setup, all existing admin routes, approval/nonce checks,
existing Skills seeder and provider certifiers, and the protected Recovery
Lifecycle. The top-level journey points users to those proven components rather
than opening new generic executors.

Specific UX repairs:
- **Staging environment notice:** an authoritative, confirmed Site Profile
  with WordPress's implicit Production default gets an *advisory*, not a
  misleading untrusted-site warning. Explicit/mismatched environments remain
  warnings. No WP host setting or authority is changed.
- **Managed Skills:** stale or missing build-bound persisted certification is
  explained next to the already-governed reconciliation form. Reconciliation
  retains existing editor/permission checks.
- **Browser Acceptance:** distinguishes missing signed WordPress adapter, a
  corrupt/legacy operator preference, and independent external runner and signed
  evidence. Browser secrets are not accepted or auto-discovered.
- **Approvals:** exposes exact ticket/provider/target/payload binding and states
  that before/after impact or rollback is not proven by the inbox. No inferred
  safe approval or auto-approval.
- **Localization:** rebuild Arabic `.po` and runtime `.mo` together; controls
  and tri-state meanings are translated without hiding technical identities.

A guided use-case is closed **only** after an independent exact-current-build
readback and, for write and Browser/Host effects, external evidence. A visible
step or button does not certify any result.

### Independent tests when CI is unavailable

`php tests/guided-operator-experience-runtime.php` is a pure native PHP
scenario matrix for stale Skills, source absence, corrupt Browser preferences,
write/Provider gaps, uncertain mutation priority, external Host ownership,
unknown statuses and untrusted snapshot denial.

`python3 tests/guided-operator-experience-contract.py` enforces non-authorizing
source boundaries and verifies that the deployed Arabic GNU gettext MO contains
real translated user-facing messages. Existing
`tests/admin-workspace-contract.py` and the exact-HEAD
`tests/recovery-no-ci-preflight.py` invoke these checks. The separate Staging
read-only Playwright route survey should be run against the **deployed exact
source**, not against prior All Royal Egypt rc.96.

Do not mark `release_certified`, `browser_certified` or
`production_mutation_allowed` true based on these local checks. True A–Z
acceptance requires an actual administrator user journey through a disposable
WordPress site, Arabic and English localized renders, live Staging route/browser
evidence, native PHP 7.4 and 8.3 plus MariaDB/MySQL, impact-readback/rollback,
and signed Provider/Host certification. CI being stalled does not waive these
independent evidence requirements.


## Supported WordPress environment synchronization modes (2026-10-09)

The exact Site Profile and WordPress bootstrap environment remain distinct
security and lifecycle authorities. WordPress **defaults to production** when
\`WP_ENVIRONMENT_TYPE\` is unset; its reader uses a request-local cached value
and checks the constant ahead of the OS environment variable. Updating an
ordinary plugin setting cannot rewrite the authoritative WordPress environment
for the already booted request.

The Site Profile administrator can explicitly choose:

| Mode | Behavior | Authoritative readback |
|---|---|---|
| \`profile_only\` (default, including historical profiles) | Existing per-origin MAD4B effective environment, with explicit attestation for the implicit WordPress Production default | Site Profile and WordPress raw values shown separately; no claim that WP was changed |
| \`host_managed\` (opt-in, non-Production only) | Persist a reviewed intent for a trusted Host operation to set \`WP_ENVIRONMENT_TYPE\` during bootstrap, before \`wp-settings.php\` | \`host_aligned\` only after a fresh WordPress request reports an **explicit** exact match AND an exact external Deployment Binding is present |

Other machine-readable states: \`blocked_profile_identity\`,
\`blocked_invalid_mode\`, \`blocked_production_or_invalid_target\`,
\`blocked_missing_deployment_binding\`, \`blocked_explicit_host_conflict\`,
and \`awaiting_host_bootstrap\`. These are not interchangeable with
release-certification statuses or write grants. The same-origin host must
provide a host-only, unique \`MAD4B_SCP_DEPLOYMENT_BINDING\` first.
The plugin never stores or exposes the raw binding.

The plugin **never** changes a WP Environment constant via \`putenv\` or
file rewriting during admin POST. The WordPress admin view supplies only
a bounded, reviewed config directive for local/development/staging after exact
identity and independent host binding are ready. The host executor (when
available and explicitly authorized) must own backup, safe atomic write,
syntax/restart validation, host identity/secret isolation, post-boot readback,
and rollback. This change does not implement that Host executor, imply a Host
connection, or certify Production.

Negative native acceptance cases include invalid mode, historical default,
explicit Host Production conflict, Production opt-in refusal, foreign/clone
profile, missing host binding, failed bootstrap readback and multi-tab revision
conflicts. The existing native Site Profile lifecycle matrix contains 12
isolated state-reducer cases plus save-path refusal and preservation assertions;
execute them on supported native PHP versions before release.

**All Royal Staging migration:** its installed rc.96 still reports
\`production\` implicitly and lacks deployment binding. Until the new exact
artifact is validated and deployed, the new mode does not exist at runtime.
After certified deployment, selecting Host-Managed Sync is still **pending**
until trusted Host bootstrap, unique binding and new-request readback are
proven; setting the Site Profile to staging alone must never be reported as
changing \`wp_get_environment_type()\`.
