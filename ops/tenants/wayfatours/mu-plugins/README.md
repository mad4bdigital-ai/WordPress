# Wayfatours — tenant-owned Query Governor source

This directory preserves the Wayfatours runtime Governor as a distinct local
Git source, rather than overwriting its newer company-owned code with the
Tours.dev source version. The source and tenant share the same small,
Elementor-native generated-metadata invalidation policy.

The runtime remains at:
  /opt/wayfa-rebuild-20261102/site-copy/wp-content/mu-plugins/mad4b-render-query-governor.php

Current site versions are deliberately distinct. Before any future company
release, compare the candidate source with both native runtime and company
diff; original signed Company Code Release admission is separate.

Do not use this tenant overlay to replace the source Governor or to bypass
Original313. Real Elementor data, page settings, bookings, prices and the
company's own Query Governor/Fragment behavior still invalidate normally.
