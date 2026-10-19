<?php
require __DIR__.'/fixtures/artifact-test-signing.php';
$GLOBALS['calls']=array();$GLOBALS['changed_package']=false;$GLOBALS['fail_install']=false;
// Installer boundary fixture: actual queue, consent, crypto and AI delegation
// execute; WordPress filesystem/upgrader side effects are replaced here.
class MAD4B_SCP_Remote_Plugin_Update {
 static function plan($input){
  $a=$GLOBALS['artifact'];$a['plugin_file']=$input['plugin_file'];
  if($GLOBALS['changed_package'])$a['package_sha256']=str_repeat('e',64);
  $v=MAD4B_SCP_Test_Attestation::verify($input['test_attestation']??null,$a);
  $p=$a+array('eligible'=>!is_wp_error($v)&&MAD4B_SCP_Production_Automation::deployment_planning_allowed($input['plugin_file']),'certificate_sha256'=>MAD4B_SCP_Production_Automation::digest($input['test_attestation']??null));
  $p['plan_sha256']=MAD4B_SCP_Production_Automation::digest($p);return $p;
 }
 static function apply($input){
  if(!MAD4B_SCP_Production_Automation::deployment_authorized($input))return new WP_Error('native_context_denied');
  $p=self::plan($input);if(!$p['eligible']||!hash_equals($p['plan_sha256'],$input['expected_plan_sha256']))return new WP_Error('package_changed');
  $GLOBALS['calls'][]=$input['plugin_file'];
  if($GLOBALS['fail_install'])return new WP_Error('install_failed_rollback_fixture');
  return array('backup_id'=>'fixture-protected-backup','readback_verified'=>true,'activation_state_preserved'=>true,'production_mutation'=>true);
 }
}
class MAD4B_SCP_Staging_Write_Authority {static function effective(){return true;}static function is_write_ability($s){return true;}static function approval_ticket_from_input($i){return '';}}
class MAD4B_SCP_Agent_Registry {
 static function resolve_agent($i){return array('id'=>1,'public_id'=>'44444444-4444-4444-8444-444444444444','status'=>'enabled','environment'=>$GLOBALS['agent_env']??'production');}
 static function get_agent_by_id($i){return self::resolve_agent(array());}
 static function exact_grant(...$a){return array('effect'=>$GLOBALS['grant_effect']??'allow','environment'=>$GLOBALS['grant_env']??'production');}
}
class MAD4B_SCP_Approval_Tickets {static function get($id){return $GLOBALS['ticket'];}static function canonical_payload_hash(...$a){return str_repeat('e',64);}}
class MAD4B_SCP_Impact_Policy {static function classify(...$a){return array('classification_sha256'=>str_repeat('f',64),'ai_approval_eligible'=>false,'approval_lane'=>'human_only');}}
require dirname(__DIR__).'/includes/class-mad4b-scp-ai-approval.php';
function fields($p){$i=$p['after'];foreach(array('plan_sha256','policy_revision','policy_digest','site_uuid','source_commit_sha','build_fingerprint')as$k)$i['expected_'.$k]=$p[$k];$i['expected_profile_revision']=$p['site_profile_revision'];$i['expected_profile_digest']=$p['site_profile_digest'];$i['confirmation']=$p['required_confirmation'];return $i;}
$a='MAD4B_SCP_Production_Automation';$g='MAD4B_SCP_Governed_Runtime_Gates';$t='MAD4B_SCP_Test_Attestation';
check(!$a::active()&&!MAD4B_SCP_AI_Approval::catalog_eligible(),'Production automation defaults disabled');
$g::admin_policy_apply('automatic',fields($g::admin_policy_plan('automatic')));
$t::save_trust(array('fixture-test-key'=>$key),$a::digest($t::trust()));
$op=array('post_id'=>17,'post_title'=>'Reviewed title','post_status'=>'draft');
$rule=array('ability'=>'mad4b/content-update-post','provider'=>'core','input'=>$op);
function job($plugin){$artifact=$GLOBALS['artifact'];$artifact['plugin_file']=$plugin;$input=array('plugin_file'=>$plugin,'reason'=>'Exact tested production deployment','test_attestation'=>cert($GLOBALS['key'],$artifact,$GLOBALS['private']));$input['expected_plan_sha256']=MAD4B_SCP_Remote_Plugin_Update::plan($input)['plan_sha256'];return array('input'=>$input);}
$batch=array('enabled'=>true,'hours'=>24,'agent_public_id'=>'44444444-4444-4444-8444-444444444444','approvals'=>array($rule),'deployments'=>array(job('demo/demo.php')),'confirmation'=>'AUTHORIZE EXACT PRODUCTION BATCH');
$save=function($input)use($a){return $a::save_batch($input,$a::digest($a::policy()));};
$initial=$a::digest($a::policy());check(!is_wp_error($save($batch))&&$a::active(),'exact owner batch activates approvals and native deployment');
check(is_wp_error($a::save_batch($batch,$initial)),'concurrent stale owner form rejected');
check(MAD4B_SCP_AI_Approval::catalog_eligible(),'AI decision ability becomes eligible under separate Production batch');
$identity=array('authenticated'=>true,'auth_method'=>'oauth2_bearer');
$GLOBALS['ticket']=array('ticket_id'=>'55555555-5555-4555-8555-555555555555','status'=>'pending','ticket_class'=>'mutation','ability_name'=>$rule['ability'],'provider'=>'core','server_id'=>'mad4b-write','payload_sha256'=>str_repeat('e',64),'agent_id'=>1,'expires_at'=>gmdate('Y-m-d H:i:s',time()+300));
$input=array('ticket_id'=>$GLOBALS['ticket']['ticket_id'],'decision'=>'approve','expected_payload_sha256'=>str_repeat('e',64),'expected_classification_sha256'=>str_repeat('f',64),'operation_input'=>$op);
$status=MAD4B_SCP_AI_Approval::delegation_status(MAD4B_SCP_AI_Approval::ABILITY,$input,$identity);
check($status['allowed']&&$status['production_authorized']&&$status['authorization_mode']==='owner_preapproved_exact_production_batch','real AI delegation accepts exact Production content ticket');
$bad=$input;$bad['operation_input']['post_id']=18;check(!MAD4B_SCP_AI_Approval::delegation_status(MAD4B_SCP_AI_Approval::ABILITY,$bad,$identity)['allowed'],'different content target rejected');
$bad=$input;$bad['expected_classification_sha256']=str_repeat('a',64);check(!MAD4B_SCP_AI_Approval::delegation_status(MAD4B_SCP_AI_Approval::ABILITY,$bad,$identity)['allowed'],'classification drift rejected');
$GLOBALS['agent_env']='staging';check(!MAD4B_SCP_AI_Approval::delegation_status(MAD4B_SCP_AI_Approval::ABILITY,$input,$identity)['allowed'],'Staging Agent cannot use Production batch');unset($GLOBALS['agent_env']);
$GLOBALS['grant_env']='staging';check(!MAD4B_SCP_AI_Approval::delegation_status(MAD4B_SCP_AI_Approval::ABILITY,$input,$identity)['allowed'],'Staging grant cannot authorize Production AI');unset($GLOBALS['grant_env']);
$GLOBALS['grant_effect']='deny';check(!MAD4B_SCP_AI_Approval::delegation_status(MAD4B_SCP_AI_Approval::ABILITY,$input,$identity)['allowed'],'exact NHI denial wins');unset($GLOBALS['grant_effect']);
check($a::claim_approval($rule['ability'],'core',$op,$input['ticket_id']),'exact content approval reserved once');
check(!$a::claim_approval($rule['ability'],'core',$op,$input['ticket_id']),'approval replay denied');
$approved=$GLOBALS['ticket']+array('binding_environment'=>'production','approved_by'=>0);
check($a::ticket_delegation_valid($approved),'approved ticket remains bound to batch');
$old=$a::policy();$revoked=$batch;$revoked['enabled']=false;$revoked['approvals']=array();$revoked['deployments']=array();$save($revoked);
check(!$a::ticket_delegation_valid($approved)&&!$a::active(),'revocation invalidates already approved AI ticket before execution');
check(!$a::deployment_authorized($batch['deployments'][0]['input']),'direct installer call has no native delegation');
$save($batch);$GLOBALS['user']=0;$a::run_deployments();
check($GLOBALS['calls']===array('demo/demo.php')&&$GLOBALS['user']===0,'native queued deployment runs once and restores cron user');
$a::run_deployments();check(count($GLOBALS['calls'])===1,'duplicate cron cannot repeat deployment');
$GLOBALS['user']=7;$save($batch);$GLOBALS['changed_package']=true;$a::run_deployments();check(count($GLOBALS['calls'])===1,'package drift blocks queued installation');$GLOBALS['changed_package']=false;
$two=$batch;$two['deployments'][]=job('second/second.php');$save($two);$GLOBALS['fail_install']=true;$a::run_deployments();check(count($GLOBALS['calls'])===2,'failed first deployment stops batch before second plugin');$GLOBALS['fail_install']=false;
$save($batch);add_option($a::LOCK,'other-worker');$a::run_deployments();check(count($GLOBALS['calls'])===2&&get_option($a::LOCK)==='other-worker','worker never steals another deployment lock');delete_option($a::LOCK);
$GLOBALS['owner_alive']=false;check(!$a::active(),'removed owner capability revokes automation');$GLOBALS['owner_alive']=true;
$p=$a::policy();$p['expires_at']=time()-1;$GLOBALS['o'][$a::OPTION]=$p;check(!$a::active(),'expired delegation blocks automation');
$bad=$batch;$bad['approvals'][0]['ability']='mad4b/database-raw-query';check(is_wp_error($save($bad)),'SQL cannot enter approved batch');
$bad=$batch;$bad['approvals'][0]['ability']='mad4b/developer-workspace-apply';check(is_wp_error($save($bad)),'Developer cannot enter approved batch');
$bad=$batch;$bad['hours']=169;check(is_wp_error($save($bad)),'delegation duration bounded');
$bad=$batch;$bad['deployments'][0]['input']['package_url']='https://foreign.test/a.zip';check(is_wp_error($save($bad)),'caller package URL cannot enter deployment');
$bad=$batch;$bad['deployments'][0]['input']['test_attestation']['payload']['tests']['smoke']='FAIL';check(is_wp_error($save($bad)),'unsigned altered test evidence cannot enter batch');
$GLOBALS['audit']=false;check(is_wp_error($save($batch)),'audit unavailable denies batch consent');$GLOBALS['audit']=true;
echo 'production-automation: PASS'.PHP_EOL;
