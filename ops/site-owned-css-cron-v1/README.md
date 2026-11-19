# Site-Owned CSS Cron v1 — MAD4B

Each WordPress site owns CSS due-job tickets through its installed
429-mad4b-css-wpcron-dispatch-v1.php. The OS Cron under the site's own
deployment root wakes that site's worker.py and can only claim and
acknowledge tickets belonging to this site.

## First-party rollout

- Tours.dev Staging: site worker under operations/css-cron-v1,
  Cron every 5 minutes at offset 0
- Wayfatours Production: separate worker under operations/css-cron-v1,
  Cron every 5 minutes at offset 2

Each has root-owned, mode 0600 site-profile.json, NOT stored in Git.
Keys: contract, site_id, php_container, site_host, environment, tasks.
The task list is a subset of first-party CSS functions in the approved
original runtime. The worker refuses symlinked, unsafe-permission,
malformed or unauthorized profiles. The site ticket identity and host
must match WordPress's own snapshot.

Existing CSS producer and Chromium sidecar remain shared, but the
scheduler, OS trigger and queue-claiming execution process are now
site-owned. This does not relocate the rendering algorithms, bypass
CPU controls or permit arbitrary execution for any company.

## Safety and state preservation

- The original central Cron was retired, not deleted. A protected
  rollback backup lives in reports/site-owned-css-cron-20261119.
- Each new Cron acquires its own lock and the original shared CSS lock
  to avoid duplicate runs, conflicting browser work and concurrent
  writes to the original attempt ledger during the cutover.
- WordPress pending IDs, retry history, native event receipts,
  certification gates and original CSS generator are preserved.
- Shell exit 0 without an exact original certificate is never enough
  to ACK a WordPress ticket; capacity deferrals retain pending tickets.
- No Chromium runs inside PHP-FPM. CSS generation uses bounded
  original rendering tools, container resource limits and host locks.
- CSS generation never authorizes public HTML or changes WooCommerce
  prices, market, nonce, payment or booking isolation.
- Existing source-owned family/visual/integrity monitoring can still
  observe both current sites; it is read-only and does not grant
  cross-site CSS generation permission.

## Operations

Run installed worker.py --inspect to read that site's WordPress queue
without invoking a heavy CSS job. Run test_site_worker.py for isolated
security tests.

Rollback: remove both new site-specific /etc/cron.d entries, restore
the original central entry from its root-owned backup, and never let
both schedules run together.

A new company needs separate original host CSS renderer enrollment.
Adding a JSON profile alone does not authorize a new execution backend.
