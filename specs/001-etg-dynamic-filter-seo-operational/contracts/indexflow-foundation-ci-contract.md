# IndexFlow Foundation CI Contract

The Foundation test surface is additive to the existing ETG Alpha13/vendor CI. Vendor certification jobs remain in place.

## Core matrix

Run on PHP 7.4 and PHP 8.3:

- PHP syntax validation for the complete plugin source;
- `indexflow-configuration-smoke.php`;
- `indexflow-legacy-profile-compat-smoke.php`;
- `indexflow-semantic-acceptance-smoke.php`;
- `indexflow-browser-acceptance-smoke.php`;
- `indexflow-foundation-architecture-smoke.php`.

## Browser/Core JavaScript

Run on Node 20:

- syntax-check `assets/js/browser-acceptance-observer.js`;
- execute `indexflow-browser-observer-smoke.js`.

## Existing certification surfaces

The existing ETG operational and vendor workflows remain authoritative for legacy ETG compatibility, JetEngine, JetSmartFilters, Elementor, Rank Math, WPML and package/provenance certification. Foundation CI does not replace those jobs.

A new exact HEAD invalidates previous exact-head CI evidence. Previous Alpha13 green runs remain historical evidence only.
