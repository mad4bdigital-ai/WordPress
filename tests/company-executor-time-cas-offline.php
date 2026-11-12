<?php
/** End-to-end target CAS mock, with EPHEMERAL test signing key only.
 * Never loads a real broker key/private key or touches a WordPress database.
 */
define('ABSPATH','/');
define('WP_CLI',true);
define('ARRAY_A','ARRAY_A');
$GLOBALS['ROW_META']='old-rate-value';
$GLOBALS['COMPANY_DIGEST']=str_repeat('d',64);
$GLOBALS['FORCE_DRIFT']=false;
$GLOBALS['FORCE_UPDATE_FAILURE']=false;
$GLOBALS['OPTIONS']=[];
$GLOBALS['TX_BACKUP']=null;
$GLOBALS['TX_COUNT']=0;
$GLOBALS['WRITES']=0;
$GLOBALS['SITE_DRIFT']=false;
$GLOBALS['ADMIN']=true;
$GLOBALS['TEST_DB']=null;
function add_action(...$args){}
function wp_is_uuid($id){return is_string($id)&&preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',$id);}
function current_user_can($cap){return ($GLOBALS['ADMIN']??true) &&$cap==='manage_options';}
function wp_json_encode($x,$flags=0){return json_encode($x,$flags);}
function wp_cache_delete(...$args){return true;}
function home_url($x='/'){return 'https://wayfatours.com/';}
function untrailingslashit($x){return rtrim($x,'/');}
function wp_get_environment_type(){return 'production';}
function get_post_type_object($type){
 return $type==='tour-rates'?(object)['public'=>true]:null;
}
class WP_CLI{public static function add_command(...$args){}}
class MAD4B_SCP_Site_Profile{
 public static function profile(){return [
  'site_uuid'=>'a3a7cc36-db7e-4a14-8456-b41857def05c',
  'canonical_origin'=>'https://wayfatours.com',
  'environment'=>'production'
 ];}
 public static function status(){return [
   'configured'=>true,'origin_match'=>true,'environment_match'=>true,
   'deployment_binding_match'=>!($GLOBALS['SITE_DRIFT']??false),
   'site_uuid'=>'a3a7cc36-db7e-4a14-8456-b41857def05c'
 ];}
}
class MAD4B_Company_Exact_Resource_Evidence_V1{
 public static function inspect($type,$id){
  if($type!=='wordpress.post'||$id!=='61152')
   return ['state'=>'EXACT_RESOURCE_READBACK_HOLD'];
  $value=$GLOBALS['ROW_META'];
  return [
   'state'=>'EXACT_RESOURCE_READ_ONLY_OBSERVED',
   'resource_key'=>'wordpress.post:61152',
   'site_uuid'=>'a3a7cc36-db7e-4a14-8456-b41857def05c',
   'canonical_origin'=>'https://wayfatours.com',
   'environment'=>'production',
   'before_sha256'=>hash('sha256','post-state:'.$value),
   'resource_manifest_sha256'=>hash('sha256','manifest:'.$value),
   'company_preservation_sha256'=>$GLOBALS['COMPANY_DIGEST']
  ];
 }
}
class FakeDB{
 public $posts='wp_posts',$postmeta='wp_postmeta',$term_relationships='wp_term_relationships',$options='wp_options';
 public function prepare($sql,...$args){return ['sql'=>$sql,'args'=>$args];}
 public function get_row($query,$mode=null){return ['ID'=>61152,'post_type'=>'tour-rates'];}
 public function get_results($query,$mode=null){
  $sql=is_array($query)?$query['sql']:$query;
  if(str_contains($sql,'FROM wp_postmeta')&&str_contains($sql,'meta_key=%s'))
   return [['meta_id'=>902,'meta_value'=>$GLOBALS['ROW_META']]];
  if(str_contains($sql,'FROM wp_postmeta'))return [['meta_id'=>902]];
  if(str_contains($sql,'FROM wp_term_relationships'))return [['term_taxonomy_id'=>1]];
  if(str_contains($sql,'FROM wp_options'))return [];
  return [];
 }
 public function query($q){
  $sql=is_array($q)?$q['sql']:$q;
  if($sql==='START TRANSACTION'){
   $GLOBALS['TX_COUNT']++;
   $GLOBALS['TX_BACKUP']=[
    'value'=>$GLOBALS['ROW_META'],'options'=>$GLOBALS['OPTIONS']
   ];return 1;
  }
  if($sql==='ROLLBACK'){
   $b=$GLOBALS['TX_BACKUP'];
   if($b){$GLOBALS['ROW_META']=$b['value'];$GLOBALS['OPTIONS']=$b['options'];}
   $GLOBALS['TX_BACKUP']=null;return 1;
  }
  if($sql==='COMMIT'){$GLOBALS['TX_BACKUP']=null;return 1;}
  if(str_starts_with(trim($sql),'INSERT INTO wp_options')){
   $args=$q['args'];
   if(isset($GLOBALS['OPTIONS'][$args[0]]))return false;
   $GLOBALS['OPTIONS'][$args[0]]=$args[1];return 1;
  }
  if(str_starts_with(trim($sql),'UPDATE wp_postmeta')){
   if($GLOBALS['FORCE_UPDATE_FAILURE'])return 0;
   $args=$q['args'];
   if($args[1]!==902 ||$args[2]!==61152
     ||$args[3]!=='rate_title'||$GLOBALS['ROW_META']!==$args[4])
      return 0;
   $GLOBALS['ROW_META']=$args[0];
   $GLOBALS['WRITES']++;
   return 1;
  }
  throw new RuntimeException('UNEXPECTED_SQL');
 }
}
$GLOBALS['wpdb']=new FakeDB();
$keypair=sodium_crypto_sign_keypair();
$testPub=base64_encode(sodium_crypto_sign_publickey($keypair));
$secret=sodium_crypto_sign_secretkey($keypair);
$path=dirname(__DIR__).'/wp-content/mu-plugins/mad4b-company-executor-time-cas-v1.php';
$source=file_get_contents($path);
$pattern="/public const ORIGINAL_305_PUBLIC_B64='[A-Za-z0-9+\\/=]+';/";
$replace="public const ORIGINAL_305_PUBLIC_B64='".$testPub."';";
$transformed=preg_replace($pattern,$replace,$source,1,$count);
if($count!==1)throw new Exception('EPHEMERAL_TEST_KEY_INJECTION_FAILED');
// eval runs from the test file; resolve the source-owned policy under /work
// rather than the mock test directory. Never change policy bytes.
$transformed=str_replace("__DIR__",
 "dirname(__DIR__).'/wp-content/mu-plugins'",$transformed);
eval('?>'.$transformed);
$cls='MAD4B_Company_Executor_Time_CAS_V1';
$tests=[];
$test=static function($label,$pass)use(&$tests){$tests[$label]=(bool)$pass;};
$old='old-rate-value';$new='new-rate-value';
$fields=[
 'contract'=>$cls::CONTRACT,
 'original_job_id'=>str_repeat('a',32),'company_code'=>'wayfatours',
 'environment'=>'production',
 'site_uuid'=>'a3a7cc36-db7e-4a14-8456-b41857def05c',
 'canonical_origin'=>'https://wayfatours.com',
 'native_operation'=>'company.resource.cas.apply',
 'source_plan_sha256'=>str_repeat('c',64),
 'resource_type'=>'wordpress.post','resource_id'=>'61152',
 'post_type'=>'tour-rates','meta_key'=>'rate_title','meta_id'=>902,
 'expected_before_sha256'=>hash('sha256','post-state:'.$old),
 'expected_manifest_sha256'=>hash('sha256','manifest:'.$old),
 'expected_company_preservation_sha256'=>str_repeat('d',64),
 'expected_meta_value_sha256'=>hash('sha256',$old),
 'new_meta_value_sha256'=>hash('sha256',$new),
 'expected_after_sha256'=>hash('sha256','post-state:'.$new),
 'issued_epoch'=>time(),'expires_epoch'=>time()+180,
 'nonce'=>str_repeat('f',32)
];
$sign=static function($fields)use($cls,$secret){
 $message=implode("\n",array_map(
  static fn($f)=>(string)$fields[$f],$cls::FIELDS));
 return $fields+['signature'=>base64_encode(
    sodium_crypto_sign_detached($message,$secret))];
};
$proof=$sign($fields);
$review=$cls::inspect_only('61152',$fields['expected_before_sha256'],
    $fields['expected_manifest_sha256'],
    $fields['expected_company_preservation_sha256']);
$test('readonly_exact_before_comparison',
 $review['state']==='PRECONDITION_MATCH_READ_ONLY_UNSIGNED'
 &&$review['before_cas_verified']===false
 &&$review['atomic_mutation_applied']===false);
$bad=$cls::inspect_only('61152',str_repeat('0',64),
    $fields['expected_manifest_sha256'],
    $fields['expected_company_preservation_sha256']);
$test('readonly_wrong_digest_fails',
 $bad['state']==='PRECONDITION_DRIFT_READ_ONLY_HOLD');
$unsigned=$proof;$unsigned['signature']=base64_encode(str_repeat('x',64));
$bad=$cls::apply_signed($unsigned,$new);
$test('unsigned_original_job_witness_rejected',
 $bad['state']==='EXECUTOR_CAS_HOLD'
 &&$bad['reason']==='SIGNED_ORIGINAL_JOB_CAS_WITNESS_INVALID');
$test('unsigned_refusal_never_opens_transaction',$GLOBALS['TX_COUNT']===0);
$wrong=$cls::apply_signed($proof,'different-content');
$test('signed_value_digest_mismatch_rejected',
 $wrong['reason']==='SIGNED_ORIGINAL_JOB_CAS_WITNESS_INVALID');
$priceAttempt=$fields;$priceAttempt['meta_key']='single_price';
$bad=$cls::apply_signed($sign($priceAttempt),$new);
$test('target_enforces_source_owned_price_field_exclusion_even_if_signed',
 $bad['reason']==='FIRST_PARTY_TARGET_PROVIDER_SCOPE_NOT_ADMITTED'
 &&$GLOBALS['TX_COUNT']===0);
$wrongOperation=$fields;$wrongOperation['native_operation']='unregistered.provider.cas';
$bad=$cls::apply_signed($sign($wrongOperation),$new);
$test('target_blocks_unregistered_source_provider_even_if_signed',
 $bad['reason']==='FIRST_PARTY_TARGET_PROVIDER_SCOPE_NOT_ADMITTED'
 &&$GLOBALS['TX_COUNT']===0);
$oldProof=$fields;$oldProof['expected_before_sha256']=str_repeat('0',64);
$oldProof=$sign($oldProof);
$bad=$cls::apply_signed($oldProof,$new);
$test('signed_original_changed_post_refused',
 $bad['reason']==='CAS_EXECUTOR_TIME_BEFORE_IMAGE_CHANGED'
 &&$GLOBALS['ROW_META']===$old);
$test('before_drift_never_persists_replay_option',count($GLOBALS['OPTIONS'])===0);
$GLOBALS['COMPANY_DIGEST']=str_repeat('e',64);
$bad=$cls::apply_signed($proof,$new);
$test('company_preservation_changed_holds',
 $bad['reason']==='CAS_EXECUTOR_TIME_BEFORE_IMAGE_CHANGED'
 &&$GLOBALS['ROW_META']===$old);
$GLOBALS['COMPANY_DIGEST']=str_repeat('d',64);
$wrongAfter=$fields;$wrongAfter['expected_after_sha256']=str_repeat('0',64);
$wrongAfter=$sign($wrongAfter);
$bad=$cls::apply_signed($wrongAfter,$new);
$test('postimage_drift_rolls_back_atomically',
 $bad['reason']==='CAS_POSTIMAGE_OR_COMPANY_PRESERVATION_MISMATCH'
 &&$GLOBALS['ROW_META']===$old &&count($GLOBALS['OPTIONS'])===0);
$GLOBALS['FORCE_UPDATE_FAILURE']=true;
$bad=$cls::apply_signed($proof,$new);
$test('conditional_sql_update_failed_rolls_back',
 $bad['reason']==='CAS_ATOMIC_META_COMPARE_FAILED'
 &&$GLOBALS['ROW_META']===$old &&count($GLOBALS['OPTIONS'])===0);
$GLOBALS['FORCE_UPDATE_FAILURE']=false;
$GLOBALS['SITE_DRIFT']=true;
$bad=$cls::apply_signed($proof,$new);
$test('site_identity_drift_blocks_before_sql',
 $bad['reason']==='ORIGINAL_COMPANY_TARGET_IDENTITY_MISMATCH');
$GLOBALS['SITE_DRIFT']=false;
$GLOBALS['ADMIN']=false;
$bad=$cls::apply_signed($proof,$new);
$test('non_admin_cannot_apply',
 $bad['reason']==='ORIGINAL_CLI_ADMIN_REQUIRED');
$GLOBALS['ADMIN']=true;
$wrongSite=$fields;$wrongSite['site_uuid']='a3a7cc36-db7e-4a14-8456-b41857def05f';
$bad=$cls::apply_signed($sign($wrongSite),$new);
$test('signed_wrong_site_rejected',$bad['reason']==='ORIGINAL_COMPANY_TARGET_IDENTITY_MISMATCH');
$future=$fields;$future['expires_epoch']=time()-1;
$bad=$cls::apply_signed($sign($future),$new);
$test('expired_broker_witness_rejected',
 $bad['reason']==='SIGNED_ORIGINAL_JOB_CAS_WITNESS_INVALID');
$ok=$cls::apply_signed($proof,$new);
$test('genuine_ephemeral_test_signature_atomic_apply_success',
 $ok['state']==='ATOMIC_CAS_APPLIED_PENDING_INDEPENDENT_313'
 &&$ok['before_cas_verified']===true
 &&$ok['atomic_mutation_applied']===true
 &&$GLOBALS['ROW_META']===$new);
$test('success_has_no_original313_claim',
 $ok['original_313_signed']===false
 &&$ok['automatic_retry_authorized']===false);
$test('replay_fence_durable_once',
 count($GLOBALS['OPTIONS'])===1 &&$GLOBALS['WRITES']>=1);
$replayed=$cls::apply_signed($proof,$new);
$test('same_signed_job_replay_not_permitted',
 $replayed['state']==='EXECUTOR_CAS_HOLD');
$test('replay_never_mutates_again',$GLOBALS['ROW_META']===$new);
$test('new_signed_job_cannot_apply_stale_before',
 $cls::apply_signed($sign(array_replace($fields,[
  'original_job_id'=>str_repeat('b',32)])),$new)['reason']
 ==='CAS_EXECUTOR_TIME_BEFORE_IMAGE_CHANGED');
echo json_encode(['pass'=>!in_array(false,$tests,true),
 'assertions'=>count($tests),
 'failed'=>array_keys(array_filter($tests,fn($v)=>!$v)),
 'test_broker_key_is_ephemeral'=>true,'real_broker_private_key_used'=>false,
 'real_site_modified'=>false,'original_313_signed'=>false],
 JSON_UNESCAPED_SLASHES).PHP_EOL;
if(in_array(false,$tests,true))exit(1);
