# MAD4B Universal Runtime Reconciler v1

A persistent convergence kernel for the MAD4B central VPS. It observes enrolled site state, resolves scoped capabilities from trusted inventory and policy, and persists receipts, dependencies, events and reviews in SQLite.

Current live adapters: transport, identity, authority, signed agent, package and workflow.

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
