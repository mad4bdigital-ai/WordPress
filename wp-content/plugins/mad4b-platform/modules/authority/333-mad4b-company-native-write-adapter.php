<?php
/**
 * Plugin Name: MAD4B Company Native Write Adapter
 * Description: Exact-site signed rollback-proof admission, no automatic writes.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Native_Write_Executor_V1 {
 const CONTRACT='mad4b.company-native-write-executor.v1';
 const PROOF_CONTRACT='mad4b.company-native-write-rollback-proof.v1';
 const PREFIX='mad4b_company_native_write_rollback_proof_v1_';
 const LAST_313_PREFIX='mad4b_company_native_last_exact_313_v1_';
 const PUB='4jwcLgOUf2d/j2uDYbVysCnkSMJW64cXnhMPaeix11Y=';
 const FIELDS=['contract','job_id','company_code','environment','site_uuid',
  'canonical_origin','intent','original_operation','command_sha256',
  'native_receipt_sha256','independent_readback_sha256','before_sha256',
  'after_sha256','rollback_sha256','verified_at_epoch','nonce'];
 public static function supported_operation($intent):bool {
  // Preserve legacy intents, but discover future first-party operations
  // dynamically from the native provider Registry. A transport capability
  // alone must never be treated as a specific executable Write operation.
  if(in_array($intent,['content.write','plugin.install'],true))return true;
  $registry='MAD4B_Original_Native_Operation_Providers_V1';
  if(!class_exists($registry,false))return false;
  $provider=$registry::profile($intent);
  return is_array($provider)
   && ($provider['operation']??null)===$intent
   && ($provider['authority_family']??null)==='original_305'
   && ($provider['target_scope']??null)==='wordpress'
   && ($provider['command_format']??null)==='wpcli.argv.json.v1'
   && ($provider['evidence_contract']??null)!=='original_305_claim'
   && !in_array($intent,['company.remote.wpcli.full','maintenance.shell'],true);
 }
 private static function blocked($company,$env,$intent,$reason,$site=[]):array {
  return ['contract'=>self::CONTRACT,'company_code'=>$company,'environment'=>$env,
   'canonical_origin'=>$site['canonical_origin']??null,'site_uuid'=>$site['site_uuid']??null,
   'intent'=>$intent,'state'=>'ORIGINAL_NATIVE_WRITE_NOT_CERTIFIED',
   'reason'=>$reason,'native_wordpress_capability_verified'=>false,
   'executor_authenticated'=>false,'readback_handler_verified'=>false,
   'rollback_handler_verified'=>false,'receipt_sha256'=>null,
   'verified_at_epoch'=>0,'write_executed'=>false,'grant_created'=>false];
 }
 private static function index_key(string $company,string $environment,
                                  string $uuid,string $intent):string {
  return self::LAST_313_PREFIX.hash('sha256',
    $company.'|'.$environment.'|'.$uuid.'|'.$intent);
 }
 private static function exact_completed_313_job(
   string $id,string $company,string $environment,string $intent,array $site
 ):?array {
  // Immutable historic job evidence, not a 312 capability or a permission.
  if(!preg_match('/^[a-f0-9]{32}$/D',$id)
    ||!in_array($intent,['content.write','commerce.write',
                        'strategy.write','operations.write'],true)
    ||!class_exists('MAD4B_Central_VPS_Broker_V1',false)
    ||!class_exists('MAD4B_Broker_Remote_SSH_Receipt_V1',false)
    ||!class_exists('MAD4B_Native_WPCLI_Receipt_Verifier_V1',false))
     return null;
  $v='MAD4B_Native_WPCLI_Receipt_Verifier_V1';
  $job=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);
  $pending=get_option(MAD4B_Broker_Remote_SSH_Receipt_V1::WPCLI_PREFIX.$id,false);
  $finish=get_option(MAD4B_Broker_Remote_SSH_Receipt_V1::WPCLI_FINISH_PREFIX.$id,false);
  $proof=get_option($v::PREFIX.$id,false);
  if(!is_array($job)||!is_array($pending)||!is_array($finish)
    ||!is_array($proof)
    ||($job['job_id']??null)!==$id
    ||($job['state']??null)!=='WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED'
    ||($job['executed']??null)!==true
    ||($job['independent_readback_verified']??null)!==true
    ||($job['domain_intent']??null)!==$intent
    ||($job['company']??null)!==$company
    ||($job['environment']??null)!==$environment
    ||($job['site_uuid']??null)!==($site['site_uuid']??null)
    ||($job['canonical_origin']??null)!==($site['canonical_origin']??null)
    ||($pending['native_executor_certified']??null)!==true
    ||($pending['independent_host_readback_verified']??null)!==true
    ||($pending['native_receipt_sha256']??null)!==($proof['native_receipt_sha256']??null)
    ||($finish['native_receipt_sha256']??null)!==($proof['native_receipt_sha256']??null)
    ||($pending['readback_sha256']??null)!==($proof['readback_sha256']??null)
    ||($job['command_sha256']??null)!==($proof['command_sha256']??null)
    ||($job['grant_id']??null)!==($proof['grant_id']??null)
    ||($job['transport_id']??null)!==($proof['transport_id']??null)
    ||($proof['job_id']??null)!==$id
    ||($proof['company']??null)!==$company
    ||($proof['environment']??null)!==$environment
    ||($proof['site_uuid']??null)!==$site['site_uuid']
    ||($proof['origin']??null)!==$site['canonical_origin']
    ||($proof['observed_operation']??null)!==$intent
    ||($proof['output_sha256']??null)!==($proof['readback_sha256']??null)
    ||($proof['expected_readback_sha256']??null)!==($proof['readback_sha256']??null)
    ||($proof['sealed_argv_sha256']??null)!==($proof['command_sha256']??null))
     return null;
  $v6=in_array($intent,['content.write','commerce.write'],true);
  $contract=$v6?$v::CONTRACT_DRAFT_POSTMETA:$v::CONTRACT_PRIVATE_DRAFT;
  if(($proof['contract']??null)!==$contract)return null;
  $fields=array_merge($v::FIELDS,[
    'observed_operation','production_mutation','postcondition_type',
    'postcondition_key','sealed_argv_sha256']);
  if($v6)$fields=array_merge($fields,['post_id','post_type','post_status','meta_row_count']);
  if(array_keys($proof)!==array_merge($fields,['signature']))return null;
  $pub=base64_decode($v::PUBLIC_KEY_B64,true);
  $sig=base64_decode((string)($proof['signature']??''),true);
  $raw=implode(chr(10),array_map(static fn($f)=>(string)$proof[$f],$fields));
  if(!is_string($pub)||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
    ||!is_string($sig)||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES
    ||!sodium_crypto_sign_verify_detached($sig,$raw,$pub))
     return null;
  if($v6){
    $meta=$intent==='content.write'
      ?'_mad4b_content_draft_review':'_mad4b_commerce_draft_policy';
    $allowed=$intent==='content.write'
      ?['post','page','tours-and-activities']:['tour-rates'];
    $post=$proof['post_id']??null;
    if(!is_int($post)||$post<1||$post>2147483647
      ||($proof['postcondition_type']??null)!=='wordpress_unpublished_draft_postmeta'
      ||($proof['postcondition_key']??null)!==$meta
      ||($proof['post_status']??null)!=='draft'
      ||($proof['meta_row_count']??null)!==1
      ||!in_array(($proof['post_type']??null),$allowed,true)
      ||($job['domain_resource_key']??null)!==
         'postmeta:'.$post.':'.$meta.':'.$id)
       return null;
  } else {
    $option=($intent==='strategy.write'
      ?'mad4b_strategy_draft_':'mad4b_operations_draft_').$id;
    if(($proof['postcondition_type']??null)!==
        'wordpress_private_draft_nonautoload_option'
      ||($proof['postcondition_key']??null)!==$option
      ||($job['domain_resource_key']??null)!==$option)
       return null;
  }
  $recorded=$finish['recorded_at']??0;
  if(!is_int($recorded)||$recorded<1)
    $recorded=(int)($proof['issued']??0);
  return [
    'contract'=>'mad4b.company-original313-historic-job-readback.v1',
    'state'=>'ORIGINAL_EXACT_JOB_313_CERTIFIED',
    'job_id'=>$id,'intent'=>$intent,'company_code'=>$company,
    'environment'=>$environment,'site_uuid'=>$site['site_uuid'],
    'original_313_contract'=>$proof['contract'],
    'readback_sha256'=>$proof['readback_sha256'],
    'native_receipt_sha256'=>$proof['native_receipt_sha256'],
    'verified_at_epoch'=>$recorded,'exact_job_verified'=>true,
    'new_job_authorized'=>false,'current_312_implied'=>false,
    'general_write_authorized'=>false,
  ];
 }
 public static function record_certified_job(string $id):bool {
  if(!preg_match('/^[a-f0-9]{32}$/D',$id)
    ||!class_exists('MAD4B_Central_VPS_Broker_V1',false)
    ||!class_exists('MAD4B_Company_Environment_Registry_V2',false))
    return false;
  $job=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);
  if(!is_array($job))return false;
  $company=(string)($job['company']??'');
  $env=(string)($job['environment']??'');
  $intent=(string)($job['domain_intent']??'');
  if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)
    ||!in_array($env,['staging','production'],true))return false;
  $site=MAD4B_Company_Environment_Registry_V2::target($company,$env);
  if(!is_array($site))return false;
  $cert=self::exact_completed_313_job($id,$company,$env,$intent,$site);
  if(!is_array($cert))return false;
  $key=self::index_key($company,$env,$site['site_uuid'],$intent);
  $old=get_option($key,false);
  if(is_array($old)&&($old['job_id']??null)===$id)return true;
  if(is_array($old)
    &&($old['verified_at_epoch']??0)>$cert['verified_at_epoch'])
    return false;
  update_option($key,[
    'job_id'=>$id,'verified_at_epoch'=>$cert['verified_at_epoch']
  ],false);
  return (get_option($key,false)['job_id']??null)===$id;
 }
 public static function updated_original_job_option($option,$old,$new):void {
  if(!class_exists('MAD4B_Central_VPS_Broker_V1',false)
    ||!is_string($option)
    ||!str_starts_with($option,MAD4B_Central_VPS_Broker_V1::JOB)
    ||!is_array($new)
    ||($new['state']??null)!=='WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED')
    return;
  $id=substr($option,strlen(MAD4B_Central_VPS_Broker_V1::JOB));
  if(preg_match('/^[a-f0-9]{32}$/D',$id))
    self::record_certified_job($id);
 }
 public static function last_exact_313(
   string $company,string $environment,string $intent,array $site
 ):?array {
  if(!in_array($intent,['content.write','commerce.write',
                       'strategy.write','operations.write'],true)
    ||!wp_is_uuid((string)($site['site_uuid']??'')))return null;
  $index=get_option(self::index_key(
     $company,$environment,$site['site_uuid'],$intent),false);
  if(!is_array($index))return null;
  $id=(string)($index['job_id']??'');
  return self::exact_completed_313_job($id,$company,$environment,$intent,$site);
 }
 public static function preflight_status($company,$environment,$intent):array {
  $company=(string)$company;$environment=(string)$environment;$intent=(string)$intent;
  $base=['contract'=>'mad4b.company-native-write-preflight.v1',
   'company_code'=>$company,'environment'=>$environment,'intent'=>$intent,
   'site_uuid'=>null,'canonical_origin'=>null,'state'=>'BLOCKED',
   'scope_preflight_passed'=>false,'target_wordpress_actor_capability_verified'=>false,
   'transport_provider_registered'=>false,'transport_provider_ready'=>false,
   'operation_provider_registered'=>false,'operation_provider_ready'=>false,
   'authority_ready'=>false,'executor_ready'=>false,'job_verified'=>false,
   'exact_grant_principal_intent_delegation_verified'=>false,
   'required_company_right'=>null,'owner_scope_review_required'=>false,
   'original_native_job_submitted'=>false,'execution_allowed'=>false,
   'remote_write_permitted'=>false,'signed_rollback_certificate_present'=>false,
   'grant_created'=>false,'production_mutated'=>false,
   'last_original_313_job_verified'=>false,
   'last_original_313_job_id'=>null,'last_original_313_verified_at_epoch'=>null,
   'last_original_313_proof_contract'=>null,
   'next_stage'=>'IDENTITY_AND_OPERATION_CHECK_REQUIRED'];
  if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)
    ||!preg_match('/^[a-z][a-z0-9_-]{1,63}$/D',$environment)
    ||!self::supported_operation($intent)) {
    $base['reason']='INVALID_EXACT_TARGET_OR_OPERATION';return $base;
  }
  if(!class_exists('MAD4B_Company_Environment_Registry_V2',false)
    ||!class_exists('MAD4B_Original_Verified_WPCLI_Transport_V1',false)
    ||!class_exists('MAD4B_Central_SSH_Command_Intake_V1',false)) {
    $base['reason']='ORIGINAL_NATIVE_SCOPE_PROVIDERS_UNAVAILABLE';return $base;
  }
  $site=MAD4B_Company_Environment_Registry_V2::target($company,$environment);
  if(!is_array($site)||!wp_is_uuid((string)($site['site_uuid']??''))) {
    $base['reason']='EXACT_SITE_REGISTRY_NOT_VERIFIED';return $base;
  }
  $base['site_uuid']=$site['site_uuid'];
  $base['canonical_origin']=$site['canonical_origin']??null;
  $last=self::last_exact_313($company,$environment,$intent,$site);
  if(is_array($last)) {
    $base['last_original_313_job_verified']=true;
    $base['last_original_313_job_id']=$last['job_id'];
    $base['last_original_313_verified_at_epoch']=$last['verified_at_epoch'];
    $base['last_original_313_proof_contract']=$last['original_313_contract'];
  }
  $slot=get_option('mad4b_company_native_wpcli_slot_v1_'.$company.'_'.$environment,false);
  $p='MAD4B_Central_SSH_Command_Intake_V1';
  $transport=is_array($slot)?get_option($p::TRANSPORT_PREFIX.($slot['transport_id']??''),false):false;
  $grant=is_array($slot)?get_option($p::PREFIX.($slot['grant_id']??''),false):false;
  if(!is_array($transport)||!is_array($grant)
    ||($slot['site_uuid']??null)!==$site['site_uuid']
    ||!MAD4B_Original_Verified_WPCLI_Transport_V1::active($transport,$grant)) {
    $base['reason']='ORIGINAL_SIGNED_TRANSPORT_OR_GRANT_NOT_ACTIVE';return $base;
  }
  // A full WP-CLI transport does not certify any particular write handler.
  // Only an exact operation-specific provider with a trusted source readback
  // can make a job eligible for submission; 312 and 313 remain separate.
  $registry='MAD4B_Original_Native_Operation_Providers_V1';
  $transport_provider=class_exists($registry,false)
    ?$registry::profile('company.remote.wpcli.full'):null;
  $base['transport_provider_registered']=is_array($transport_provider)
    && ($transport_provider['authority_family']??null)==='original_305'
    && ($transport_provider['target_scope']??null)==='wordpress';
  $base['transport_provider_ready']=$base['transport_provider_registered']
    && $registry::legacy_admitted('company.remote.wpcli.full','wordpress','wpcli.argv.json.v1');
  $provider=class_exists($registry,false)?$registry::profile($intent):null;
  $found=class_exists($registry,false)?$registry::discover($intent):[];
  $rows=(array)($found['operations']??[]);
  $base['scope_preflight_passed']=true;
  $base['original_transport_verified']=true;
  // A signed transport and a valid 312 operation-specific heartbeat are
  // NOT an exact owner delegation. Validate the original 305 grant actor
  // against the registered Company Manager assignment for this intent.
  // This is read-only and fail-closed; it does not issue or change grants.
  $actor=(int)($grant['central_principal_id']??0);
  $manager=class_exists('MAD4B_Company_Operation_Admission_V1',false)
    ?MAD4B_Company_Operation_Admission_V1::manager_id():'';
  $canReview=class_exists('MAD4B_Manager_Auto_Write_V1',false)
    &&class_exists('MAD4B_Company_Operation_Admission_V1',false)
    &&wp_is_uuid((string)$manager)&&$actor>0;
  $base['required_company_right']=$canReview
    ?(MAD4B_Manager_Auto_Write_V1::RIGHTS[$intent]??null):null;
  // Do NOT call Manager_Auto_Write::entitlement here for Production:
  // entitlement invokes Execution_Resolver::plan, whose native lane calls
  // this exact preflight. Once 305 is active that becomes a recursive loop.
  // Independently verify existing ORIGINAL manager membership, enrolled actor,
  // exact active Production assignment, approved rights-chain and site UUID.
  // This remains an authority READBACK, never an authorization to execute.
  $base['exact_grant_principal_intent_delegation_verified']=false;
  if($canReview && $environment==='production') {
    $p='MAD4B_Company_Portfolio_Manager_V1';
    $actorEligible=MAD4B_Manager_Auto_Write_V1::enrolled_owner($actor)===$actor;
    $assignment=class_exists($p,false)
      ?$p::production_assignment_status($manager,$company):null;
    $sameSite=is_array($assignment)
      &&($assignment['state']??null)==='active'
      &&($assignment['binding_current']??false)===true
      &&($assignment['manager_id']??null)===$manager
      &&($assignment['company_code']??null)===$company
      &&($assignment['environment']??null)===$environment
      &&($assignment['company_site_uuid']??null)===$site['site_uuid']
      &&($assignment['canonical_origin']??null)===$site['canonical_origin'];
    $base['exact_grant_principal_intent_delegation_verified']=$actorEligible
      &&$sameSite&&is_string($base['required_company_right'])
      &&$p::has_production_delegation(
        $manager,$company,$base['required_company_right'],$actor);
  } elseif($canReview) {
    // Preserve existing Staging admission until the Staging bridge is
    // refactored separately. A Production grant never authorizes Staging.
    $entitlement=MAD4B_Manager_Auto_Write_V1::entitlement(
      $manager,$company,$intent,$actor);
    $base['exact_grant_principal_intent_delegation_verified']=
      is_array($entitlement)
      &&($entitlement['assignment_authorized']??false)===true
      &&($entitlement['company_binding_verified']??false)===true
      &&($entitlement['manager_membership_active']??false)===true;
  }
  $base['owner_scope_review_required']=!$base['exact_grant_principal_intent_delegation_verified'];
  // Three independent facts. A historic Original 313 is never permission
  // for a new 305 Job and never substitutes for company-scoped delegation.
  $base['authority_ready']=$base['exact_grant_principal_intent_delegation_verified'];
  $base['executor_ready']=false;
  $base['job_verified']=false;
  $base['operation_provider_registered']=is_array($provider)
    && ($provider['operation']??null)===$intent
    && ($provider['authority_family']??null)==='original_305'
    && ($provider['target_scope']??null)==='wordpress';
  $base['operation_provider_ready']=$base['transport_provider_ready']
    && $base['operation_provider_registered']
    && count($rows)===1
    && ($rows[0]['provider']??null)===($provider['provider']??null);
  $base['provider_discovery_is_global_only']=true;
  // A provider is global, but every signed 312 capability is bound to
  // exactly one company Site UUID and environment. Never let a Wayfatours
  // heartbeat make another company's 305 job eligible for submission.
  if(class_exists('MAD4B_Company_Native_Scoped_Write_Handler_V1',false)
     && isset(MAD4B_Company_Native_Scoped_Write_Handler_V1::OPERATIONS[$intent])) {
    $scoped=MAD4B_Company_Native_Scoped_Write_Handler_V1::capability_status(
      $intent,$company,$environment);
    $base['exact_312_operation_readback_state']=$scoped['state']??'NOT_READY';
    $base['exact_312_operation_readback_reason']=$scoped['reason']??null;
    $base['operation_provider_ready']=$base['operation_provider_ready']
      && ($scoped['handler_ready']??false)===true
      && ($scoped['company_code']??null)===$company
      && ($scoped['environment']??null)===$environment
      && ($scoped['site_uuid']??null)===$site['site_uuid']
      && ($scoped['canonical_origin']??null)===$site['canonical_origin'];
  } else {
    $base['operation_provider_ready']=$base['operation_provider_ready']
      && ($rows[0]['provider_capability_ready']??false)===true;
  }
  $base['executor_ready']=$base['operation_provider_ready'];
  // A fresh, signed operation handler heartbeat proves the target WP-CLI
  // capability. It does not create/approve a 305 Job or authorize execution.
  $base['target_wordpress_actor_capability_verified']=$base['operation_provider_ready'];
  $registered_pending=$base['operation_provider_registered']
    && !$base['executor_ready'];
  $base['state']=!$base['authority_ready']
    ?'EXACT_OWNER_INTENT_DELEGATION_REQUIRED'
    :($base['executor_ready']
      ?'AUTHORITY_AND_EXECUTOR_READY_FOR_EXACT_JOB'
      :($registered_pending
        ?'AUTHORITY_READY_EXECUTOR_NOT_READY'
        :'AUTHORITY_READY_OPERATION_HANDLER_NOT_INSTALLED'));
  $base['reason']=!$base['authority_ready']
    ?'EXACT_COMPANY_WRITE_DELEGATION_MISSING'
    :($base['executor_ready']?null:($registered_pending
      ?'EXACT_NATIVE_OPERATION_HANDLER_NOT_READY'
      :'EXACT_NATIVE_OPERATION_HANDLER_NOT_REGISTERED'));
  $base['next_stage']=!$base['authority_ready']
    ?'OWNER_APPROVE_EXACT_COMPANY_INTENT_RIGHT'
    :($base['executor_ready']
      ?'ISSUE_ONE_EXACT_305_JOB_THEN_VERIFY_312_AND_313'
      :'INSTALL_OR_REPAIR_EXACT_312_OPERATION_HANDLER');
  return $base;
 }
 public static function preflight_cli($args,$assoc):void {
  if(count($args)!==3 || !empty($assoc))
   WP_CLI::error('EXACT_COMPANY_ENVIRONMENT_INTENT_REQUIRED');
  $result=self::preflight_status($args[0],$args[1],$args[2]);
  WP_CLI::line(wp_json_encode($result,JSON_UNESCAPED_SLASHES));
 }
 public static function boot():void {
  // Record a verified Original 313 job when Core finalizes its existing
  // broker Job option. Other WordPress option writes are ignored.
  add_action('updated_option',[__CLASS__,'updated_original_job_option'],10,3);
  if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
    WP_CLI::add_command('mad4b company-native-write-preflight',[__CLASS__,'preflight_cli']);
 }
 public static function certified_status($company,$environment,$intent):array {
  $company=(string)$company;$environment=(string)$environment;$intent=(string)$intent;
  if (!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)
   || !preg_match('/^[a-z][a-z0-9_-]{1,63}$/D',$environment)
   || !self::supported_operation($intent))
   return self::blocked($company,$environment,$intent,'INVALID_EXACT_OPERATION');
  if (!class_exists('MAD4B_Company_Environment_Registry_V2',false)
   || !class_exists('MAD4B_Original_Verified_WPCLI_Transport_V1',false)
   || !class_exists('MAD4B_Central_SSH_Command_Intake_V1',false)
   || !class_exists('MAD4B_Central_VPS_Broker_V1',false)
   || !class_exists('MAD4B_Broker_Remote_SSH_Receipt_V1',false)
   || !class_exists('MAD4B_Native_WPCLI_Receipt_Verifier_V1',false))
   return self::blocked($company,$environment,$intent,'ORIGINAL_NATIVE_CHAIN_UNAVAILABLE');
  $site=MAD4B_Company_Environment_Registry_V2::target($company,$environment);
  if(!is_array($site)||!wp_is_uuid((string)($site['site_uuid']??'')))
   return self::blocked($company,$environment,$intent,'EXACT_SITE_NOT_ENROLLED');
  $slot=get_option('mad4b_company_native_wpcli_slot_v1_'.$company.'_'.$environment,false);
  $c='MAD4B_Central_SSH_Command_Intake_V1';
  $t=is_array($slot)?get_option($c::TRANSPORT_PREFIX.($slot['transport_id']??''),false):false;
  $g=is_array($slot)?get_option($c::PREFIX.($slot['grant_id']??''),false):false;
  if(!is_array($t)||!is_array($g)||!MAD4B_Original_Verified_WPCLI_Transport_V1::active($t,$g))
   return self::blocked($company,$environment,$intent,'ORIGINAL_GRANT_OR_HOST_BINDING_INVALID',$site);
  $proof=get_option(self::PREFIX.$company.'_'.$environment.'_'.$intent,false);
  // An original independent 313 receipt certifies precisely its historic
  // draft Job. It is NOT a standing general write-rollback certificate.
  if($proof===false){
    $last=self::last_exact_313($company,$environment,$intent,$site);
    if(is_array($last))return [
      'contract'=>self::CONTRACT,
      'state'=>'ORIGINAL_EXACT_JOB_313_CERTIFIED',
      'company_code'=>$company,'environment'=>$environment,
      'site_uuid'=>$site['site_uuid'],
      'canonical_origin'=>$site['canonical_origin'],
      'intent'=>$intent,
      'last_original_313_job_id'=>$last['job_id'],
      'last_original_313_verified_at_epoch'=>$last['verified_at_epoch'],
      'last_original_313_proof_contract'=>$last['original_313_contract'],
      'native_receipt_sha256'=>$last['native_receipt_sha256'],
      'independent_readback_sha256'=>$last['readback_sha256'],
      'last_exact_job_executed'=>true,
      'general_write_authorized'=>false,
      'new_job_authorized'=>false,
      'signed_rollback_certificate_present'=>false,
      'grant_created'=>false
    ];
  }
  if(!is_array($proof)||array_keys($proof)!==array_merge(self::FIELDS,['signature'])
   ||($proof['contract']??null)!==self::PROOF_CONTRACT
   ||($proof['company_code']??null)!==$company
   ||($proof['environment']??null)!==$environment
   ||($proof['site_uuid']??null)!==$site['site_uuid']
   ||($proof['canonical_origin']??null)!==$site['canonical_origin']
   ||($proof['intent']??null)!==$intent
   ||($proof['original_operation']??null)!=='company.remote.wpcli.full'
   ||!is_int($proof['verified_at_epoch']??null)
   ||$proof['verified_at_epoch']>time()+10
   ||$proof['verified_at_epoch']<time()-900)
   return self::blocked($company,$environment,$intent,'CURRENT_SIGNED_WRITE_ROLLBACK_PROOF_MISSING',$site);
  foreach(['command_sha256','native_receipt_sha256','independent_readback_sha256',
     'before_sha256','after_sha256','rollback_sha256'] as $field)
   if(!preg_match('/^[a-f0-9]{64}$/D',(string)($proof[$field]??'')))
    return self::blocked($company,$environment,$intent,'PROOF_DIGEST_INVALID',$site);
  if (!preg_match('/^[a-f0-9]{32}$/D',(string)($proof['job_id']??''))
   || !preg_match('/^[a-f0-9]{32}$/D',(string)($proof['nonce']??''))
   || !hash_equals($proof['before_sha256'],$proof['rollback_sha256'])
   || hash_equals($proof['before_sha256'],$proof['after_sha256']))
   return self::blocked($company,$environment,$intent,'MUTATION_AND_ROLLBACK_NOT_PROVEN',$site);
  $pub=base64_decode(self::PUB,true);$sig=base64_decode((string)($proof['signature']??''),true);
  $data=implode(chr(10),array_map(static fn($key)=>(string)$proof[$key],self::FIELDS));
  if(!is_string($pub)||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
   ||!is_string($sig)||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES
   ||!sodium_crypto_sign_verify_detached($sig,$data,$pub))
   return self::blocked($company,$environment,$intent,'INDEPENDENT_ROOT_SIGNATURE_INVALID',$site);
  $id=$proof['job_id'];
  $job=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);
  $r=get_option(MAD4B_Broker_Remote_SSH_Receipt_V1::WPCLI_PREFIX.$id,false);
  $signed=get_option(MAD4B_Native_WPCLI_Receipt_Verifier_V1::PREFIX.$id,false);
  if(!is_array($job)||!is_array($r)||!is_array($signed)
   ||($job['state']??null)!=='WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED'
   ||($job['executed']??null)!==true
   ||($job['company']??null)!==$company ||($job['environment']??null)!==$environment
   ||($job['site_uuid']??null)!==$site['site_uuid']
   ||($job['grant_id']??null)!==($slot['grant_id']??null)
   ||($job['transport_id']??null)!==($slot['transport_id']??null)
   ||($job['command_sha256']??null)!==$proof['command_sha256']
   ||($proof['command_sha256']??null)===hash('sha256','["option","get","home"]')
   ||($r['native_executor_certified']??null)!==true
   ||($r['independent_host_readback_verified']??null)!==true
   ||($r['native_receipt_sha256']??null)!==$proof['native_receipt_sha256']
   ||($signed['native_receipt_sha256']??null)!==$proof['native_receipt_sha256']
   ||($r['readback_sha256']??null)!==$proof['independent_readback_sha256']
   ||($signed['readback_sha256']??null)!==$proof['independent_readback_sha256']
   ||($signed['job_id']??null)!==$id
   ||($signed['site_uuid']??null)!==$site['site_uuid'])
   return self::blocked($company,$environment,$intent,'ORIGINAL_313_WRITE_JOB_EVIDENCE_MISSING',$site);
  return ['contract'=>self::CONTRACT,'company_code'=>$company,'environment'=>$environment,
   'canonical_origin'=>$site['canonical_origin'],'site_uuid'=>$site['site_uuid'],
   'intent'=>$intent,'state'=>'ORIGINAL_EXACT_WRITE_AND_ROLLBACK_CERTIFIED',
   'native_wordpress_capability_verified'=>true,'executor_authenticated'=>true,
   'readback_handler_verified'=>true,'rollback_handler_verified'=>true,
   'receipt_sha256'=>hash('sha256',$data),'verified_at_epoch'=>$proof['verified_at_epoch'],
   'write_executed'=>false,'grant_created'=>false];
 }
}

MAD4B_Company_Native_Write_Executor_V1::boot();
