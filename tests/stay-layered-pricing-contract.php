<?php
/**
 * Offline, non-mutating contract tests for the MU self-provider accommodation
 * source resolver. Run: php tests/stay-layered-pricing-contract.php
 */
error_reporting(E_ALL);
define('ABSPATH','/');
class WP_Error {
    private $code;
    public function __construct($code,$message='') {$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
class WP_Term {
    public $term_id;
    public $slug;
    public function __construct($id,$slug){$this->term_id=$id;$this->slug=$slug;}
}
function is_wp_error($value){return $value instanceof WP_Error;}
function add_filter($hook,$callback){return true;}
function get_woocommerce_currencies(){return ['USD'=>'Dollar','EUR'=>'Euro','GBP'=>'Pound','EGP'=>'Egyptian Pound'];}
function get_post_meta($id,$key,$single=true) {
    return $GLOBALS['meta'][(int)$id][$key]??'';
}
function get_post_type($id) {
    if(in_array((int)$id,[100,101,110],true))return 'properties';
    if((int)$id===200)return 'units';
    if(in_array((int)$id,[301,302,303],true))return 'property-rates';
    return false;
}
function get_post_status($id){return get_post_type($id)?'publish':false;}
function get_posts($args){return $GLOBALS['rates'];}
function get_the_title($id){return $GLOBALS['titles'][(int)$id]??'Real Rate';}
function get_term($id,$taxonomy) {
    return isset($GLOBALS['boards'][(int)$id])?
        new WP_Term((int)$id,$GLOBALS['boards'][(int)$id]):new WP_Error('missing_term');
}
function get_term_by($field,$value,$taxonomy) {
    foreach($GLOBALS['boards'] as $id=>$slug)
        if($slug===$value)return new WP_Term($id,$slug);
    return false;
}
function abk_entity_map() {
    return ['relation_table_suffix'=>'jet_rel_default','relation_id'=>223];
}
function abk_effective_price_units($property,$unit) {
    return ['per_person','per_room_night','per_cabin_night','per_person_night','per_stay'];
}
class MAD4B_Hotel_Meal_Base_Policy_V1 {
    public static function resolve($property,$rate=0) {
        return ['base_board'=>(int)$property===110?'full-board':'bed-breakfast'];
    }
}
$wpdb=new class {
    public $prefix='wp_';
    public function prepare($sql,...$params){return implode(':',$params);}
    public function get_var($query){return $query==='223:100:200'?1:0;}
};
$GLOBALS['boards']=[272=>'bed-breakfast',274=>'full-board',275=>'all-inclusive'];
$GLOBALS['titles']=[];
$GLOBALS['meta']=[
    100=>['prop_start_price'=>'100','prop_base_currency'=>'USD',
        'prop_direct_price_unit'=>'per_room_night','prop_direct_price_kind'=>'sell'],
    101=>[],
    110=>[],
    200=>['unit_start_price'=>'180','unit_base_currency'=>'USD',
        'unit_direct_price_unit'=>'per_room_night','unit_direct_price_kind'=>'sell'],
    301=>['prop_related_property_id'=>'100','prop_related_unit_id'=>'',
        'mad4b_rate_scope_v1'=>'property','stop_sale'=>'0',
        'valid_from'=>'2026-12-01','valid_to'=>'2027-01-31',
        'prop_board_type'=>'272','base_amount'=>'130','currency'=>'USD',
        'prop_price_unit'=>'per_room_night'],
    302=>['prop_related_property_id'=>'100','prop_related_unit_id'=>'200',
        'mad4b_rate_scope_v1'=>'unit','stop_sale'=>'0',
        'valid_from'=>'2026-12-01','valid_to'=>'2027-01-31',
        'prop_board_type'=>'272','base_amount'=>'210','currency'=>'USD',
        'prop_price_unit'=>'per_room_night'],
];
$GLOBALS['meta'][303]=$GLOBALS['meta'][302];
$GLOBALS['meta'][303]['base_amount']='250';
$GLOBALS['rates']=[];
require_once __DIR__.'/../wp-content/mu-plugins/006-mad4b-accommodation-layered-pricing.php';
function assert_eq($label,$actual,$expected) {
    if($actual!==$expected)
        throw new RuntimeException($label.' expected '.var_export($expected,true).
            ', got '.var_export($actual,true));
    $GLOBALS['checks'][]=$label;
}
function result($extra=[]) {
    return MAD4B_Stay_Layered_Pricing_V1::preview(array_merge(
        ['property_id'=>100,'unit_id'=>200,'checkin'=>'2027-01-01',
            'nights'=>2,'adults'=>2,'children'=>0],$extra));
}
function source_name($input=[]) {
    $r=result($input);
    return is_wp_error($r)?'ERROR:'.$r->get_error_code():$r['selected_source'];
}
assert_eq('unit direct above property direct',source_name(),'unit_direct');
$GLOBALS['rates']=[301,302];
assert_eq('unit rate takes priority',source_name(),'unit_rate');
assert_eq('rate contains declared meal inclusion',result()['meal_base_explicit_in_rate'],true);
assert_eq('preview remains unverified',result()['checkout_ready'],false);
assert_eq('provider always self',result()['provider_id'],'self');
assert_eq('no checkout authority',result()['quote_authorized'],false);
assert_eq('no mutation',result()['mutation_performed'],false);
$GLOBALS['meta'][302]['stop_sale']='1';
assert_eq('stop sale excludes unit rate',source_name(),'unit_direct');
$GLOBALS['meta'][302]['stop_sale']='false';
assert_eq('noncanonical false still stop sale to match native core',source_name(),'unit_direct');
$GLOBALS['meta'][302]['stop_sale']='0';
$GLOBALS['meta'][200]['unit_direct_price_kind']='cost';
assert_eq('unit cost never returned as selling price',source_name(),'unit_rate');
$GLOBALS['meta'][302]['stop_sale']='1';
assert_eq('property rate fallback after unit cost',source_name(),'property_rate');
$GLOBALS['rates']=[];
assert_eq('property direct final fallback',source_name(),'property_direct');
$GLOBALS['meta'][100]['prop_direct_price_kind']='cost';
assert_eq('cost-only must not be displayed as selling price',source_name(),null);
$GLOBALS['meta'][100]['prop_direct_price_kind']='sell';
$GLOBALS['meta'][200]['unit_direct_price_kind']='sell';
$GLOBALS['rates']=[302,303];
$GLOBALS['meta'][302]['stop_sale']='0';
assert_eq('overlapping rate rejected',source_name(),'ERROR:stay_layer_overlapping_rates');
$GLOBALS['rates']=[302];
assert_eq('checkout within rate end is accepted',
    source_name(['checkin'=>'2027-01-29','nights'=>2]),'unit_rate');
assert_eq('checkout after rate end rejected using native core rule',
    source_name(['checkin'=>'2027-01-30','nights'=>2]),'unit_direct');
$GLOBALS['meta'][302]['prop_max_adults']='2';
assert_eq('rate occupancy over limit falls back',
    source_name(['adults'=>3]),'unit_direct');
$GLOBALS['meta'][302]['prop_max_adults']='';
$GLOBALS['meta'][302]['prop_max_children']='0';
assert_eq('child capacity enforced',source_name(['children'=>1]),'unit_direct');
$GLOBALS['meta'][302]['prop_max_children']='';
assert_eq('explicit wrong board excludes rate',
    source_name(['board_type'=>'full-board']),'unit_direct');
assert_eq('base meal unaffected by requested plan',
    result(['board_type'=>'full-board'])['meal_base'],'bed-breakfast');
assert_eq('requested plan visible separately',
    result(['board_type'=>'full-board'])['requested_board'],'full-board');
assert_eq('direct price never verifies meal inclusion',
    result(['board_type'=>'full-board'])['meal_inclusion_verified'],false);
$GLOBALS['meta'][200]['unit_max_occupancy']='2';
assert_eq('hard unit capacity enforced',
    source_name(['adults'=>3]),'ERROR:stay_layer_unit_capacity_exceeded');
unset($GLOBALS['meta'][200]['unit_max_occupancy']);
$GLOBALS['meta'][302]['provider_id']='external_supplier';
assert_eq('external supplier rate cannot be attributed to self',source_name(),'unit_direct');
unset($GLOBALS['meta'][302]['provider_id']);
$GLOBALS['meta'][302]['_shared_generation_supplier_proposal']=['cost'=>10];
assert_eq('supplier proposal remains excluded',source_name(),'unit_direct');
unset($GLOBALS['meta'][302]['_shared_generation_supplier_proposal']);
$GLOBALS['meta'][302]['_mad4b_simulated_supplier_evidence']='synthetic';
assert_eq('simulated supplier evidence excluded',source_name(),'unit_direct');
unset($GLOBALS['meta'][302]['_mad4b_simulated_supplier_evidence']);
$GLOBALS['rates']=[];
$GLOBALS['meta'][200]['unit_base_currency']='ZZZ';
assert_eq('unsupported currency direct price excluded',source_name(),'property_direct');
$GLOBALS['meta'][200]['unit_base_currency']='USD';
$GLOBALS['rates']=[302];
$GLOBALS['titles'][302]='QA ONLY fake pricing fixture';
assert_eq('QA fixture excluded',source_name(),'unit_direct');
unset($GLOBALS['titles'][302]);
$GLOBALS['meta'][302]['prop_board_type']='';
assert_eq('undeclared rate meal excluded',source_name(),'unit_direct');
$GLOBALS['meta'][302]['prop_board_type']='272';
assert_eq('negative property IDs rejected',
    source_name(['property_id'=>-100]),'ERROR:stay_layer_entity_id_invalid');
assert_eq('wrong relationship rejected',
    source_name(['property_id'=>101]),'ERROR:stay_layer_unit_relation_missing');
assert_eq('invalid date rejected',
    source_name(['checkin'=>'2027-02-30']),'ERROR:stay_layer_date_invalid');
assert_eq('unknown board rejected',
    source_name(['board_type'=>'not-real']),'ERROR:stay_layer_board_invalid');
assert_eq('invalid occupancy rejected',
    source_name(['nights'=>0]),'ERROR:stay_layer_occupancy_invalid');
assert_eq('unexpected key rejected',source_name(['admin_override'=>1]),'ERROR:stay_layer_unknown_field');
$GLOBALS['rates']=[301];
assert_eq('property-wide rate selectable without unit',
    source_name(['unit_id'=>0]),'property_rate');
assert_eq('cruise meal policy preserved',
    result(['property_id'=>110,'unit_id'=>0])['meal_base'],'full-board');
echo "PASS ".count($GLOBALS['checks'])." assertions. No database or HTTP access.\n";
