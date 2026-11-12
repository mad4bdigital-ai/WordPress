<?php
/**
 * A source-backed, exact-tenant resource resolver for dynamic Repair Jobs.
 * Does not fetch arbitrary URLs or execute commands; only the already trusted
 * Original Registry provider's same-file static resolver can observe a resource.
 *
 * This is an unsigned planning observation, never a Grant or Original 313.
 */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_Company_Repair_Resource_Resolver_V2',false)){
final class MAD4B_Company_Repair_Resource_Resolver_V2{
 public const CONTRACT='mad4b.company-repair.resource-resolver.v2';
 public const OBSERVATION='mad4b.company-repair.resource-observation.v1';
 public const MAX_AGE=600;

 private static function hold(string $reason,array $scope=[]):array {
  return [
   'contract'=>self::CONTRACT,'state'=>'RESOURCE_RESOLUTION_HOLD',
   'reason'=>$reason,
   'company_code'=>$scope['company_code']??null,
   'environment'=>$scope['environment']??null,
   'site_uuid'=>$scope['site_uuid']??null,
   'canonical_origin'=>$scope['canonical_origin']??null,
   'resource_observed'=>false,
   'resource_manifest_signed'=>false,
   'original_305_submitted'=>false,'original_312_signed'=>false,
   'original_313_signed'=>false,'target_mutated'=>false,
   'repair_execution_authorized'=>false
  ];
 }
 public static function selector_valid(array $selector):bool {
  return array_keys($selector)===['resource_type','resource_id']
   &&is_string($selector['resource_type'])
   &&preg_match('/^[a-z][a-z0-9._-]{2,63}$/D',$selector['resource_type'])===1
   &&is_string($selector['resource_id'])
   &&preg_match('/^[a-z0-9][a-z0-9._:-]{0,127}$/D',$selector['resource_id'])===1
   &&strlen($selector['resource_id'])<=128;
 }
 private static function canonical(array $scope):bool {
  return is_string($scope['company_code']??null)
    &&preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$scope['company_code'])===1
    &&in_array($scope['environment']??null,['staging','production'],true)
    &&function_exists('wp_is_uuid')&&wp_is_uuid($scope['site_uuid']??null)
    &&is_string($scope['canonical_origin']??null)
    &&preg_match('~^https://[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$~iD',
        $scope['canonical_origin'])===1
    &&is_string($scope['source_plan_sha256']??null)
    &&preg_match('/^[a-f0-9]{64}$/D',$scope['source_plan_sha256'])===1;
 }
 /**
  * Pure validator, suitable for deliberate negative tests with fake results.
  * Never return "signed" because only Original 313 may certify postconditions.
  */
 public static function validate(array $scope,array $candidate,array $selector,
                                  array $observed,int $now):array {
  if(!self::canonical($scope))return self::hold('EXACT_SITE_SCOPE_INVALID',$scope);
  if(!self::selector_valid($selector))return self::hold('RESOURCE_SELECTOR_INVALID',$scope);
  if(($candidate['resource_resolver_contract']??null)!==self::CONTRACT
    ||!in_array($selector['resource_type'],
        (array)($candidate['resource_types']??[]),true)
    ||!is_string($candidate['operation']??null)
    ||!is_string($candidate['provider']??null)
    ||($candidate['resource_scope']??'')!=='exact_site_resource')
    return self::hold('PROVIDER_RESOURCE_TYPE_NOT_DECLARED',$scope);
  if(($observed['contract']??null)!==self::OBSERVATION
    ||($observed['state']??null)!=='EXACT_RESOURCE_READ_ONLY_OBSERVED'
    ||($observed['observation_level']??null)!=='TARGET_WORDPRESS_AUTHENTICATED_READ_ONLY'
    ||($observed['original_313_signed']??true)!==false
    ||($observed['original_312_signed']??true)!==false
    ||($observed['mutation_performed']??true)!==false
    ||($observed['native_job_created']??true)!==false)
    return self::hold('UNTRUSTED_OR_NON_READONLY_RESULT',$scope);
  foreach(['company_code','environment','site_uuid','canonical_origin','source_plan_sha256'] as $field)
   if(($observed[$field]??null)!==($scope[$field]??null))
    return self::hold('PROVIDER_EXACT_SITE_OR_SOURCE_PLAN_MISMATCH',$scope);
  foreach(['resource_type','resource_id'] as $field)
   if(($observed[$field]??null)!==($selector[$field]??null))
    return self::hold('OBSERVED_RESOURCE_SELECTOR_MISMATCH',$scope);
  foreach(['operation','provider'] as $field)
   if(($observed[$field]??null)!==($candidate[$field]??null))
    return self::hold('PROVIDER_OPERATION_IDENTITY_MISMATCH',$scope);
  $key=$selector['resource_type'].':'.$selector['resource_id'];
  if(($observed['resource_key']??null)!==$key
     ||str_starts_with($key,'incident:'))
    return self::hold('RESOURCE_KEY_NOT_EXACT',$scope);
  foreach(['before_sha256','resource_manifest_sha256',
            'company_preservation_sha256'] as $name)
   if(!is_string($observed[$name]??null)
       ||preg_match('/^[a-f0-9]{64}$/D',$observed[$name])!==1)
    return self::hold('RESOURCE_DIGEST_MISSING',$scope);
  $epoch=$observed['observed_epoch']??null;
  if(!is_int($epoch)||$now-$epoch>self::MAX_AGE||$epoch>$now+30)
    return self::hold('RESOURCE_TARGET_OBSERVATION_EXPIRED',$scope);
  $ownership=$observed['ownership_scope']??null;
  if(!in_array($ownership,['shared_definition','tenant_owned','mixed',
    'site_generated','reconnect','never_clone','inspect_only','company_policy'],true))
    return self::hold('RESOURCE_OWNERSHIP_NOT_DEFINED',$scope);
  if(in_array($ownership,['never_clone','inspect_only'],true))
    return self::hold('RESOURCE_IMMUTABLE_OR_INSPECT_ONLY',$scope);
  $material=[
   'contract'=>self::OBSERVATION,
   'company_code'=>$scope['company_code'],
   'environment'=>$scope['environment'],
   'site_uuid'=>$scope['site_uuid'],
   'canonical_origin'=>$scope['canonical_origin'],
   'source_plan_sha256'=>$scope['source_plan_sha256'],
   'operation'=>$candidate['operation'],'provider'=>$candidate['provider'],
   'resource_type'=>$selector['resource_type'],
   'resource_id'=>$selector['resource_id'],
   'resource_key'=>$key,
   'ownership_scope'=>$ownership,
   'before_sha256'=>$observed['before_sha256'],
   'resource_manifest_sha256'=>$observed['resource_manifest_sha256'],
   'company_preservation_sha256'=>$observed['company_preservation_sha256'],
   // Freshness is verified separately. A new observation of unchanged bytes
   // must not create a new semantic snapshot or break job idempotency.
   'observation_level'=>'TARGET_WORDPRESS_AUTHENTICATED_READ_ONLY'
  ];
  $encoded=json_encode($material,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  if(!is_string($encoded))return self::hold('RESOLUTION_HASH_ENCODING_FAILED',$scope);
  $sha=hash('sha256',$encoded);
  return [
   'contract'=>self::CONTRACT,
   'state'=>'EXACT_RESOURCE_OBSERVED_UNSIGNED',
   'company_code'=>$scope['company_code'],
   'environment'=>$scope['environment'],
   'site_uuid'=>$scope['site_uuid'],
   'canonical_origin'=>$scope['canonical_origin'],
   'source_plan_sha256'=>$scope['source_plan_sha256'],
   'operation'=>$candidate['operation'],'provider'=>$candidate['provider'],
   'resource_type'=>$selector['resource_type'],
   'resource_id'=>$selector['resource_id'],
   'resource_key'=>$key,
   'ownership_scope'=>$ownership,
   'before_sha256'=>$observed['before_sha256'],
   'resource_manifest_sha256'=>$observed['resource_manifest_sha256'],
   'company_preservation_sha256'=>$observed['company_preservation_sha256'],
   'observed_epoch'=>$epoch,
   'evidence_age_seconds'=>$now-$epoch,
   'resource_resolution_sha256'=>$sha,
   'resource_observed'=>true,
   'resource_manifest_signed'=>false,
   'original_305_submitted'=>false,'original_312_signed'=>false,
   'original_313_signed'=>false,'target_mutated'=>false,
   'repair_execution_authorized'=>false,
  ];
 }
 public static function trusted_provider_resolver(array $provider,array $resolver):bool {
  $readback=$provider['readback']??null;
  if(!is_array($readback)||count($readback)!==2
    ||!is_array($resolver)||count($resolver)!==2
    ||!is_string($readback[0]??null)||!is_string($readback[1]??null)
    ||!is_string($resolver[0]??null)||!is_string($resolver[1]??null)
    ||!is_callable($readback)||!is_callable($resolver))return false;
  try{
   $a=new ReflectionMethod($readback[0],$readback[1]);
   $b=new ReflectionMethod($resolver[0],$resolver[1]);
   if(!$a->isPublic()||!$a->isStatic()||!$b->isPublic()||!$b->isStatic())return false;
   $fa=realpath((string)$a->getFileName());
   $fb=realpath((string)$b->getFileName());
   if(!$fa||!$fb||$fa!==$fb||is_link($fa)||fileowner($fa)!==0
     ||(fileperms($fa)&0022)!==0)return false;
   // Registry::profile() only exposes registered source-trusted readback.
   // Same release file and root ownership prevent arbitrary callback injection.
   return true;
  }catch(Throwable $error){return false;}
 }
 /**
  * Dynamically dispatches to the selected trusted provider's same-file
  * read-only resolver, with explicit exact-site and resource selectors.
  */
 public static function resolve(array $scope,array $discovery,array $selector,
                                  int $now=0):array {
  if(!self::canonical($scope)||!self::selector_valid($selector))
   return self::hold('INVALID_SOURCE_OR_RESOURCE_SELECTOR',$scope);
  $selected=$discovery['selected']??null;
  if(($discovery['state']??null)!=='EXACT_CAPABILITY_DISCOVERED'
    ||!is_array($selected)
    ||($selected['resource_resolver_contract']??null)!==self::CONTRACT)
   return self::hold('NO_UNAMBIGUOUS_TRUSTED_PROVIDER_RESOLVER',$scope);
  $registry='MAD4B_Original_Native_Operation_Providers_V1';
  if(!class_exists($registry,false)||!is_callable([$registry,'profile']))
   return self::hold('ORIGINAL_PROVIDER_REGISTRY_UNAVAILABLE',$scope);
  $provider=$registry::profile($selected['operation']);
  if(!is_array($provider)
    ||($provider['provider']??null)!==$selected['provider']
    ||($provider['authority_family']??null)!=='original_305'
    ||($provider['target_scope']??null)!=='wordpress')
   return self::hold('REGISTERED_ORIGINAL_PROVIDER_CHANGED',$scope);
  try{
   $meta=call_user_func($provider['readback']);
   if(!is_array($meta)||($meta['contract']??null)!==$provider['evidence_contract']
      ||!is_array($meta['repair_support']??null))
    return self::hold('PROVIDER_READBACK_CONTRACT_CHANGED',$scope);
   $support=$meta['repair_support'];
   if(($support['contract']??null)!==
       MAD4B_Company_Repair_Capability_Discovery_V1::CONTRACT
      ||($support['operation']??null)!==$selected['operation']
      ||($support['provider']??null)!==$selected['provider']
      ||($support['evidence_contract']??null)!==$provider['evidence_contract']
      ||($support['resource_resolver_contract']??null)!==self::CONTRACT
      ||($support['resource_resolution_read_only']??null)!==true
      ||($support['mutation_authorized']??true)!==false
      ||($support['independent_313_required']??false)!==true
      ||($support['rollback_via_new_original_job']??false)!==true
      ||!is_array($support['supported_checks']??null)
      ||!in_array($scope['check']??null,$support['supported_checks'],true)
      ||!in_array($selector['resource_type'],
          (array)($support['resource_types']??[]),true)
      ||implode('::',(array)($support['resource_resolver']??[]))!==
           ($selected['resource_resolver_id']??null)
      ||!self::trusted_provider_resolver($provider,(array)($support['resource_resolver']??[])))
    return self::hold('PROVIDER_RESOLVER_PROVENANCE_INVALID',$scope);
   $request=$scope+['resource_type'=>$selector['resource_type'],
                    'resource_id'=>$selector['resource_id'],
                    'dry_run'=>true,'allow_write'=>false];
   $observed=call_user_func($support['resource_resolver'],$request);
   if(!is_array($observed))return self::hold('PROVIDER_RESOLVER_RESULT_INVALID',$scope);
   if(($observed['state']??null)==='RESOURCE_OBSERVATION_HOLD'){
       $why=$observed['reason']??null;
       if(!is_string($why)||!preg_match('/^[A-Z_]{5,80}$/D',$why))
           $why='RESOURCE_PROVIDER_READBACK_NOT_READY';
       return self::hold($why,$scope);
   }
   return self::validate($scope,$selected,$selector,$observed,$now>0?$now:time());
  }catch(Throwable $e){
   return self::hold('PROVIDER_RESOURCE_READBACK_FAILED',$scope);
  }
 }
}
}
