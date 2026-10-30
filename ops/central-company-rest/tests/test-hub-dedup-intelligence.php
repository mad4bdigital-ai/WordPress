<?php
/**
 * Isolated tests for generic + specialist Intelligence Dedup.
 * Run: php tests/test-hub-dedup-intelligence.php
 * No database or network writes.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH','/test/');
$GLOBALS['user_can']=true;
$GLOBALS['test_posts']=[];
$GLOBALS['test_meta']=[];
$GLOBALS['writes']=0;
class WP_Error {
    private $code;
    function __construct($code,$message,$data=[]) {$this->code=$code;}
    function get_error_code(){return $this->code;}
}
class MAD4B_Hub_Source_Reuse_Gate_V1 {}
class MAD4B_Central_Company_REST_V1 {
    const COMPANIES=['tourism-almallahgroup'=>[],'another-company'=>[]];
}
function current_user_can($cap){return $GLOBALS['user_can'] && $cap==='manage_options';}
function wp_get_environment_type(){return 'staging';}
function home_url($part='/'){return 'https://tours.dev.mad4b.com/';}
function wp_parse_url($v,$part){return parse_url($v,$part);}
function is_wp_error($a){return $a instanceof WP_Error;}
function post_type_exists($type){return in_array($type,['mad4b_hub_content','mad4b_hub_nile'],true);}
function get_posts($options) {
    $allowed=(array)$options['post_status'];
    return array_keys(array_filter($GLOBALS['test_posts'],static function($p)use($options,$allowed){
        return $p->post_type===$options['post_type'] && in_array($p->post_status,$allowed,true);
    }));
}
function get_post($id){return $GLOBALS['test_posts'][$id]??null;}
function get_post_meta($id,$key,$single=true){return $GLOBALS['test_meta'][$id][$key]??'';}
function wp_strip_all_tags($text){return strip_tags($text);}
function add_action($event,$callback,$priority=10) {}
function register_rest_route($namespace,$route,$options){}
function wp_insert_post($data){$GLOBALS['writes']++;throw new Exception('Unexpected write');}
function update_post_meta($id,$key,$value){$GLOBALS['writes']++;throw new Exception('Unexpected write');}
function wp_delete_post($id){$GLOBALS['writes']++;throw new Exception('Unexpected delete');}
function check($pass,$label) {
    static $n=0;$n++;
    if(!$pass) {fwrite(STDERR,"FAIL $n: $label\n");exit(1);}
    $GLOBALS['tests']=$n;
}
function set_import($id,$company,$type,$lang,$sourceId,$title,$body='') {
    $GLOBALS['test_posts'][$id]=(object)[
        'ID'=>$id,'post_type'=>'mad4b_hub_content','post_status'=>'private',
        'post_title'=>$title,'post_content'=>$body
    ];
    $GLOBALS['test_meta'][$id]=[
        '_mad4b_hub_import_owner'=>$company,
        '_mad4b_hub_import_type'=>$type,
        '_mad4b_hub_import_language'=>$lang,
        '_mad4b_hub_import_source_id'=>$sourceId
    ];
}
function set_legacy($id,$kind,$sourceId=0,$lang='en',$state='',$native=[],$trid=0) {
    $GLOBALS['test_posts'][$id]=(object)[
        'ID'=>$id,'post_type'=>'mad4b_hub_nile',
        'post_status'=>'publish','post_title'=>'Old evidence','post_content'=>''
    ];
    $GLOBALS['test_meta'][$id]=[
        '_mad4b_hub_source_company'=>'tourism-almallahgroup',
        '_mad4b_hub_source_kind'=>$kind,
        '_mad4b_hub_source_id'=>$sourceId,
        '_mad4b_hub_source_payload_json'=>json_encode([
            'translation_source'=>['language_code'=>$lang]
        ]),
        '_mad4b_hub_reuse_policy_state'=>$state,
        '_mad4b_hub_native_candidates_json'=>json_encode($native),
        '_mad4b_hub_target_term_id'=>($kind==='nile_category'?17:0),
        '_mad4b_hub_translation_source_trid'=>$trid
    ];
}
require dirname(__DIR__).'/central/270-mad4b-hub-dedup-intelligence.php';
require dirname(__DIR__).'/central/271-mad4b-hub-dedup-tourism-nile.php';
MAD4B_Hub_Dedup_Tourism_Nile_V1::boot();
check(MAD4B_Hub_Dedup_Intelligence_V1::adapters()===['tourism_nile_v1'],
    'Specialized module registered under generic engine');
set_import(101,'tourism-almallahgroup','tours-and-activities','en',555,'Nile  Cruise');
set_import(102,'tourism-almallahgroup','tours-and-activities','en',556,'Nile-Cruise');
set_import(103,'another-company','properties','ar',888,'Nile Cruise');
set_import(104,'tourism-almallahgroup','properties','en',889,'Nile Cruise','Valid long body');
set_legacy(201,'nile_tour',555,'en','native_slug_collision_requires_owner_review',[401],900);
set_legacy(202,'nile_category',900,'en','existing_canonical_term_linked',[],901);
set_legacy(203,'historical_rate',333,'en');
$full=MAD4B_Hub_Dedup_Intelligence_V1::discover();
check(is_array($full)&&$full['imported_records_scanned']===4,'Generic core discovers all companies');
check($full['identity_duplicates']['groups']===0,'No false source-id duplicates');
check($full['title_candidates']['groups']===1,'Normalized punctuation groups candidates');
check($full['body_candidates']['groups']===0,'Empty body not treated as duplicate');
check($full['body_evidence']['empty']===3 && $full['body_evidence']['nonempty']===1,
    'Blank and nonblank body distinguish semantic readiness');
check($full['specialists']['tourism_nile_v1']['legacy_source_identity_links']===1,
    'Legacy ID links with modern post by complete provenance');
check($full['specialists']['tourism_nile_v1']['canonical_category_reuse_count']===1,
    'Specialist reuses pre-evaluated taxonomy decision');
check(count($full['specialists']['tourism_nile_v1']['native_review_holds'])===1,
    'Specialist preserves existing native collision review');
check(!$full['auto_merge']&&$GLOBALS['writes']===0,'No automatic merges or writes');
$scope=MAD4B_Hub_Dedup_Intelligence_V1::discover(['company_id'=>'another-company']);
check($scope['imported_records_scanned']===1 &&
      $scope['specialists']['tourism_nile_v1']['applicable']===false,
      'Company scope does not leak Tourism specialized evidence');
$wrong=MAD4B_Hub_Dedup_Intelligence_V1::discover(['company_id'=>'unregistered-company']);
check(is_wp_error($wrong),'Unknown company fails closed');
$custom=MAD4B_Hub_Dedup_Intelligence_V1::discover(['source_type'=>'custom-cpt']);
check(is_array($custom) && $custom['imported_records_scanned']===0,
    'Generic source type namespace is extensible');
set_import(105,'tourism-almallahgroup','properties','ar',777,'Nile Cruise');
$twoCompanies=MAD4B_Hub_Dedup_Intelligence_V1::discover();
check(count($twoCompanies['cross_company_title_candidates'])===1 &&
    !$twoCompanies['cross_company_title_candidates'][0]['auto_merge'],
    'Cross-company matching title is review-only and ownership remains separate');
set_import(106,'tourism-almallahgroup','properties','fr',800,'French Hotel');
$GLOBALS['test_meta'][104]['_mad4b_hub_import_translation_trid']=900;
$GLOBALS['test_meta'][106]['_mad4b_hub_import_translation_trid']=900;
$translations=MAD4B_Hub_Dedup_Intelligence_V1::discover();
check($translations['translation_families_identified']===1,
    'Multiple languages of a verified translation family are not duplicates');
require dirname(__DIR__).'/central/272-mad4b-hub-dedup-typed.php';
MAD4B_Hub_Dedup_Typed_V1::boot();
$GLOBALS['test_meta'][101]['_mad4b_hub_import_taxonomy_refs']=[
    'location_jet'=>[['id'=>111,'slug'=>'luxor','parent'=>0]]
];
$GLOBALS['test_meta'][102]['_mad4b_hub_import_taxonomy_refs']=[
    'location_jet'=>[['id'=>111,'slug'=>'luxor','parent'=>0]]
];
$scored=MAD4B_Hub_Dedup_Intelligence_V1::discover();
check(isset($scored['specialists']['typed_evidence_v1']),
    'Typed intelligence plugged into generic engine');
$typed=$scored['specialists']['typed_evidence_v1'];
check($typed['review_candidate_count']>=1,'Matching localized title and source location produce candidate');
check($typed['review_candidates'][0]['auto_merge']===false &&
    $typed['score_is_probability']===false,
    'Scoring is heuristic evidence, never probability or mutation');
check(count($typed['review_candidates'][0]['evidence'])>=2,
    'Typed similarity shows underlying signals');
$noForeign=MAD4B_Hub_Dedup_Intelligence_V1::discover(['company_id'=>'another-company']);
check($noForeign['specialists']['typed_evidence_v1']['review_candidate_count']===0,
    'Typed specialist isolates other company tenant');
$GLOBALS['test_meta'][102]['_mad4b_hub_import_translation_trid']=9000;
$GLOBALS['test_meta'][101]['_mad4b_hub_import_translation_trid']=9000;
$translated=MAD4B_Hub_Dedup_Intelligence_V1::discover();
check($translated['specialists']['typed_evidence_v1']['review_candidate_count']===0,
    'Same verified WPML group does not produce false duplicate');
$GLOBALS['user_can']=false;
$blocked=MAD4B_Hub_Dedup_Intelligence_V1::discover();
check(is_wp_error($blocked),'Unauthorized discovery denied');
check($GLOBALS['writes']===0,'No post or meta writes in any scenario');
echo "PASS ".$GLOBALS['tests']." Intelligence Dedup generic + Tourism specialist tests\n";
