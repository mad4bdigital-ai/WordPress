<?php
/**
 * Standalone provider-contract regression; runs without WordPress/DB.
 * php ops/pricing-unified-checkpoints/tests/verify-native-self-package-cost-readback.php
 */
define('ABSPATH',__DIR__);
function add_action($name,$callback,$priority=10,$args=1){}
function wp_json_encode($v){return json_encode($v,JSON_UNESCAPED_SLASHES);}
function current_user_can($cap){return !empty($GLOBALS['operator']);}
function get_post_type($id){
    return $id===101?'tours-and-activities':
        (in_array($id,[201,202],true)?'tour-rates':null);
}
function get_post_status($id){
    return $id===101?'publish':(in_array($id,[201,202],true)?'draft':null);
}
function get_posts($query){
    if(($query['post_type']??'')==='tour-rates' &&
       ($query['meta_key']??'')==='related_tour_id' &&
       ($query['meta_value']??'')==='101')
        return [201,202];
    return [];
}
function get_post_meta($id,$key,$single=true){
    if($key==='related_tour_id' && in_array($id,[201,202],true))
        return '101';
    if($key==='_mad4b_package_stay_verified_room_program_links_v1')
        return ['schema'=>'1','tour_id'=>$id,'checkout_ready'=>false,
            'bindings'=>[['stage_key'=>'hotel-cairo-01','lodging_kind'=>'hotel',
                'nights'=>2,'bookable_unit_ids'=>[],
                'selected_property_id'=>0,'price_status'=>'unpriced']]];
    if($key==='_mad4b_transport_stages_v1')
        return $id===201?($GLOBALS['transport_stages']??''):
            ($id===101?[['key'=>'bogus-tour-meta-not-authoritative']]:'');
    return '';
}
function sanitize_key($s){return strtolower((string)$s);}
class WP_Error {
    private $code;
    public function __construct($code){$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
function is_wp_error($value){return $value instanceof WP_Error;}
class MAD4B_Guide_Daily_Base_Cost_V1 {
    public static function status(){return ['configured'=>true,'amounts'=>['en'=>2500]];}
}
class MAD4B_Guide_Service_Composition_V1 {
    public static function source($tour){
        return ['tour_id'=>$tour,'source_hash'=>str_repeat('a',64),
            'days'=>['day-1'=>[],'day-2'=>[]]];
    }
    public static function saved($source){
        return !empty($GLOBALS['approved_guide'])
            ?['statuses'=>['day-1'=>'full_day','day-2'=>'full_day']]
            :new WP_Error('guide_coverage_not_reviewed');
    }
    public static function guide($tour,$language){
        if(empty($GLOBALS['approved_guide']))return new WP_Error('guide_coverage_not_reviewed');
        return $GLOBALS['guide_receipt'];
    }
}
class MAD4B_Transport_Self_Pricing_Controls_V1 {
    public static function current(){return ['price_role'=>'self_cost','currency'=>'EGP'];}
    public static function source(){return ['rows'=>array_fill(0,51,['source_key'=>'example'])];}
}
class MAD4B_Transport_Distance_Time_V1 {
    public static function current(){return ['revision'=>1,'vehicle_profile_owner'=>'jetengine_taxonomy_term_meta',
        'profiles'=>[
            'limousine'=>['source'=>'pdf_proposal','per_km'=>13.53,'per_drive_min'=>4.51],
            'hiace'=>['source'=>'pdf_proposal','per_km'=>16.45,'per_drive_min'=>5.48],
            'coaster'=>['source'=>'pdf_proposal','per_km'=>27,'per_drive_min'=>9],
            'bus'=>['source'=>'pdf_proposal','per_km'=>61.6,'per_drive_min'=>20.53],
            'h1'=>['source'=>'manual_draft','per_km'=>0,'per_drive_min'=>0],
            'luxury_7_seats'=>['source'=>'manual_draft','per_km'=>0,'per_drive_min'=>0],
        ]];
    }
}
class MAD4B_Extra_Readonly_Quote_Runtime_V1 {
    public static function status(){return ['rate_eligibility_verified'=>false];}
}

require dirname(__DIR__,3).'/wp-content/mu-plugins/mad4b-package-supplier-admission-v1.php';
function check($condition,$label){
    if(!$condition){fwrite(STDERR,"FAIL: $label\n");exit(1);}
    $GLOBALS['tests']++;
}
$receipt=['contract'=>'mad4b.guide-cost-component.v1','supplier'=>'self',
    'currency'=>'EGP','amount_type'=>'internal_cost_not_sell',
    'not_price_authority'=>true,'apply_to_retail_total'=>false,
    'checkout_ready'=>false,'source_record_revision'=>1,
    'source_hash'=>str_repeat('a',64),
    'unit_cost'=>2500,'effective_guide_days'=>2,'guide_count'=>1,
    'cost'=>5000];
$receipt['cost_fingerprint']=hash('sha256',wp_json_encode($receipt));
$GLOBALS['guide_receipt']=$receipt;
$GLOBALS['operator']=true;$GLOBALS['approved_guide']=false;$GLOBALS['tests']=0;
$cls='MAD4B_Package_Supplier_Admission_V1';
check($cls::guideEvidenceReady($receipt),'valid native guide receipt');
$bad=$receipt;$bad['cost']=7000;
check(!$cls::guideEvidenceReady($bad),'reject modified guide cost');
$bad=$receipt;$bad['apply_to_retail_total']=true;
unset($bad['cost_fingerprint']);
$bad['cost_fingerprint']=hash('sha256',wp_json_encode($bad));
check(!$cls::guideEvidenceReady($bad),'reject chargeable supplier receipt');
$bad=$receipt;$bad['source_record_revision']=0;
unset($bad['cost_fingerprint']);
$bad['cost_fingerprint']=hash('sha256',wp_json_encode($bad));
check(!$cls::guideEvidenceReady($bad),'reject unreviewed guide');
$a=$cls::inspect(101,'2027-01-18','EGP',2,0,0);
check(!is_wp_error($a),'read-only package admission');
check($a['components']['guide']['source_day_count']===2,'native itinerary-day discovery');
check($a['components']['guide']['coverage_review_status']==='guide_coverage_not_reviewed','missing per-day review');
check($a['components']['guide']['company_self_cost_configured']===true,'configured guide source visible');
check($a['components']['guide']['source_cost_egp']===null,'no unapproved guide cost');
check($a['components']['transportation']['original_source_row_count']===51,'reuse original Self transport');
check(count($a['components']['transportation']['model_candidate_vehicle_keys'])===4,'reuse JetEngine profile candidates');
check(count($a['components']['transportation']['vehicle_profiles_needing_review'])===2,'empty H1 and 7 seats blocked');
check(in_array('transport_route_stage_binding_missing',$a['blockers'],true),'no invented route');
check($a['components']['transportation']['linked_tour_rate_count']===2,
      'Rate source discovered through related_tour_id, not Tour query');
check($a['components']['transportation']['explicit_route_stage_count']===0,
      'ignore unrelated stage meta on Tour itself');
check($a['components']['transportation']['stage_meta_owner']==='tour-rates' &&
      $a['components']['transportation']['relation_source']==='related_tour_id',
      'single original relationship authority');
$GLOBALS['approved_guide']=true;
$b=$cls::inspect(101,'2027-01-18','EGP',2,0,0);
check($b['components']['guide']['cost_qualified']===true,'verified guide costs recognized');
check($b['components']['guide']['source_cost_egp']===5000.0,'correct cost-only readback');
check($b['components']['guide']['included_in_customer_price']===null &&
      $b['components']['guide']['not_added_to_retail_price']===true,
      'unknown Tour Rate guide inclusion must not be double-charged');
check(!in_array('guide:guide_coverage_not_reviewed',$b['blockers'],true),'no false guide ready blocker');
check($b['checkout_ready']===false && $b['total_cost_minor']===null &&
      $b['rate_generation_ready']===false,'no selling, checkout or generation authority');
$GLOBALS['transport_stages']=[['key'=>'airport-cairo']];
$c=$cls::inspect(101,'2027-01-18','USD',2,1,1);
check($c['components']['transportation']['explicit_route_stage_count']===1,'native Tour Rate repeater stage discovered');
check($c['components']['transportation']['linked_rate_stage_inventory'][0]['rate_id']===201,
      'linked Rate identity preserved');
check($c['components']['transportation']['tour_queried_rates_inventory_only']===true,
      'Tour query remains non-authoritative inventory');
check(in_array('transport_route_cost_unapproved',$c['blockers'],true),'editorial route not priced');
check(in_array('certified_fx_receipt_missing',$c['blockers'],true),'unsupported FX remains blocked');
check(in_array('child_infant_component_costs_not_certified',$c['blockers'],true),'child/infant remains blocked');
$GLOBALS['operator']=false;
$d=$cls::inspect(101,'2027-01-18','EGP');
check(is_wp_error($d)&&$d->get_error_code()==='package_admission_forbidden','guest denied');
echo "PACKAGE_NATIVE_SELF_READBACK_TESTS_PASS ".$GLOBALS['tests']."/". $GLOBALS['tests'] ."\n";
