<?php
declare(strict_types=1);
define('ABSPATH', __DIR__);
// Minimal WordPress primitive for an offline, database-free pure policy test.
if (!function_exists('absint')) { function absint($v):int {return abs((int)$v);} }
require_once __DIR__ . '/../payload/wordpress/mu-plugins/shared-product-core/adapters/presentation-form6-occupancy-policy-v1.php';
$tests = [
 ['single rate-owned inquiry zero nights','simple_or', [36117], 'inquiry', 0, 'none', 'rate_owned'],
 ['single included requires verified catalog','simple_or', [36117], 'included', 0, 'verified_catalog_only', 'rate_owned'],
 ['single additional requires verified catalog','simple_or', [36117], 'additional', 0, 'verified_catalog_only', 'rate_owned'],
 ['alternative properties remain explicit','simple_or', [36117,36239], 'inquiry', 0, 'verified_catalog_only', 'traveler'],
 ['dated stays remain explicit','simple_or', [36117], 'inquiry', 3, 'verified_catalog_only', 'rate_owned'],
 ['multi stage remains explicit','multi_stage', [36117], 'inquiry', 0, 'verified_catalog_only', 'rate_owned'],
 ['no listed property does not invent selector','simple_or', [], 'inquiry', 0, 'verified_catalog_only', 'none'],
];
foreach ($tests as [$label,$mode,$ids,$policy,$nights,$detail,$selector]) {
 $actual=shared_form6_stay_ui_contract_v1($mode,[
   'properties'=>$ids,'price_policy'=>$policy,'nights'=>$nights]);
 if ($actual['contract'] !== 'shared.form6.stay-ui.v1'
   || $actual['detail_controls'] !== $detail
   || $actual['property_selector'] !== $selector) {
   fwrite(STDERR,"FAIL {$label}: ".json_encode($actual)."\n");exit(1);
 }
 echo "PASS {$label}\n";
}
echo "FORM6_PHP_POLICY_MATRIX_7_7_PASS\n";
