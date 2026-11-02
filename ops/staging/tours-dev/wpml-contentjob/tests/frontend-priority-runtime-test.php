<?php
define('ABSPATH','/');
define('ICL_SITEPRESS_VERSION','4.8-local-test');
$GLOBALS['mock_options']=[];
$GLOBALS['mock_langs']=['en','ar','fr','de','es','it','pt'];
function wp_get_environment_type(){return 'staging';}
function home_url($suffix=''){return 'https://tours.dev.mad4b.com'.$suffix;}
function get_option($key,$default=[]){return $GLOBALS['mock_options'][$key]??$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['mock_options'][$key]=$value;return true;}
function sanitize_key($value){return strtolower(preg_replace('/[^a-z0-9_-]/i','',(string)$value));}
function get_post_type($source){return (int)$source===999?'page':'elementor_library';}
function apply_filters($name,$value,...$args){
 if($name==='wpml_default_language')return 'en';
 if($name==='wpml_active_languages')return array_fill_keys($GLOBALS['mock_langs'],true);
 if($name==='wpml_object_id')return $value==101?201:$value;
 return $value;
}
function add_action(...$args){}
class WP_Error{
 private $code;
 public function __construct($code,$message=''){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($value){return $value instanceof WP_Error;}
class MAD4B_SCP_Policy {public static function can_mutate(){return true;}}
require dirname(__DIR__).'/357-mad4b-wpml-frontend-priority.php';
$ok=0;
function check($value,$desc){global $ok;if(!$value)throw new Exception("FAIL ".$desc);$ok++;}
$now=gmdate('c');
$old=gmdate('c',time()-23*3600);
$expired=gmdate('c',time()-38*3600);
$pages=[];
foreach(['ar','fr','de','es','it','pt'] as $lang){
 $pages[$lang]=[
 ['path'=>"/$lang/",'kind'=>'home','observed_at'=>$now,'http_status'=>200,
  'elementor_ids'=>['201'],'listing_ids'=>[],'post_ids'=>[]],
 ['path'=>"/$lang/tours-and-activities/",'kind'=>'archive','observed_at'=>$now,'http_status'=>200,
  'elementor_ids'=>[],'listing_ids'=>[],'post_ids'=>['999']],
 ['path'=>"/$lang/old-page/",'kind'=>'linked_page','observed_at'=>$old,'http_status'=>200,
  'elementor_ids'=>['777'],'listing_ids'=>[],'post_ids'=>[]]
 ];
}
$fixture=['contract'=>'mad4b.wpml.frontend-visibility.v1','site'=>'https://tours.dev.mad4b.com',
 'observed_at'=>$now,'pages'=>$pages];
$f='/tmp/wpml-priority-fixture.json';
file_put_contents($f,json_encode($fixture));
$r=MAD4B_WPML_Frontend_Priority_V1::import($f);
check(!is_wp_error($r),'import valid 6 language fixture');
check($r['languages']===6&&$r['pages']===18,'auto recognized new PT language');
$home=MAD4B_WPML_Frontend_Priority_V1::rank(101,'elementor_library','ar',201);
$archive=MAD4B_WPML_Frontend_Priority_V1::rank(999,'page','ar',999);
$oldscore=MAD4B_WPML_Frontend_Priority_V1::rank(777,'elementor_library','ar',777);
$unknown=MAD4B_WPML_Frontend_Priority_V1::rank(888,'elementor_library','ar',888);
check($home['visible']&&$home['priority']>900,'home visible at highest priority');
check($archive['visible']&&$archive['priority']<=$home['priority'],'archive lower than home');
check($oldscore['visible']&&$oldscore['priority']<$archive['priority'],'past evidence decays');
check(!$unknown['visible']&&$unknown['priority']===90,'unobserved retains fair fallback');
check(MAD4B_WPML_Frontend_Priority_V1::rank(101,'elementor_library','pt',201)['visible'],'new language prioritized');
check(MAD4B_WPML_Frontend_Priority_V1::rank(101,'elementor_library','ar',0)['visible'],'WPML resolves missing target dynamically');
$list=[
 ['source_post_id'=>888,'target_post_id'=>888,'target_language'=>'ar','issue_id'=>'z'],
 ['source_post_id'=>777,'target_post_id'=>777,'target_language'=>'ar','issue_id'=>'x'],
 ['source_post_id'=>101,'target_post_id'=>201,'target_language'=>'ar','issue_id'=>'a']
];
$sorted=MAD4B_WPML_Frontend_Priority_V1::sort_outbox($list,3);
check($sorted[0]['source_post_id']===101,'MCP outbox sorts visible first');
check($sorted[2]['source_post_id']===888,'unobserved remains in tail');
$initial=get_option(MAD4B_WPML_Frontend_Priority_V1::SNAPSHOT);
$fixture['site']='https://example.com';
file_put_contents($f,json_encode($fixture));
$rejected=MAD4B_WPML_Frontend_Priority_V1::import($f);
check(is_wp_error($rejected)&&$rejected->get_error_code()==='wpml_priority_wrong_contract_or_site',
 'wrong site denied');
check(get_option(MAD4B_WPML_Frontend_Priority_V1::SNAPSHOT)===$initial,'previous evidence retained when invalid');
$fixture['site']='https://tours.dev.mad4b.com';
$fixture['pages']=array_slice($pages,0,2,true);
file_put_contents($f,json_encode($fixture));
$rejected=MAD4B_WPML_Frontend_Priority_V1::import($f);
check(is_wp_error($rejected)&&get_option(MAD4B_WPML_Frontend_Priority_V1::SNAPSHOT)===$initial,
 'incomplete crawl does not replace snapshot');
$fixture['pages']=$pages;
$fixture['pages']['ar'][1]['observed_at']=$expired;
file_put_contents($f,json_encode($fixture));
$accepted=MAD4B_WPML_Frontend_Priority_V1::import($f);
check(!is_wp_error($accepted)&&$accepted['pages']===17,'expired per-page evidence discarded');
echo "PASS $ok: WPML frontend priority imported + dynamic locales + ranking + failure isolation\n";
