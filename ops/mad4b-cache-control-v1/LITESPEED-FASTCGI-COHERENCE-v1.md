# MAD4B LiteSpeed Cache → Nginx FastCGI coherence v1

Deployed on Tours.dev Staging and Wayfatours Production on 8 November 2026.

## Ownership model

Nginx FastCGI remains the authoritative 1800-second public HTML origin cache.
LiteSpeed Cache remains the existing plugin for its own supported cache,
CSS, JavaScript, lazy loading and native purge events. It does **not**
implicitly purge Nginx FastCGI.

The new MU plugin subscribes to these **post-purge** events:
- `litespeed_purged_link`: only the exact same-origin, public URL path.
- `litespeed_purged_post`: only published, non-private WordPress
  page/post/tour paths and their existing WPML translations.
- `litespeed_purged_frontpage`: published static homepage and languages.

The bridge accumulates up to **60** distinct paths per request and dispatches
one batch on shutdown to the company's existing approved
`MAD4B_Cache_Control_V1::socket_purge()` local UNIX socket.

Guardrails: no arbitrary hosts or query-bearing URL, no private
checkout/cart/wp-admin, no private WooCommerce post, no background
full-cache purge, no direct Redis commands, no vendor plugin edits,
no rate/booking changes, no recursive reverse bridge.
Global `litespeed_purged_all`, `litespeed_purged_all_lscache`,
`litespeed_purged_pages`, taxonomy-wide/post-type-wide purges are
**intentionally not mapped** to FastCGI. Use MAD4B Cache Control
with a scoped preview for those changes; don't claim cross-cache global parity.

## Independent live acceptance

The official LSCWP `litespeed_purge_url` action was invoked once per
site on a disposable, nonexistent QA URL, **not a customer URL**.
The actual plugin dispatched `litespeed_purged_link`.
On each site the bridge's WordPress receipt reported:
`status=ok`, `versioned=1`, `redis_flushed=false`.
An independent root filesystem read confirmed the corresponding Nginx
FastCGI `.generation` marker appeared. Temporary QA markers and empty
directories were removed. Both homepages remained HTTP 200.

A read-only WPML shadow test verified the Post hook resolves 6 source
and 2 Wayfatours translated tour URLs, and excludes checkout/external
hosts. PHP isolated tests on both WordPress containers verified dedup,
single batch, 8 rejected URLs and fail-closed >60-path overflow.

The Redis provenance observer detected **separate unattributed FLUSHDB
activity** during concurrent site maintenance; the bridge receipt only
proves this module does not invoke FLUSHDB. Never infer from
`redis_flushed=false` that the site's Redis counter was unchanged.

## Readiness

The existing 30-minute passive Cache Control readiness observer now
pins this MU bridge's SHA in addition to Cache Control, Registry v2
and Admin Bridge. Its 11 unit tests PASS; both site observations PASS
with no module drift.

The independently scheduled general Cache Coherence observer may be
DEGRADED when Redis warmup or historical unattributed flushes remain.
This is not evidence that the URL bridge failed.

## VPS artifacts

`/opt/mad4b-company-provisioning/reports/litespeed-fastcgi-coherence-20261108/`

Rollback: remove **only** `wp-content/mu-plugins/mad4b-litespeed-fastcgi-coherence-v1.php`
after authorized rollback and adjust the passive monitor pin. Existing
FastCGI Socket, LiteSpeed vendor, quote/rate engine and 1800-second TTL
are independent and must remain untouched.

The operational feature is deployed but does not retroactively prove every
possible LiteSpeed purge source or full-price/render regression.
