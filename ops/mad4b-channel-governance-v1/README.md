# MAD4B Channel Governance — Original Dispatcher Integration

Scope: **tours.dev.mad4b.com** (Central Staging), within the original
`mad4b-dispatch-v1.service` and its existing timer.

## Responsibility

The `integration.py` component is status-only. It aggregates **10 original
provider/source contracts** and preserves the difference between transport
failures, original authority denials, unresolved effects and external tool
policy refusals. The service itself cannot see ChatGPT's tool-layer refusals.

It does not mint grants, authorize WordPress writes, switch lanes, start a new
executor, or re-run uncertain jobs. Source `305` remains the original grant
issuer. An existing, exact, separately authorized operation is still required
for any native write.

## Release source and certification

`integration.py` is the currently installed 10-source implementation.
`integration.previous.py` is the exact prior 7-source version. Both are
versioned with their SHA-256 digests. The local Git branch is
`fix/channel-governance-certified-20261031`.

From the folder in a local Git worktree:

```bash
python3 -m unittest discover -s . -p 'test_*.py' -q
python3 certify.py
python3 certify.py --output acceptance.json
```

The certificate requires: exact local-to-runtime source and systemd drop-in
checksums, 10 readable source contracts, zero effects by this diagnostic
component, and a matching successful **completed** systemd cycle in the
original journal. It works even if a later timer cycle has already started.

A runtime acceptance does not attest that arbitrary WordPress writes or
Production execution are approved. The 305/316 issuer and native provider
still enforce those independently.

## Installation and rollback

No service or queued job is started by these scripts.

```bash
sudo bash ./install.sh
sudo bash ./rollback.sh --to-previous
# or, for complete removal of the diagnostic integration only:
sudo bash ./rollback.sh --pre-integration
```

All mutating scripts require an exact live SHA-256 match and check that the
original Dispatcher is idle before changing any file. They never change the
original Dispatcher unit, original timer, WordPress data, grants or Broker.

`rollback.sh --to-previous` restores the previous 7-source Python module;
`--pre-integration` removes only the added module and one systemd drop-in.
Both invoke `daemon-reload` and verify the original service configuration,
without starting it.

## Operational caveats

A status snapshot alone is not proof of systemd execution. The runtime
certificate verifies journal events from the same completed cycle.
An unavailable source is reported as such, not converted to an authority
denial, and no denied action is rerouted to another lane.
