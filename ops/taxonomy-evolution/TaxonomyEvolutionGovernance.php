<?php
declare(strict_types=1);
/**
 * Site-neutral taxonomy evolution planner: planning and receipt verification only.
 * Observed input and hashes do not grant authority to the native executor.
 */
final class MAD4B_TaxonomyEvolutionGovernance {
 public const CONTRACT='mad4b.taxonomy-evolution-governance.v1';
 private static function demand(bool $ok,string $why):void {
  if(!$ok)throw new DomainException($why);
 }
 private static function digest(array $x):string {
  return hash('sha256',json_encode($x,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
 }
 private static function slug($s):bool {
  return is_string($s) && preg_match('/^[a-z][a-z0-9_-]{2,59}$/D',$s)===1;
 }
 public static function plan(array $site,array $move,array $observed):array {
  foreach(['company_id','site_uuid','origin','environment'] as $k)
   self::demand(is_string($site[$k]??null)&&$site[$k]!=='','site_binding_missing:'.$k);
  self::demand(str_starts_with($site['origin'],'https://')&&(bool)filter_var($site['origin'],FILTER_VALIDATE_URL),'invalid_site_origin');
  self::demand(in_array($site['environment'],['development','local','staging','production'],true),'invalid_environment');
  self::demand(self::slug($move['from']??null)&&self::slug($move['to']??null)&&$move['from']!==$move['to'],'invalid_taxonomy_evolution');
  self::demand(($move['version']??null)===1&&is_string($move['source_digest']??null)&&preg_match('/^[a-f0-9]{64}$/D',$move['source_digest'])===1,'source_manifest_missing');
  self::demand(($observed['company_id']??null)===$site['company_id']&&($observed['site_uuid']??null)===$site['site_uuid']&&($observed['origin']??null)===$site['origin']&&($observed['environment']??null)===$site['environment'],'inventory_site_mismatch');
  self::demand(is_string($observed['inventory_revision']??null)&&preg_match('/^[A-Za-z0-9._:-]{1,96}$/D',$observed['inventory_revision'])===1,'inventory_revision_missing');
  self::demand(($observed['complete']??null)===true&&($observed['provider']??null)==='enrolled_native_readback','incomplete_or_untrusted_inventory');
  $reg=$observed['registered_taxonomies']??null;
  self::demand(is_array($reg)&&is_bool($reg[$move['from']]??null)&&is_bool($reg[$move['to']]??null),'taxonomy_status_missing');
  $counts=$observed['counts']??null;
  self::demand(is_array($counts),'counts_missing');
  foreach(['definitions','terms','translation_rows','relationships','unbound_wpml_terms'] as $k)
   self::demand(is_int($counts[$k]??null)&&$counts[$k]>=0,'invalid_count:'.$k);
  $refs=$observed['references']??null;
  self::demand(is_array($refs)&&count($refs)<=128,'dependency_census_missing_or_unbounded');
  $supported=[];$unsupported=[];
  foreach($refs as $key=>$item){
   self::demand(is_string($key)&&preg_match('/^[a-z][a-z0-9_-]{1,60}$/D',$key)===1&&is_array($item)&&is_int($item['count']??null)&&$item['count']>=0&&in_array($item['adapter']??null,['certified','not_required','missing','review'],true),'bad_reference_census');
   if($item['count']===0)continue;
   if(in_array($item['adapter'],['missing','review'],true))$unsupported[$key]=$item['count'];
   else $supported[$key]=$item['count'];
  }
  $languages=$observed['languages']??null;
  self::demand(is_array($languages)&&is_array($languages['active']??null)&&is_array($languages['hidden']??null),'language_state_missing');
  foreach(['active','hidden'] as $set){
   self::demand(count($languages[$set])<=100,'language_list_unbounded');
   foreach($languages[$set] as $code)self::demand(is_string($code)&&preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/D',$code)===1,'invalid_language_code');
   self::demand(count(array_unique($languages[$set]))===count($languages[$set]),'duplicate_language');
  }
  self::demand(count(array_diff($languages['hidden'],$languages['active']))===0,'hidden_locale_not_active');
  $old=$reg[$move['from']];$new=$reg[$move['to']];
  if($old&&!$new){$action='MIGRATE_LEGACY';$state='LEGACY_ONLY';}
  elseif(!$old&&$new){$action='ADOPT_CANONICAL';$state='CANONICAL_ONLY';}
  elseif($old&&$new){$action='REVIEW_DUAL';$state='DUAL_REGISTRATION';}
  else{$action='PROVISION_SCHEMA';$state='NONE_REGISTERED';}
  $blockers=[];
  if($state==='DUAL_REGISTRATION')$blockers[]='dual_taxonomy_identity_conflict';
  if($state==='CANONICAL_ONLY' && ($counts['definitions']!==0||$counts['terms']!==0||$counts['translation_rows']!==0))$blockers[]='legacy_residue_requires_review';
  if($state==='NONE_REGISTERED' && ($counts['definitions']!==0||$counts['terms']!==0||$counts['translation_rows']!==0))$blockers[]='orphan_rows_without_registration';
  if($state==='CANONICAL_ONLY' && ($supported||$unsupported))$blockers[]='legacy_references_remain';
  // No-op adoption is read-only; requiring a restore grant would be wrong.
  // Mutations use the original native authority and independent restore.
  if(in_array($action,['MIGRATE_LEGACY','PROVISION_SCHEMA'],true)) {
   if($action==='MIGRATE_LEGACY'&&empty($observed['preserve_rewrite_verified']))$blockers[]='rewrite_contract_unverified';
   if(empty($observed['canonical_source_observed']))$blockers[]='canonical_definition_not_observed';
   if($unsupported)$blockers[]='unresolved_provider_dependencies';
   if(empty($observed['verified_restore_checkpoint']))$blockers[]='restore_checkpoint_missing';
   if(empty($observed['native_executor_ready']))$blockers[]='native_executor_unverified';
   if(empty($observed['exact_runtime_provider_certified']))$blockers[]='runtime_adapter_not_certified';
  }
  $reviews=[];
  if($counts['unbound_wpml_terms']>0)$reviews[]=['kind'=>'wpml_term_identity','count'=>$counts['unbound_wpml_terms'],'scope'=>'per_object_review','blocks_all_unrelated_objects'=>false];
  if($unsupported)$reviews[]=['kind'=>'provider_reference_adapter','references'=>$unsupported,'blocks_native_apply'=>true];
  $plan=[
   'contract'=>self::CONTRACT,'site'=>$site,'evolution'=>$move,
   'observed_snapshot_sha256'=>self::digest($observed),'inventory_revision'=>$observed['inventory_revision'],'state'=>$state,'action'=>$action,
   'supported_reference_counts'=>$supported,'unsupported_reference_counts'=>$unsupported,
   'reviews'=>$reviews,'blockers'=>$blockers,
   'wpml_hidden_languages_preserved'=>$languages['hidden'],
   'stages'=>['discover','identity_reconcile','dependency_graph','snapshot','native_authority','execute','site_readback','frontend_readback','rollback_or_commit'],
   'status'=>$blockers?'BLOCKED_PENDING_EVIDENCE':($state==='CANONICAL_ONLY'?'NOOP_PENDING_READBACK':'PLAN_READY_FOR_INDEPENDENT_NATIVE_AUTHORIZATION'),
   'authorizing'=>false,'write_performed'=>false,'production_mutation'=>false
  ];
  $plan['plan_sha256']=self::digest($plan);
  return $plan;
 }
 public static function verify(array $plan):void {
  $sha=$plan['plan_sha256']??null;
  self::demand(is_string($sha)&&preg_match('/^[a-f0-9]{64}$/D',$sha)===1,'missing_plan_digest');
  unset($plan['plan_sha256']);
  self::demand(hash_equals($sha,self::digest($plan)),'plan_changed');
  self::demand(($plan['authorizing']??null)===false&&($plan['write_performed']??null)===false,'planner_must_not_write');
 }
 public static function verifyShadow(array $plan,array $receipt):void {
  self::verify($plan);
  self::demand(($receipt['plan_sha256']??null)===$plan['plan_sha256'],'shadow_plan_not_bound');
  self::demand(($receipt['site_uuid']??null)===$plan['site']['site_uuid']&&($receipt['company_id']??null)===$plan['site']['company_id']&&($receipt['origin']??null)===$plan['site']['origin'],'shadow_wrong_site');
  self::demand(($receipt['inventory_revision']??null)===$plan['inventory_revision'],'shadow_stale_inventory');
  self::demand(($receipt['database_mode']??null)==='connection_local_temporary_tables','shadow_isolation_missing');
  self::demand(($receipt['forward_counts_verified']??null)===true&&($receipt['rollback_hashes_verified']??null)===true&&($receipt['persistent_writes']??null)===false,'shadow_verification_failed');
  // This validates a caller-supplied simulation claim, NOT the native executor's signature.
  // It must never be accepted as execution/restore authority.
 }
}
