<?php
declare(strict_types=1);
namespace {
    define('ABSPATH','/tmp/');
    $GLOBALS['test_policy']=[
        'schema'=>'mad4b.accommodation-supplier-precedence.v1',
        'tenant_url'=>'https://company.example',
        'domain'=>'accommodation','primary'=>'external_contract','secondary'=>'self',
        'fallback'=>'only_when_primary_has_no_eligible_verified_offer',
        'operation'=>'preview_only','booking_authorized'=>false,'pricing_activated'=>false,
        'self_fallback_enabled'=>true
    ];
    $GLOBALS['test_external']=[];
    $GLOBALS['test_status']=[11=>'publish',22=>'publish',33=>'draft',44=>'publish'];
    $GLOBALS['test_type']=[11=>'properties',22=>'units',33=>'units',44=>'units'];
    $GLOBALS['test_links']=['11:22'=>true,'11:33'=>true];
    $GLOBALS['test_user_allowed']=true;
    $GLOBALS['price_present']=false;
    function home_url(){return 'https://company.example';}
    function get_option($key,$default=false){
        return $key==='mad4b_stay_supplier_precedence_v1'?$GLOBALS['test_policy']:($key==='blogname'?'Test Company':$default);
    }
    function sanitize_text_field($text){return trim((string)$text);}
    function add_filter(...$args){return true;}
    function is_wp_error($value){return $value instanceof WP_Error;}
    class WP_Error {
        private string $code;
        function __construct(string $code){$this->code=$code;}
        function get_error_code(){return $this->code;}
    }
    function current_user_can($cap,...$args){return $GLOBALS['test_user_allowed'];}
    function get_post_type($id){return $GLOBALS['test_type'][(int)$id]??false;}
    function get_post_status($id){return $GLOBALS['test_status'][(int)$id]??false;}
    function abk_entity_map(){return ['relation_id'=>223,'relation_table_suffix'=>'jet_rel_default'];}
    class FakeDb {
        public string $prefix='wp_';
        function prepare($sql,...$args){return ['sql'=>$sql,'args'=>$args];}
        function get_var($query){
            $args=$query['args'];
            return (int)!empty($GLOBALS['test_links'][$args[1].':'.$args[2]]);
        }
    }
    $GLOBALS['wpdb']=new FakeDb();
    function mad4b_stay_layered_price_preview_v1($input){
        $price=$GLOBALS['price_present']?['id'=>11,'source'=>'property_direct','amount'=>120,'currency'=>'USD','price_unit'=>'per_room_night','price_kind'=>'sell']:null;
        return ['provider_id'=>'self','property_id'=>(int)$input['property_id'],
            'unit_id'=>$input['unit_id']??null,'selected_source'=>$price?'property_direct':null,
            'price'=>$price,'price_status'=>$price?'indicative':'unpriced_or_cost_only',
            'checkout_ready'=>false,'quote_authorized'=>false,
            'mutation_performed'=>false];
    }
}
namespace BookingExperience {
    function supplier_integrations(){return \MAD4B_Stay_Secondary_Self_Supplier_V1::register($GLOBALS['test_external']);}
}
namespace {
    $source=getenv('MAD4B_TEST_PLUGIN_PATH');
    if(!$source)$source=dirname(__DIR__,2).'/wp-content/mu-plugins/009-mad4b-stay-secondary-supplier.php';
    require $source;
    $c='MAD4B_Stay_Secondary_Self_Supplier_V1';
    $n=0;
    $pass=static function($condition,$label)use(&$n){
        $n++;
        if(!$condition)throw new \RuntimeException($label);
    };
    $pass($c::status()['status']==='self_fallback_active','policy active');
    $pass(array_keys(\BookingExperience\supplier_integrations())===['self'],'self registered');
    $first=$c::resolve(['property_id'=>11,'unit_id'=>22]);
    $pass(!is_wp_error($first),'published relation valid');
    $pass($first['selected_supplier']==='self'&&$first['fallback_used']===true,'self selected');
    $pass($first['price_supplier']===null&&$first['price_status']==='unpriced_or_cost_only','unpriced transparently');
    $pass($first['external_quotes_checked']===0&&$first['checkout_ready']===false,'no fake external and no checkout');
    $GLOBALS['price_present']=true;
    $price=$c::resolve(['property_id'=>11,'unit_id'=>22]);
    $pass(!is_wp_error($price)&&$price['price_supplier']==='self','native self amount routed');
    $pass($price['quote']['price']['amount']===120&&$price['checkout_ready']===false,'indicative only');
    $GLOBALS['price_present']=false;
    $draft=$c::resolve(['property_id'=>11,'unit_id'=>33]);
    $pass(!is_wp_error($draft)&&$draft['quote']['price_status']==='draft_entity_pending_review','draft blocked');
    $wrong=$c::resolve(['property_id'=>11,'unit_id'=>44]);
    $pass(is_wp_error($wrong)&&$wrong->get_error_code()==='stay_supplier_relation_mismatch','wrong property unit fails');
    $GLOBALS['test_user_allowed']=false;
    $denied=$c::resolve(['property_id'=>11]);
    $pass(is_wp_error($denied)&&$denied->get_error_code()==='stay_supplier_forbidden','unauthorized denied');
    $GLOBALS['test_user_allowed']=true;
    $GLOBALS['test_external']=['supplier1'=>['id'=>'supplier1','name'=>'External','categories'=>['accommodation'],
        'active'=>true,'marketing_authorized'=>true,'quote'=>static fn()=>['ok'=>true]]];
    $connected=$c::resolve(['property_id'=>11]);
    $pass(!is_wp_error($connected)&&$connected['selected_supplier']==='self','connected != certified offer');
    $pass($connected['external_quote_status']==='adapter_registered_but_not_price_certified','correct unverified label');
    $GLOBALS['test_policy']['self_fallback_enabled']=false;
    $disabled=$c::resolve(['property_id'=>11]);
    $pass(is_wp_error($disabled)&&$disabled->get_error_code()==='stay_supplier_fallback_not_enabled','feature flag blocks');
    echo "MAD4B_STAY_SELF_FALLBACK: {$n}/{$n} passed\n";
}
