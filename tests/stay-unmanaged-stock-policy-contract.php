<?php
define('ABSPATH','/');
$GLOBALS['site']='https://company.example.test';
$GLOBALS['option']=[];
function home_url(){return $GLOBALS['site'];}
function get_option($name,$default=null){return $GLOBALS['option']?:$default;}
function add_filter($hook,$callback,$priority=10,$args=2){return true;}
require __DIR__.'/../wp-content/mu-plugins/021-mad4b-stay-unmanaged-stock.php';
$c='MAD4B_Stay_Unmanaged_Stock_Policy_V1';$count=0;
function ok($name,$b){global $count;if(!$b)throw new RuntimeException($name);$count++;}
ok('default fail closed',$c::stock_quantity_required());
$opt=[
 'schema'=>$c::SCHEMA,'domain'=>'accommodation',
 'site'=>'https://company.example.test','mode'=>'unmanaged',
 'stock_quantity_required'=>false,'enforce_occupancy_capacity'=>true,
 'honor_stop_sale'=>true,'do_not_decrement_stock'=>true,
 'requires_supplier_availability_confirmation'=>true,'checkout_ready'=>false,
];
$GLOBALS['option']=$opt;
$s=$c::status();
ok('tenant valid',$s['status']==='unmanaged_active');
ok('accommodation stock no longer required',!$c::stock_quantity_required());
ok('capacity still enforced',$s['keep_occupancy_capacity_check']===true);
ok('stop-sale still enforced',$s['honor_stop_sale']===true);
ok('supplier confirmation remains required',$s['requires_supplier_availability_confirmation']===true);
ok('no checkout authority',$s['checkout_ready']===false);
ok('accommodation filter removes stock check',$c::filter_quantity_required(true,'accommodation')===false);
ok('transport unaffected',$c::filter_quantity_required(true,'transport')===true);
ok('tour unaffected',$c::filter_quantity_required(true,'tour')===true);
ok('other domains unchanged',$c::filter_quantity_required(false,'transport')===false);
$GLOBALS['site']='https://other-company.example.test';
ok('tenant mismatch fails closed',$c::stock_quantity_required());
$GLOBALS['site']='https://company.example.test';
$GLOBALS['option']['checkout_ready']=true;
ok('booking enablement cannot masquerade as stock config',$c::stock_quantity_required());
$GLOBALS['option']=$opt;$GLOBALS['option']['honor_stop_sale']=false;
ok('stop sale cannot be bypassed',$c::stock_quantity_required());
$GLOBALS['option']=$opt;$GLOBALS['option']['enforce_occupancy_capacity']=false;
ok('cannot remove occupancy capacity checks',$c::stock_quantity_required());
$GLOBALS['option']=$opt;$GLOBALS['option']['requires_supplier_availability_confirmation']=false;
ok('no unconfirmed allocation',$c::stock_quantity_required());
echo "PASS $count scoped inventory-policy checks\n";
