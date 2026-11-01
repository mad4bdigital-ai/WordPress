<?php
/** Hermetic original Context Source Pipeline authority and complete-scan gate. */
define('ABSPATH',__DIR__.'/');
$GLOBALS['admin']=true;$GLOBALS['policy']=true;$GLOBALS['writes']=0;
$GLOBALS['scan_calls']=0;$GLOBALS['media_complete']=true;
$GLOBALS['drive_complete']=true;$GLOBALS['admit']=true;$GLOBALS['last_scan']=null;
$GLOBALS['source']=[
 'media'=>['source_id'=>'media','provider'=>'wordpress_media','label'=>'Local Media'],
 'drive'=>['source_id'=>'drive','provider'=>'google_drive','external_root_id'=>'root','recursive'=>true],
 'invalid'=>['source_id'=>'invalid','provider'=>'unknown']
];
class WP_Error{private $code;function __construct($code,$message=''){$this->code=$code;}function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function current_user_can($cap){return $GLOBALS['admin']&&$cap==='manage_options';}
function get_current_user_id(){return 3;}
function absint($v){return abs((int)$v);}
function wp_json_encode($v){return json_encode($v);}
class MAD4B_SCP_Policy{static function can_connect_user($user){return $GLOBALS['policy'] && $user===3;}}
class MAD4B_SCP_Context_Authority {
 static function source($id){return $GLOBALS['source'][$id]??null;}
 static function registry_revision(){return 7;}
 static function assets(){return [hash('sha256','drive|absent')=>['source_id'=>'drive','content_hash'=>str_repeat('a',64)]];}
 static function review_queue(){return ['counts'=>['pending'=>4]];}
 static function replace_source_assets($id,$assets,$meta){
  $GLOBALS['writes']++;$GLOBALS['last_scan']=['source'=>$id,'assets'=>$assets,'meta'=>$meta];
  return ['scan_complete'=>true,'source_id'=>$id,'assets_reconciled'=>count($assets)];
 }
}
class MAD4B_SCP_WordPress_Media_Context {
 static function attachment_record($id){return [
 'file_id'=>(string)$id,'content_hash'=>hash('sha256','test-media-'.$id),
 'provider'=>'wordpress_media','content_complete'=>true];}
 static function inventory($types,$source){
  $GLOBALS['scan_calls']++;
  return ['complete'=>true,'content_complete'=>$GLOBALS['media_complete'],
   'assets'=>[self::attachment_record(11)],'scope'=>['mime_types'=>$types]];
 }
}
class MAD4B_SCP_Google_Drive_Context {
 static function scan_folder($id,$recursive){
  $GLOBALS['scan_calls']++;
  return ['complete'=>$GLOBALS['drive_complete'],
   'assets'=>[['file_id'=>'one','content_hash'=>str_repeat('b',64)],
             ['file_id'=>'two','content_hash'=>str_repeat('c',64)]]];
 }
}
class MAD4B_SCP_Brand_Context_Builder {
 static function source_scan_apply_admission($policy,$scan){
  return $GLOBALS['admit']?true:new WP_Error('context_admission_denied');
 }
}
require dirname(__DIR__).'/includes/class-mad4b-scp-context-source-pipeline.php';
$n=0;
function ck($name,$result){global $n;if(!$result){fwrite(STDERR,'FAIL '.$name.PHP_EOL);exit(1);}$n++;}
function denied($v,$code){return is_wp_error($v)&&$v->get_error_code()===$code;}
$owner='MAD4B_SCP_Context_Source_Pipeline';
ck('unknown source refused',denied($owner::plan('absent'),'mad4b_pipeline_source_missing'));
ck('unsupported provider refused',denied($owner::plan('invalid'),'mad4b_pipeline_provider_unknown'));
ck('media needs explicit IDs',denied($owner::plan('media'),'mad4b_pipeline_media_scope_invalid'));
ck('media invalid zero IDs refused',denied($owner::plan('media',['attachment_ids'=>[0]]),'mad4b_pipeline_media_scope_invalid'));
ck('media over 25 IDs refused',denied($owner::plan('media',['attachment_ids'=>range(1,26)]),'mad4b_pipeline_media_scope_invalid'));
$plan=$owner::plan('media',['attachment_ids'=>[11,12,11]]);
ck('media plan deduplicates 1-25 exact IDs',is_array($plan)&&count($plan['records'])===2 &&count($plan['changes'])===2);
ck('media plan review only',($plan['stages']['approve']??'')==='manual_only'
 &&$plan['stages']['review']==='pending' &&$plan['destructive_changes']===false);
ck('media plan source and record fingerprints',strlen($plan['records_fingerprint'])===64&&strlen($plan['source_fingerprint'])===64);
ck('media partial sync denied',denied($owner::sync($plan),'mad4b_pipeline_media_use_complete_scan'));
$bad=$plan;$bad['source_fingerprint']=str_repeat('0',64);
ck('source CAS drift denied',denied($owner::sync($bad),'mad4b_pipeline_source_changed'));
$bad=$plan;$bad['provider']='google_drive';
ck('source provider drift denied',denied($owner::sync($bad),'mad4b_pipeline_provider_changed'));
$GLOBALS['admin']=false;
ck('sync without admin refused',denied($owner::sync($plan),'mad4b_pipeline_write_denied'));
ck('complete scan requires administrator',denied($owner::scan_complete('media'),'mad4b_pipeline_scan_denied'));
$GLOBALS['admin']=true;$GLOBALS['policy']=false;
ck('complete scan requires original user policy',denied($owner::scan_complete('media'),'mad4b_pipeline_scan_denied'));
$GLOBALS['policy']=true;
ck('complete scan requires registered source',denied($owner::scan_complete('unknown'),'mad4b_pipeline_source_missing'));
ck('unsupported scan provider denied',denied($owner::scan_complete('invalid'),'mad4b_pipeline_provider_unknown'));
$GLOBALS['media_complete']=false;
ck('incomplete media content cannot apply',denied($owner::scan_complete('media'),'mad4b_pipeline_content_normalization_required'));
$GLOBALS['media_complete']=true;$GLOBALS['admit']=false;
ck('independent source admission enforced',denied($owner::scan_complete('media'),'context_admission_denied'));
$GLOBALS['admit']=true;
$result=$owner::scan_complete('media',['mime_types'=>['text/plain']]);
ck('complete source inventory proceeds through original owner',
 is_array($result)&&($result['scan_complete']??false)===true&&$GLOBALS['writes']===1);
ck('applied scan CAS and explicit scope',$GLOBALS['last_scan']['meta']['expected_registry_revision']===7
 &&$GLOBALS['last_scan']['meta']['scope']['mime_types']===['text/plain']);
ck('direct Drive scan requires opt-in',denied($owner::plan('drive'),'mad4b_pipeline_drive_scan_explicit'));
$GLOBALS['drive_complete']=false;
ck('incomplete Drive inventory cannot plan',denied($owner::plan('drive',['allow_live_scan'=>true]),'mad4b_pipeline_drive_scan_incomplete'));
$GLOBALS['drive_complete']=true;
$drivePlan=$owner::plan('drive',['allow_live_scan'=>true]);
ck('complete Drive plan pending manual review',is_array($drivePlan)
 &&count($drivePlan['records'])===2 &&$drivePlan['stages']['approve']==='manual_only');
ck('Drive missing candidates advisory only',count($drivePlan['missing_candidates'])===1&&!$drivePlan['absence_mutation_allowed']);
ck('Drive sync always needs separate certified workflow',denied($owner::sync($drivePlan),'mad4b_pipeline_drive_requires_certified_apply'));
$bad=$drivePlan;$bad['absence_mutation_allowed']=true;
ck('Drive absence mutation explicitly denied',denied($owner::sync($bad),'mad4b_pipeline_absence_mutation_denied'));
ck('exact approved complete scan was only fixture write',$GLOBALS['writes']===1);
echo 'PASS: '.$n.' source pipeline admission and review-only checks'.PHP_EOL;
