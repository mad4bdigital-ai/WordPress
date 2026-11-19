<?php
/** No WP or DB needed: php tests/transport-source-audit-hermetic.php. */
define('ABSPATH','/fixture/');
function add_action($event,$cb){}
require_once __DIR__.'/../includes/transport-source-candidates.php';
$c='MAD4B_Transport_Source_Candidates_V1';
$s=$c::snapshot();
$rows=$c::review_rows();
$checks=array();
$checks['all_operations_51']=$s['rows_count']===51 && count($rows)===51;
$checks['all_prices_210']=$s['price_cells']===210;
$checks['city_rows']=$s['rows_by_city']===array('Cairo'=>13,'Aswan'=>15,'Luxor'=>23);
$checks['city_filter_count']=count($c::review_rows('Cairo'))===13 &&
  count($c::review_rows('Aswan'))===15 && count($c::review_rows('Luxor'))===23;
$checks['source_immutable_sha']=$s['source_sha256']==='c3bde2be4ac89f1b7b9671720b552d39646c93ea9fa5a786fe2a9bde8f1644cd';
$checks['no_currency_claim']=$s['source_currency_confirmed']===false;
$checks['no_cost_or_sale_claim']=$s['commercial_role_confirmed']===false;
$checks['no_commerce_authority']=!$s['ready_to_import']&&!$s['ready_to_publish']&&!$s['checkout_authorized'];
$checks['no_source_write']=$s['mutation_performed']===false;
$checks['proposal_coverage']=count(array_filter($rows,static fn($r)=>
    !empty($r['classification_proposal'])))===51;
$checks['proposed_only']=count(array_filter($rows,static fn($r)=>
    ($r['classification_status']??'')==='proposal_only'))===51;
$ambiguous=array_values(array_filter($rows,static fn($r)=>
    ($r['origin_city']??'')==='Cairo' && (int)($r['source_row']??0)===9));
$checks['multi_destination_not_sold_as_route']=count($ambiguous)===1 &&
    ($ambiguous[0]['classification_proposal']??'')==='other';

$checks['cairo_luxury_7_seats_header']=array_values(array_filter($rows,
    static fn($r)=>$r['origin_city']==='Cairo'&&(int)$r['source_row']===1))[0]['luxury_7_seats']==='1400';
$checks['cairo_hiace_header']=array_values(array_filter($rows,
    static fn($r)=>$r['origin_city']==='Cairo'&&(int)$r['source_row']===1))[0]['hiace']==='1600';
$checks['luxor_blank_23']=array_values(array_filter($rows,
    static fn($r)=>$r['origin_city']==='Luxor'&&(int)$r['source_row']===23))[0]['limousine']==='';
$checks['aswan_H1_blank']=array_values(array_filter($rows,
    static fn($r)=>$r['origin_city']==='Aswan'&&(int)$r['source_row']===1))[0]['luxury_7_seats']==='';
$issues=array_map(static fn($r)=>$r['issue'],$s['review']);
$checks['luxor_price_order_flagged']=count(array_filter($issues,
    static fn($v)=>$v==='vehicle_price_order_anomaly'))===1;
$checks['all_price_cells_absent_flagged']=count(array_filter($issues,
    static fn($v)=>$v==='all_prices_missing'))===1;
foreach($checks as $name=>$v)echo $name.'='.($v?'PASS':'FAIL')."\n";
echo 'TOTAL='.count(array_filter($checks)).'/'.count($checks)."\n";
exit(in_array(false,$checks,true)?1:0);
