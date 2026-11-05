<?php
defined('ABSPATH') || exit;
/**
 * Distinct original-owner contracts for Content Hub tasks without ContentJob.
 * Never convert a Canonical Gap, QA diagnostic or provider handoff into a
 * ContentJob. No grant issuance, executor selection or publication.
 */
final class MAD4B_Hub_Unlinked_Task_Context {
 const CONTRACT='mad4b.hub.unlinked-task-context.v1';
 const GAP_OPERATIONS=['verify_source_inventory','review_canonical_identity',
                       'verify_property_identity','review_rate_date_range'];
 static function err($why,$http=409){
  return new WP_Error('mad4b_hub_unlinked_'.$why,$why,
    ['status'=>$http,'contract'=>self::CONTRACT]);
 }
 static function classify($origin){
  if(!is_array($origin) || !$origin)
   return ['workflow_type'=>'legacy_unverified','decision'=>'RECONCILE_ORIGINAL_ORIGIN','eligible'=>false];
  $type=(string)($origin['workflow_type']??'');
  if($type==='product_data')return ['workflow_type'=>$type,
    'decision'=>'CANONICAL_GAP_ORIGINAL_SOURCE_CHECK_REQUIRED','eligible'=>false];
  if($type==='integration_operation')return ['workflow_type'=>$type,
    'decision'=>'VERIFIED_PROVIDER_HANDOFF_RECEIPT_REQUIRED','eligible'=>false];
  if($type==='qa_diagnostic')return ['workflow_type'=>$type,
    'decision'=>'DIAGNOSTIC_NO_ORIGINAL_PROVENANCE','eligible'=>false];
  return ['workflow_type'=>$type?:'unknown','decision'=>'UNKNOWN_ORIGIN_REVIEW','eligible'=>false];
 }
 private static function canonical($v){
  if(!is_array($v))return $v;
  if(!array_is_list($v))ksort($v,SORT_STRING);
  foreach($v as $key=>$part)$v[$key]=self::canonical($part);
  return $v;
 }
 private static function digest($v){
  return hash('sha256',wp_json_encode(self::canonical($v),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
 }
 static function plan(array $item,array $origin,array $payload){
  if((string)($item['job_id']??'')!=='')
   return self::err('noncontent_must_not_bind_content_job');
  if(($origin['contract']??'')!=='mad4b.hub.workflow-origin.v1')
   return self::err('workflow_origin_contract_invalid');
  $receipt=(string)($origin['origin_sha256']??'');
  $bound=$origin;unset($bound['origin_sha256']);
  if(!preg_match('/^[a-f0-9]{64}$/D',$receipt)
    ||!hash_equals($receipt,hash('sha256',wp_json_encode($bound,JSON_UNESCAPED_SLASHES))))
    return self::err('workflow_origin_digest_invalid');
  $kind=(string)($origin['workflow_type']??'');
  if($kind==='qa_diagnostic')return self::err('diagnostic_source_unverified');
  if($kind==='integration_operation')return self::err('provider_handoff_receipt_unverified');
  if($kind!=='product_data')return self::err('missing_original_source_evidence');
  if(($origin['origin_type']??'')!=='canonical_gap'
     ||($origin['source_verified']??false)!==true
     ||($origin['company_scope']??'')!=='verified_canonical_source_not_distribution_target'
     ||($origin['executor_lane']??'')!=='product_gap_resolution')
    return self::err('canonical_origin_unverified');
  $source_id=(string)($origin['source_record_id']??'');
  $target=(string)($item['target_ref']??'');
  if(!preg_match('/^canonical_gap:([1-9][0-9]{0,17})$/D',$source_id,$gap)
    ||!preg_match('/^canonical:([1-9][0-9]{0,17})$/D',$target,$entity))
    return self::err('canonical_reference_invalid');
  if(!in_array((string)($item['operation']??''),self::GAP_OPERATIONS,true))
    return self::err('canonical_high_impact_operation_requires_original_review');
  if(($payload['effect_type']??'')!=='read_only')
    return self::err('canonical_effect_must_be_verified_read_only');
  $output=(string)($payload['output_contract']??'');
  if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/D',$output))
    return self::err('output_contract_missing');
  foreach(['policy_snapshot_sha256','capability_snapshot_sha256'] as $field) {
   if(!preg_match('/^[a-f0-9]{64}$/D',(string)($payload[$field]??'')))
    return self::err('verified_context_'.$field.'_required');
  }
  if(($payload['assigned_executor']??'mcp_client')!=='mcp_client')
   return self::err('original_product_provider_lane_required');
  if(!class_exists('MAD4B_Hub_Workflow_Origin')
     ||MAD4B_Hub_Workflow_Origin::claim_source_guard([
       'target_ref'=>$target,'payload'=>$payload])!==true)
   return self::err('original_canonical_claim_guard_rejected');
  global $wpdb;
  $g=$wpdb->prefix.'mad4b_hub_canonical_gaps';
  $e=$wpdb->prefix.'mad4b_hub_canonical_entities';
  $row=$wpdb->get_row($wpdb->prepare(
   "SELECT g.id,g.canonical_id,g.company_id,g.source_hub_id,g.source_type,g.language,
       g.gap_code,g.state AS gap_state,g.source_sha256,
       e.company_id AS entity_company,e.source_hub_id AS entity_hub,
       e.source_type AS entity_type,e.language AS entity_language,
       e.snapshot_json,e.snapshot_hash,e.version
    FROM $g g INNER JOIN $e e ON e.id=g.canonical_id
    WHERE g.id=%d AND e.id=%d LIMIT 1",(int)$gap[1],(int)$entity[1]),ARRAY_A);
  if(!is_array($row) || ($row['gap_state']??'')!=='needs_evidence'
    ||$row['company_id']==='' || $row['company_id']!==$row['entity_company']
    ||$row['company_id']!==($origin['source_company']??'')
    ||$row['company_id']!==($origin['company_id']??'')
    ||(int)$row['source_hub_id']!==(int)$row['entity_hub']
    ||(int)$row['source_hub_id']!==(int)($origin['source_hub_id']??-1)
    ||$row['source_type']!==$row['entity_type']
    ||$row['source_type']!==($origin['source_type']??'')
    ||$row['language']!==$row['entity_language']
    ||$row['entity_language']!==($origin['source_language']??'')
    ||$row['gap_code']!==($origin['gap_code']??''))
    return self::err('canonical_original_source_or_company_drift');
  $snapshot=json_decode((string)$row['snapshot_json'],true);
  if(!is_array($snapshot) || (string)($snapshot['provider']??'')==='' ||
     (string)($snapshot['dataset']??'')==='' ||
     (string)($snapshot['provider']??'')!==(string)($origin['source_provider']??'') ||
     (string)($snapshot['dataset']??'')!==(string)($origin['source_dataset']??''))
    return self::err('canonical_original_provider_or_dataset_drift');
  if(!class_exists('MAD4B_SCP_Site_Profile'))
   return self::err('original_central_site_profile_missing');
  $site=(string)MAD4B_SCP_Site_Profile::site_uuid();
  if(!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$site))
   return self::err('original_central_site_uuid_invalid');
  // Source snapshots are derived from authoritative stored records,
  // never from a client-supplied source hash.
  $original_fingerprint=self::digest([
    'gap_id'=>(int)$gap[1],'entity_id'=>(int)$entity[1],
    'gap_source_sha256'=>(string)$row['source_sha256'],
    'canonical_snapshot_hash'=>(string)$row['snapshot_hash'],
    'entity_version'=>(string)$row['version'],
    'canonical_snapshot_json_sha256'=>hash('sha256',(string)$row['snapshot_json']),
    'state'=>$row['gap_state'],
    'company_id'=>$row['company_id'],
  ]);
  $company=(string)$row['company_id'];
  $slot=['workflow'=>'product_data','site_uuid'=>$site,
    'source_company'=>$company,'origin'=>'canonical_gap',
    'gap_id'=>(int)$gap[1],'entity_id'=>(int)$entity[1],
    'stage'=>(string)$item['stage'],'operation'=>(string)$item['operation'],
    'target_ref'=>$target];
  $semantic=$slot+[
    'original_source_fingerprint'=>$original_fingerprint,
    'source_provider'=>(string)($origin['source_provider']??''),
    'source_dataset'=>(string)($origin['source_dataset']??''),
    'policy_snapshot_sha256'=>$payload['policy_snapshot_sha256'],
    'capability_snapshot_sha256'=>$payload['capability_snapshot_sha256'],
    'output_contract'=>$output,'effect_type'=>'read_only',
    'stage_input'=>$payload['stage_input']??[],
    'stage_ability'=>$payload['stage_ability']??'',
    'executor_lane'=>$origin['executor_lane'],
    'assigned_executor'=>$payload['assigned_executor']??'mcp_client',
    'preferred_ability'=>$payload['preferred_ability']??'',
    'dependencies'=>$item['depends_on']??[],
  ];
  return ['eligible'=>true,'workflow_type'=>'product_data',
    'scope'=>['job_id'=>'','site_uuid'=>$site,'tenant_id'=>'','brand_id'=>'',
     'job_revision'=>0,'source_company'=>$company,'source_record_id'=>$source_id],
    'slot_hash'=>self::digest($slot),'work_hash'=>self::digest($semantic),
    'source_readback_hash'=>$original_fingerprint,
    'original_provenance_verified'=>true];
 }
}
