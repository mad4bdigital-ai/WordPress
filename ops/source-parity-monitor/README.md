# MAD4B live plugin-to-Git parity monitor (read-only)

Reviewed source definition of a host-operated comparison tool. No WordPress
database, media, options, Cron configurations or live code are written.

Installed on the authorized VPS:
  /opt/mad4b-company-provisioning/ops/workspace-lifecycle/source_parity_monitor.py

Scheduled by /etc/cron.d/mad4b-source-parity-monitor each Monday 03:23 UTC,
with flock, nice, ionice, timeout and weekly log rotation.
The deployed source and this reviewed source copy have identical SHA256 bytes.

Before reading files, verifies:
- the pinned candidate Git Worktree is clean
- both company PHP containers are running
- the site source path equals the active Docker /var/www/html bind mount

Only tracked wp-content/plugins/mad4b-* paths are compared. Classification
is exact, different, source-only, runtime candidate or development/QA.
Reports and initial baseline are stored with restricted permissions under
/opt/mad4b-company-provisioning/reports/workspace-lifecycle-20261121/live-source-parity/

Self-test: python3 source_parity_monitor.py --selftest
Read-only report: python3 source_parity_monitor.py --run

2026-11-21 baseline:
Tours.dev Staging: 1745 exact, 272 different, 1221 source-only.
Wayfatours Production: 960 exact, 284 different, 1994 source-only.

These are source drift indicators, not automatic defects or permission to
deploy changes. 57 original PR942 functional reviews and owner/staging
release evidence remain separate.
