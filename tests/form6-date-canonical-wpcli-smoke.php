<?php
/** Read-only WP-CLI test after bootstrap; never inserts Form entries or orders. */
defined('ABSPATH') || exit;
require_once WPMU_PLUGIN_DIR.'/shared-product-core/adapters/presentation-form6-date-normalization-v1.php';
$cases=[
 ['10-Dec-26','2026-12-10',true],
 ['10-Dec-2026','2026-12-10',true],
 ['2026-12-10','2026-12-10',true],
 ['10 December 2026','2026-12-10',true],
 ['December 10, 2026','2026-12-10',true],
 ['Dec 10, 2026','2026-12-10',true],
 ['2026/12/10','2026-12-10',true],
 ['2026.12.10','2026-12-10',true],
 ['٢٠٢٦-١٢-١٠','2026-12-10',true],
 ['2026-12-10T14:30:00+02:00','2026-12-10',true],
 ['2026-12-10 12:45','2026-12-10',true],
 ['15/12/2026','2026-12-15',true],
 ['12/15/2026','2026-12-15',true],
 ['2026-12-11','2026-12-10',false],
 ['2026-12-10T30:45:00Z','2026-12-10',false],
 ['2026-02-30','2026-02-28',false],
 ['2030-02-29','2030-02-28',false],
 ['2026/12/10','2026-12-11',false],
 ['11-Dec-26','2026-12-10',false]
];
$format=shared_form6_date_format_v1(6,'datetime');
if($format==='d-M-y'){
 $cases[]=['10/12/2026','2026-12-10',false];
 $cases[]=['12/10/2026','2026-12-10',false];
}
$pass=0;
foreach($cases as [$raw,$day,$expected]){
 $actual=shared_form6_dates_equal_v1($raw,$day,6,'datetime');
 if($actual!==$expected){
  echo 'FAIL '.wp_json_encode($raw).' '.$day.' actual='.(int)$actual.' expected='.(int)$expected."\n";
  exit(3);
 }
 $pass++;
}
echo 'CANONICAL_DATE_PASS='.$pass.' FORMAT='.$format."\n";
