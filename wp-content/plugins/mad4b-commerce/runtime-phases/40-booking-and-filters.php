<?php
// Original ordered Shared Product Core source lines 116–151; eager until separately certified.
if (!defined('ABSPATH')) exit;
require_once $root.'pricing/property-layer-policy.php';

require_once $root.'adapters/package-tier-index.php';

require_once $root.'adapters/tour-rate-package-filter-options.php';


require_once $root.'adapters/tour-rate-eligible-query.php';

require_once $root.'adapters/tour-rate-ajax-context.php';

require_once $root.'adapters/tour-rate-filter-url-context.php';


require_once $root.'adapters/tour-rate-season-url-context.php';

require_once $root.'adapters/tour-rate-season-filter-options.php';
require_once $root.'adapters/tour-program-source-contract-v1.php';
require_once $root.'adapters/tour-rate-semantic-reference-v1.php';
require_once $root.'adapters/tour-rate-facets-from-query.php';
require_once $root.'adapters/pricing-legacy-template-tags.php';

require_once $root.'adapters/booking-config-validation-guard.php';

require_once $root.'adapters/cpt-product-price-compat.php';
require_once $root.'adapters/cpt-woo-rate-guard.php';
if ($mad4b_shared_core_admin_registrars) require_once $root.'admin/cpt-woo-rate-link.php';
require_once $root.'adapters/booking-preview-bridge.php';

require_once $root.'adapters/rate-actions-styles.php';

require_once $root.'adapters/rate-selection-actions.php';
require_once $root.'adapters/extra-selection-actions.php';
require_once $root.'pricing/source-resolution-policy.php';
// Read-only unified release certification; never approves checkout or deployment.
require_once $root.'adapters/commerce-suite-release-certification-v1.php';
