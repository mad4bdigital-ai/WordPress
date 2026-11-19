<?php
if (!defined('ABSPATH') || !defined('WP_CLI')) exit(1);
if (!class_exists('MAD4B_Guide_Daily_Base_Cost_V1',false)) WP_CLI::error('Guide resolver absent');
$cases=[
  ['arabic',1,1,2000,'ar'],
  ['english',1,1,2500,'en'],
  ['french',1,1,3000,'fr'],
  ['german',1,1,3000,'de'],
  ['spanish',1,1,3000,'es'],
  ['english',3,1,7500,'en'],
  ['french',2,2,12000,'fr'],
];
foreach ($cases as [$slug,$days,$guides,$expected,$code]) {
    do_action('wpml_switch_language','en');
    $actual=MAD4B_Guide_Daily_Base_Cost_V1::preview($slug,$days,$guides);
    if (is_wp_error($actual) || ($actual['base_cost']??null)!==$expected ||
        ($actual['language_code']??null)!==$code ||
        ($actual['currency']??null)!=='EGP' ||
        ($actual['apply_to_commercial_total']??true)!==false ||
        ($actual['checkout_ready']??true)!==false) {
        WP_CLI::error('Guide daily-cost preview mismatch: '.$slug);
    }
    WP_CLI::log("PASS $slug guides=$guides days=$days cost=$expected EGP");
}
$invalid=[
    MAD4B_Guide_Daily_Base_Cost_V1::preview('not-a-term',1),
    MAD4B_Guide_Daily_Base_Cost_V1::preview('english',0),
    MAD4B_Guide_Daily_Base_Cost_V1::preview('english',1,0),
    MAD4B_Guide_Daily_Base_Cost_V1::preview('qa-pricing-guide-language',1),
];
foreach ($invalid as $row) if (!is_wp_error($row)) WP_CLI::error('Invalid guide request accepted');
WP_CLI::success('GUIDE_COST_TESTS_PASSED cases='.count($cases).' rejects='.count($invalid));
