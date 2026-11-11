<?php
/** Definitions only; no frontend hooks, quote calls, price writes or checkout changes. */
if (!defined('ABSPATH')) return;
require_once __DIR__.'/shared-product-core/contracts/commercial-component-shadow-v1.php';
require_once __DIR__.'/shared-product-core/contracts/unit-rate-contract-v1.php';

require_once __DIR__.'/shared-product-core/contracts/booking-v2-component-shadow-v1.php';

require_once __DIR__.'/shared-product-core/contracts/property-relation-shadow-v1.php';

require_once __DIR__.'/shared-product-core/contracts/package-readiness-shadow-v1.php';

require_once __DIR__.'/shared-product-core/contracts/intake-rate-handoff-v1.php';

require_once __DIR__.'/shared-product-core/contracts/property-rate-source-audit-v1.php';

require_once __DIR__.'/shared-product-core/contracts/accommodation-component-quote-v1.php';

require_once __DIR__.'/shared-product-core/contracts/native-package-quote-shadow-v1.php';

require_once __DIR__.'/shared-product-core/contracts/tour-stay-commercial-readiness-v1.php';
