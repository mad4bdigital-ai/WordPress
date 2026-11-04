<?php
// Original ordered Shared Product Core source lines 6–27; eager until separately certified.
if (!defined('ABSPATH')) exit;
require_once $root.'core.php';
require_once $root.'contracts/field-shape-contract-v1.php';
require_once $root.'contracts/primary-tour-query-context-v1.php';
require_once $root.'scheduling/policy-settings.php';
require_once $root.'scheduling/advance-notice.php';
require_once $root.'scheduling/program-calendar.php';
require_once $root.'pricing/page-options-provider.php';
require_once $root.'pricing/jetengine-policy-provider.php';
require_once $root.'pricing/strategy-registry.php';
require_once $root.'pricing/strategy-evaluators.php';
require_once $root.'pricing/unit-resolver.php';
require_once $root.'pricing/booking-addons.php';
require_once $root.'pricing/shared-extra-unit-v1.php';
require_once $root.'pricing/booking-modifiers.php';
require_once $root.'pricing/composite-quote.php';
require_once $root.'pricing/payment-offers-visa.php';
// JetEngine Options Page owns financial-policy administration.
require_once $root.'pricing/visa-matrix.php';
require_once $root.'pricing/dynamic-option-sources.php';
require_once $root.'pricing/gateway-availability.php';
require_once $root.'pricing/travel-scope.php';
require_once $root.'pricing/tour-origin-options.php';
