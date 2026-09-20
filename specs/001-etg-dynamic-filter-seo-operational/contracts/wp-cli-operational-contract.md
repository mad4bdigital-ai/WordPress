# WP-CLI Operational Contract

## Commands
- `wp etg-dfsb inventory` prints the bounded runtime inventory JSON to stdout.
- `wp etg-dfsb reconcile --previous=/path/prior.json` compares current runtime evidence against configured Profiles and an optional previous snapshot.

## Safety
- Commands are read-only and do not mutate options, Profiles, terms, routes, combinations, or index state.
- Previous snapshot input is limited to 2 MiB, must be readable JSON, and is validated by the Runtime Inventory/Reconciliation contracts before comparison.
- `blocked_drift` emits a CLI warning but does not auto-disable or auto-edit a Profile. Operational response remains explicit/operator-controlled.
- CLI output may be redirected to an evidence file for T120/T121, but the file itself is not authorization.
