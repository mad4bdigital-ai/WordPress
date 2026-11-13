<?php
/**
 * Taxonomy evolution post-execution evidence gate.
 * Never invokes mutation. Does not create or broaden execution authority.
 * Requires the ORIGINAL Site Control Plane's signed execution receipt.
 */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__.'/TaxonomyEvolutionGovernance.php';
final class MAD4B_TaxonomyEvolutionNativeEvidence {
 const CONTRACT='mad4b.taxonomy-evolution-native-evidence.v1';
 const ABILITY='mad4b/taxonomy-evolution-native-apply';
 const PROVIDER='jetengine';
 private static function fail(string $code):WP_Error {
  return new WP_Error('mad4b_taxonomy_evolution_'.$code,'Taxonomy evolution evidence rejected.');
 }
 public static function verify(array $plan,array $receipt,array $nativeTargetInput) {
  try { MAD4B_TaxonomyEvolutionGovernance::verify($plan); }
  catch(Throwable $e){return self::fail('invalid_plan');}
  if(($plan['status']??null)!=='PLAN_READY_FOR_INDEPENDENT_NATIVE_AUTHORIZATION' ||
     ($plan['authorizing']??null)!==false)return self::fail('no_native_candidate');
  if(!class_exists('MAD4B_SCP_Execution_Receipt')||
     !class_exists('MAD4B_SCP_Authorization_Target_Fingerprint')||
     !class_exists('MAD4B_SCP_Resource_Constraint_Set'))return self::fail('native_crypto_or_policy_missing');
  $expected=[
   'plan_sha256'=>$plan['plan_sha256'],
   'company_id'=>$plan['site']['company_id'],
   'site_uuid'=>$plan['site']['site_uuid'],
   'origin'=>$plan['site']['origin'],
   'environment'=>$plan['site']['environment'],
   'source_digest'=>$plan['evolution']['source_digest'],
   'inventory_revision'=>$plan['inventory_revision'],
  ];
  foreach($expected as $k=>$v) {
   if(!is_string($nativeTargetInput[$k]??null)||!hash_equals($v,$nativeTargetInput[$k]))return self::fail('native_binding_mismatch');
  }
  $fingerprint=MAD4B_SCP_Authorization_Target_Fingerprint::fingerprint(self::ABILITY,self::PROVIDER,$nativeTargetInput);
  if(!is_string($fingerprint)||!preg_match('/^[a-f0-9]{64}$/D',$fingerprint))return self::fail('fingerprint_unavailable');
  $resourceSet=MAD4B_SCP_Resource_Constraint_Set::compile(self::ABILITY,self::PROVIDER,$nativeTargetInput);
  if(is_wp_error($resourceSet)||!is_array($resourceSet)||!is_string($resourceSet['resource_set_sha256']??null)||
     !preg_match('/^[a-f0-9]{64}$/D',$resourceSet['resource_set_sha256']))return self::fail('resource_binding_unavailable');
  $verification=MAD4B_SCP_Execution_Receipt::verify($receipt);
  if(is_wp_error($verification)||!is_array($verification)||
     ($verification['valid']??null)!==true||
     ($verification['cryptographic_signature_verified']??null)!==true)return self::fail('signature_not_verified');
  if(($receipt['ability']??null)!==self::ABILITY || ($receipt['provider_id']??null)!==self::PROVIDER ||
     !is_string($receipt['target_fingerprint']??null) ||
     !hash_equals($fingerprint,$receipt['target_fingerprint']) ||
     !is_string($receipt['resource_set_sha256']??null) ||
     !hash_equals($resourceSet['resource_set_sha256'],$receipt['resource_set_sha256']))return self::fail('signed_target_binding_mismatch');
  if(($receipt['stages']['readback_reconciliation']['status']??null)!=='PASS')return self::fail('independent_readback_missing');
  return [
   'contract'=>self::CONTRACT,'status'=>'SIGNED_NATIVE_EXECUTION_EVIDENCE_VALID',
   'plan_sha256'=>$plan['plan_sha256'],
   'site_uuid'=>$plan['site']['site_uuid'],
   'execution_receipt_sha256'=>$verification['receipt_sha256'],
   'cryptographic_signature_verified'=>true,
   'readback_stage_present'=>true,
   'authorizing'=>false,'mutation_performed_by_verifier'=>false,
   // Backend/frontend and restore acceptance remain separate postconditions.
   'full_e2e_certified'=>false,
  ];
 }
}
