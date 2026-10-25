<?php
define('ABSPATH',__DIR__.'/');
class WP_Error {private $c;public function __construct($c,$message=''){$this->c=$c;}public function get_error_code(){return $this->c;}}
function is_wp_error($x){return $x instanceof WP_Error;}
class WP_Post {
 public $ID,$post_title,$post_content,$post_status,$post_type,$post_modified_gmt,$post_date_gmt;
 public function __construct($id,$type,$text) {
   $this->ID=$id;$this->post_type=$type;$this->post_title="ETG QA source $id";
   $this->post_content=$text;$this->post_status='draft';
   $this->post_modified_gmt='2026-10-25 07:00:00';$this->post_date_gmt=$this->post_modified_gmt;
 }
}
$GLOBALS['posts']=array(
 1=>new WP_Post(1,'product','Draft product information based on public facts for Egypt.'),
 2=>new WP_Post(2,'post','SEO draft information about Cairo, Arabic and the Egyptian pound.'),
 3=>new WP_Post(3,'post','مسودة ترجمة باللغة العربية عن مصر والقاهرة والعملة الرسمية.'),
 4=>new WP_Post(4,'elementor_library','Design sample layout with public country facts and safe draft content.')
);
$GLOBALS['meta']=array(4=>array('_elementor_data'=>'[{"id":"e1","elType":"section"}]'),
 3=>array('_mad4b_review_source_language'=>'ar'),2=>array('rank_math_description'=>'Egypt country profile'));
function get_post($id){return $GLOBALS['posts'][$id]??null;}
function get_post_meta($id,$key,$single=true){return $GLOBALS['meta'][$id][$key]??'';}
function wc_get_product($id){return $id===1?(object)array('id'=>1):null;}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function wp_get_environment_type(){return $GLOBALS['env']??'local';}
function add_action($hook,$callback,$priority=10){}
$GLOBALS['abilities']=array();
function wp_has_ability($name){return isset($GLOBALS['abilities'][$name]);}
function wp_register_ability($name,$definition){$GLOBALS['abilities'][$name]=$definition;return true;}
function is_user_logged_in(){return false;}
class MAD4B_SCP_Content_Jobs {
 public static function get_job($input){
  return array('job'=>array('job_id'=>$input['job_id'],'state'=>'WAITING_REVIEW',
   'job_revision'=>3,'subject'=>'Country Egypt content test draft','primary_keyword'=>'Egypt travel',
   'language'=>'ar','country'=>'eg','stage'=>'EDITORIAL_QA','current_artifact_id'=>'fixture-eg'));
 }
}
class MAD4B_SCP_Catalog_Object_Store {}
class MAD4B_SCP_G5_SEO_Provider_Families {
 public static function catalog(){return array('field_ids'=>array('title','description','focus_keyword'));}
}
class MAD4B_SCP_Translation_Bridge_Adapter {
 public function is_available(){return true;}
 public function translation_get_post($input) {
  return array('provider'=>'wpml','language'=>'ar','group'=>'111','post_id'=>(int)$input['post_id']);
 }
}
class MAD4B_SCP_Browser_Acceptance_Core {}
class MAD4B_SCP_Audit {
 public static $entry=null;
 public static function native_owner_review_events($selector){
  if(!is_array(self::$entry))return array('chain_valid'=>true,'head_consistent'=>true,'events'=>array());
  return array('chain_valid'=>!empty($GLOBALS['chain_valid']),'head_consistent'=>true,
    'events'=>array(self::$entry));
 }
}
class MAD4B_SCP_Site_Profile {public static function site_uuid(){return '123e4567-e89b-12d3-a456-426614174000';}}
class MAD4B_SCP_AI_Only_Local_Activation {
 public static function site_bound(){return ($GLOBALS['env']??'local')==='local' || !empty($GLOBALS['crossenv_bound']);}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-ai-native-owner-review.php';
$passes=0;
function ok($n,$c){global $passes;if(!$c){fwrite(STDERR,"FAILED $n\n");exit(1);}++$passes;echo "$n: PASS\n";}
MAD4B_SCP_AI_Native_Owner_Review::register_abilities();
ok('five_original_owner_scoped_abilities_declared',count($GLOBALS['abilities'])===5);
foreach($GLOBALS['abilities'] as $name=>$info)
 ok('nonexecutable_non_mcp_local_ability_'.$name,
    $info['permission_callback']==='__return_false'
    &&empty($info['meta']['public']) &&empty($info['meta']['mcp']['public'])
    &&MAD4B_SCP_AI_Native_Owner_Review::local_ability_allowed($name));
$GLOBALS['env']='production';
ok('native_grant_denied_production_without_scope',!MAD4B_SCP_AI_Native_Owner_Review::local_ability_allowed('mad4b/ai-content-job-review'));
$GLOBALS['crossenv_bound']=true;
ok('native_grant_visible_for_scoped_production',MAD4B_SCP_AI_Native_Owner_Review::local_ability_allowed('mad4b/ai-content-job-review'));
unset($GLOBALS['crossenv_bound']);
$GLOBALS['env']='local';
$items=array(
 'content_job'=>'job:123e4567-e89b-42d3-a456-426614174000',
 'product_catalog'=>'post:1','seo_metadata'=>'post:2',
 'localization'=>'post:3','design_template'=>'post:4');
foreach($items as $domain=>$id) {
 $e=MAD4B_SCP_AI_Native_Owner_Review::evidence($domain,array('item_id'=>$id));
 ok($domain.'_reads_original_owner_source',is_array($e)&&strlen($e['normalized_text'])>35);
 ok($domain.'_uses_sha256_cas',preg_match('/^[a-f0-9]{64}$/D',$e['content_hash'])===1&&$e['owner_revision']>0);
 $audit_id='123e4567-e89b-42d3-a456-426614174000';
 $entry_hash=str_repeat('b',64);
 $receipt=array('domain'=>$domain,'event_id'=>$audit_id,'entry_hash'=>$entry_hash,'decision'=>'needs_changes');
 $binding=array('content_hash'=>$e['content_hash'],'owner_revision'=>$e['owner_revision']);
 $source_item=array('item_id'=>$id)+$binding;
 MAD4B_SCP_Audit::$entry=array('event_id'=>$audit_id,'entry_hash'=>$entry_hash,
   'ability'=>'mad4b/ai-native-owner-review-'.$domain,'summary'=>array(
     'contract'=>MAD4B_SCP_AI_Native_Owner_Review::CONTRACT,'domain'=>$domain,
     'owner_class'=>MAD4B_SCP_AI_Native_Owner_Review::owner($domain),
     'item_id'=>$id,'content_hash'=>$e['content_hash'],
     'owner_revision'=>$e['owner_revision'],'actor_type'=>'ai_agent',
     'decision'=>'needs_changes','site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid(),
     'publication_authorized'=>false,'privileged_grants_issued'=>false));
 $GLOBALS['chain_valid']=true;
 ok($domain.'_requires_original_audit_readback',
   MAD4B_SCP_AI_Native_Owner_Review::verify($domain,$source_item,$receipt,$binding)===true);
 $wrong_decision=$receipt;$wrong_decision['decision']='approve';
 ok($domain.'_rejects_audit_decision_mismatch',
   MAD4B_SCP_AI_Native_Owner_Review::verify($domain,$source_item,$wrong_decision,$binding)===false);
 $GLOBALS['chain_valid']=false;
 ok($domain.'_rejects_invalid_original_chain',
   MAD4B_SCP_AI_Native_Owner_Review::verify($domain,$source_item,$receipt,$binding)===false);
 $GLOBALS['chain_valid']=true;
 $wrong=$source_item;$wrong['content_hash']=str_repeat('a',64);
 ok($domain.'_rejects_stale_source_digest',
   MAD4B_SCP_AI_Native_Owner_Review::verify($domain,$wrong,$receipt,$binding)===false);
 $result=MAD4B_SCP_AI_Native_Owner_Review::submit($domain,$source_item,'approve',
    'This content review is sufficiently documented with two evidence lines.',$binding);
 ok($domain.'_cannot_issue_receipt_using_untrusted_cli',
   is_wp_error($result)&&$result->get_error_code()==='mad4b_owner_review_agent_http_required');
}
$GLOBALS['posts'][4]->post_status='publish';
ok('published_template_excluded',is_wp_error(
 MAD4B_SCP_AI_Native_Owner_Review::evidence('design_template',array('item_id'=>'post:4'))));
$GLOBALS['posts'][4]->post_status='draft';$GLOBALS['meta'][4]['_elementor_data']='';
ok('template_requires_real_elementor_data',is_wp_error(
 MAD4B_SCP_AI_Native_Owner_Review::evidence('design_template',array('item_id'=>'post:4'))));
ok('different_source_owner_rejected',is_wp_error(
 MAD4B_SCP_AI_Native_Owner_Review::evidence('product_catalog',array('item_id'=>'post:2'))));
echo "SUMMARY pass=$passes fail=0\n";
echo "PASS: FIVE_NATIVE_OWNER_SOURCES_AND_AUDIT_CAS\n";
