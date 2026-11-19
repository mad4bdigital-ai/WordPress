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

## Isolated immutable per-site renderer (v11)

Each enrolled site now has an actual, independent renderer directory under
its own deployment operations/css-cron-v1/renderer. It contains 119 original
first-party source files. The original shared renderer directory is no longer
used by the site's worker; the WordPress CSS queue, output receipts and
browser resource/CPU gates remain first-party. The site-local JSON profile
binds renderer_sha256, and worker.py validates the entire root-owned,
non-symlinked, non-writable tree BEFORE accessing WordPress or running any
shell tool. Both active sites are pinned to:
33951bee57e041b6dc572f4f22abdf84936a1e5d816c7b06686484503e5fe6a0

For reproducibility, rebuild_renderer.py constructs that exact 119-file
runtime from the tracked ops/dynamic-critical-css-20261116 source tree,
five pinned renderer source/test deviations from the PR baseline, and an exact anchored insertion recorded in
run-site-probe.patch. Its renderer-v11-manifest.json pins SHA-256 per
file and the exact final tree hash. It does not overwrite newer
shared-source tests or modify the installed site on its own.

On 19 November both site renderers were atomically switched under the
original global CSS lock. Their full v1 renderer trees and root-owned
worker/profile backups remain on the host for a reversible rollback.

## Cheap, non-certifying event-gate checks under CPU pressure

The original run-site.sh source-identity and event-gate checks run in
MAD4B_CSS_PROBE_ONLY=1 without allowing Chromium or certificate
publication. If the original consumer itself determines the existing
CSS certificate is unchanged and prints its exact original skip marker,
the site worker can ACK the exact pending WP job; any changed identity,
timeout, invalid proof, lost WP-CLI admission, or unknown output preserves
the ticket and leaves native CSS in place. Probe retry is bounded to
one attempt per job generation per 900 seconds. CPU/PSI and shared browser
resource restrictions are unchanged for all NEW certificates.

The original source and Wayfa report/readback collector may observe
both sites, but each site uses its own immutable renderer to write
new CSS artifacts only for its own admitted task.

## Archive and authenticated-session CSS qualification

The independent site runner does **not** substitute source files for a
certificate of browser layout or interactions. Optional proof tooling:

- browser_archive_qa.js takes viewport 390px and 768px screenshots,
  observed CSS class identities, card/price/link digests, JavaScript
  errors, image state and safe expanded-button interaction on the
  site's public archive. It is read-only: WordPress activation must be
  handled outside the script with an automatic rollback. A source/host
  CPU budget and shared browser lock must admit the run.
- 436-mad4b-private-session-critical-css-v1.php still requires a
  first-party authenticated session CSS bundle, exact source SHA,
  independently passed 390/768 visual and interaction QA and a
  site-local immutable manifest. Never manufacture this proof from
  an anonymous request or an untrusted simulated logged-in cookie.

Until independently verified for each site, both
mad4b_perf_archive_stable_dcss_v1 and
mad4b_private_session_critical_css_v1 remain OFF.
Native full CSS is the correct fail-closed behavior. The per-site
automated Cron retries its existing source/route jobs without reducing
price/checkout privacy or publishing an unproven new CSS certificate.
