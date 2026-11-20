<?php
/** Hermetic self-provider commercial readiness contract; no WordPress/SQL writes. */
error_reporting(E_ALL);
define('ABSPATH','/');
define('ARRAY_A','ARRAY_A');
class WP_Error {
    private $code;
    public function __construct($code){$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function add_action(){return true;}
function add_filter(){return true;}
function current_user_can($cap,$id=null){return $GLOBALS['allowed'];}
function get_post_status($id){
    return $GLOBALS['status'][(int)$id]??false;
}
function get_post_meta($id,$key,$single=true){
    return $GLOBALS['meta'][(int)$id][$key]??'';
}
function get_post_thumbnail_id($id){
    return (int)($GLOBALS['thumbnail'][(int)$id]??0);
}
function get_the_title($id){return $GLOBALS['titles'][(int)$id]??'Unknown';}
function get_edit_post_link($id,$context=''){return 'https://demo.test/wp-admin/post.php?post='.$id.'&action=edit';}
function get_woocommerce_currencies(){return ['USD'=>'Dollar','EUR'=>'Euro','EGP'=>'Pound'];}
function get_post_type($id){return in_array((int)$id,[200,201,202],true)?'units':'properties';}
function wp_json_encode($value){return json_encode($value);}
function home_url(){return 'https://demo.test';}
class WP_Post {public $ID;public function __construct($id){$this->ID=$id;}}
function update_meta_cache($type,$ids){return true;}
function get_posts($args){
    if(($args['post_type']??'')==='units')return [new WP_Post(200),new WP_Post(201),new WP_Post(202)];
    if(($args['post_type']??'')==='properties')return [new WP_Post(100),new WP_Post(101)];
    return [];
}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function get_option($name,$default=null){
    if($name==='mad4b_stay_inventory_sales_policy_v1')
        return $GLOBALS['stock_policy']??$default;
    return $default;
}
function absint($value){return abs((int)$value);}
function admin_url($value){return 'https://demo.test/wp-admin/'.$value;}
function plugin_dir_url($path){return 'https://demo.test/wp-content/mu-plugins/';}
function wp_create_nonce($action){return 'fake_nonce';}
function wp_enqueue_style($handle,$url,$deps,$version){$GLOBALS['styles'][]=$handle;}
function wp_enqueue_script($handle,$url,$deps,$version,$footer){$GLOBALS['scripts'][]=$handle;}
function wp_add_inline_script($handle,$src,$position){$GLOBALS['inline'][]=$src;}
function mad4b_stay_layered_price_preview_v1($payload){
    return ['provider_id'=>'self','selected_source'=>'property_direct',
        'price'=>['amount'=>100,'currency'=>'USD'],'checkin'=>$payload['checkin']??null,
        'checkout_ready'=>false,'mutation_performed'=>false];
}
class MAD4B_Stay_Secondary_Self_Supplier_V1 {
    public static $active=false;
    public static function status(){
        return ['status'=>self::$active?'self_fallback_active':'configured_preview_only'];
    }
    public static function resolve(array $input) {
        if(!self::$active)return new WP_Error('stay_supplier_fallback_not_enabled');
        return ['quote'=>mad4b_stay_layered_price_preview_v1($input)];
    }
}
class FakeDB {
    public $prefix='wp_';
    public function prepare($sql,...$args){return $sql;}
    public function get_results($sql,$style=null){return [
        ['property_id'=>'100','unit_id'=>'200'],
        ['property_id'=>'101','unit_id'=>'201'],
        ['property_id'=>'100','unit_id'=>'202']
    ];}
}
$wpdb=new FakeDB();
function abk_entity_map(){return ['relation_id'=>223,'relation_table_suffix'=>'jet_rel_default'];}
$GLOBALS['allowed']=true;
$GLOBALS['status']=[100=>'publish',101=>'draft',200=>'draft',201=>'draft',202=>'publish'];
$GLOBALS['titles']=[
    100=>'Good Hotel',101=>'Incomplete Hotel',
    200=>'Standard Room',201=>'QA ONLY Test Cabin',202=>'Luxury Suite'
];
$GLOBALS['thumbnail']=[202=>20];
$GLOBALS['meta']=[
    100=>['prop_start_price'=>'120','prop_base_currency'=>'USD',
        'prop_direct_price_unit'=>'per_room_night','prop_direct_price_kind'=>'sell'],
    101=>[],
    200=>['unit_kind'=>'room','unit_readiness'=>'pending_review',
        'unit_available'=>'false'],
    201=>['unit_kind'=>'cabin','unit_readiness'=>'pending_review',
        'unit_available'=>'false'],
    202=>['unit_kind'=>'suite','unit_max_occupancy'=>'3',
        'unit_inventory_quantity'=>'2','unit_readiness'=>'validated',
        'unit_available'=>'true','unit_start_price'=>'165','unit_base_currency'=>'USD',
        'unit_direct_price_unit'=>'per_room_night','unit_direct_price_kind'=>'sell']
];
require __DIR__.'/../wp-content/mu-plugins/021-mad4b-stay-unmanaged-stock.php';
require __DIR__.'/../wp-content/mu-plugins/020-mad4b-stay-commercial-readiness.php';
$c='MAD4B_Stay_Commercial_Readiness_V1';
$checks=[];
$assert=static function($name,$passed) use(&$checks) {
    if(!$passed)throw new RuntimeException('FAIL: '.$name);
    $checks[]=$name;
};
$a=$c::evaluate(200,[100]);
$assert('review draft not eligible for commercial use',!$a['checkout_ready']);
$assert('draft is attached to published Property',$a['property_status']==='publish');
$assert('missing images detected',in_array('unit_images_missing',$a['catalog_blockers'],true));
$assert('missing occupancy detected',in_array('occupancy_not_verified',$a['catalog_blockers'],true));
$assert('missing physical inventory detected under legacy mode',
    in_array('physical_inventory_unverified',$a['catalog_blockers'],true));
$GLOBALS['stock_policy']=[
 'schema'=>'mad4b.stay-inventory-sales-policy.v1','domain'=>'accommodation',
 'site'=>'https://demo.test','mode'=>'unmanaged','stock_quantity_required'=>false,
 'enforce_occupancy_capacity'=>true,'honor_stop_sale'=>true,
 'do_not_decrement_stock'=>true,'requires_supplier_availability_confirmation'=>true,
 'checkout_ready'=>false
];
$noStock=$c::evaluate(200,[100]);
$assert('no stock barrier for accommodation',
    !in_array('physical_inventory_unverified',$noStock['catalog_blockers'],true));
$assert('guest occupancy stays required',
    in_array('occupancy_not_verified',$noStock['catalog_blockers'],true));
$assert('no stock policy grants checkout',$noStock['checkout_ready']===false);
$assert('pending unit not validated',in_array('unit_readiness_not_validated',$a['catalog_blockers'],true));
$assert('parent direct indicative price recognized',$a['property_direct']['sell_configured']);
$b=$c::evaluate(201,[101]);
$assert('QA draft excluded',$b['qa_only']);
$assert('draft Property blocked',in_array('parent_property_unpublished',$b['catalog_blockers'],true));
$assert('QA blocker explicit',in_array('qa_or_test_unit',$b['catalog_blockers'],true));
$ready=$c::evaluate(202,[100],true);
$assert('verified Unit editorial ready',$ready['catalog_ready_for_review']);
$assert('verified Unit still not checkout-ready',$ready['checkout_ready']===false);
$assert('unit direct sale terms validated',$ready['unit_direct']['sell_configured']);
$assert('unit edit uses native link',strpos($ready['edit_unit_url'],'post.php')!==false);
$candidate=$c::evaluate(202,[100,101]);
$assert('multiple relation blocked',in_array('property_relation_unverified',$candidate['catalog_blockers'],true));
// A visually covered Unit is not a certified exact-room image.
$GLOBALS['thumbnail'][202]=77;
$GLOBALS['meta'][202]['unit_gellery']='77';
$GLOBALS['meta'][202]['_mad4b_stay_visual_coverage_v1']=[
 'schema'=>'mad4b.stay-relaxed-visual-coverage.v1','unit_id'=>202,
 'attachment_id'=>77,'strategy'=>'shared_official_asset_across_room_categories',
 'exact_room_match_unverified'=>true,'rights_independently_verified'=>false
];
$visual=$c::evaluate(202,[100]);
$assert('relaxed shared photo recognized as visual-only',
    $visual['unit_media_quality_status']==='relaxed_visual_only');
$assert('relaxed category preserved',
    $visual['unit_media_visual_mode']==='shared_official_asset_across_room_categories');
$assert('relaxed visual photo never grants editorial readiness',
    in_array('relaxed_visual_exact_room_unverified',$visual['catalog_blockers'],true)
    && !$visual['catalog_ready_for_review']);
$assert('relaxed visual photo never grants checkout',
    $visual['checkout_ready']===false);
$visualAudit=$c::audit(['scope'=>'relaxed_images']);
$assert('scoped visual filter returns one Unit',
    $visualAudit['found']===1 && $visualAudit['items'][0]['unit_id']===202
    && $visualAudit['summary']['relaxed_visual_coverage']===1);
$GLOBALS['meta'][202]['_mad4b_stay_visual_coverage_v1']['strategy']='partner_nearest_room_with_pool_view';
$partner=$c::evaluate(202,[100]);
$assert('partner photo described as provisional',
    $partner['unit_media_visual_mode']==='partner_nearest_room_with_pool_view');
unset($GLOBALS['meta'][202]['_mad4b_stay_visual_coverage_v1'],
    $GLOBALS['meta'][202]['unit_gellery']);
$GLOBALS['thumbnail'][202]=20;
$restored=$c::evaluate(202,[100]);
$assert('original standard image mode unaffected',
    $restored['unit_media_quality_status']==='standard_or_unclassified'
    && $restored['catalog_ready_for_review']);
$GLOBALS['allowed']=false;
$denied=$c::audit(['scope'=>'all']);
$assert('unprivileged audit refused',is_wp_error($denied)
    &&$denied->get_error_code()==='stay_readiness_forbidden');
$denied=$c::handoff(['property_id'=>100,'unit_id'=>202]);
$assert('unprivileged handoff refused',is_wp_error($denied));
$GLOBALS['allowed']=true;
$all=$c::audit(['scope'=>'all']);
$assert('all units counted',$all['summary']['total']===3);
$assert('no editorial auto publish',$all['read_only']===true);
$assert('zero commerce ready',$all['summary']['commercially_ready']===0);
$assert('pagination bounded',count($all['items'])===3);
$qa=$c::audit(['scope'=>'qa']);
$assert('QA filter works',$qa['found']===1 && $qa['items'][0]['unit_id']===201);
$priority=$c::audit(['scope'=>'priority']);
$assert('priority draft from published Property',$priority['found']===1
   &&$priority['items'][0]['unit_id']===200);
$draft=$c::audit(['scope'=>'draft_parent']);
$assert('draft-parent filtered',$draft['found']===1);
$invalid=$c::audit(['scope'=>'sql_injection']);
$assert('unknown scopes refused',is_wp_error($invalid));
$handoff=$c::handoff(['property_id'=>100,'unit_id'=>202,'checkin'=>'2027-01-01']);
$assert('disabled self fallback rejected',
  $handoff['reason']==='stay_supplier_fallback_not_enabled');
MAD4B_Stay_Secondary_Self_Supplier_V1::$active=true;
$handoff=$c::handoff(['property_id'=>100,'unit_id'=>202,'checkin'=>'2027-01-01']);
$assert('direct price never auto converts to a native booked rate',
  $handoff['reason']==='native_published_unit_rate_required');
$assert('no checkout from direct price',$handoff['checkout_ready']===false);
$assert('handoff preserves self provider',$handoff['provider']==='self');
$assert('handoff has no mutations',$handoff['mutation_performed']===false);
echo 'PASS '.count($checks)." self-provider readiness invariants, no database mutation.\n";
