<?php
// Original ordered Shared Product Core source lines 66–115; eager until separately certified.
if (!defined('ABSPATH')) exit;

require_once $root.'adapters/form-booking-intent.php';
// Original Fluent Form #6 + native Tour Rate selections, local read-only.
require_once $root.'adapters/presentation-form6-candidate-v1.php';
// Optional non-booking subtotal/total REST quote for existing Fluent Form #6.
require_once $root.'adapters/presentation-form6-price-quote-v1.php';

require_once $root.'adapters/tour-rate-dynamic-search.php';

require_once $root.'adapters/tour-rate-related-options.php';

require_once $root.'adapters/elementor-visual-pickers.php';
// Read-only compatibility introspection; no vendor hooks or writes.
require_once $root.'adapters/presentation-compatibility-manifest-v1.php';
require_once $root.'adapters/presentation-source-field-guard-v1.php';
// Read-only, local-only native display adapter arbitration.
require_once $root.'adapters/presentation-adapter-registry-v1.php';
// Read-only WPML/Listing context inspection for Local Lab.
require_once $root.'adapters/presentation-context-v1.php';
// One canonical catalog contract for WPML facets, signed JSF and WCML presentation.
require_once $root.'adapters/presentation-context-registry-v2.php';
require_once $root.'adapters/presentation-native-facet-discovery-v1.php';
require_once $root.'adapters/presentation-company-destination-facet-v1.php';
require_once $root.'adapters/presentation-native-consumer-policy-v1.php';
require_once $root.'adapters/presentation-native-filter-policy-v1.php';
require_once $root.'adapters/presentation-native-typed-source-registry-v1.php';
require_once $root.'adapters/presentation-native-fluent-form-sources-v1.php';
require_once $root.'adapters/presentation-native-commercial-source-registry-v2.php';
require_once $root.'adapters/presentation-native-filter-diagnostics-v1.php';
require_once $root.'adapters/presentation-query-consumer-registry-v1.php';
require_once $root.'adapters/presentation-native-jetengine-price-v1.php';
require_once $root.'adapters/presentation-tour-rate-date-facet-v1.php';
require_once $root.'adapters/presentation-blog-native-facets-v1.php';
require_once $root.'adapters/presentation-catalog-context-v1.php';
require_once $root.'adapters/presentation-native-destination-query-guard-v1.php';
require_once $root.'adapters/presentation-destination-commercial-listing-guard-v1.php';
require_once $root.'adapters/presentation-native-rate-eligibility-preview-v1.php';
require_once $root.'adapters/presentation-catalog-price-facet-v1.php';
require_once $root.'adapters/presentation-jsf-pagination-v2.php';
// Tour creation screen: type-dependent Direct vs Seed Rate UI, no rate mutations.
require_once $root.'adapters/presentation-tour-pricing-entry-v1.php';
// Authoritative saved-CPT seed -> existing native Draft Rate Generator.
require_once $root.'adapters/tour-rate-seed-bridge-v1.php';
// Native Tour Transportation display mode, taxonomy and signed Listing context.
require_once $root.'adapters/presentation-transport-context-v1.php';
// Read-only unified discovery search presentation: WP Posts OR Taxonomy Terms.
require_once $root.'adapters/presentation-discovery-search-v1.php';
require_once $root.'adapters/presentation-destination-term-v1.php';
require_once $root.'adapters/presentation-taxonomy-archive-v1.php';

