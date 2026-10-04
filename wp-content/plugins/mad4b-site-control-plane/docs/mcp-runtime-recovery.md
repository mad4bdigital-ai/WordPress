# MCP runtime recovery and Site Profile updates

The same certified Adapter entrypoint may coexist with foreign builder, validator
or DTO classes from Rank Math or another Jetpack package. A successful OAuth login
does not certify this PHP class set or its tool catalog.

The managed MU loader now uses the regular Site Profile's pure early binding.
An exact valid enrolled profile may override WordPress's implicit Production
DEFAULT only after the explicit one-time non-Production attestation for that
bound identity/environment. Profiles that request this downgrade without the
attestation are quarantined on read. Explicit host Production, foreign origins,
deployment-binding drift, invalid profiles, disabled managed-runtime and
reenrollment-required profiles remain ineligible.

On the exact diagnostic AJAX POST, MU scope first requires the bounded Site
Profile HMAC routing proof; an external caller cannot trigger early MCP
hashing/loading merely by naming the AJAX action. The worker still owns nonce,
administrator, target and build authorization. Foreign frontend/AJAX requests
bypass the loader. Developer and developer-breakglass MCP routes use the same class set;
transport permission and grants still determine access.

All executable pin files and the package autoloader are hashed before execution.
An already declared foreign class cannot be replaced in the current request.

## Lifecycle

- Normal managed-loader refresh remains available through the governed recovery
  lifecycle. An unknown occupied MAD4B MU path on an exact Staging enrollment no
  longer requires a browser POST: ordinary Staging lifecycle traffic detects the
  conflict, schedules one background recovery job and returns without changing
  loader bytes in that browser request. The worker performs the mutation later.
- Recovery validates the installed certified disk set and owns the shared maintenance
  lease. Automatic refresh accepts the current source, exact historical MAD4B MU
  fingerprints, or a site-bound ownership receipt previously written by a successful
  governed recovery. The receipt is bound to Site Profile UUID, environment and
  hashed canonical origin, so normal future loader revisions no longer depend on a
  growing hard-coded hash list.
- An occupied managed path with neither historical lineage nor a valid site-bound
  ownership receipt is still reported as `unmanaged_mu_bootstrap_path_conflict`.
  On exact Staging only, the Recovery coordinator may automatically adopt that
  dedicated MAD4B path from a scheduled recovery worker or an already-owned
  convergence lease. It first captures the observed SHA-256, then rechecks those
  exact bytes under the filesystem mutex, writes a protected backup outside
  WordPress web roots, atomically installs the certified loader, records bounded
  audit evidence and establishes the site-bound ownership receipt. Any SHA drift,
  backup failure, audit failure, lost lease or transaction ambiguity aborts or rolls
  back. Generic WP-CLI, MCP/OAuth hotpaths, endpoint diagnostics and Production can
  never enter this automatic adoption path. The legacy explicit administrator path
  remains a compatibility fallback but is no longer presented in the connection UI.
- Filesystem install/refresh uses a durable option-row transaction with atomic
  `add_option` ownership, owner tokens, stale-worker fencing, persistent-cache
  eviction, same-directory atomic rename, byte readback and audit-before-finalization.
  A pending/ambiguous transaction makes the MU loader fail closed on the next request.
- Plugin update/activation schedules a new-code cron request. A normal Staging
  request that observes the unknown-path blocker also schedules the same worker if
  one is not already pending; it does not perform filesystem mutation inline.
  Profile saves schedule recovery only for exact non-production managed-runtime
  enrollment. Production or disabled enrollment cancels that scheduled recovery.
- Existing post-update convergence runs this phase under its existing lease after
  schema convergence. It cannot carry forward or repair write authority through
  this phase.
- The redirect/fresh request is required. Run the endpoint diagnostic afterward;
  the repair result reports `armed_for_next_request`, never external certification.
- First deployment from a version without these hooks may need the explicit repair
  POST. Recovery never disables Rank Math, rewrites foreign package bytes or grants
  Production/write/OAuth authority.

## Site Profile environment changes

The form includes Production-write confirmation even while the current profile
is Staging. It shows the fields when the SELECTED environment is Production and
write is selected. With JavaScript disabled, the fields and explanation remain
available. Production without write needs no write acknowledgement. Production
with write requires both the checkbox and the exact phrase
`ENABLE GOVERNED PRODUCTION WRITE`.

A Site Profile generation that crashes between pending persistence, audit append
and finalization remains authority-quarantined. New pending generations carry the
exact prior snapshot, target digest, audit action and mutation ID. After a grace
period, an administrator-only plan/apply reconciler uses exact append-only audit
evidence to choose either finalization or rollback; stale/ambiguous evidence stays
fail-closed. Write-authority revocation remains effective even when audit storage
fails: bounded revocation evidence is placed in a separate CAS-protected outbox,
validated before replay, deduplicated against the audit chain, and repeated
disable requests are idempotent.

Environment rebinding creates a new site identity and may reset its revision to
1. AJAX verifies the committed UUID, revision, digest, origin and environment,
without assuming the revision must exceed that of the old identity. The save form
also submits its expected profile digest: an old tab cannot overwrite a different
UUID that happens to share revision 1. Forms opened before this change must reload.
This catches stale tabs; it does not assert atomic compare-and-swap for simultaneous
database writers. The whole
profile workspace refreshes after success, including the displayed environment.

FormData is captured before controls are disabled; one-time acknowledgement fields
are cleared after successful persistence. Staging does not retain a Production
write acknowledgement. When WordPress is using its implicit Production default,
selecting Local/Development/Staging additionally requires the exact
`CONFIRM THIS ORIGIN IS NON-PRODUCTION` phrase; the persisted attestation is
part of the bound identity and is checked again on every authority read.

Hosts that need protection against independent same-origin database clones may set
`MAD4B_SCP_DEPLOYMENT_BINDING` outside the database. Only its SHA-256 digest is
stored. All nodes of one intentional deployment/cluster must share the same
value; an independent clone must use a different value. A mismatch quarantines
OAuth/Skills/Write and managed-runtime authority.

## Update channel states

`mad4b_self_update_manifest_not_cached` means the explicit channel check has not
populated its cache. The Plugins row presents it as an unchecked state and keeps
its signed refresh action. Passive page loads still make no outbound requests.

`mad4b_self_update_continuation_prior_authority_drift` is an authority gate. The
existing candidate-drift bootstrap applies only when its exact persisted grants
are clean and the candidate binding alone is stale. No broader drift is bypassed,
and Production classification does not become an update-policy escape hatch.

## Verification

CI includes expanded fresh-process MU boundary cases, lifecycle/recovery
contention and persistent-cache cases, settings submission regressions,
Staging/Production rebind/readback tests, renamed-plugin-root cases, generic
WP-CLI bypass plus explicit MCP CLI opt-in, diagnostic-proof checks and
same-origin deployment-binding checks.
The real WordPress 6.9/latest mixed-class fixture removes WP_ENVIRONMENT_TYPE to
model the implicit Production default, verifies first-request fail-closed repair,
then verifies CLI and HTTP-style AJAX recovery with all 26 tools. The AJAX fixture
keeps external certification false and checks runtime bytes/plugin inventory are
unchanged by diagnosis.

## Installation and scenario assessment

The scenario matrix evaluates the shipped Site Profile, recovery orchestrator,
MU refresh, conflict guard and maintenance lease together. It is complemented by
real WordPress fixtures: each of the four environments, with both an explicit
host declaration and an implicit Production default, receives fresh Core and
plugin database tables on WordPress 6.9 and the workflow's `latest` version.

| Scenario | Required result | Evidence |
| --- | --- | --- |
| Unknown origin, including a hostname beginning with `staging` | No OAuth, Skills or Write enrollment; no runtime-repair schedule | Lifecycle matrix and fresh WordPress journeys |
| Local / Development / Staging selected on an implicit Production default | Requires exact non-Production attestation; without it all Site Profile authority and early managed runtime stay quarantined | All-environment journeys and MU boundary processes |
| Explicit host environment disagrees with selected profile | Save rejected without persistence or scheduling | Lifecycle matrix and WordPress journeys |
| Production without Write | Saves without write acknowledgement; managed recovery stays disabled | WordPress journeys |
| Production with Write | Exact one-time checkbox and phrase required; audit readiness required | Profile regressions and WordPress journeys |
| Staging ↔ Production on the implicit default | New UUID/revision 1; exact committed readback; recovery schedule follows new eligibility | Profile regressions and WordPress journeys |
| Copy database to another origin, even a related origin | All authority and early runtime binding quarantined | Matrix and WordPress journeys |
| Independent database clone with the same public origin | Configure a distinct host-side deployment binding; mismatch quarantines authority without storing the raw secret | Site Profile general-distribution regression |
| Concurrent MU repair workers / stale persistent option cache | Exactly one transaction owner; other workers fail closed until stale/blocked reconciliation; cache cannot hide the database marker | Recovery contention/cache regressions |
| Existing unknown file occupies the dedicated managed MU path | Ordinary Staging traffic schedules background recovery without inline mutation; the scheduled/convergence lifecycle requires exact observed SHA, protected backup, mutex recheck, audit and ownership receipt. Generic CLI/hotpaths and Production remain fail-closed | Runtime recovery automatic-conflict regressions |
| Generic WP-CLI | Does not load/arm MCP; only commands/tests defining the explicit MCP CLI request marker enter the early runtime | MU boundary and connection lifecycle fixtures |
| Stale form / anonymous enrollment / nonexistent OAuth user | Rejected; no successful-save hook | Matrix and WordPress journeys |
| Audit append fails after profile persistence | Exact previous profile restored; no recovery scheduled for the failed change | Matrix |
| Options storage drops profile/result writes | No successful persistence or armed recovery result reported | Matrix |
| Valid borrowed convergence lease / forged token / lease stolen during audit | Preserve caller ownership; reject invalid fencing; never publish success after losing fence | Matrix with real lease implementation |
| Negative identity/version, array environment or text `"false"` feature | Invalid stored profile quarantined before coercion; no regular/MU authority | MU boundary processes |
| Missing, disabled or tampered Adapter/baseline | Fail closed before executable pin bytes run | MU boundary processes and installed-integrity fixtures |
| Plain permalinks, subdirectory URL, custom REST prefix | Same exact owned MCP route; no foreign AJAX/front-end shortcut | MU boundary processes |
| Official Adapter + foreign validator/DTO ownership | Current request remains blocked; next CLI/AJAX request pins canonical classes and exposes 26 ChatGPT tools | Real mixed-class WordPress fixture |
| Passive GET and explicit endpoint diagnosis | No filesystem repair, plugin inventory change or outbound discovery; definition registration remains lazy | Hotpath contracts and actual AJAX fixture |
| Update replaced control-plane/Adapter bytes | New-code lifecycle schedules recovery; existing managed loader refresh is audited | Recovery fixture, update/continuity suites |
| Candidate-only update drift vs actual grant drift | Narrow bootstrap only for clean persisted grants; real authority drift blocks normal continuation | Self-update candidate-drift/continuation fixtures |

The standalone matrix covers 160 origin/environment/declaration combinations,
including HTTPS names, ports, IPv4, IPv6 and subdirectory homes, plus nine fault
cases. It intentionally includes unusual host-returned environment combinations
as negative consistency tests; it does not imply WordPress natively returns a
non-production default without a declaration.

The package keeps a real distinction between environment classification,
profile feature enrollment, transport authentication, exact grants, runtime
catalog integrity and external acceptance. Passing the first two does not certify
the others. Profile changes must not silently create or reconcile grants. The
real journeys compare agents, subjects, grants and active plugins and prohibit
outbound HTTP during profile/recovery work.

### Limits and deployment acceptance

- Runtime recovery currently certifies only the current serving node. Per-node
  evidence is bound to the exact Site Profile UUID/revision/digest, hashed origin,
  target build, expected/observed MU hash and executed runtime provenance. A stable
  evidence SHA-256 supports external deduplication, but it is not a signature and
  does not replace authenticated collection from each serving node. The result
  reports `runtime_node_scope=current_node`, does not claim a shared filesystem,
  and marks cluster convergence as required. Multi-webhead deployments must either
  use a shared/identical `WPMU_PLUGIN_DIR` or perform an external per-node
  convergence/readback before external certification.
- Network-only plugin activation in WordPress Multisite is not supported by this
  managed-runtime loader: it reads per-site `active_plugins`, and its network-only
  fixture fails closed. Do not treat a shared network activation or subdirectory
  multisite clone as a certified tenant-isolation deployment. Full Multisite
  support needs separate blog-identity and network lifecycle work.
- Tests cover certified Adapter 0.6.1. An offered newer Adapter version is not a
  permission to install or certify it; its independent baseline must be reviewed.
- The `latest` workflow resolves WordPress at run time. The emitted journey JSON
  records the actual version; the test name alone is not a compatibility claim
  for every past/future WordPress or PHP release.
- PHP 7.4/8.3 boundary coverage, local simulation, two WordPress versions and a
  synthetic competing provider do not prove every hosting stack, database/cache
  drop-in, arbitrary MU priority, WAF/proxy or third-party plugin combination.
- Audit append readiness retains committed count/head/tail consistency and the
  `chain_sequence` index; full hash-chain verification is explicit and processed
  in batches of 500 events rather than on every append. Reaching 100,000 audit
  events marks deployment-specific load qualification as required; the runtime
  deliberately does not self-claim a high-volume load-test certification.
- A site-bound ownership receipt is migration evidence for the exact managed MU path,
  not proof that arbitrary PHP is trusted. It is created only after a governed install
  or replacement and becomes invalid across Site Profile UUID, origin or environment
  rebinding. Automatic adoption of previously unknown bytes is limited to the reserved
  MAD4B MU filename on exact Staging, requires protected backup plus exact-SHA CAS, and
  never widens Production or remote authority.
- Runtime recovery reports pending next-request verification. Deployment requires
  a fresh authorized endpoint job, canonical ownership of all critical classes,
  the expected tool catalog, then independent real OAuth/acceptance evidence.
- The supplied `prior_authority_drift` message alone does not identify which
  grant/checkpoint differed on the live site. Preserve the bounded blocker and
  inspect the exact persisted authority evidence; do not change environment to
  bypass that gate or label it recovered from a synthetic test.
