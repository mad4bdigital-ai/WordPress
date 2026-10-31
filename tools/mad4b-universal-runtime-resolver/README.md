# MAD4B Universal Runtime Reconciler v1

A persistent convergence kernel for the MAD4B central VPS. It observes enrolled site state, resolves scoped capabilities from trusted inventory and policy, and persists receipts, dependencies, events and reviews in SQLite.

Current live adapters on All Royal Egypt Staging: transport, identity, scoped authority, registered administrative SSH shell, execution route, source-native Full Staging Authority status, signed agent, package and workflow.

## Runtime

Contract: mad4b.universal-runtime-reconciler.v1

Installation root: /opt/mad4b-company-transport/universal-runtime-v1/

- Python 3 standard library with existing Registry SSH transport, WP-CLI and Dispatcher.
- SQLite runtime.sqlite3 stores event ledger, observations, receipts and reviews.
- policy.json stores environment- and operation-specific policy.
- inbox/*.json is an atomic, local event spool, monitored by systemd.path.
- The existing mad4b-dispatch-v1.timer schedules reconciliation about every 5 minutes.
- resolver.py emit accepts a direct event/manual trigger.
- Dispatcher state transitions may emit a local durable notification.

## Commands

    python3 resolver.py emit --target all-royal-egypt:staging --trigger manual --source operator --idempotency-key site-staging-recheck-001
    python3 resolver.py scheduled
    python3 resolver.py consume
    python3 resolver.py status
    python3 -m unittest -v test_resolver

Event JSON:

    {
      "contract": "mad4b.universal-runtime-event.v1",
      "target": "all-royal-egypt:staging",
      "trigger": "provider_change",
      "source": "dispatcher",
      "idempotency_key": "dispatcher-unique-event-id"
    }

Create the event in a root-owned temporary file, then rename atomically to inbox/<unique>.json. Malformed events go to the deadletter directory and never execute shell commands.

## Authority

- P-256 signature proves identity only.
- Transport privileges come from enrolled identities with pinned host keys.
- Mutations require explicitly enrolled site-scoped operations.
- Package repair uploads only a SHA-256 pinned ZIP, without installation or activation.
- Unknown environments, absent providers and conflicting bindings fail closed.
- Production is observed but not mutated in this rollout.
- Uncertain writes are not automatically replayed.
- Adapter effects require independent verification and must be authorized by policy.

## Central Host control is independent of Native Owner readiness

Central Staging (`tours-central:staging`) is a **local VPS target**, not an
externally provisioned company. It is resolved from the exact centrally owned
Resolver scope and independently checked against the original root-owned
`mad4b.native-vps-operator.v1` policy, command hash and its original
mode-0600 diagnostic receipt. A regenerated *company* inventory must not
disable this special local target.

The `central_execution` observer has no dependency on the native Full
Staging Authority plan, remote company SSH transport, or Agent OAuth
subject. It reports the availability of the original central host route,
not blanket permission to write. Every NEW operation still requires its
own original Host Operator preflight and readback.

For this central target, remote-only adapters show `not_applicable`, while
`central_execution` reports `verified` independently from
`central_source` and `central_authority`. A mismatch in Source
Provenance remains a separate release/deployment blocker, and an OAuth
Agent-binding conflict remains a separate WordPress governed-write blocker.
Neither prevents legitimate, individually authorized root Host maintenance.

This does **not** alter the original `mad4b-unified-full-authority` plugin:
its native `ready_to_apply=false` still applies to that plugin's OWN
`apply` route. Central execution through its independent authorized host
route must not be represented as native Full Authority.

## Original authority and registered SSH administrative fallback

The main site `tours.dev.mad4b.com` uses its existing root-owned host operator,
not a second remote WordPress plugin or an unrestricted web endpoint.

- An enrolled Registry SSH key is referenced on the VPS and the target's
  host key is pinned. The key is not copied to WordPress, Content Hub or CI.
- The original `mad4b.native-vps-operator.v1` root-owned policy has a distinct
  `all-royal-egypt:staging` scope with `allow_remote_shell` and the exact
  site UUID, canonical origin, enrolled transport and site root.
- `vps-remote-shell-operator.patch` extends that original native operator with
  `--kind ssh` for direct root-authorized host administration. It accepts
  arbitrary shell *program bytes*, not a fixed operation catalog, but only
  from root-owned local files, an exact command SHA-256, a readback program and
  expected readback SHA-256. Mutating intent also needs backup and rollback
  artifacts. Reused correlation IDs are refused; outcomes are journaled.
- The native operator now resolves remote targets **dynamically** from its
  root-owned `host-authority.json` scope and the original enrolled Registry
  `TARGETS`; adding an independently enrolled company does not require
  another hard-coded command catalog. It will not inherit an unspecified
  company's key, unregistered target, or a revoked host scope.
- The SSH account has the privileges of the **enrolled remote hosting user**,
  not remote VPS root. An SSH connection does not assert sandbox isolation.
- Automatic WordPress/Broker jobs **do not** inherit arbitrary remote shell
  execution from this administrative scope. Those jobs still require the
  original per-operation admission and readback.
- `native_authority_status.py` retrieves
  `MAD4B_SCP_Full_Staging_Authority::status()` from the target over enrolled
  SSH/WP-CLI. Its original `operational_ready=false` remains unmodified.
  The runtime independently reports `admin_shell=verified` and
  `execution_route=verified`, selecting `registered_remote_ssh` for
  directly-authorized administrative operations even if PHP `proc_open`
  is unavailable.
- Remote SSH compensates for missing PHP process execution for the admitted
  admin tasks. It is **not** equivalent to a network-isolated Developer sandbox,
  nor does it certify the Unified Full Authority native approval plan.

### Operator usage

The direct host operator executes from an authenticated VPS root SSH session
(available through the enrolled Registry admin connection). Supply root-owned,
mode 0600 command and readback files. Use `sha256sum` for the exact input
and expected stdout bytes. Example:

    python3 /opt/mad4b-company-transport/vps-native-operator.py plan \
       --kind ssh --company all-royal-egypt --environment staging \
       --intent diagnostic --file /root/admin-command.sh \
       --sha256 <exact-command-sha256> \
       --readback-file /root/admin-readback.sh \
       --expected-readback-sha256 <expected-stdout-sha256>

Run the same arguments with `apply`, adding a fresh `--correlation-id`;
do not blindly retry a failed or uncertain mutation. No `--user` override
or web-controlled arbitrary command payload is accepted.

### Live acceptance 2026-10-31

- Signed host scope and exact site root: PASS.
- Direct remote general shell command via Registry SSH: exit 0.
- Independent remote readback: matching SHA-256, state VERIFIED.
- Negative tests: unregistered Production target rejected; mutation without
  backup/rollback rejected.
- Resolver contracts: 20/20 PASS; original Dispatcher contracts: 10/10 PASS.
- `admin_shell=verified`, `execution_route=verified`;
  `full_authority=blocked_dependency` preserves native sandbox blockers.
- Production mutation performed: false.

## Central WP-CLI Host Identity Bridge (2026-10-31)

`central_wpcli_host.py` provides an authenticated, read/plan-only WP-CLI
entrypoint for `tours.dev.mad4b.com`. On the enrolled VPS it is installed
as `/usr/local/bin/mad4b-central-wpcli`. Examples:

    mad4b-central-wpcli context-check
    mad4b-central-wpcli context-inspect
    mad4b-central-wpcli full-authority-plan
    mad4b-central-wpcli full-authority-lifecycle

It **does not accept `--user=1`**, arbitrary WP-CLI commands, or user-supplied
site/environment credentials. The caller must be authenticated as the original
Root SSH operator, with an exact-scoped central Staging Host Authority record.
The runner verifies WordPress's enrolled Site UUID, canonical origin, database
and environment independently, then passes those facts into WP-CLI's existing
Host Execution Context. It runs `wp --allow-root` inside the enrolled
container in the exact context required by the existing WordPress authority
class, without impersonating a WordPress user. Successful readbacks create
a source-backed event in the original Dispatcher journal.

Read/plan success **does not imply** that the native Full Authority plan is
ready or that a WordPress governed write is authorized. It does not expose
`apply`; write operations remain in their existing Native VPS Operator,
Grants and independent readback lanes. The live acceptance confirmed
`read_context_verified=true`, `host_site_binding_verified=true`, and no
blockers. The Unified Full Authority plan was readable via `host_cli`
but returned `ready_to_apply=false` with
`native_owner_plan_not_ready`.

Isolated tests in `test_central_wpcli_host.py` check exact site binding,
untrusted target rejection, host scope and readback contract integrity,
non-impersonation and denied Production reuse.

## Unified authentication routes and per-operation admission

Live adapter: `multi_transport_authority.py`, consumed by
`resolver.py` domains `authentication_routes` and `operation_admission`.

**Authentication is not an operation grant.** Three original identities
are accepted, independently:

- OAuth/MCP: authorization occurs inside the original active Bearer request,
  via WordPress Execution Authority and existing Agent/Grant/Approval policy.
  CLI inspection does not impersonate a bearer; it reports
  `request_context_required`.
- VPS SSH/WP-CLI: authentication and exact site/enrollment come from the
  existing root-owned VPS Operator and Registry. A direct maintenance shell
  command is admitted only by the original Native Operator's plan and
  independently verified receipt. A scheduled service may **observe** that
  recent original receipt without impersonating an active root SSH session.
- Broker: a signed round trip to the original Central verifier is required;
  its `claimed` list is checked per exact job SHA, target, and signed
  grant/claim. A functioning heartbeat does not permit shell execution.
  Host-side authority and execution readback remain separate requirements.

The common operation evaluator matches the exact company, Site UUID,
environment, canonical origin, operation class, original source permission,
approval and independently auditable readback. A WordPress governed-write
Grant cannot be manufactured from a healthy SSH socket or Agent signature.
A pending OAuth Subject conflict is surfaced by `central_authority` as
a review, without forcing an unrelated authorized Host maintenance operation
to fail.

### Central Source/Grant Drift, 2026-10-31

- Live central source: `73c6852f3a22148cf4e79c73643cbbdeffca7f8a`,
  runtime manifest matched and stale=false.
- Original readback: 112 write abilities, 92 exact current-environment
  Grants and 20 missing; 15 missing are outside the source's
  Staging approved pair list; 9 reviewed obsolete Grants remain.
- Current OAuth subject issue: `oauth_subject_bound_to_other_agent`.
  It is not automatically reassigned and does not revoke legitimate
  independent host authority.
- A source-only patch for the 15 exact first-party core content pairs
  is retained in `staging-grant-policy-15-source-only.patch`; original
  local source commit `4e81da221c6d5fee045fa20233399bc5763575e8`.
  This patch **has not** been installed into the live WordPress plugin or
  used to issue grants: any installation requires a new rebuilt release
  manifest, live provenance, and original operation-owner admission.

## Extension contract

Each adapter returns a structured observation with state, provider, reason and evidence. States include verified, blocked_dependency, retry, review_required and awaiting_provider.

Adapters are allowlisted and connected through a dependency graph. Additional Content Hub, Pricing, Inventory, Media, WPML and Migration adapters require real provider bindings and independent source readbacks. Names in the policy are not certifications.

## Rollback

1. Disable mad4b-universal-runtime-event.path and remove the resolver service drop-in.
2. Restore dispatch-v1.py from its timestamped original if it has not been independently changed.
3. Reload systemd and keep the existing Dispatcher timer enabled.
4. Preserve runtime.sqlite3 as evidence. Do not delete evidence in rollback.
5. Leave legacy Production trust and WordPress Production records untouched.

Do not claim universal rollout until additional domain adapters pass contract tests and live readback.
