# Source-governed Adaptive CSS company parity, 2026-11-16

Four production plugin files were read back without changing the sites from the
Tours.dev and Wayfatours PHP containers. The four current Tours.dev bytes
exactly equal the branch fix/adaptive-css-dependency-epoch-20261116 (including
the newest 7a104602f8 global CSS epoch fix) and have been imported as the
canonical plugin source under wp-content/plugins/mad4b-adaptive-asset-intelligence.
The legacy Site Control Plane bridge remains a loader only, not a second
implementation. No source files are promoted to either live site by this review.

Wayfatours is different in two file sources:
- Its Adaptive Asset Intelligence PHP file has older CSS epoch and no audited
  homepage dependency classification, and lacks newer predictive-css status.
- Its Presentation Discovery PHP file uses a timestamp Dirty marker and can
  clear a concurrent edit by a blind update rather than an options-table CAS.

The other two plugin PHP files are exact cross-site byte matches. The manifest
ops/site-company-overlays/wayfatours/adaptive-runtime/source-parity-20261116.json
records all four exact SHA256 pairs and treats the two Wayfa differences as
pending governed promotion, not a company-specific override. Any release
needs company source ownership/revision, rollback and independent Original313
signed acceptance. No live writes were made.

The canonical source preserves current WordPress staging/production admission;
this review does not reinterpret it as an automatic mandate for all sites or
weaken WP admin, profile, branding and permission checks. Future
environment-neutral provider registration is a separate policy change.

Tests: plugin contract under PHP 7.4 and 8.5; 9/9 adaptive epoch cases under
both; source SHA/Wayfa drift 2/2; Composite JetEngine gallery fixtures 9/9
under PHP 8.5 in a networkless container. Original305 historical HOLD remains.
