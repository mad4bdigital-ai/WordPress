# Automatic native Lazy certificate admission — Tours.dev scoped

The daily discovery timer discovers public WPML page and nested Elementor
listing contexts, records first-party WCML country/currency, measures SSR
at mobile and desktop widths, and orders uncertified candidates. The new
auto-admission timer processes up to three cases daily. Previously certified
sources invalidated by query changes take priority over new listings.

For every case: validate source/local Git SHA and discovery freshness; use a
15-minute hashed-token staging-only browser probe bound to route, language,
WCML market, listing, Elementor document, CSS epoch and query generation;
compare native SSR and signed JetEngine Ajax at seven actual Chromium sizes
(320,390,767,768,1024,1440,1920). Ordered item text and links, counts,
signed native response, responsive footprint, CLS <=0.10, absence of
JavaScript errors and FCP no worse than +20% are required. The probe is
removed before any certificate write. The issuer re-discovers the exact
source, checks every piece of evidence and atomically updates both responsive
proofs via DB advisory lock + compare-and-swap, then checks the native
runtime gate. Any failure keeps the existing native SSR behavior.

The old seven-day proof renewer stays active for already valid certificates.
The new worker covers invalidated or newly eligible contexts automatically.
It never alters the independent inner pagination safety gate.

This rollout is restricted to Tours.dev Staging. Wayfatours Production uses
different engine versions and is left unchanged. EUR/EGP/GBP are configured
but unproven; the worker must observe a real first-party WCML context rather
than synthesize a cookie or visitor header.

## Source-generation churn safety

The daily discovery inventory is a work queue, never the source of an
approval. Each candidate is rescanned from its current WPML page and
its two SSR baseline viewport heights are freshly remeasured immediately
before creating a new 15-minute browser probe. The seven signed QA pairs
then attest this new source revision. Any further concurrent term,
relation or company-policy update still causes a failed issuer check and
keeps complete SSR; a later run retries automatically. Neither a stale
certificate nor a post-edit Query Builder version is rewritten by hand.

## Global CSS epoch rollover

A complete seven-viewport signed browser certificate can independently
admit its current Elementor/CSS epoch. If the global CSS generation has
changed since the previous registry was created, the issuer rotates the
registry epoch within its own locked CAS transaction, marks **all old
locale/currency/layout entries HOLD**, and releases only the cohort that
passed current seven-viewport QA. This prevents incorrectly reviving old
proofs on a new CSS epoch. Other cohorts are queued and audited in later
runs; until then their visitors receive complete SSR.

## Durable source-quiescence gate (2026-11-16)

During live deployment, an editorial batch changed all translated Cairo
tour pages. The global CSS source revision advanced from 125 through 127
while the browser cohort was being tested, invalidating previous proofs
even though the separate destinations term list content did not change.
The source change is real; it must never be silenced or forged.

The admission worker now records the current WP CSS epoch and the latest
first-party home-impact job time from Adaptive Asset Intelligence. An
unseen or changed epoch is checked against the trusted timestamp of its
latest home-impact update (conservative new-observation fallback when no
trusted event exists). The ten-minute source quiet gate applies before QA. The worker
makes no WordPress writes and no browser/probe requests while the source
is changing. It rechecks every thirty minutes with 0-5min jitter, never
concurrently with its own discovery browser. In each run it skips any
already native-admitted cohort using the current live source/market/CSS
identity, avoiding redundant expensive browser tests.

The exact existing signed SSR/AJAX seven-viewport and atomic readback
checks remain mandatory. Persistent source churn leaves the listing
in safe SSR, never silently extends a stale certificate. This gate is
site-scoped to Tours.dev; Wayfatours remains on its own newer runtime.

Critical CSS is a separate certification track. Its public header may
continue reporting Ready=0 because its manifest/source and 768px LCP
evidence are different. Do not forcibly promote that critical-CSS
manifest or mark the whole performance rollout complete merely because
Lazy proof renewal succeeds.
