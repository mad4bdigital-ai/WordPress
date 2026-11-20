# MAD4B automatic native Lazy recertification

Scope: Tours.dev Staging, verified EN/ES DE-USD cohorts of Query Builder
291, Listing Template 45938 and matching Elementor EN/ES parent templates.
The default is auto. Only a CURRENT valid certificate can be renewed;
other sites, markets, translations, widgets, query generations and
expired or held proofs remain on complete native SSR.

This uses the normal public WPML route as candidate and an unknown
nontracking query argument as an independently rendered SSR control.
The native Lazy engine already rejects such query arguments. There are
no privileged debug HTTP headers, no helper MU plugins, no alternate AJAX
endpoint, and no rewriting any visitor's signed request.

Chromium tests 12 real page pairs (EN/ES at 320,390,767,768,1024,1920px).
It requires complete link and visible-text SHA-256 parity, signed query
and widget native JetEngine POST HTTP 200, counts and responsive dimensions,
CLS <=0.10, no overflow or JS errors. The registrar compares all results
to the previously authorized full per-viewport certificate, site identity,
current WPML language, WCML market, Elementor document and listing CSS SHA,
Query Builder generation, current CSS epoch and fresh local run timestamps.

No first-paint or performance claim derives from this maintenance audit.
It is a proof-refresh mechanism, not a waiver of performance thresholds.
The separate Inner Progressive complete-pagination certificate is still
required before the first AJAX can be paginated.

## Operation

A systemd timer runs at 23:15 UTC daily with up to 15 minutes of jitter.
The first-party WP-CLI preflight skips Chromium while the four certified
cohorts are younger than four days. Near expiry the worker tests the
full 12-viewport browser report and ATOMICALLY extends only the existing
approved proof's timestamps, measured CLS and evidence digest using SQL
compare-and-swap. Source and company market are verified again before write.

The worker uses a file lock to prevent duplicate runs. It obeys the
existing WordPress CLI-concurrency gate with three bounded retries.
Transient Docker, browser and network errors cannot forge certificates;
source drift and certificate expiry naturally return complete native SSR.
No global HTML purge is necessary for an unchanged source and proof.

Runtime files:
 /opt/mad4b-company-provisioning/ops/native-lazy-auto-renew-20261116
Reports:
 /opt/mad4b-company-provisioning/reports/native-lazy-auto-renew-20261116

Set MAD4B_LAZY_RENEW_FORCE_VALIDATE=1 to audit all 12 cases without
modifying the WordPress registry. The actual daily unit never sets it.

If a source or proof becomes invalid/expired after an outage, this worker
deliberately cannot re-admit it; a new browser certificate is required.
Production Wayfatours is not in this rollout.
