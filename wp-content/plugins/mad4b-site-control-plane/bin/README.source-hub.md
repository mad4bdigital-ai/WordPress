# Bundled Source Hub host transport

`source-hub-worker.py` reads the private manifest from MAD4B, and `source-hub-batch.py` runs each approved source. The WordPress plugin owns registrations, approval, revision, private receipt validation and review intake. The host runner is a component of the MAD4B plugin, not an independent external gateway.

Never schedule these example commands against an unknown site, an unreviewed feed host, or a production instance without governed authority. One-time manual fetches do not imply a recurring task is active. `source-hub-scheduler.example` is documentation, not an installed crontab.
