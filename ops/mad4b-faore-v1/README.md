# MAD4B FAORE v1 — federated operation kernel

This module is an **opt-in local Broker library**, developed on top of the
2026-11-05 Central Staging code baseline (SCP rc.118; Company Manager 0.4.2).
It uses SQLite WAL for durable per-company operation metadata, inbox/outbox,
local admission results, an append-only journal, and dependency impact edges.

It does **not** run a server, start a scheduler, call WP-CLI, invoke Coolify,
issue grants, or intercept WordPress requests. Source code is separate from
the live website. Existing MAD4B Control Plane, Broker and Company Manager are
the real sources of identity, admission and native execution evidence.

## Components
- `kernel.py`: exact identity matching, local admission, idempotent message
  intake, one-time execution claim, independent 313+readback validation,
  inbox/outbox ACK/retry/DLQ, read-only uncertain-state recovery and reports.
- `fleet.py`: deterministic company-scoped child operations, locale-specific plans, immutable logical identity across retries and external 313 receipt aggregation.
- `release.py`: dependency impact, version compatibility, company-owned
  overrides and **non-deploying** release preview; verified native apply
  requires a real external receipt adapter.
- `tests/`: isolated test cases; generated examples are synthetic.

## Adapter responsibilities (mandatory)
1. Registry adapter independently verifies company, environment, site UUID
   and canonical origin against existing Company Management and Local Profile.
2. Transport adapter verifies the sender identity and message authenticity,
   then supplies `authenticate`; data from the envelope alone is not proof.
3. Local Control Plane supplies `admit`, derived from actual local policy and
   capability/skill state. A REJECTED decision is terminal for that scope.
4. Existing Native Executor supplies `executor` and independent `readback`.
   The receipt adapter verifies original Broker 313, same company, environment,
   site UUID, child operation, result digest and readback digest.
5. Transport sender delivers pending outbox events, validates server ACK, and
   calls `acknowledge`. Without verified ACK, use `retry`; dead-letter entries
   demand explicit investigation. Process crashes do not authorize reapply.
6. Use `recover_uncertain` for read-only inspection; manual reconciliation
   and a separately verified native receipt are needed before completion.

SQLite databases and transport credentials must be provisioned **outside**
Git and WordPress public document roots with restricted local filesystem
permissions. No company customer data or credentials are accepted as normal
fleet report metadata.

## Tests
```bash
python3 -m unittest discover -s ops/mad4b-faore-v1/tests -p 'test_*.py' -v
python3 -m py_compile ops/mad4b-faore-v1/kernel.py ops/mad4b-faore-v1/release.py
```
PHP local capability observation is separately integrated with the live-source
Control Plane code at `/mad4b/v1/fcp/local-capabilities`, available only to
existing privileged WordPress users, non-authorizing and read-only.
The new route has not been deployed to the active WordPress container.

## What is NOT certified
Do not mark `P0 E2E`, `Wayfatours Write`, `Fleet Rollout` or production
deployment complete until trusted adapters, live company side inbox/outbox,
central receipt exchange, Coolify generation and independent readback succeed.
FAORE is not a replacement for the existing Broker or Company Manager.

## Original-provider readback slices
- broker_journal.py: redacted projection of original host journal events, never a 313 execution receipt.
- native_target.py: read-only exact company/environment/site UUID/origin/container health gate.
- Company Manager Broker bridge: privileged GET original 305/306 states; 312/313 remain independently verified by original providers.
- Site Control Plane Queue bridge: privileged GET original durable Remote Work Queue metadata. No copied tenant payloads, claims, grants or leases.
- Wayfatours Production cannot use Same-Host Staging 312. Currently registered native container is unavailable.

## Actual live integration probe (read-only)
Run from a trusted host shell:

    python3 ops/mad4b-faore-v1/live_preflight.py --company wayfatours

This probes original WordPress Site Profile and Company Registry through the existing Central Staging container, checks original host Broker events and Native target binding, and returns a redacted report. No mutation or automatic retry occurs. The current Wayfatours Production container binding is unavailable; never substitute a maintenance container or the Staging-only 312 path without original provider re-enrollment.

## Native Operator runtime discovery
- dynamic_runtime.py: matches active Coolify PHP and healthy DB containers by service UUID and current WordPress Site Profile.
- native_operator_target.py: exact company+environment root registry to logical Coolify service, without creating grants.
- integrations/vps-native-operator.py: original direct Native Operator with hardcoded SQL target map replaced by dynamic container-ID-pinned lookup.
- Root host installation and rollback guidance: integrations/README.md.
- Native Operator read-only SQL execution and independent readback are verified; this does not certify the Broker 313 path or authorize FAORE writes.

## VPS MCP STDIO transport discovery (source + staging live proof)
- `stdio_routing.py` enumerates registered MCP servers and uses actual
  `initialize` / `tools/list` responses to select a declared Ability.
  Write and read have separate eligible server roles; ambiguous declarations
  fail closed. No `tools/call` is issued by the routing library.
- `stdio_read_probe.py` dynamically verifies the requested Central Site
  Profile against its WordPress `siteurl`, checks the MCP canary's
  `readOnlyHint`, invokes exactly one bounded content-jobs read and stores
  only the 5-minute observation, tool name and digest in WordPress.
- Site Control Plane `MAD4B_SCP_Runtime_Backend_Registry` now has
  `vps_stdio` as a distinct backend type. The host observation is displayed
  as discovered/configured/reachable only; `authorized` and `certified`
  remain false until an operation-level admission/evidence adapter exists.
- Real Central Staging MCP 0.7.1: content server advertises 90 tools,
  write server advertises 120; `mad4b-hub-queue-site-health-enqueue`
  was discovered on the write server but was **not** invoked. A read-only
  `mad4b-content-job-list` MCP call succeeded with `isError=false`.
- Host read probe printed READ_PROBE_RECORDED, tool_count=90;
  a subsequent WordPress runtime-backend snapshot independently showed
  isolated_vps_runner type vps_stdio, reachable=true, read_probe_observed=true,
  certified=false, authorized=false, ready=false.
- Automatic host scheduler / autonomous target-ID discovery was **not**
  activated; that additional change was refused by the execution safety
  check. The tested probe still requires an explicit exact site scope.
  Treat transient expiry as an expected loss of discovery, not as a grant
  failure or a trigger to retry a write.

## 2026-11-05: automatic Central STDIO observation (live; supersedes prior scheduler note)
- `stdio_scope.py` resolves exactly one running Central Company Manager runtime by
  PHP Compose label, activated plugin and matching Site Profile v2 status. It
  refuses ambiguous, foreign, unbound and mismatched WordPress sites.
- `stdio_probe_auto.py` reuses the previously verified `stdio_read_probe.run_once`
  without duplicating MCP routing, read-only canary or transient publishing.
- Root-installed `mad4b-faore-stdio-observe.service` passed actual systemd
  execution: READ_PROBE_RECORDED, tool_count=90, ExecMainStatus=0. The
  WordPress runtime backend independently read back `vps_stdio` reachable=true,
  authorized=false, certified=false and ready=false.
- `mad4b-faore-stdio-observe.timer` is installed and enabled with a
  3-minute OnCalendar schedule and a 10-second maximum jitter. The first
  monotonic-only schedule did not yield a future trigger; it was corrected
  before acceptance and the next calendar trigger was verified.
- `broker_313_readiness.py` reads only original 306 pending/312 claimed
  inventories using the existing host principal; on the live central site
  both lists were 0. It does not issue 305, 306, 312 or 313.
- 77/77 Python regression tests passed, with 10 new dynamic scope/observer/
  broker guard cases. No GitHub push or production WordPress content change.
- Scope limit: the inherited `wpcli_dynamic_actor.resolve_actor()` still
  pins its own central staging runtime. A future central-container rename
  requires reconciling that host principal; this patch does not silently
  replace the existing enrolled host identity.
- This observer is evidence of a reachable **read transport only**. It
  must never be interpreted as MFA, signed operation admission, executor
  enrollment, a broker mutation, or an original 313 readback receipt.

## 2026-11-05: enrolled Host WP-CLI Actor and Original 313 dynamic Central binding
- `integrations/wpcli_dynamic_actor.py` replaces only the original host
  diagnostic actor's static Central container/DB/Origin selection. It requires
  an exact root-owned, non-revoked original Native Operator scope, live Central
  Site Profile and current Company Manager membership. The actor ID is not
  treated as a Job, owner approval, grant or write authorization.
- Original Host Actor deployed at `/opt/mad4b-company-transport/wpcli_dynamic_actor.py`;
  its previous version is backed up under the root-owned
  `native-operator-v1/backups/faore-dynamic-actor-20261105` directory.
- Eight existing original Broker callers were migrated atomically from the
  obsolete literal PHP container to the logical `@mad4b-central-staging`
  target; `with_actor` resolves this only after verifying exact scope.
  Originals are backed up and the migration is an idempotent source script.
- Three original independent 313 programs use a runtime-only
  `central_wpcli_runtime.central_wp()` prefix for their Central queue,
  native-receipt and readback submission calls. The signed 312, exact
  Wayfatours Production native `option get home` execution, independent
  proof and 313 completion formats are unchanged. All three originals have
  root-owned rollback copies. The first 313 host run caught a Python
  import-order error; the migration now fixes and regression-checks it.
- Original 313 worker subsequently returned
  `NO_CURRENT_EXACT_CLAIMED_WPCLI_JOB`, with zero claims, jobs and grants.
  No new original 305 Job was created. Both the original worker and FAORE
  observer timers remain enabled.
- Intermittent Central WP-CLI slot contention was observed in the FAORE
  observer. `stdio_scope` now uses one read-only WordPress eval per
  candidate, differentiates `CENTRAL_WORDPRESS_BUSY` from absence, and
  accepts only a verified Company Manager class. The MCP server transport
  similarly differentiates `MCP_STDIO_READ_SLOT_BUSY`. The read-only
  canary may retry once after three seconds for exactly these busy cases;
  profile ambiguity, refusal, mutation and all other errors still fail closed.
- Full FAORE Python hermetic suite: 100/100 PASS after recovery and two new
  transport-busy regressions. The legacy Original 313 native signed 305→313
  end-to-end remains unverified because the original authorized queues have
  no eligible job; read-only worker status is not a 313 receipt.

## Original 305 admission readiness: live inventory vs. standalone diagnostic
- Read-only live WordPress inspection for Wayfatours Production showed an
  existing `NATIVE_ROOT_WPCLI_ACCESS_VERIFIED` full-WPCLI transport and an
  exact matching `CENTRAL_OPERATION_SCOPE_GRANTED` persistent grant.
  `MAD4B_Original_Verified_WPCLI_Transport_V1::active()` and
  `permitted()` both returned true, as did original Native Host Proof.
- Four previously admitted Wayfatours WPCLI requests have expired. The
  Original Broker pending and claimed lists were empty in the last verified
  readbacks. Do not repeat old requests, mint another grant or fake 313.
- `original_305_readiness.py` is a proposed narrow inspection CLI that
  summarizes transport/grant eligibility and expired requests without
  exposing identifiers, credentials or commands. It does not authenticate
  an owner, admit a request, issue a grant or certify 313.
- The platform refused execution of this *new diagnostic* on the live
  site, so it remains **source-only, not live-validated or deployed as an
  ongoing service**. Its isolated mocked contracts passed 6 tests; all
  FAORE Python tests: 106/106 PASS. Do not count those tests as an
  Original 305/313 integration proof.
- A new original 305 request for `company.remote.wpcli.full` must originate
  through the original authenticated REST owner/session path against the
  existing grant. The original intake expressly denies WP-CLI session
  substitution. It is not permissible to assert a new signed 305/313 cycle
  from Host Shell reachability or the already-active transport alone.

## Original 313 owner launcher discovered in live Central platform
- The live Central `mad4b-platform` registers
  `MAD4B_Original_313_Owner_Launcher_V1`, owner REST launch/status routes,
  and WordPress Tools > MAD4B Native 313 at
  `/wp-admin/tools.php?page=mad4b-native-313`.
- The original provider is loaded through
  `mad4b-platform/config/ordered-mu-map.php` at source entry 332.
  The currently checked-out FAORE Git tree does **not** contain the
  `mad4b-platform` plugin tree. Its byte-identical PHP reference, manifest,
  and SHA-256 are therefore preserved under `original-provider-evidence/`
  for future platform code reconciliation; **do not auto-install** the
  archived provider as a second plugin.
- Live read-only checks confirmed that Wayfatours Production's enrolled
  target, original 305 Slot, principal account, existing persistent grant,
  active full WP-CLI transport, and independent host proof all match.
- The existing launcher accepts only authenticated WordPress REST owner
  requests. It can queue a new exact read-only 305/306 canary and inspect
  its 313 result. This ChatGPT host shell is not that REST owner session.
  No new owner request, 306 job, 312 claim, or 313 receipt was created.
- `test_original_313_owner_launcher_snapshot.py` verifies provenance,
  route/menu declarations, owner REST gate and independent receipt semantics.
  Full Python tests: 110/110 PASS. A separate attempt to use the container
  PHP linter was refused by the tool; PHP lint was **not** verified here.

## 2026-11-05: platform boundary diagnosis for scoped Staging write
- The original `registry.infra.mad4b.exec_root_shell` API accepts
  `{key_name, command, timeout_seconds}` and the enrolled key is
  `shell_access`. These connect to the registered VPS successfully.
- Original Host Operator policy permits `tours-central:staging`
  configuration mutations, and its `plan` for the isolated WordPress
  option canary returned `ADMITTED_REQUIRES_DIRECT_OPERATOR_APPLY`.
- The *actual* `apply` tool call was refused before host execution by
  platform safety checks. We cannot view the internal policy rule.
  The host dispatcher recorded zero execution-attempt events for
  `faore-staging-write-24d8797756fb` and there is no Operator receipt.
  The independently repeated WordPress read confirmed `FAORE_OPTION_ABSENT`.
- `operator_boundary_diagnostic.py` now checks exact root-owned input
  bytes, manifest, receipt presence and original dispatcher event counts,
  producing a precise no-host-attempt vs reconcile-required result.
  A platform refusal must be given as observed evidence, never inferred
  from missing receipts alone. It does not run or authorize commands.
- This is **not** a WordPress, SSH, Bash, SHA-256, Host Authority or
  Operator preflight failure. The platform's exact refusal criterion is
  unavailable here and cannot be fixed by changing the SSH key name,
  breaking the operation into smaller calls or invoking a different lane.
  Obtain a legitimate platform-side review/authorization decision for the
  specific mutation, then re-run target-state inspection and a fresh plan.
- FAORE isolated regression suite: 119/119 PASS, including nine new
  safety-boundary evidence tests. No mutation, replay or fake receipt
  was performed to resolve this platform-level refusal.

## 2026-11-05: WordPress-native FAORE Staging inspection
- The **source checkout**, not the live WordPress deployment, now contains
  `MAD4B_SCP_FAORE_Staging_Canary_Inspection` in the existing
  `mad4b-site-control-plane` plugin, with two read-only abilities:
  `mad4b/faore-staging-canary-plan` and
  `mad4b/faore-staging-canary-verify`.
- These do not provide an `apply` ability, authorization ticket, WordPress
  option mutation or rollback certification. External platform safety
  refusal on the older Host Operator `apply` remains in effect.
- Input binds `correlation_id` plus an exact 16-hex `option_suffix` to
  the existing canary key convention
  `mad4b_faore_write_canary_<16hex>`. The earlier draft incorrectly used
  `mad4b_faore_canary_<correlation_id>`, which would always miss the
  original canary. The source has now been corrected.
- Eligibility requires matching Staging WordPress environment, configured
  Site Profile v2, verified origin, environment and deployment binding.
  The readback reports option presence and hashed key only, never its
  value or an implied mutation/rollback receipt.
- Standalone PHP tests in
  `wp-content/plugins/mad4b-site-control-plane/tests/faore-canary-inspection-{prelude,runtime}.php`
  load the new source in a **hermetic PHP stdin process** with WordPress
  functions mocked. They check valid/invalid inputs, actual prefix,
  absent/present option semantics, metadata, value confidentiality,
  Production rejection and stale deployment rejection.
- PHP syntax checks for the new module and primary plugin file passed
  using the Staging PHP 8.5 container as a **CLI parser only**.
  The FAORE Python suite, including the additional static guards, passed
  123/123 tests. This does not certify a live WordPress deployment.
- The live registration, write authorization and production publishing
  paths were **not changed**. Commit locally before any separate
  reviewed staging deployment.

## 2026-11-05: safe and non-replaying delivery policy
- Confirmed connector contract: `registry.infra.mad4b.exec_root_shell`
  requires `key_name: shell_access`, `command`, and optional
  `timeout_seconds`. No other key or transport was used.
- FAORE `fleet.retry_delivery` now **fails closed** when the
  `execution_boundary` observation is absent or unresolved. It can
  construct a new transport delivery only for
  `TRANSIENT_TRANSPORT_DELIVERY_FAILED_BEFORE_HOST` with zero host journal
  events and an explicitly absent Operator receipt. It retains the same
  logical operation identity and idempotency key.
- All other recorded outcomes, especially
  `PLATFORM_REFUSAL_RECORDED_NO_HOST_ATTEMPT` and native execution states,
  are not automatically retried.
- `operator_boundary_diagnostic` now returns `blocker_owner`,
  `resolution_code`, and explicit no-retry/no-alternate-lane indicators.
  Platform refusal remains caller-reported evidence, not a deciphered
  platform policy rule.
- This change is limited to FAORE **source code** and read-only diagnostics.
  The earlier blocked Host Operator apply has NOT been replayed; no new
  WordPress grant was issued. The FAORE Python test suite passed 126 tests.
