<?php
define('ABSPATH', __DIR__);
require __DIR__ . '/../includes/module-registry.php';
require __DIR__ . '/../includes/readiness.php';
$observed = MAD4B_Commerce_Suite_Registry::snapshot(['shared_product_core']);
$result = MAD4B_Commerce_Readiness::inspect($observed);
assert($result['activation_authorized'] === false);
assert($result['activation_executed'] === false);
$module = $result['modules']['shared_product_core'];
if ($module['blockers'] === [] || $module['activation_executed'] !== false) {
    throw new RuntimeException('Readiness must not activate modules');
}
if (isset($result['modules']['woocommerce'])) {
    throw new RuntimeException('External dependencies are not enrollment modules');
}
echo "COMMERCE_READINESS_PASS\n";
