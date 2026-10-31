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
