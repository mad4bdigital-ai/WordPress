# MAD4B Native Asset Coherence — v1.0.0

Reproducible host service package from the **live Tours.dev and Wayfatours** native repair scripts captured 22 November 2026. GitHub stores these operational bytes; Git master is not the functional WordPress source of truth. Do not replace WordPress plugin files or grant Production Write Authority with this bundle.

## Architecture

The primary event path uses Linux inotify and a dedicated long-running systemd service **per site**, including fast recovery on hashed LiteSpeed CSS/JS deletion and Nginx FastCGI cache writes. Each site has a separate **systemd timer** (Cron-equivalent) roughly once per minute as an independent backup; do not replace primary detection with traffic-dependent WP-Cron.

Wayfatours uses Nginx FastCGI cache at /var/cache/nginx/wayfa; Tours.dev uses /var/cache/nginx/etg_woo_perf_v1. Distinct site-local roots are never crossed. Tours.dev retains only files referenced by cached HTML, not the full 2.2 GB LiteSpeed catalog.

## Package verification

- Run: python3 ops/mad4b-cache-coherence-v1/install.py --verify-package
- On enrolled root VPS check: python3 ops/mad4b-cache-coherence-v1/install.py --site both --check
- Explicit idempotent apply: python3 ops/mad4b-cache-coherence-v1/install.py --site both --apply

The installer first verifies SHA-256 lock records and **refuses to overwrite divergent live files**. Reconcile any newer live behavior before a new package. Only the four scripts and six systemd units are installed; WordPress content, bookings, tax, production grants and cache HTML elsewhere are untouched. No external browser is used.

## Service names

| Site | Immediate inotify event | Independent per-site timer |
| --- | --- | --- |
| Wayfatours | mad4b-wayfa-tour-asset-event-watch-v2.service | mad4b-wayfa-tour-asset-coherence.timer |
| Tours.dev | mad4b-toursdev-asset-event-watch-v2.service | mad4b-toursdev-asset-coherence-fallback.timer |

Each watcher invokes the corresponding guarded repair under a per-site lock; a dropped event is recovered by the independent timer. Confirm status with systemctl is-active and journalctl -u. Services restart automatically after a crash, and remain installed across host reboot.

## Native-only regression

Run: python3 -m unittest discover -s ops/mad4b-cache-coherence-v1/tests -p 'test_*.py' -v

Regression checks filesystem inotify close/write/delete, restored immutable assets, selective invalid cache object eviction, bounded snapshots and idempotence. GitHub Actions uses Linux and Python standard library only: no Chrome, Playwright, screenshots, external web pages, live WordPress mutation, site credentials or DB access.

## Rollback and provenance

A SHA-256 manifest pins the already-deployed live bytes. GitHub commit SHA and Actions CI prove source-control traceability, **not** a cryptographic release signature or WordPress Production Write Authority. For rollback, disable only the appropriate watcher/timer and restore their previous root-only scripts and units from the incident backups, then daemon-reload. Never replace source or target company overlays with Git master blindly.

Operational receipts on VPS:
- /opt/mad4b-company-provisioning/reports/wayfa-tour-blank-20261121/
- /opt/mad4b-company-provisioning/reports/toursdev-asset-coherence-20261122/

Known independent work: Tours.dev uncached TTFB; CSS V2 qualification; 82 Control Plane source/target differences; signed release provenance.
