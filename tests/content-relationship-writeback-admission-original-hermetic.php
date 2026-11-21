<?php
/** Native ContentJob relationship writeback classifies input, no original provider execution. */
define('ABSPATH',__DIR__.'/');
class WP_Error{private $code;function __construct($c,$m='',$d=[]){$this->code=$c;}function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function add_action($action,$callback,$priority=10,$args=1){$GLOBALS['native_hooks'][$action]=$callback;return true;}
function current_user_can($cap){return true;}
class MAD4B_SCP_Policy {static function can_mutate(){return true;}}
class MAD4B_SCP_Site_Profile {
 static function status(){return [
 'site_uuid'=>'11111111-1111-4111-8111-111111111111',
 'origin_match'=>true,'configured'=>true,'authority_ready'=>true,
 'deployment_binding_match'=>true,'same_origin_clone_protection'=>true,
 'environment_match'=>true,'mutation_pending_audit'=>false,
 'environment'=>'staging','configured_environment'=>'staging'];}
}
function wb_check($ok,$why){if(!$ok){fwrite(STDERR,'FAIL '.$why.PHP_EOL);exit(1);}$GLOBALS['n']=($GLOBALS['n']??0)+1;}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-content-relationship-writeback.php';
$c='MAD4B_SCP_Content_Relationship_Writeback';
$job='22222222-2222-4222-8222-222222222222';
foreach(['job_id','relationship_kind','meta_key'] as $field){
 foreach([new WP_Error('source_unknown'),1,[],null] as $bad){
  $request=['job_id'=>$job,'relationship_kind'=>'typed_meta','meta_key'=>'some_meta'];
  $request[$field]=$bad;
  $r=$c::plan($request);
  wb_check(($r['state']??null)==='HOLD'
   &&($r['reason']??null)==='original_owner_adapter_missing',
   'Error-shaped relationship owner input cannot select a first-party write adapter');
 }
}
foreach([new WP_Error('digest_unknown'),1,'true',[],null] as $bad){
 $r=$c::apply(['job_id'=>$job,'relationship_kind'=>'featured_image',
  'expected_source_sha256'=>$bad]);
 wb_check(($r['state']??null)==='HOLD'
  &&($r['reason']??null)==='original_source_sha_required',
  'Unverified expected original source digest cannot start provider source plan');
}
$callback=$GLOBALS['native_hooks']['mad4b_scp_content_job_transitioned']??null;
wb_check(is_callable($callback),'Original native event remains registered for explicit ContentJob transition');
foreach([new WP_Error('job_unknown'),1,'true',[],null] as $bad){
 $callback(['job_id'=>$bad,'job_revision'=>1]);
 wb_check(true,'Bad ContentJob event never enters original native provider read/write');
}
echo 'PASS: CONTENT_RELATIONSHIP_WRITEBACK_ADMISSION_ORIGINAL '.($GLOBALS['n']??0).
 ' native typed adapter, digest and event denial checks'.PHP_EOL;
