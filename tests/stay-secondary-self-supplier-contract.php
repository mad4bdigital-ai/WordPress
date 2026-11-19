<?php
/** Hermetic policy contract for Secondary Self Supplier, no live WordPress writes. */
error_reporting(E_ALL);
define('ABSPATH','/');
class WP_Error {
    private $code;
    public function __construct($code){$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function add_filter(){return true;}
function get_option($key,$default=null){
    return $key==='mad4b_stay_supplier_precedence_v1'
        ?($GLOBALS['supplier_config']??$default)
        :($key==='blogname'?'Company One':$default);
}
function home_url(){return 'https://company-one.test';}
function sanitize_text_field($s){return trim(strip_tags((string)$s));}
function current_user_can($cap,$id=null){return $GLOBALS['authorized'];}
function get_post_status($id){return $GLOBALS['post_status'][(int)$id]??false;}
function get_post_type($id){
    if((int)$id===100)return 'properties';
    if((int)$id===200)return 'units';
    return false;
}
function abk_entity_map(){return ['relation_id'=>223,'relation_table_suffix'=>'jet_rel_default'];}
class FakeDB {
    public $prefix='wp_';
    public function prepare($sql,...$args){return $sql;}
    public function get_var($query){return 1;}
}
$wpdb=new FakeDB();
function mad4b_stay_layered_price_preview_v1($input){
    return ['contract'=>'mad4b.stay-layered-price.v1','provider_id'=>'self',
        'selected_source'=>null,'price_status'=>'unpriced_or_cost_only',
        'price'=>null,'checkout_ready'=>false,'quote_authorized'=>false,
        'mutation_performed'=>false];
}
$GLOBALS['post_status']=[100=>'publish',200=>'publish'];
$GLOBALS['authorized']=true;
$GLOBALS['supplier_config']=null;
require __DIR__.'/../wp-content/mu-plugins/009-mad4b-stay-secondary-supplier.php';
$c='MAD4B_Stay_Secondary_Self_Supplier_V1';
$checks=[];
$ok=static function($name,$passed)use(&$checks){
    if(!$passed)throw new RuntimeException('FAIL: '.$name);
    $checks[]=$name;
};
$ok('unconfigured supplier inert',$c::status()['status']==='not_configured');
$ok('unconfigured does not register self supplier',!isset($c::register([])['self']));
$cfg=[
 'schema'=>'mad4b.accommodation-supplier-precedence.v1',
 'tenant_url'=>'https://company-one.test','domain'=>'accommodation',
 'primary'=>'external_contract','secondary'=>'self',
 'fallback'=>'only_when_primary_has_no_eligible_verified_offer',
 'operation'=>'preview_only','booking_authorized'=>false,
 'pricing_activated'=>false,'self_fallback_enabled'=>false
];
$GLOBALS['supplier_config']=$cfg;
$ok('configured preview-only',$c::status()['status']==='configured_preview_only');
$ok('self has rank two',$c::status()['supplier_order'][1]['rank']===2);
$ok('external has rank one',$c::status()['supplier_order'][0]['id']==='external_contract');
$ok('no checkout even configured',$c::status()['checkout_ready']===false);
$denied=$c::resolve(['property_id'=>100,'unit_id'=>200]);
$ok('disabled fallback rejected',is_wp_error($denied)
    &&$denied->get_error_code()==='stay_supplier_fallback_not_enabled');
$GLOBALS['supplier_config']['self_fallback_enabled']=true;
$ok('self fallback explicit opt in',$c::status()['status']==='self_fallback_active');
$ok('checkout remains blocked after opt in',$c::status()['checkout_ready']===false);
$quote=$c::resolve(['property_id'=>100,'unit_id'=>200]);
$ok('authorized self preview routed',!is_wp_error($quote)
    &&$quote['selected_supplier']==='self');
$ok('previews never certify supplier price',$quote['supplier_price_verified']===false);
$ok('fallback does not authorize checkout',$quote['checkout_ready']===false);
$ok('fallback causes no writes',$quote['mutation_performed']===false);
$ok('no invented amount',$quote['quote']['price']===null);
$GLOBALS['authorized']=false;
$denied=$c::resolve(['property_id'=>100,'unit_id'=>200]);
$ok('unauthorized user refused',is_wp_error($denied)
    &&$denied->get_error_code()==='stay_supplier_forbidden');
$GLOBALS['authorized']=true;
$denied=$c::resolve(['property_id'=>100,'unit_id'=>200,'admin_override'=>true]);
$ok('unknown field refused',is_wp_error($denied));
$GLOBALS['supplier_config']['tenant_url']='https://other-company.test';
$ok('cross-tenant policy refused',$c::status()['status']==='configuration_mismatch');
$denied=$c::resolve(['property_id'=>100,'unit_id'=>200]);
$ok('tenant mismatch rejects fallback',is_wp_error($denied));
$GLOBALS['supplier_config']['tenant_url']='https://company-one.test';
$GLOBALS['supplier_config']['pricing_activated']=true;
$ok('unapproved commerce flag rejected',$c::status()['status']==='configuration_mismatch');
$GLOBALS['supplier_config']['pricing_activated']=false;
$cat=$c::register([]);
$ok('self catalog entry is not a payment quote',isset($cat['self'])
  &&$cat['self']['quote']===null);
$ok('self source is not marketing authorized',$cat['self']['marketing_authorized']===false);
echo 'PASS '.count($checks)." supplier policy assertions. No WordPress mutations.\n";
