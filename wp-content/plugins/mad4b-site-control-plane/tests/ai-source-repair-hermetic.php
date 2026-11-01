<?php
/** Synthetic original Source Repair lifecycle; private /tmp only, no live WP data. */
define('ABSPATH',__DIR__.'/');
$root=sys_get_temp_dir().'/mad4b-ai-source-repair-'.bin2hex(random_bytes(5));
$uploads=$root.'/uploads';$sub=$uploads.'/2026';mkdir($sub,0700,true);
$path=$sub.'/brand-context.txt';
$old="Brand Core / Version 1\n".str_repeat("Original business context and market notes. ",13);
$next="Brand Core / Version 1\n".str_repeat("Revised context with stronger examples and scope. ",14);
file_put_contents($path,$old);chmod($path,0600);
$GLOBALS['path']=$path;$GLOBALS['uploads']=$uploads;$GLOBALS['old']=$old;$GLOBALS['next']=$next;
$GLOBALS['bound']=true;$GLOBALS['model_enabled']=true;$GLOBALS['model_repair']=true;
$GLOBALS['admin']=true;$GLOBALS['policy']=true;$GLOBALS['environment']='local';
$GLOBALS['asset_id']=hash('sha256','media|11');$GLOBALS['asset_status']='needs_changes';
$GLOBALS['content_hash']=hash('sha256',$old);
$GLOBALS['review_hash']=$GLOBALS['content_hash'];
$GLOBALS['source_provider']='wordpress_media';
$GLOBALS['scan_fail']=false;$GLOBALS['scan_count']=0;$GLOBALS['registry_writes']=0;
class WP_Error{private $code;function __construct($code,$message='',$data=[]){$this->code=$code;}function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_get_environment_type(){return $GLOBALS['environment'];}
function current_user_can($cap){return $GLOBALS['admin']&&$cap==='manage_options';}
function get_current_user_id(){return 17;}
function get_post_type($id){return $id===11?'attachment':false;}
function get_post_mime_type($id){return $id===11?'text/plain':'application/zip';}
function get_attached_file($id){return $id===11?$GLOBALS['path']:false;}
function wp_get_upload_dir(){return ['basedir'=>$GLOBALS['uploads']];}
function wp_check_invalid_utf8($data,$strip=false){return preg_match('//u',(string)$data)?(string)$data:'';}
class MAD4B_SCP_Local_Client{static function bound(){return $GLOBALS['bound'];}}
class MAD4B_SCP_AI_Model_Router{
 static function settings(){return ['enabled'=>$GLOBALS['model_enabled'],'allow_source_repair'=>$GLOBALS['model_repair']];}
}
class MAD4B_SCP_Policy{static function can_connect_user($id){return $id===17&&$GLOBALS['policy'];}}
class MAD4B_SCP_Context_Authority {
 static function asset($id){
  return $id===$GLOBALS['asset_id']?[
   'source_id'=>'media','source_mode'=>'governed',
   'review_status'=>$GLOBALS['asset_status'],'content_hash'=>$GLOBALS['content_hash'],
   'reviewed_content_hash'=>$GLOBALS['review_hash'],'file_id'=>11
  ]:false;
 }
 static function source($id){return $id==='media'?['provider'=>$GLOBALS['source_provider']]:false;}
 static function registry_revision(){return 8;}
}
class MAD4B_SCP_WordPress_Media_Context {
 static function normalize_bytes($mime,$bytes){
  if($mime!=='text/plain'||!preg_match('//u',$bytes))return new WP_Error('invalid_text');
  return ['content_complete'=>true,'normalized_text'=>$bytes];
 }
}
class MAD4B_SCP_Context_Source_Pipeline{
 static function scan_complete($source_id,$opts){
  $GLOBALS['scan_count']++;
  if($GLOBALS['scan_fail'])return new WP_Error('mock_rescan_failed');
  $GLOBALS['registry_writes']++;
  return ['scan_complete'=>true,'asset_count'=>1];
 }
}
require dirname(__DIR__).'/includes/class-mad4b-scp-ai-source-repair.php';
$n=0;
function ck($name,$pass){global $n;if(!$pass){fwrite(STDERR,'FAIL '.$name.PHP_EOL);exit(1);}$n++;}
function code_is($v,$code){return is_wp_error($v)&&$v->get_error_code()===$code;}
$repair='MAD4B_SCP_AI_Source_Repair';
$id=$GLOBALS['asset_id'];$hash=$GLOBALS['content_hash'];
ck('non-string asset ID denied before original scan',code_is($repair::apply_registered_text_revision(['bad'],$hash,$next),'mad4b_ai_repair_input_invalid'));
ck('non-SHA content hash denied',code_is($repair::apply_registered_text_revision($id,'not-a-sha',$next),'mad4b_ai_repair_input_invalid'));
ck('non-string model output denied',code_is($repair::apply_registered_text_revision($id,$hash,['bad']),'mad4b_ai_repair_input_invalid'));
ck('no initial IO side effects',$GLOBALS['scan_count']===0&&file_get_contents($path)===$old);
ck('MIME must be text or markdown',code_is($repair::validate_revision($old,$next,'application/pdf'),'mad4b_ai_repair_type_denied'));
ck('revision must be substantial',code_is($repair::validate_revision($old,'Brand Core / Version 1\nshort','text/plain'),'mad4b_ai_repair_bounds'));
ck('unchanged content refused',code_is($repair::validate_revision($old,$old,'text/plain'),'mad4b_ai_repair_unchanged'));
$changedHeader='Foreign Brand Header\n'.str_repeat('Market copy ',45);
ck('document identity heading bound',code_is($repair::validate_revision($old,$changedHeader,'text/plain'),'mad4b_ai_repair_identity_drift'));
$manual="Brand Core / Version 1\nDRAFT FOR OWNER REVIEW\n".str_repeat('Other original text ',32);
ck('old manual approval marker cannot recur',code_is($repair::validate_revision($old,$manual,'text/plain'),'mad4b_ai_repair_manual_review_regression'));
$newExternal="Brand Core / Version 1\n".str_repeat('Updated evidence https://invented-source.example/ ',12);
ck('invented provenance URL denied',code_is($repair::validate_revision($old,$newExternal,'text/plain'),'mad4b_ai_repair_new_external_urls'));
ck('invalid UTF8 denied',code_is($repair::validate_revision($old,"Brand Core / Version 1\n".str_repeat("x",650)."\xff",'text/plain'),'mad4b_ai_repair_text_invalid'));
ck('correct revision can pass structural validation',$repair::validate_revision($old,$next,'text/plain')===true);
$GLOBALS['environment']='production';
ck('Production never repairs source',code_is($repair::apply_registered_text_revision($id,$hash,$next),'mad4b_ai_repair_scope_denied'));
$GLOBALS['environment']='local';$GLOBALS['bound']=false;
ck('unenrolled Local Client denied',code_is($repair::apply_registered_text_revision($id,$hash,$next),'mad4b_ai_repair_scope_denied'));
$GLOBALS['bound']=true;$GLOBALS['model_enabled']=false;
ck('disabled AI route denied',code_is($repair::apply_registered_text_revision($id,$hash,$next),'mad4b_ai_repair_disabled'));
$GLOBALS['model_enabled']=true;$GLOBALS['admin']=false;
ck('operator permission required',code_is($repair::apply_registered_text_revision($id,$hash,$next),'mad4b_ai_repair_operator_denied'));
$GLOBALS['admin']=true;
$GLOBALS['asset_status']='unreviewed';
ck('needs_changes review receipt required',code_is($repair::apply_registered_text_revision($id,$hash,$next),'mad4b_ai_repair_review_required'));
$GLOBALS['asset_status']='needs_changes';$GLOBALS['source_provider']='google_drive';
ck('only original WP Media can be repaired',code_is($repair::apply_registered_text_revision($id,$hash,$next),'mad4b_ai_repair_source_denied'));
$GLOBALS['source_provider']='wordpress_media';
$GLOBALS['review_hash']=str_repeat('f',64);
ck('review CAS drift denied',code_is($repair::apply_registered_text_revision($id,$hash,$next),'mad4b_ai_repair_drift'));
$GLOBALS['review_hash']=$hash;
$GLOBALS['scan_fail']=true;
$failed=$repair::apply_registered_text_revision($id,$hash,$next);
ck('rescan failure surfaced without approval',code_is($failed,'mad4b_ai_repair_rescan_failed'));
ck('original bytes restored after denied rescan',file_get_contents($path)===$old&&$GLOBALS['registry_writes']===0);
$GLOBALS['scan_fail']=false;
$good=$repair::apply_registered_text_revision($id,$hash,$next);
ck('owner reviewed repair applied after successful scan',is_array($good)&&$good['applied']===true&&$good['rescan_performed']===true);
ck('new bytes persisted in private fixture only',file_get_contents($path)===$next);
ck('source review not auto-approved',!$good['approved']&&!$good['publication_authorized']);
ck('expected original exact SHA preserved',$good['previous_content_hash']===$hash&&$good['new_content_hash']===hash('sha256',$next));
ck('exact scoped rescan after successful repair',$GLOBALS['scan_count']===2&&$GLOBALS['registry_writes']===1);
ck('old CAS denied if invoked again',code_is($repair::apply_registered_text_revision($id,$hash,$next),'mad4b_ai_repair_disk_drift'));
unlink($path);rmdir($sub);rmdir($uploads);rmdir($root);
echo 'PASS: '.$n.' original Source Repair local-only and CAS/rollback cases'.PHP_EOL;
