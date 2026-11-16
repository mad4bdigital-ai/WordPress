<?php
/** Original Source Archive/Restore: isolated site/brand scoped WP fixture. */
define('ABSPATH',__DIR__.'/');
class WP_Error {
 private $code;
 public function __construct($code,$message=''){ $this->code=$code; }
 public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function current_user_can($cap){return $GLOBALS['user_manage'];}
function get_current_user_id(){return 17;}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function add_action($n,$cb){$GLOBALS['actions'][$n]=$cb;}
function get_option($name,$fallback=[]){return $GLOBALS['options'][$name]??$fallback;}
function update_option($name,$value,$autoload=false){$GLOBALS['writes']++;$GLOBALS['options'][$name]=$value;return true;}
function wp_generate_uuid4(){return '11111111-1111-4111-8111-111111111111';}
function source_assert($ok,$reason){
 if(!$ok){fwrite(STDERR,'FAIL: '.$reason.PHP_EOL);exit(1);}
 $GLOBALS['cases']=($GLOBALS['cases']??0)+1;
}
class MAD4B_SCP_Policy {
 static $allowed=true;
 static function can_connect_user($id){return self::$allowed;}
}
class MAD4B_SCP_Context_Authority {
 static $source;
 static $assets=[];
 static $profile=['site_uuid'=>'site-1','brand_id'=>'brand-1'];
 static $remove_count=0;
 static $upsert_count=0;
 static function source($id){return self::$source && is_array(self::$source) &&
 ($id===self::$source['source_id'])?self::$source:self::$source;}
 static function assets(){return self::$assets;}
 static function profile(){return self::$profile;}
 static function registry_revision(){return 12;}
 static function remove_source($id){self::$remove_count++;self::$source=null;self::$assets=[];return ['removed'=>true];}
 static function upsert_source($input){
  self::$upsert_count++;
  self::$source=['source_id'=>'my-source','site_uuid'=>'site-1','brand_id'=>'brand-1']+$input;
  return self::$source;
 }
}
$GLOBALS['user_manage']=true;
$GLOBALS['options']=[];$GLOBALS['writes']=0;$GLOBALS['actions']=[];
MAD4B_SCP_Context_Authority::$source=[
 'source_id'=>'my-source','site_uuid'=>'site-1','brand_id'=>'brand-1',
 'provider'=>'wordpress_media','mode'=>'manual','external_root_id'=>'root-1',
 'label'=>'My photos','task_scope'=>'media','recursive'=>false,
 'write_policy'=>'read_write'
];
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-source-lifecycle.php';
$c='MAD4B_SCP_Source_Lifecycle';
source_assert(isset($GLOBALS['actions']['admin_post_mad4b_context_archive_source'])
 &&isset($GLOBALS['actions']['admin_post_mad4b_context_restore_source']),
 'Source archive uses the two original WP admin POST handlers');
foreach([new WP_Error('policy_unknown'),null,1,'true'] as $unknown){
 MAD4B_SCP_Policy::$allowed=$unknown;
 source_assert(is_wp_error($c::archive_plan('my-source')),
  'Only explicit original user-connection approval admits source archiving');
}
MAD4B_SCP_Policy::$allowed=true;
$GLOBALS['user_manage']=false;
source_assert(is_wp_error($c::archive_plan('my-source')),
 'WordPress admin permission is independent of connection policy');
$GLOBALS['user_manage']=true;
$plan=$c::archive_plan('my-source');
source_assert(is_array($plan)&&!$plan['external_files_mutated']
 &&$plan['identity']['source_id']==='my-source' &&$GLOBALS['writes']===0,
 'Read-only original site/brand archive plan needs no external mutation');
MAD4B_SCP_Context_Authority::$assets=['asset-2'=>[
 'source_id'=>'my-source','site_uuid'=>'other','brand_id'=>'brand-1']];
source_assert(is_wp_error($c::archive_plan('my-source')) &&$GLOBALS['writes']===0,
 'Foreign source-owned asset cannot enter snapshot');
MAD4B_SCP_Context_Authority::$assets=[];
MAD4B_SCP_Context_Authority::$source=new WP_Error('source_backend_down');
source_assert(is_wp_error($c::archive_plan('my-source')) &&$GLOBALS['writes']===0,
 'Original source lookup error cannot be treated as an archiveable source');
MAD4B_SCP_Context_Authority::$source=[
 'source_id'=>'my-source','site_uuid'=>'site-1','brand_id'=>'brand-1',
 'provider'=>'wordpress_media','mode'=>'manual','external_root_id'=>'root-1',
 'label'=>'My photos','task_scope'=>'media','recursive'=>false,
 'write_policy'=>'read_write'
];
source_assert(is_wp_error($c::archive_source('my-source',str_repeat('b',64)))
 &&$GLOBALS['writes']===0,'Stale archive digest cannot remove original source');
$plan=$c::archive_plan('my-source');
$archived=$c::archive_source('my-source',$plan['digest']);
source_assert(is_array($archived)&&$archived['state']==='detached'
 &&MAD4B_SCP_Context_Authority::$remove_count===1
 &&$archived['former_approvals_restored']===false,
 'Original removal stores first-party snapshot before detaching source only');
$archive_id=$archived['archive_id'];
$GLOBALS['options'][$c::OPTION][$archive_id]['source']['label']='tampered';
source_assert(is_wp_error($c::restore_source($archive_id))
 &&MAD4B_SCP_Context_Authority::$upsert_count===0,
 'Tampered original snapshot cannot be restored');
$GLOBALS['options'][$c::OPTION][$archive_id]['source']['label']='My photos';
$restored=$c::restore_source($archive_id);
source_assert(is_array($restored)&&$restored['state']==='restored_rescan_required'
 &&MAD4B_SCP_Context_Authority::$upsert_count===1
 &&MAD4B_SCP_Context_Authority::$source['write_policy']==='read_only'
 &&$restored['old_assets_reapproved']===false,
 'Original restore re-enrolls read-only and demands new provider scan');
source_assert(is_wp_error($c::restore_source($archive_id))
 &&MAD4B_SCP_Context_Authority::$upsert_count===1,
 'Restored original source cannot be replayed');
$archives=$c::archives();
source_assert(is_array($archives)&&count($archives)===1 &&$archives[$archive_id]['state']==='restored_rescan_required',
 'Original source archives remain site and brand scoped');
$garbage_id='22222222-2222-4222-8222-222222222222';
$GLOBALS['options'][$c::OPTION][$garbage_id]=['site_uuid'=>'site-1','brand_id'=>'brand-1','state'=>'detached','assets'=>'oops'];
source_assert(is_array($c::archives()),
 'Malformed historical archive row cannot crash original native admin listing');
echo 'PASS: SOURCE_LIFECYCLE_ORIGINAL '.($GLOBALS['cases']??0).
 ' first-party authority and read-only restoration cases'.PHP_EOL;
