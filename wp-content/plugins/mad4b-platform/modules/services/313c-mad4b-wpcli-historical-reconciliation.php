<?php
/**
 * Original MAD4B read-only historical WPCLI reconciliation.
 * A separate historical receipt is derived from two ORIGINAL persisted
 * attestations (host-signed pending 312 and independent-signed 313).
 * No original 313 finish is fabricated; no Job or command is reissued.
 */
defined('ABSPATH') || exit;
final class MAD4B_Original_WPCLI_Historical_Reconciliation_V1 {
 const CONTRACT='mad4b.original-wpcli-historical-evidence-reconciliation.v1';
 const PREFIX='mad4b_original_wpcli_historical_reconciliation_v1_';
 const STATE='WPCLI_HISTORICAL_EXECUTION_READBACK_RECONCILED';
 // Scope is restricted to ORIGINAL on-host transport enrollment.
 // Cross-host reconciliation requires an independently scoped host verifier.
 const HOST='srv2030908';
 const MAX_AGE=604800;
 private static function allowed_readonly_command(string $digest):bool {
  // Canonical argv JSON; never trust a free-form WP-CLI command from a Job.
  return in_array($digest,[
    hash('sha256','["option","get","home"]'),
    hash('sha256','["option","get","siteurl"]')
  ],true);
 }
 /** Only the exact, already owner-approved Experience first-install job.
  * A historical readback cannot authorize a second install or a new Job.
  */
 private static function allowed_experience_install(string $id,array $job,array $proof):bool {
  if(($job['company']??null)!=='wayfatours'
    ||($job['environment']??null)!=='production'
    ||($job['canonical_origin']??null)!=='https://wayfatours.com'
    ||($job['site_uuid']??null)!=='a3a7cc36-db7e-4a14-8456-b41857def05c'
    ||($job['command_sha256']??null)!=='c9d3df50b400028819ea3ad26087ba3536ef14efc355c91b19a64c5d619feb1d'
    ||($proof['contract']??null)!=='mad4b.native-313-independent-host-proof.v2'
    ||($proof['observed_operation']??null)!=='plugin.install'
    ||($proof['production_mutation']??null)!=='true')return false;
  $owner=get_option('mad4b_company_exact_experience_install_owner_job_v1',false);
  return is_array($owner)
    &&($owner['job_id']??null)===$id
    &&($owner['command_sha256']??null)===$job['command_sha256']
    &&($owner['owner_user_id']??null)===$job['central_principal_id'];
 }
 private static function deny($reason):array {
  return ['contract'=>self::CONTRACT,'verified'=>false,'historical_reconciled'=>false,
   'original_313_certified'=>false,'execution_performed'=>false,
   'production_mutation'=>false,'reason'=>$reason];
 }
 private static function same($a,$b,array $names):bool {
  if(!is_array($a)||!is_array($b))return false;
  foreach($names as $name)if(!array_key_exists($name,$a)
     ||!array_key_exists($name,$b)||$a[$name]!==$b[$name])return false;
  return true;
 }
 public static function verify(string $id):array {
  if(!defined('WP_CLI')||!WP_CLI||!current_user_can('manage_options')
    ||get_current_user_id()<1
    ||!class_exists('MAD4B_Central_Dual_Lane_Registry_V1',false)
    ||!MAD4B_Central_Dual_Lane_Registry_V1::central())
    return self::deny('CENTRAL_ORIGINAL_OWNER_REQUIRED');
  if(!preg_match('/^[a-f0-9]{32}$/D',$id))return self::deny('JOB_ID_INVALID');
  foreach(['MAD4B_Central_VPS_Broker_V1','MAD4B_Central_SSH_Command_Intake_V1',
   'MAD4B_Broker_Remote_SSH_Receipt_V1','MAD4B_Native_WPCLI_Receipt_Verifier_V1',
   'MAD4B_Company_Environment_Registry_V2',
   'MAD4B_Original_Verified_WPCLI_Transport_V1',
   'MAD4B_Manager_Auto_Write_V1',
   'MAD4B_Company_Operation_Admission_V1',
   'MAD4B_Company_Portfolio_Manager_V1'] as $class)
    if(!class_exists($class,false))return self::deny('ORIGINAL_CORE_NOT_LOADED');
  $broker='MAD4B_Central_VPS_Broker_V1';
  $intake='MAD4B_Central_SSH_Command_Intake_V1';
  $receipt='MAD4B_Broker_Remote_SSH_Receipt_V1';
  $independent='MAD4B_Native_WPCLI_Receipt_Verifier_V1';
  $job=get_option($broker::JOB.$id,false);
  $claim=get_option($broker::CLAIM.$id,false);
  $req=get_option($intake::REQUEST_PREFIX.$id,false);
  $pending=get_option($receipt::WPCLI_PREFIX.$id,false);
  $proof=get_option($independent::PREFIX.$id,false);
  if(!is_array($job)||!is_array($claim)||!is_array($req)
    ||!is_array($pending)||!is_array($proof))
    return self::deny('ORIGINAL_305_306_312_313_RECORDS_MISSING');
  $mutating=self::allowed_experience_install($id,$job,$proof);
  $readonly=self::allowed_readonly_command((string)($job['command_sha256']??''));
  if(($job['job_id']??null)!==$id
    ||($job['job_type']??null)!=='signed_vps_wpcli'
    ||($job['command_format']??null)!=='wpcli.argv.json.v1'
    ||($job['operation']??null)!=='company.remote.wpcli.full'
    ||($job['target_scope']??null)!=='wordpress'
    ||($job['grant_mode']??null)!=='persistent'
    ||($job['central_principal_id']??null)!==get_current_user_id()
    ||($job['target_host_id']??null)!==self::HOST
    ||($job['broker_host_id']??null)!==self::HOST
    ||!is_string($job['company']??null)
    ||!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$job['company'])
    ||$job['company']==='tours-central'
    ||!in_array($job['environment']??null,['staging','production'],true)
    ||!is_string($job['canonical_origin']??null)
    ||!filter_var($job['canonical_origin'],FILTER_VALIDATE_URL)
    ||parse_url($job['canonical_origin'],PHP_URL_SCHEME)!=='https'
    ||!is_string($job['command_sha256']??null)
    ||!($readonly||$mutating))
    return self::deny('EXACT_ORIGINAL_READONLY_JOB_REQUIRED');
  $saved=get_option(self::PREFIX.$id,false);
  $was_reconciled=is_array($saved);
  $job_states=$was_reconciled?[self::STATE,'BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY']:
    ['BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'];
  $pending_states=$was_reconciled?[self::STATE,'PENDING_INDEPENDENT_NATIVE_READBACK']:
    ['PENDING_INDEPENDENT_NATIVE_READBACK'];
  if(!in_array($job['state']??null,$job_states,true)
    ||!in_array($pending['state']??null,$pending_states,true)
    ||!in_array($job['executed']??null,$was_reconciled?[false,true]:[false],true)
    ||get_option($receipt::WPCLI_FINISH_PREFIX.$id,false)!==false)
    return self::deny('NORMAL_313_ORIGINAL_STATE_CONFLICT');
  if(($claim['job_id']??null)!==$id
    ||($claim['state']??null)!=='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'
    ||($claim['host_id']??null)!==self::HOST
    ||($req['request_id']??null)!==$id
    ||($req['status']??null)!=='ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY'
    ||($req['operation']??null)!=='company.remote.wpcli.full'
    ||($req['target_scope']??null)!=='wordpress'
    ||($req['command_format']??null)!=='wpcli.argv.json.v1'
    ||!self::same($job,$req,['company','environment','site_uuid','canonical_origin',
     'grant_id','transport_id','command_sha256','correlation_id','expires_epoch']))
    return self::deny('ORIGINAL_305_306_CLAIM_SCOPE_DRIFT');
  $corr=$job['correlation_id']??null;
  if(!is_string($corr)||$corr===''||strlen($corr)>150)
    return self::deny('ORIGINAL_CORRELATION_MISSING');
  $consumed=$intake::CLAIM_PREFIX.$job['grant_id'].'_'.hash('sha256',$corr);
  if(get_option($consumed,false)!==$id)
    return self::deny('ORIGINAL_PER_CORRELATION_305_CLAIM_MISSING');
  $grant=get_option($intake::PREFIX.$job['grant_id'],false);
  $transport=get_option($intake::TRANSPORT_PREFIX.$job['transport_id'],false);
  if(!is_array($grant)||!is_array($transport)
    ||($grant['state']??null)!=='CENTRAL_OPERATION_SCOPE_GRANTED'
    ||($grant['operation']??null)!=='company.remote.wpcli.full'
    ||($grant['grant_mode']??null)!=='persistent'
    ||($grant['transport_id']??null)!==$job['transport_id']
    ||!array_key_exists('expires_epoch',$grant)
    ||($grant['target_host_id']??null)!==self::HOST
    ||($grant['target_ssh_principal']??null)!=='root'
    ||($transport['host_id']??null)!==self::HOST
    ||($transport['host_principal']??null)!=='root'
    ||($transport['credential_ref']??null)!=='vault:registry/local-srv2030908'
    ||($transport['full_wpcli_grant_id']??null)!==$job['grant_id']
    ||($transport['capabilities']??null)!==['wpcli.full']
    ||($grant['expires_epoch']??null)!==null && (int)$grant['expires_epoch']<=time()
    ||!self::same($job,$grant,['company','environment','site_uuid','canonical_origin',
      'central_principal_id'])
    ||!self::same($job,$transport,['company','environment','site_uuid',
      'canonical_origin','central_principal_id'])
    ||(($transport['full_wpcli_pending_native_enrollment']??null)!==true
        &&!MAD4B_Original_Verified_WPCLI_Transport_V1::active($transport,$grant))
    ||!MAD4B_Original_Verified_WPCLI_Transport_V1::permitted($transport,$grant))
    return self::deny('ORIGINAL_ENROLLED_WPCLI_GRANT_REVOKED');
  $company=$job['company'];
  $environment=$job['environment'];
  $target=MAD4B_Manager_Auto_Write_V1::company_target($company);
  $manager=MAD4B_Company_Operation_Admission_V1::manager_id();
  if(!is_array($target)||($target['state']??null)!=='REGISTERED'
    ||($target['site_uuid']??null)!==$job['site_uuid']
    ||($target['canonical_origin']??null)!==$job['canonical_origin']
    ||($target['environment']??null)!==$environment
    ||!MAD4B_Company_Portfolio_Manager_V1::has_delegation(
      $manager,$company,'operations_manage',get_current_user_id()))
    return self::deny('CURRENT_OWNER_COMPANY_DELEGATION_REVOKED');
  $site=MAD4B_Company_Environment_Registry_V2::target($company,$environment);
  if(!is_array($site)||($site['site_uuid']??null)!==$job['site_uuid']
    ||($site['canonical_origin']??null)!==$job['canonical_origin'])
    return self::deny('COMPANY_PRODUCTION_BINDING_CHANGED');
  $created=$job['created_epoch']??null;
  $claimed=$claim['claimed_epoch']??null;
  $expires=$job['expires_epoch']??null;
  $issued=$proof['issued']??null;
  $proof_exp=$proof['expires']??null;
  $received=$pending['received_epoch']??null;
  if(!is_int($created)||!is_int($claimed)||!is_int($expires)
    ||!is_int($issued)||!is_int($proof_exp)||!is_int($received)
    ||!($created<=$claimed&&$claimed<=$received&&$received<=$expires)
    ||!($claimed<=$issued&&$issued<=$proof_exp&&$proof_exp<=$expires)
    ||$expires>=time()||time()-$expires>self::MAX_AGE)
    return self::deny('ORIGINAL_EXECUTION_TIME_WINDOW_NOT_PROVEN');
  $signed_fields=$mutating
    ?array_merge($independent::FIELDS,['observed_operation','production_mutation'])
    :$independent::FIELDS;
  $proof_contract=$mutating?$independent::CONTRACT_MUTATING:$independent::CONTRACT;
  if(($proof['contract']??null)!==$proof_contract
    ||array_keys($proof)!==array_merge($signed_fields,['signature']))
    return self::deny('SIGNED_INDEPENDENT_READBACK_CONTRACT_MISMATCH');
  $message=[];
  foreach($signed_fields as $key){
   if(!is_string($proof[$key])&&!is_int($proof[$key]))
     return self::deny('INDEPENDENT_SIGNATURE_TYPE_MISMATCH');
   $message[]=(string)$proof[$key];
  }
  $message=implode(chr(10),$message);
  $signature=base64_decode((string)$proof['signature'],true);
  $public=base64_decode($independent::PUBLIC_KEY_B64,true);
  if(!function_exists('sodium_crypto_sign_verify_detached')
    ||!is_string($signature)||strlen($signature)!==SODIUM_CRYPTO_SIGN_BYTES
    ||!is_string($public)||strlen($public)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
    ||!sodium_crypto_sign_verify_detached($signature,$message,$public))
    return self::deny('ORIGINAL_INDEPENDENT_313_SIGNATURE_INVALID');
  $scope=['job_id'=>$id,'company'=>$company,'environment'=>$environment,
   'site_uuid'=>$job['site_uuid'],'origin'=>$job['canonical_origin'],
   'target_id'=>self::HOST,'grant_id'=>$job['grant_id'],
   'transport_id'=>$job['transport_id'],'command_sha256'=>$job['command_sha256']];
  if(!self::same($scope,$proof,array_keys($scope))
    ||!self::same($scope,$pending,['job_id','company','environment','site_uuid',
      'origin','target_id','grant_id','transport_id','command_sha256']))
    return self::deny('ORIGINAL_SIGNED_TARGET_BINDING_MISMATCH');
  foreach(['native_receipt_sha256','readback_sha256','expected_readback_sha256',
    'output_sha256'] as $k)
    if(!preg_match('/^[a-f0-9]{64}$/D',(string)($proof[$k]??'')))
      return self::deny('HISTORIC_HASH_INVALID_'.$k);
  if(($pending['contract']??null)!==$receipt::WPCLI_CONTRACT
    ||($pending['exit_code']??null)!==0
    ||($pending['host_signed_attestation_received']??null)!==true
    ||($pending['operation']??null)!=='company.remote.wpcli.full'
    ||!self::same($pending,$proof,['output_sha256','readback_sha256'])
    ||!hash_equals($proof['expected_readback_sha256'],$proof['readback_sha256'])
    ||!hash_equals($proof['output_sha256'],$proof['readback_sha256']))
    return self::deny('ORIGINAL_HOST_AND_INDEPENDENT_EVIDENCE_MISMATCH');
  $evidence_hash=hash('sha256',$message.chr(10).$proof['signature']);
  if($saved!==false&&(!is_array($saved)
    ||($saved['contract']??null)!==self::CONTRACT
    ||($saved['job_id']??null)!==$id
    ||($saved['signed_independent_sha256']??null)!==$evidence_hash
    ||($saved['native_receipt_sha256']??null)!==$proof['native_receipt_sha256']
    ||($saved['original_claim_expiry_epoch']??null)!==$expires))
    return self::deny('HISTORICAL_RECEIPT_ALREADY_EXISTS_DIFFERENT_EVIDENCE');
  return ['contract'=>self::CONTRACT,'verified'=>true,'job_id'=>$id,
   'signed_independent_sha256'=>$evidence_hash,
   'native_receipt_sha256'=>$proof['native_receipt_sha256'],
   'readback_sha256'=>$proof['readback_sha256'],
   'original_claim_expiry_epoch'=>$expires,
   'original_independent_issued_epoch'=>$issued,
   'existing_historical_receipt'=>$saved!==false,
   'observed_operation'=>$mutating?'plugin.install':'read',
   'original_execution_mutated_production'=>$mutating,
   'original_313_certified'=>false,'execution_performed'=>false,
   'production_mutation'=>false];
 }
 public static function reconcile(string $id):array {
  $v=self::verify($id);
  if(($v['verified']??false)!==true)return $v;
  $key=self::PREFIX.$id;
  if(($v['existing_historical_receipt']??false)!==true) {
   $row=['contract'=>self::CONTRACT,'job_id'=>$id,
    'state'=>self::STATE,'signed_independent_sha256'=>$v['signed_independent_sha256'],
    'native_receipt_sha256'=>$v['native_receipt_sha256'],
    'readback_sha256'=>$v['readback_sha256'],
    'original_claim_expiry_epoch'=>$v['original_claim_expiry_epoch'],
    'original_independent_issued_epoch'=>$v['original_independent_issued_epoch'],
    'central_recorded_at'=>gmdate('c'),
    'historical_only'=>true,'original_313_certified'=>false,
    'observed_operation'=>$v['observed_operation'],
    'original_execution_mutated_production'=>$v['original_execution_mutated_production'],
    'original_host_finish_signature_created'=>false,
    'execution_replayed'=>false,'production_mutation'=>false];
   if(!add_option($key,$row,'',false))
     return self::deny('HISTORICAL_313_RECEIPT_WRITE_CONFLICT');
  }
  $receipt='MAD4B_Broker_Remote_SSH_Receipt_V1';
  $broker='MAD4B_Central_VPS_Broker_V1';
  $pending=get_option($receipt::WPCLI_PREFIX.$id,false);
  if(!is_array($pending)||!in_array(($pending['state']??null),
      ['PENDING_INDEPENDENT_NATIVE_READBACK',self::STATE],true))
    return self::deny('PENDING_313_CHANGED_BEFORE_RECONCILE');
  if(($pending['state']??null)!==self::STATE) {
   $pending['state']=self::STATE;
   $pending['historical_reconciliation_contract']=self::CONTRACT;
   $pending['historical_native_receipt_sha256']=$v['native_receipt_sha256'];
   $pending['original_313_certified']=false;
   $pending['historical_original_production_mutation']=$v['original_execution_mutated_production'];
   $pending['production_mutation']=false;
   if(!update_option($receipt::WPCLI_PREFIX.$id,$pending,false))
     return self::deny('PENDING_313_HISTORIC_UPDATE_UNCERTAIN');
  }
  $job=get_option($broker::JOB.$id,false);
  if(!is_array($job)||!in_array(($job['state']??null),
     ['BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY',self::STATE],true))
    return self::deny('JOB_CHANGED_BEFORE_RECONCILE');
  if(($job['state']??null)!==self::STATE) {
   $job['state']=self::STATE;
   $job['executed']=true;
   $job['independent_readback_verified']=true;
   $job['historical_reconciliation']=true;
   $job['historical_reconciliation_contract']=self::CONTRACT;
   $job['original_313_certified']=false;
   $job['historical_original_production_mutation']=$v['original_execution_mutated_production'];
   $job['execution_replayed']=false;
   $job['receipt_contract']=self::CONTRACT;
   $job['production_mutation']=false;
   if(!update_option($broker::JOB.$id,$job,false))
     return self::deny('HISTORIC_JOB_WRITE_UNCERTAIN');
  }
  $read=get_option($broker::JOB.$id,false);
  $done=get_option($key,false);
  if(!is_array($read)||!is_array($done)
    ||($read['executed']??null)!==true
    ||($read['state']??null)!==self::STATE
    ||($read['receipt_contract']??null)!==self::CONTRACT
    ||($done['signed_independent_sha256']??null)!==$v['signed_independent_sha256'])
    return self::deny('HISTORIC_CENTRAL_READBACK_UNCERTAIN');
  return ['contract'=>self::CONTRACT,'verified'=>true,
   'historical_reconciled'=>true,'job_id'=>$id,
   'state'=>self::STATE,'original_313_certified'=>false,
   'observed_operation'=>$v['observed_operation'],
   'historical_original_production_mutation'=>$v['original_execution_mutated_production'],
   'production_mutation'=>false,'execution_performed'=>false,
   'signed_independent_sha256'=>$v['signed_independent_sha256']];
 }
 public static function cli($args,$assoc) {
  if(!defined('WP_CLI')||!WP_CLI||!class_exists('WP_CLI')
    ||$args!==['reconcile']
    ||array_keys($assoc)!==['job']
    ||!is_string($assoc['job']))
    WP_CLI::error('ORIGINAL_CENTRAL_HISTORICAL_RECONCILE_REQUIRES_EXACT_JOB');
  $r=self::reconcile($assoc['job']);
  if(($r['verified']??false)!==true)WP_CLI::error($r['reason']??'DENIED');
  WP_CLI::line(wp_json_encode($r,JSON_UNESCAPED_SLASHES));
 }
 public static function boot() {
  if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
    WP_CLI::add_command('mad4b historical-wpcli',[__CLASS__,'cli']);
 }
}
MAD4B_Original_WPCLI_Historical_Reconciliation_V1::boot();
