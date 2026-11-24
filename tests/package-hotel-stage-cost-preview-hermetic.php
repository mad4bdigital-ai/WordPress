<?php
define('ABSPATH','/');$done=0;
function add_filter(...$a){}
function get_posts($args){return $GLOBALS['rates']??[];}
function get_post_meta($id,$key,$single=true){return $GLOBALS['meta'][$id][$key]??'';}
function get_post_status($id){return $GLOBALS['status'][$id]??'publish';}
function get_the_title($id){return $GLOBALS['titles'][$id]??'Source';}
require __DIR__.'/../wp-content/mu-plugins/mad4b-package-hotel-stage-cost-preview-v1.php';
$cls='MAD4B_Package_Hotel_Stage_Cost_Preview_V1';
function ok($yes,$label){$GLOBALS['done']++;if(!$yes)throw new RuntimeException('FAIL '.$label);}
$x=$cls::math('per_room_night',120,3,1,2);
ok($x['amount']===360.0&&$x['factor']===3,'three room nights 120 = 360');
$x=$cls::math('per_room_night',120,3,2,4);ok($x['amount']===720.0,'two rooms three nights = 720');
$x=$cls::math('per_person_night',120,3,1,2);ok($x['amount']===720.0,'per-person-night is multiplied by adults and nights');
$x=$cls::math('per_person',120,3,1,2);ok($x['amount']===null,'ambiguous per-person not treated as nightly');
$x=$cls::math('per_stay',120,3,1,2);ok($x['amount']===null,'per-stay is not nightly');
$x=$cls::math('per_room_night',120,3,1,2,1);ok($x['amount']===null,'child rate requires separate policy');
$x=$cls::math('per_room_night',-5,3,1,2);ok($x['amount']===null,'negative cost rejected');
$x=$cls::math('per_room_night',120,0,1,2);ok($x['amount']===null,'zero nights rejected');
$rates=[201,202];$status=[201=>'publish',202=>'publish'];
$meta=[201=>['prop_related_property_id'=>100,'prop_related_unit_id'=>0,
 'mad4b_rate_scope_v1'=>'property','mad4b_rate_price_kind_v1'=>'cost',
 'currency'=>'USD','base_amount'=>120,'prop_price_unit'=>'per_room_night',
 'valid_from'=>'2027-01-01','valid_to'=>'2027-12-31','stop_sale'=>'1'],
 202=>['prop_related_property_id'=>100,'prop_related_unit_id'=>101,
 'mad4b_rate_scope_v1'=>'unit','mad4b_rate_price_kind_v1'=>'cost',
 'currency'=>'USD','base_amount'=>150,'prop_price_unit'=>'per_room_night',
 'valid_from'=>'2027-01-01','valid_to'=>'2027-12-31','stop_sale'=>'0']];
$method=new ReflectionMethod($cls,'sourceRate');
$r=$method->invoke(null,100,0,'2027-02-01','2027-02-03','USD');
ok($r['rate_id']===201&&$r['scope']==='property','Standard Property Cost Rate with no Unit');
ok($r['stop_sale']===true && $r['supplier_cost_receipt_verified']===false,'Stop Sale reference never approved');
$r=$method->invoke(null,100,101,'2027-02-01','2027-02-03','USD');
ok($r['rate_id']===202&&$r['scope']==='unit','specific Unit wins');
$meta[202]['mad4b_rate_price_kind_v1']='sell';
$r=$method->invoke(null,100,101,'2027-02-01','2027-02-03','USD');
ok($r['rate_id']===201,'retail Unit price not used as supplier cost');
$r=$method->invoke(null,100,0,'2027-02-01','2027-02-03','EUR');
ok($r['rate_id']===null,'currency mismatch does not convert FX');
$r=$method->invoke(null,100,0,'2026-11-01','2026-11-03','USD');
ok($r['rate_id']===null,'out-of-window price held');
$meta[202]['mad4b_rate_price_kind_v1']='cost';$meta[202]['prop_related_unit_id']=0;$meta[202]['mad4b_rate_scope_v1']='property';
$r=$method->invoke(null,100,0,'2027-02-01','2027-02-03','USD');
ok($r['status']==='ambiguous_property_rates','ambiguous same-scope costs blocked');
$meta[202]['prop_related_unit_id']=101;$meta[202]['mad4b_rate_scope_v1']='unit';
$status[201]='draft';$r=$method->invoke(null,100,0,'2027-02-01','2027-02-03','USD');
ok($r['rate_id']===null,'Draft supplier cost held');
echo 'PASS '.$done.'/'.$done.' stage cost math + original Rate source eligibility'.PHP_EOL;
