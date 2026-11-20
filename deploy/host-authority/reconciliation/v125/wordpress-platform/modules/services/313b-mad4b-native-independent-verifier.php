<?php
/**
 * Plugin Name: MAD4B Independent Native WPCLI Receipt Verifier
 * Description: Per-Job verifier backed by a separately signed host Readback. Never creates Jobs or Grants.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
// MAD4B_DYNAMIC_313_WP_READ_ONLY_V1
require_once dirname(__DIR__).'/authority/dynamic313/class-mad4b-dynamic-313-wordpress.php';
final class MAD4B_Native_WPCLI_Receipt_Verifier_V1 {
 const CONTRACT='mad4b.native-313-independent-host-proof.v1';
 const CONTRACT_MUTATING='mad4b.native-313-independent-host-proof.v2';
 const CONTRACT_OPTION='mad4b.native-313-independent-host-proof.v3';
 const CONTRACT_DYNAMIC='mad4b.native-313-independent-host-proof.v4';
 const CONTRACT_PRIVATE_DRAFT='mad4b.native-313-independent-host-proof.v5';
 const CONTRACT_DRAFT_POSTMETA='mad4b.native-313-independent-host-proof.v6';
 const PREFIX='mad4b_native_313_independent_proof_v1_';
 const FIELDS=['contract','job_id','company','environment','site_uuid','origin',
  'target_id','grant_id','transport_id','command_sha256','native_receipt_sha256',
  'output_sha256','expected_readback_sha256','readback_sha256',
  'issued','expires','nonce'];
 const PUBLIC_KEY_B64='4jwcLgOUf2d/j2uDYbVysCnkSMJW64cXnhMPaeix11Y=';
 private static function denied(string $why):array {return ['verified'=>false,'reason'=>$why];}
 private static function check(array $p,bool $require_original=true):array {
  $mutating=($p['contract']??'')===self::CONTRACT_MUTATING;
  $option=($p['contract']??'')===self::CONTRACT_OPTION;
  $dynamic=($p['contract']??'')===self::CONTRACT_DYNAMIC;
  $private_draft=($p['contract']??'')===self::CONTRACT_PRIVATE_DRAFT;
  $draft_postmeta=($p['contract']??'')===self::CONTRACT_DRAFT_POSTMETA;
  $option_fields=['observed_operation','production_mutation','postcondition_type',
    'postcondition_key','sealed_argv_sha256'];
  $dynamic_fields=array_merge($option_fields,[
    'dynamic_plan_sha256','dynamic_evidence_sha256']);
  $postmeta_fields=array_merge($option_fields,[
    'post_id','post_type','post_status','meta_row_count']);
  $signed_fields=$dynamic?array_merge(self::FIELDS,$dynamic_fields):
    ($draft_postmeta?array_merge(self::FIELDS,$postmeta_fields):
    (($option||$private_draft)?array_merge(self::FIELDS,$option_fields):
    ($mutating?array_merge(self::FIELDS,['observed_operation','production_mutation']):self::FIELDS)));
  if(array_keys($p)!==array_merge($signed_fields,['signature']))
    return self::denied('EXACT_INDEPENDENT_PROOF_SCHEMA_REQUIRED');
  if($mutating&&!in_array($p['observed_operation']??null,['plugin.install','mu-plugin.deploy'],true))
    return self::denied('MUTATING_OBSERVATION_OPERATION_INVALID');
  if($mutating&&($p['production_mutation']??null)!=='true')
    return self::denied('MUTATING_OBSERVATION_PROOF_INVALID');
  // v3 is a FIRST signed generic-argv staging canary, not a broad production
  // certification. Independently signed proof must identify the exact option
  // that the owner-approved 305 command wrote, with signed SHA of that argv.
  if($option && (($p['observed_operation']??null)!=='wpcli.option.update'
    ||!in_array(($p['environment']??null),['staging','production'],true)
    ||($p['production_mutation']??null)!==
      (($p['environment']??null)==='production'?'true':'false')
    ||($p['postcondition_type']??null)!=='wordpress_option_scalar'
    ||!is_string($p['postcondition_key']??null)
    ||!preg_match('/^mad4b_(?:signed|full_wpcli)_canary_[a-z0-9_]{1,40}$/D',$p['postcondition_key'])
    ||!is_string($p['sealed_argv_sha256']??null)
    ||!hash_equals((string)($p['command_sha256']??''),$p['sealed_argv_sha256'])))
      return self::denied('SIGNED_STAGING_OPTION_READBACK_CONTRACT_INVALID');
  // Dynamic v4 is not inferred from the original generic Full WP-CLI
  // transport: require a separately enrolled Dynamic proof and exact
  // evidence postcondition for the operation and original claimed Job.
  if ($dynamic && (
       (!is_string($p['observed_operation']??null) || !preg_match('/^[a-z][a-z0-9_.-]{2,79}$/D',(string)$p['observed_operation']))
    || !is_string($p['environment']??null)
    || !preg_match('/^[a-z][a-z0-9_-]{1,63}$/D',$p['environment'])
    || ($p['production_mutation']??null)!==
        (($p['environment']==='production')?'true':'false')
    || ($p['postcondition_type']??null)!=='enrolled_dynamic_evidence'
    || !class_exists('MAD4B_Dynamic_WP_313_Readback_V1',false)
    || !is_callable(['MAD4B_Dynamic_WP_313_Readback_V1','enrolled_schema_id'])
    || !is_callable(['MAD4B_Dynamic_WP_313_Readback_V1','operation_operational_ready'])
    || !MAD4B_Dynamic_WP_313_Readback_V1::operation_operational_ready((string)$p['observed_operation'])
    || ($p['postcondition_key']??null)!==
       MAD4B_Dynamic_WP_313_Readback_V1::enrolled_schema_id(
          (string)$p['observed_operation'])
    || !is_string($p['sealed_argv_sha256']??null)
    || !hash_equals((string)($p['command_sha256']??''),$p['sealed_argv_sha256'])
    || !preg_match('/^[a-f0-9]{64}$/D',(string)($p['dynamic_plan_sha256']??''))
    || !preg_match('/^[a-f0-9]{64}$/D',(string)($p['dynamic_evidence_sha256']??''))))
     return self::denied('DYNAMIC_SIGNED_POSTCONDITION_SCOPE_INVALID');
  // v5 is a separately signed independent *private draft* WordPress Core
  // readback. It does not authorize generic option add, and cannot convert
  // the original Canary v3 or Dynamic Code Release v4 into domain writes.
  if($private_draft){
    $intent=(string)($p['observed_operation']??'');
    $prefix=$intent==='strategy.write'?'mad4b_strategy_draft_':
      ($intent==='operations.write'?'mad4b_operations_draft_':null);
    $id=(string)($p['job_id']??'');
    $option_key=$p['postcondition_key']??null;
    if($prefix===null || !preg_match('/^[a-f0-9]{32}$/D',$id)
       || !is_string($option_key)
       || $option_key!==$prefix.$id
       || ($p['postcondition_type']??'')!=='wordpress_private_draft_nonautoload_option'
       || !in_array(($p['environment']??null),['production','staging'],true)
       || ($p['production_mutation']??null)!==
            (($p['environment']??null)==='production'?'true':'false')
       || !is_string($p['sealed_argv_sha256']??null)
       || !hash_equals((string)($p['command_sha256']??''),$p['sealed_argv_sha256']))
      return self::denied('SIGNED_PRIVATE_DRAFT_V5_POSTSTATE_CONTRACT_INVALID');
    if(!class_exists('MAD4B_Central_VPS_Broker_V1',false)
       ||!class_exists('MAD4B_Central_SSH_Command_Intake_V1',false))
      return self::denied('ORIGINAL_305_PRIVATE_DRAFT_BINDING_NOT_LOADED');
    $original=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);
    $request=get_option(MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.$id,false);
    if(!is_array($original)||!is_array($request)
       || ($original['state']??null)!=='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'
       || ($original['executed']??null)!==false
       || ($request['status']??null)!=='ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY'
       || ($request['request_id']??null)!==$id
       || ($request['correlation_id']??null)!==$id
       || ($original['correlation_id']??null)!==$id
       || ($original['domain_intent']??null)!==$intent
       || ($request['domain_intent']??null)!==$intent
       || ($original['domain_resource_key']??null)!==$option_key
       || ($request['domain_resource_key']??null)!==$option_key
       || !preg_match('/^[a-f0-9]{64}$/D',
           (string)($original['domain_witness_sha256']??''))
       || ($request['domain_witness_sha256']??null)!==
           ($original['domain_witness_sha256']??null)
       || ($original['company']??null)!==($p['company']??null)
       || ($original['environment']??null)!==($p['environment']??null)
       || ($original['site_uuid']??null)!==($p['site_uuid']??null)
       || ($original['canonical_origin']??null)!==($p['origin']??null)
       || ($original['grant_id']??null)!==($p['grant_id']??null)
       || ($original['transport_id']??null)!==($p['transport_id']??null)
       || ($original['command_sha256']??null)!==($p['command_sha256']??null))
      return self::denied('SIGNED_PRIVATE_DRAFT_ORIGINAL_305_306_SCOPE_DRIFT');
  }
  // v6 is a separate Original signed independent postmeta postcondition.
  // A draft-only row proves no permission for generic published content,
  // arbitrary product prices, or unscoped WooCommerce writes.
  if($draft_postmeta){
    $intent=(string)($p['observed_operation']??'');
    $meta=$intent==='content.write'?'_mad4b_content_draft_review':
      ($intent==='commerce.write'?'_mad4b_commerce_draft_policy':null);
    $types=$intent==='content.write'?['post','page','tours-and-activities']:
      ($intent==='commerce.write'?['tour-rates']:[]);
    $post_id=$p['post_id']??null;
    $id=(string)($p['job_id']??'');
    $resource='postmeta:'.(string)$post_id.':'.$meta.':'.$id;
    if($meta===null || !is_int($post_id)||$post_id<1
       ||$post_id>2147483647 ||!preg_match('/^[a-f0-9]{32}$/D',$id)
       ||($p['postcondition_key']??null)!==$meta
       ||($p['postcondition_type']??null)!=='wordpress_unpublished_draft_postmeta'
       ||!in_array(($p['post_type']??null),$types,true)
       ||($p['post_status']??null)!=='draft'
       ||($p['meta_row_count']??null)!==1
       ||!in_array(($p['environment']??null),['staging','production'],true)
       ||($p['production_mutation']??null)!==
          (($p['environment']??null)==='production'?'true':'false')
       ||($p['sealed_argv_sha256']??null)!==($p['command_sha256']??null))
        return self::denied('SIGNED_POSTMETA_V6_EXACT_DRAFT_POSTSTATE_INVALID');
    if(!class_exists('MAD4B_Central_VPS_Broker_V1',false)
       ||!class_exists('MAD4B_Central_SSH_Command_Intake_V1',false))
        return self::denied('ORIGINAL_POSTMETA_305_AND_306_REQUIRED');
    $j=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);
    $request=get_option(MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.$id,false);
    if(!is_array($j)||!is_array($request)
       ||($j['state']??null)!=='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'
       ||($j['executed']??null)!==false
       ||($request['status']??null)!=='ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY'
       ||($j['job_id']??null)!==$id
       ||($request['request_id']??null)!==$id
       ||($j['correlation_id']??null)!==$id
       ||($request['correlation_id']??null)!==$id
       ||($j['domain_intent']??null)!==$intent
       ||($request['domain_intent']??null)!==$intent
       ||($j['domain_resource_key']??null)!==$resource
       ||($request['domain_resource_key']??null)!==$resource
       ||!preg_match('/^[a-f0-9]{64}$/D',
         (string)($j['domain_witness_sha256']??''))
       ||($j['domain_witness_sha256']??null)!==
         ($request['domain_witness_sha256']??null)
       ||($j['company']??null)!==($p['company']??null)
       ||($j['environment']??null)!==($p['environment']??null)
       ||($j['site_uuid']??null)!==($p['site_uuid']??null)
       ||($j['canonical_origin']??null)!==($p['origin']??null)
       ||($j['grant_id']??null)!==($p['grant_id']??null)
       ||($j['transport_id']??null)!==($p['transport_id']??null)
       ||($j['command_sha256']??null)!==($p['command_sha256']??null))
        return self::denied('SIGNED_POSTMETA_V6_ORIGINAL_305_306_SCOPE_CHANGED');
  }
  foreach($signed_fields as $key)if(!is_string($p[$key])&&!is_int($p[$key]))return self::denied('PROOF_FIELD_TYPE_INVALID');
  $id=$p['job_id'];$now=time();
  if(!in_array($p['contract'],[self::CONTRACT,self::CONTRACT_MUTATING,self::CONTRACT_OPTION,self::CONTRACT_DYNAMIC,self::CONTRACT_PRIVATE_DRAFT,self::CONTRACT_DRAFT_POSTMETA],true)
   || !preg_match('/^[a-f0-9]{32}$/D',$id)
   || !preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$p['company'])
   || !preg_match('/^[a-z][a-z0-9_-]{1,63}$/D',$p['environment'])
   || !preg_match('/^[a-f0-9]{32}$/D',$p['grant_id'])
   || !preg_match('/^[a-f0-9]{32}$/D',$p['transport_id'])
   || !preg_match('/^[a-f0-9]{32}$/D',$p['nonce'])
   || !is_int($p['issued']) || !is_int($p['expires'])
   || $p['issued']<$now-120 || $p['issued']>$now+10
   || $p['expires']<$now || $p['expires']>$p['issued']+120
   || !filter_var($p['origin'],FILTER_VALIDATE_URL)
   || !str_starts_with($p['origin'],'https://'))return self::denied('PROOF_TARGET_OR_TIME_INVALID');
  foreach(['command_sha256','native_receipt_sha256','output_sha256',
            'readback_sha256','expected_readback_sha256'] as $key)
   if(!preg_match('/^[a-f0-9]{64}$/D',$p[$key]))return self::denied('PROOF_HASH_INVALID');
  if(!hash_equals($p['expected_readback_sha256'],$p['readback_sha256']))
   return self::denied('PROOF_READBACK_MISMATCH');
  $data=implode(chr(10),array_map(static fn($key)=>(string)$p[$key],$signed_fields));
  $pub=base64_decode(self::PUBLIC_KEY_B64,true);
  $sig=base64_decode($p['signature'],true);
  if(!is_string($pub)||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
    || !is_string($sig)||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES
    || !sodium_crypto_sign_verify_detached($sig,$data,$pub))
    return self::denied('INDEPENDENT_VERIFIER_SIGNATURE_INVALID');
  // Never let a v4 host receipt certify itself. The separately signed
  // evidence must already be durably persisted in Central WordPress and
  // independently checked against the same original 305/312 claim.
  if ($dynamic) {
   if (!class_exists('MAD4B_Dynamic_WP_313_Readback_V1',false) ||
       !is_callable(['MAD4B_Dynamic_WP_313_Readback_V1','recorded_exact_for_original_313']))
     return self::denied('DYNAMIC_CENTRAL_EVIDENCE_PROVIDER_NOT_ENROLLED');
   $read=MAD4B_Dynamic_WP_313_Readback_V1::recorded_exact_for_original_313($id);
   $evidence=$read['proof']??null;
   if (($read['verified']??false)!==true ||
       ($read['operation_id']??'')!==$p['observed_operation'] ||
       !is_array($evidence) ||
       ($evidence['plan_sha256']??'')!==$p['dynamic_plan_sha256'] ||
       ($evidence['evidence_sha256']??'')!==$p['dynamic_evidence_sha256'] ||
       ($evidence['job_id']??'')!==$id ||
       ($evidence['command_sha256']??'')!==$p['command_sha256'] ||
       ($evidence['site_uuid']??'')!==$p['site_uuid'] ||
       ($evidence['origin']??'')!==$p['origin'] ||
       ($evidence['environment']??'')!==$p['environment'] ||
       ($evidence['target_id']??'')!==$p['target_id'] ||
       ($evidence['payload']['readback_sha256']??'')!==$p['readback_sha256'] ||
       $p['readback_sha256']!==$p['expected_readback_sha256'] ||
       $p['readback_sha256']!==$p['output_sha256'])
        return self::denied('DYNAMIC_313_INDEPENDENT_EVIDENCE_MISMATCH');
  }
  if(!$require_original)return ['verified'=>true];
  if(!class_exists('MAD4B_Broker_Exact_SSH_Authority_V1',false)
    ||!class_exists('MAD4B_Central_VPS_Broker_V1',false)
    ||!class_exists('MAD4B_Broker_Remote_SSH_Receipt_V1',false))return self::denied('ORIGINAL_BROKER_UNAVAILABLE');
  $a=MAD4B_Broker_Exact_SSH_Authority_V1::evaluate($id,$p['target_id'],$p['command_sha256']);
  if(($a['verified']??false)!==true||($a['eligible']??false)!==true
    ||($a['owner_enrolled_wpcli']??false)!==true
    ||($a['operation']??null)!=='company.remote.wpcli.full')return self::denied('ORIGINAL_312_NOT_ELIGIBLE');
  foreach(['company','environment','site_uuid','origin','grant_id','transport_id'] as $key)
   if(($a[$key]??null)!==$p[$key])return self::denied('ORIGINAL_SCOPE_CHANGED_'.$key);
  $j=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);
  $pending=get_option(MAD4B_Broker_Remote_SSH_Receipt_V1::WPCLI_PREFIX.$id,false);
  if(!is_array($j)||($j['executed']??null)!==false
    ||($j['state']??null)!=='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'
    ||!(($j['job_type']??null)==='signed_vps_wpcli' && ($p['target_id']??null)==='srv2030908'
       || ($j['job_type']??null)==='signed_remote_hostinger_wpcli'
          && ($j['target_host_id']??null)===($p['target_id']??null)
          && in_array(($p['target_id']??null),['hosting-2','hosting-3'],true)
          && ($p['contract']??null)===self::CONTRACT)
    ||!is_array($pending)||($pending['state']??null)!=='PENDING_INDEPENDENT_NATIVE_READBACK'
    ||($pending['command_sha256']??null)!==$p['command_sha256']
    ||($pending['output_sha256']??null)!==$p['output_sha256']
    ||($pending['readback_sha256']??null)!==$p['readback_sha256']
    ||($pending['grant_id']??null)!==$p['grant_id'])return self::denied('ORIGINAL_JOB_OR_PENDING_313_MISMATCH');
  return ['verified'=>true,'auth'=>$a];
 }
 public static function verify_exact(array $a,array $auth,array $original,array $pending):array {
  $id=(string)($a['job_id']??'');
  if(!preg_match('/^[a-f0-9]{32}$/D',$id))return self::denied('INVALID_JOB');
  $row=get_option(self::PREFIX.$id,false);
  if(!is_array($row))return self::denied('INDEPENDENT_PROOF_NOT_PRESENT');
  $x=self::check($row,false);
  if(($x['verified']??false)!==true)return $x;
  $comparisons=['job_id'=>'job_id','target_id'=>'target_id',
    'grant_id'=>'grant_id','transport_id'=>'transport_id','site_uuid'=>'site_uuid',
    'origin'=>'origin','environment'=>'environment',
    'command_sha256'=>'command_sha256','native_receipt_sha256'=>'native_receipt_sha256',
    'expected_readback_sha256'=>'expected_readback_sha256',
    'readback_sha256'=>'readback_sha256','output_sha256'=>'output_sha256'];
  foreach($comparisons as $key=>$other)if(($row[$key]??null)!==($a[$other]??null))
    return self::denied('INDEPENDENT_SIGNED_EVIDENCE_MISMATCH_'.$key);
  if(($row['company']??null)!==($auth['company']??null)
    ||($original['job_id']??null)!==$id
    ||($pending['job_id']??null)!==$id
    ||($pending['state']??null)!=='PENDING_INDEPENDENT_NATIVE_READBACK')
    return self::denied('ORIGINAL_JOB_IDENTITY_DRIFT');
  return ['verified'=>true,'job_id'=>$id,
    'native_receipt_sha256'=>$row['native_receipt_sha256'],
    'command_sha256'=>$row['command_sha256'],
    'readback_sha256'=>$row['readback_sha256'],
    'production_mutation'=>($row['contract']===self::CONTRACT_MUTATING ||
      (in_array($row['contract'],[self::CONTRACT_OPTION,self::CONTRACT_DYNAMIC,self::CONTRACT_PRIVATE_DRAFT,self::CONTRACT_DRAFT_POSTMETA],true) &&
       $row['environment']==='production')),
    'independent_option_postcondition_signed'=>($row['contract']===self::CONTRACT_OPTION),
    'independent_dynamic_postcondition_signed'=>($row['contract']===self::CONTRACT_DYNAMIC),
    'independent_private_draft_postcondition_signed'=>($row['contract']===self::CONTRACT_PRIVATE_DRAFT),
    'independent_postmeta_draft_postcondition_signed'=>($row['contract']===self::CONTRACT_DRAFT_POSTMETA),
    'draft_postmeta_post_id'=>($row['contract']===self::CONTRACT_DRAFT_POSTMETA
       ?$row['post_id']:null),
    'draft_postmeta_operation'=>($row['contract']===self::CONTRACT_DRAFT_POSTMETA
       ?$row['observed_operation']:null),
    'private_draft_operation'=>($row['contract']===self::CONTRACT_PRIVATE_DRAFT
      ?$row['observed_operation']:null)];
 }
 public static function ingest(array $p):array {
  $x=self::check($p,true);
  if(($x['verified']??false)!==true)return $x;
  $id=$p['job_id'];$key=self::PREFIX.$id;
  if(!add_option($key,$p,'',false))return self::denied('INDEPENDENT_PROOF_ALREADY_RECORDED');
  $read=get_option($key,false);
  if(!is_array($read)||($read['signature']??null)!==$p['signature'])
    return self::denied('INDEPENDENT_PROOF_PERSISTENCE_UNCERTAIN');
  return ['verified'=>true,'state'=>'INDEPENDENT_NATIVE_HOST_PROOF_STORED',
     'job_id'=>$id,'issuer'=>'separate-root-host-verifier',
     'executor_certified'=>false,'grant_issued'=>false,
     'production_mutation'=>($p['contract']===self::CONTRACT_MUTATING ||
       (in_array($p['contract'],[self::CONTRACT_OPTION,self::CONTRACT_DYNAMIC,self::CONTRACT_PRIVATE_DRAFT,self::CONTRACT_DRAFT_POSTMETA],true) &&
        $p['environment']==='production')),
     'staging_option_postcondition_signed'=>$p['contract']===self::CONTRACT_OPTION,
     'independent_dynamic_postcondition_signed'=>$p['contract']===self::CONTRACT_DYNAMIC,
     'independent_private_draft_postcondition_signed'=>$p['contract']===self::CONTRACT_PRIVATE_DRAFT,
     'independent_postmeta_draft_postcondition_signed'=>$p['contract']===self::CONTRACT_DRAFT_POSTMETA];
 }
 public static function supports_draft_postmeta_v6():bool {
   return self::CONTRACT_DRAFT_POSTMETA===
       'mad4b.native-313-independent-host-proof.v6'
     &&class_exists('MAD4B_Central_VPS_Broker_V1',false)
     &&class_exists('MAD4B_Central_SSH_Command_Intake_V1',false);
 }
 public static function supports_private_draft_v5():bool {
   return self::CONTRACT_PRIVATE_DRAFT==='mad4b.native-313-independent-host-proof.v5'
     &&class_exists('MAD4B_Central_VPS_Broker_V1',false)
     &&class_exists('MAD4B_Central_SSH_Command_Intake_V1',false);
 }
 public static function supports_dynamic_v4():bool {
  return class_exists('MAD4B_Dynamic_WP_313_Readback_V1',false)
    &&is_callable(['MAD4B_Dynamic_WP_313_Readback_V1',
                   'recorded_exact_for_original_313'])
    &&self::CONTRACT_DYNAMIC==='mad4b.native-313-independent-host-proof.v4';
 }
 public static function cli($args,$assoc):void {
  if(!defined('WP_CLI')||!WP_CLI || !class_exists('WP_CLI')
    ||!current_user_can('manage_options'))WP_CLI::error('ORIGINAL_CENTRAL_ACTOR_REQUIRED');
  if(($args[0]??'')==='status') {
   WP_CLI::line(wp_json_encode(['contract'=>self::CONTRACT,'loaded'=>true,
     'independent_signing_key_present_in_wordpress'=>false,
     'grants_issued'=>false,'jobs_created'=>false]));return;
  }
  if(($args[0]??'')!=='ingest'||array_keys($assoc)!==['payload'])
   WP_CLI::error('EXACT_SIGNED_PAYLOAD_REQUIRED');
  $raw=base64_decode((string)$assoc['payload'],true);
  if(!is_string($raw)||strlen($raw)>6000)WP_CLI::error('PAYLOAD_INVALID');
  $p=json_decode($raw,true);
  $r=is_array($p)?self::ingest($p):self::denied('JSON_INVALID');
  if(($r['verified']??false)!==true)WP_CLI::error((string)($r['reason']??'INGEST_FAILED'));
  WP_CLI::line(wp_json_encode($r,JSON_UNESCAPED_SLASHES));
 }
 public static function boot():void {
  if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
    WP_CLI::add_command('mad4b native-313-proof',[__CLASS__,'cli']);
 }
}
MAD4B_Native_WPCLI_Receipt_Verifier_V1::boot();
