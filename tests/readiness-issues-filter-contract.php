<?php
/** Hermetic regression of original Content Readiness REST read filtering.
 * Only loads checked-in PHP source: no WordPress boot, network or database.
 */
define('ABSPATH','/hermetic/');
class WP_Error {
    private $code;
    function __construct($code){$this->code=$code;}
    function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
class WP_REST_Request {
    private $params=[];
    public function __construct($method='GET',$path=''){ }
    public function get_param($key){return $this->params[$key]??null;}
    public function set_param($key,$value){$this->params[$key]=$value;}
}
class WP_REST_Response {
    private $data;
    public function __construct($x){$this->data=$x;}
    public function get_data(){return $this->data;}
}
function rest_ensure_response($x){return new WP_REST_Response($x);}
function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$s));}
function sanitize_text_field($s){return trim(strip_tags((string)$s));}
function absint($s){return abs((int)$s);}
function post_type_exists($s){return in_array($s,['post','tours'],true);}
function taxonomy_exists($s){return in_array($s,['category','destinations'],true);}
function apply_filters($name,$value,...$args){return $value;}
$GLOBALS['actor']=true;
function current_user_can($cap){return $GLOBALS['actor'] && $cap==='edit_others_posts';}
$GLOBALS['ledger']=[
 'a'=>['id'=>str_repeat('a',64),'source'=>'native','post_type'=>'tours',
   'object_id'=>3,'code'=>'pricing_missing','severity'=>'critical','priority'=>0,
   'status'=>'open','label'=>'Fix source price','last_seen'=>'2026-11-03T02:00:00+00:00'],
 'b'=>['id'=>str_repeat('b',64),'source'=>'mad4b-locale-experience','post_type'=>'site',
   'object_id'=>0,'code'=>'lexp_asset_http_error_fr','severity'=>'high','priority'=>94,
   'content_job_id'=>'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee','board_task_id'=>41,
   'content_job_request'=>['language'=>'fr','status'=>'LINKED'],
   'status'=>'open','label'=>'404 hero','last_seen'=>'2026-11-03T02:00:00+00:00'],
 'c'=>['id'=>str_repeat('c',64),'source'=>'mad4b-locale-experience','post_type'=>'site',
   'object_id'=>0,'code'=>'lexp_asset_http_error_ar','severity'=>'high','priority'=>94,
   'content_job_request'=>['language'=>'ar','status'=>'PRIORITY_INTAKE'],
   'status'=>'open','label'=>'404 hero AR','last_seen'=>'2026-11-03T02:00:00+00:00'],
 'd'=>['id'=>str_repeat('d',64),'source'=>'mad4b-locale-experience','post_type'=>'site',
   'object_id'=>0,'code'=>'lexp_untranslated_ui_candidate_fr','severity'=>'medium','priority'=>82,
   'content_job_request'=>['language'=>'fr','status'=>'PRIORITY_INTAKE'],
   'status'=>'open','label'=>'Label FR','last_seen'=>'2026-11-03T02:00:00+00:00'],
 'e'=>['id'=>str_repeat('e',64),'source'=>'native','post_type'=>'post',
   'object_id'=>7,'code'=>'sample_keyword_fr','severity'=>'medium',
   'priority'=>16,'status'=>'open','label'=>'SEO content','last_seen'=>'2026-11-03T02:00:00+00:00'],
 'f'=>['id'=>str_repeat('f',64),'source'=>'mad4b-locale-experience','post_type'=>'site',
   'object_id'=>0,'code'=>'lexp_cross_locale_link_en','severity'=>'high',
   'content_job_id'=>'eeeeeeee-bbbb-cccc-dddd-eeeeeeeeeeee','board_task_id'=>45,
   'priority'=>91,'status'=>'resolved','label'=>'links may be fixed',
   'last_seen'=>'2026-11-03T01:00:00+00:00']
];
function get_option($name,$default=null){
 return $name==='mad4b_content_readiness_issues_v1'?$GLOBALS['ledger']:$default;
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-content-readiness-alerts.php';
$pass=[];
function check($name,$ok){global $pass;$pass[$name]=(bool)$ok;}
$before=serialize($GLOBALS['ledger']);
$q=new WP_REST_Request;
$q->set_param('status','open');
$r=MAD4B_SCP_Content_Readiness_Alerts::rest_issues($q)->get_data();
check('default_critical_first',($r['total']??0)===5 && ($r['items'][0]['severity']??'')==='critical');
check('default_read_only',$r['read_only']===true && $r['contract']==='mad4b.content-readiness.issues.v1');
$q->set_param('source','mad4b-locale-experience');
$r=MAD4B_SCP_Content_Readiness_Alerts::rest_issues($q)->get_data();
check('source_match_before_pagination',$r['total']===3 &&
 count(array_filter($r['items'],static fn($x)=>$x['source']!=='mad4b-locale-experience'))===0);
$q->set_param('language','fr');
$r=MAD4B_SCP_Content_Readiness_Alerts::rest_issues($q)->get_data();
check('locale_match_on_original_intake',$r['total']===2 &&
 count(array_filter($r['items'],static fn($x)=>$x['language']!=='fr'))===0);
$q->set_param('assignment','content_job');
$r=MAD4B_SCP_Content_Readiness_Alerts::rest_issues($q)->get_data();
check('source_language_native_job_linked',$r['total']===1 &&
 ($r['items'][0]['content_job_id']??'')==='aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
$q->set_param('assignment','unassigned');
$r=MAD4B_SCP_Content_Readiness_Alerts::rest_issues($q)->get_data();
check('source_language_unassigned',$r['total']===1 &&
 array_key_exists('content_job_id',$r['items'][0]) && $r['items'][0]['content_job_id']===null);
$q->set_param('assignment','board');
$r=MAD4B_SCP_Content_Readiness_Alerts::rest_issues($q)->get_data();
check('source_language_original_board',$r['total']===1 && $r['items'][0]['board_task_id']===41);
$q->set_param('assignment','all');$q->set_param('language','');
$q->set_param('status','resolved');
$r=MAD4B_SCP_Content_Readiness_Alerts::rest_issues($q)->get_data();
check('resolved_source_preserves_original_links',$r['total']===1 &&
 ($r['items'][0]['board_task_id']??0)===45);
$q->set_param('status','open');$q->set_param('assignment','unknown');
$e=MAD4B_SCP_Content_Readiness_Alerts::rest_issues($q);
check('unknown_assignment_fails_closed',is_wp_error($e));
$q->set_param('assignment','all');$q->set_param('language','../../..');
$e=MAD4B_SCP_Content_Readiness_Alerts::rest_issues($q);
check('invalid_language_fails_closed',is_wp_error($e));
$q->set_param('language','fr');$q->set_param('source','mad4b-locale-experience');
$q->set_param('per_page',1);$q->set_param('page',2);
$r=MAD4B_SCP_Content_Readiness_Alerts::rest_issues($q)->get_data();
check('pagination_after_all_filters',$r['total']===2 && $r['pages']===2 && count($r['items'])===1);
$GLOBALS['actor']=false;
check('original_read_permission_denies_anonymous',MAD4B_SCP_Content_Readiness_Alerts::rest_permission()===false);
check('no_mutation_or_intake_from_GET',$before===serialize($GLOBALS['ledger']));
$base=['enabled'=>true,'exclude_qa'=>false,'rules'=>[]];
$normal=MAD4B_SCP_Content_Completeness::normalize($base);
check('original_completeness_provider_loaded',!is_wp_error($normal) &&
 ($normal['enabled']??false)===true && ($normal['rules']??null)===[]);
$invalid=$base;$invalid['unknown_toggle']=true;
check('completeness_unknown_rule_setting_denied',
 is_wp_error(MAD4B_SCP_Content_Completeness::normalize($invalid)));
$valid=$base;
$valid['rules']=[['id'=>'tour_title_fr','target'=>'post','post_type'=>'tours',
  'field'=>'post_title','language'=>'fr','min_chars'=>3,'severity'=>'high']];
$normalized=MAD4B_SCP_Content_Completeness::normalize($valid);
check('completeness_exact_post_type_locale_rule',
 !is_wp_error($normalized) &&
 ($normalized['rules'][0]['post_type']??null)==='tours' &&
 ($normalized['rules'][0]['language']??null)==='fr');
$invalid=$valid;$invalid['rules'][0]['field']='unknown_html';
check('completeness_reject_unsupported_field',
 is_wp_error(MAD4B_SCP_Content_Completeness::normalize($invalid)));
$invalid=$valid;$invalid['rules'][0]['post_type']='unknown_company_site';
check('completeness_reject_unknown_post_type',
 is_wp_error(MAD4B_SCP_Content_Completeness::normalize($invalid)));
$invalid=$valid;$invalid['rules'][0]['field']='meta_image';
check('completeness_reject_unbacked_media_source',
 is_wp_error(MAD4B_SCP_Content_Completeness::normalize($invalid)));
$invalid=$valid;$invalid['rules'][]=$valid['rules'][0];
check('completeness_reject_duplicate_rule_id',
 is_wp_error(MAD4B_SCP_Content_Completeness::normalize($invalid)));
check('completeness_admin_permission_separate',
 MAD4B_SCP_Content_Completeness::admin_permission()===false);
check('original_completeness_no_read_side_effect',
 $before===serialize($GLOBALS['ledger']));
$failed=array_keys(array_filter($pass,static fn($x)=>!$x));
foreach($failed as $name)echo 'FAIL '.$name,PHP_EOL;
echo 'SUMMARY checks='.count($pass).' failures='.count($failed),PHP_EOL;
if($failed)exit(1);
