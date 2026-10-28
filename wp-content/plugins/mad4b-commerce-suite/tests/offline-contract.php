<?php
/** Source-only tests: no WordPress install or database required. */
error_reporting(E_ALL);
define('ABSPATH', __DIR__);
require __DIR__ . '/../includes/module-registry.php';
$passed = 0;
function must($condition, string $name): void {
    global $passed;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$name}\n");
        exit(1);
    }
    $passed++;
}

must(MAD4B_Commerce_Suite_Registry::CONTRACT === 'mad4b.commerce.modules.v1', 'contract version');
must(MAD4B_Commerce_Suite_Registry::normalize_desired('invalid') === [], 'non-array fails closed');
must(MAD4B_Commerce_Suite_Registry::normalize_desired(['unknown']) === [], 'unknown capability rejected');
must(
    MAD4B_Commerce_Suite_Registry::normalize_desired(['woocommerce','woocommerce','shared_product_core','unknown'])
      === ['shared_product_core'],
    'dedupe allowlist and keep external vendors read-only'
);
$empty = MAD4B_Commerce_Suite_Registry::snapshot([]);
must($empty['runtime_mutation_performed'] === false, 'observation does not mutate');
must($empty['release_certified'] === false, 'observation does not certify');
must($empty['commercial_authority_changed'] === false, 'observation does not reassign pricing authority');
must($empty['modules']['shared_product_core']['runtime_detected'] === false, 'no phantom product core');
must(
    in_array('woocommerce', $empty['modules']['shared_product_core']['missing_dependencies'], true),
    'woo prerequisite enforced'
);
must(
    in_array('jetengine', $empty['modules']['shared_product_core']['missing_dependencies'], true),
    'jetengine prerequisite enforced'
);
function make_test_engines_available(): void {
    class WooCommerce {}
    class Jet_Engine {}
    class Shared_Product_Date_Service {}
}
make_test_engines_available();
$loaded = MAD4B_Commerce_Suite_Registry::snapshot(['shared_product_core']);
must($loaded['modules']['shared_product_core']['runtime_detected'] === true, 'loaded core observed');
must($loaded['modules']['shared_product_core']['missing_dependencies'] === [], 'required modules observed');
must($loaded['modules']['shared_product_core']['desired'] === true, 'desired is recorded');
must($loaded['modules']['shared_product_core']['activation_authorized'] === false, 'enrollment cannot authorize');
must($loaded['modules']['shared_product_core']['activation_performed'] === false, 'enrollment cannot activate');
must($loaded['modules']['pricing_source_registry']['runtime_detected'] === false, 'independent registry not invented');
must($loaded['modules']['woocommerce']['desired'] === false, 'dependency does not self-enroll');
must($loaded['modules']['woocommerce']['enrollable'] === false, 'vendor cannot be enrolled');
must($loaded['modules']['pricing_source_registry']['enrollable'] === true, 'owned registry can be desired');
foreach (['cpt_product_adapter','rate_pricing','scheduling','booking_cart','financial_ledger','dynamic_presentation','form_pricing_bridge'] as $engine) {
    must(array_key_exists($engine, $loaded['modules']), 'concrete engine inventory: ' . $engine);
    must($loaded['modules'][$engine]['activation_performed'] === false, 'engine not activated: ' . $engine);
}
must(count($loaded['modules']) === count(MAD4B_Commerce_Suite_Registry::definitions()), 'complete known module set');
echo "COMMERCE_SUITE_CONTRACT_PASS {$passed}/{$passed} php=" . PHP_VERSION . "\n";
