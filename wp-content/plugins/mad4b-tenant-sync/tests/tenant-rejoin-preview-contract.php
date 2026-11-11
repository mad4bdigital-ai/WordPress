<?php
if(!class_exists('MAD4B_Tenant_Rejoin_Preview_V1',false))
 require_once '/tmp/mad4b-tenant-rejoin-preview.php';
$uuid=MAD4B_Tenant_Sync::profile()['site_uuid'];
$source=[
 'global_content_id'=>'content-'.str_repeat('a',32),
 'source_version'=>str_repeat('b',64),
 'post_type'=>'post',
 'language'=>'en',
 'share_state'=>'approved',
 'allowed_tenants'=>[$uuid],
 'fields'=>[
    'title'=>'Hub changed title',
    'excerpt'=>'Hub changed excerpt'
 ]
];
$mapping=[
 'global_content_id'=>$source['global_content_id'],
 'site_uuid'=>$uuid,
 'mode'=>'override',
 'language'=>'en',
 'field_modes'=>['title'=>'review','excerpt'=>'review'],
 'baseline_fields'=>['title'=>'Shared original title','excerpt'=>'Shared original excerpt'],
 'local_fields'=>['title'=>'Company local title','excerpt'=>'Shared original excerpt']
];
$checks=[];
$report=MAD4B_Tenant_Rejoin_Preview_V1::compare(['source'=>$source,'mapping'=>$mapping]);
$checks['conflict_detected']=!is_wp_error($report)
 &&$report['preview_result']==='requires_review'
 &&count($report['conflicts'])===1 &&$report['conflicts'][0]['field']==='title';
$checks['safe_source_update_proposed']=count($report['operations'])===1
 &&$report['operations'][0]['field']==='excerpt'
 &&$report['operations'][0]['action']==='update_inherited_field';
$checks['local_preserved']=in_array('title',$report['preserved_local_fields'],true);
$checks['cannot_apply']=$report['can_apply']===false
 &&$report['rejoin_completed']===false &&$report['tenant_writes']===0
 &&$report['hub_writes']===0 &&$report['financial_records_touched']===false;
$checks['candidate_unverified']=$report['candidate_source_proof_verified']===false
 &&$report['requires_provider_signed_baseline']===true;
$checks['no_raw_data_leaked']=strpos(wp_json_encode($report),'Hub changed excerpt')===false
 &&strpos(wp_json_encode($report),'Company local title')===false;
$notShared=$source;$notShared['share_state']='private_preview';
$blocked=MAD4B_Tenant_Rejoin_Preview_V1::compare(['source'=>$notShared,'mapping'=>$mapping]);
$checks['unapproved_source_blocked']=$blocked['preview_result']==='blocked';
$wrong=$source;$wrong['allowed_tenants']=['11111111-1111-4111-8111-111111111111'];
$blocked=MAD4B_Tenant_Rejoin_Preview_V1::compare(['source'=>$wrong,'mapping'=>$mapping]);
$checks['cross_tenant_blocked']=$blocked['preview_result']==='blocked';
$money=$source;$money['fields']['payment_credentials']='unsafe';
$blocked=MAD4B_Tenant_Rejoin_Preview_V1::compare(['source'=>$money,'mapping'=>$mapping]);
$checks['financial_contract_rejected']=$blocked['preview_result']==='blocked';
$fork=$mapping;$fork['mode']='fork';
$r=MAD4B_Tenant_Rejoin_Preview_V1::compare(['source'=>$source,'mapping'=>$fork]);
$checks['fork_preserved']=$r['preview_result']==='detached'
 &&$r['can_apply']===false;
$ref=$mapping;$ref['mode']='reference';
$r=MAD4B_Tenant_Rejoin_Preview_V1::compare(['source'=>$source,'mapping'=>$ref]);
$checks['reference_no_local_mutation']=$r['preview_result']==='reference_only'
 &&$r['tenant_writes']===0;
$bad=MAD4B_Tenant_Rejoin_Preview_V1::compare(['source'=>$source,'mapping'=>$mapping,'execute'=>true]);
$checks['unexpected_payload_rejected']=is_wp_error($bad)
 &&$bad->get_error_code()==='mad4b_rejoin_invalid_candidate_payload';
$checks['public_not_allowed']=is_wp_error(MAD4B_Tenant_Rejoin_Preview_V1::permission());
echo wp_json_encode(['pass'=>!in_array(false,$checks,true),
  'checks'=>$checks,'conflicts'=>$report['conflicts'],
  'operations'=>$report['operations'],'can_apply'=>$report['can_apply']],JSON_UNESCAPED_SLASHES),PHP_EOL;
if(in_array(false,$checks,true))exit(8);
