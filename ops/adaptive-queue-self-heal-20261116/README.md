# Autonomous Adaptive Asset Intelligence queue self-healing, 2026-11-16

## Root causes
The original Adaptive job queue treated internal tour-rates post permalinks
as navigable public pages even though each failed HTTP 404. It also deleted
failed and HOLD jobs as if they were terminal, leaving no automatic retry.
An early save_post hook ran before WordPress had committed all custom fields
and taxonomies, so the CSS home dependency graph could be stale.

## Changes to existing plugin, not an alternate worker
- Hook wp_after_insert_post instead of early save_post; covers first publication,
  ignores WordPress autosaves/revisions, observes final post data.
- Route internal tour-rates changes through related_tour_id to actual public
  tours-and-activities permalink. If unlinked, queue canonical homepage
  rediscovery rather than fabricate a URL.
- Retain HOLD and FAILED jobs, with bounded retry attempts and exponential
  backoff up to 6 hours. A new source edit resets the retry gate.
- Only first-party WP-CLI worker native/certified results retire a queued item.
- Bounded WP-CLI due_worker_jobs returns routable work without authorizing
  a release or forging a browser or Original313 certificate.
- The existing homepage dependency-preview guards still govern CSS Epoch.

## Tests and live evidence
Offline isolated PHP contract suite: 16/16 assertions pass. PHP syntax passes.
The Staging plugin is byte-identical to the local branch source file.
Public EN/ES homepage continues to return HTTP 200.
No new market cache admission and no alteration to Woocommerce orders.

Before cleanup Staging queue: 20 entries. With private JSON rollback and
DB transaction + advisory lock, removed eight numeric and one legacy QA
tour-rate route after checking they had no active manifest certificate.
Final Staging queue: 11; internal tour-rate route count: 0.
The original rowsets were saved under private VPS ops files. This is a
one-time historical queue repair; new authoring now uses canonical routing.
The remaining 11 jobs are NOT claimed certified by this cleanup.

## Limits
The new queue API does not by itself execute full browser recertification.
It supplies stable due/retry semantics to authorized existing workers.
Production Wayfatours remains unchanged until separately reconciled.

## Race-safe worker improvements (2026-11-16)

Each new source edit creates a fresh random source_token; the due list carries
that token. A first-party browser result can acknowledge only its own exact
token. Old results are rejected with source_revision_changed without erasing
or authorizing the new revision.

All queue mutations use WordPress's existing options row with an InnoDB
SELECT ... FOR UPDATE transaction and explicit COMMIT, cache invalidation and
ROLLBACK on failure; no new tables or brokers. The immutable token check
occurs while that row is locked. This fixes stale read-modify-write races
between concurrent editor requests, browser worker and other admin actions.

Offline PHP suite 20/20 assertions PASS. Live WordPress readback verifies
the database transaction preserves the original queue. A real temporary
test job verified: initial queue, HOLD and backoff, second source mutation,
rejection of an old "certified" receipt, and safe retirement with current
token, with original job count and CSS Epoch unchanged.
