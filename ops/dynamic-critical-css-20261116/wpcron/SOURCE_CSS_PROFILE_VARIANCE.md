# Tours.dev source CSS live-profile variance, 18 November 2026

Staging live sources remain authoritative. A previous Chrome browser proof
is not a license to substitute its CSS for another distinct source graph.
The independently sampled query-free HTTP HTML for EN/ES/IT includes
Elementor/JetEngine documents 34644 (Inject Attraction Archive Banner)
and 45938 (Destination/Attraction Term Listing). Its combined CSS
differs by 8,998 bytes per affected locale from the prior Chromium
certified version. DE/FR/AR match current source and Chromium.

A narrow mobile FastCGI key epoch experiment did not reconcile the
distinct profiles. The original source Nginx configuration has been
fully restored and verified byte-identical to its pre-test snapshot.

The lightweight source-css-profile-variance.py in the WordPress-owned
WP-Cron host worker compares actual HTTP CSS and Elementor fingerprints
against independently observed Chromium evidence and original manifest.
It is READ-ONLY, cannot issue a CSS certificate, acknowledge a ticket,
change CSS or flush cache. EN/ES/IT public HTTP profiles remain pending
independent native Chromium QA with fallback, and the previously valid
Chromium certificates remain separate.

The original CPU budget and shared browser lock are unchanged. A new
source observation or a newer independently certified manifest may
change this status; no fake ready decision is generated. The missing
HTTP variants have NOT been successfully recertified at this point.

## Verified host-egress Chromium and enrolled v5 — 18 Nov

Root cause of the different source CSS families was confirmed using an
actual Chromium navigation from host egress. The original Docker-bridge
browser context saw EN doc count 27 and certified CSS 1bf82405..., while
the original first-party HTTP source saw 29 documents including JetEngine
34644/45938 and CSS 6bb43551.... Host-egress Chromium reproduced
the HTTP source's same CSS and both extra Elementor/JetEngine documents,
with mobile CSS-ready 0. Host-egress EN/ES/IT CSS and Elementor document
fingerprints all matched the independent HTTP observer exactly.

Native release v5 is enrolled separately under
/opt/mad4b-company-provisioning/releases/template-aware-css-wpcron-v5-20261118.
Its run-site.sh supports MAD4B_SOURCE_CSS_NETWORK_PROFILE=host for source
Staging only. All original Chromium probe, coverage capture, optional
599px QA, and readback use the same profile. The container uses
--network host --cap-drop ALL, bounded memory/CPU/pids and
--security-opt=no-new-privileges. Wayfa retains Docker bridge mode.

The original WordPress 900-second source first_fold ticket now dispatches
immutable v5 with source host mode. All existing source QA gates,
WordPress epoch, shared browser lock, original adaptive visual checks,
SHA/Elementor original provenance, dry-run then atomic native manifest
promotion, and verified public readback remain mandatory. A successful
independent new source certification is keyed by CSS SHA and never
overwrites the valid earlier Docker bridge profile certificate.

This enrollment alone is NOT a source mobile CSS certificate. The
resource-guarded manual cycle was deferred due to reserved WP-CLI
slots; EN/ES/IT HTTP variants still require complete native browser QA.
Existing v4 release plus a timestamped host worker backup are retained.

## V6: minimum required read access for native capture

The first v5 original source host-egress capture correctly reached the
uncertified CSS and Elementor graph, but its original capture.js could
not read the CSS bytes through the read-only volume. The source
litespeed/css folder is owned by www-data and group 33 with mode 0770,
while public CSS files have mode 0644. The host-network Chromium
container had all Linux capabilities dropped and did not belong to
group 33, resulting in EACCES. The CSS is already publicly available
over HTTPS and its content remains unchanged.

Native v6 preserves --cap-drop ALL and the read-only mounted CSS assets,
but adds --group-add 33 for the source host-profile container only.
A direct read-only container proof succeeded on the real 3,161,082-byte
EN CSS file without any filesystem chmod or broader privileges.
The existing v5 runtime remains available as a rollback and its
failed capture cannot issue certificates or acknowledge WP tickets.
V6 keeps every existing original visual QA/independent SHA and
publication gate unchanged.

## Multi-profile observer correction

The original watcher initially retained only the most recent Chromium
snapshot per locale. Once v5 ran an uncertified host-egress capture, that
could hide the still-valid earlier Docker-bridge certificate in the
monitor's reporting. The corrected observer now considers up to three
independently observed CSS SHA + Elementor document-graph profiles per
language, deduplicated from actual first-party Chromium run receipts.
An old bridge certificate remains explicitly visible without ever
qualifying a different HTTP CSS source. The EN/ES/IT host-egress profile
remains pending a separately verified native browser publication.
No manifest, CSS file, cache key, WordPress ticket, or grant is mutated
by this purely observational change. Original and new regressions
cover dual-profile coexistence and stale CSS epoch refusal.
