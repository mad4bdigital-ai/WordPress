<?php
if(!class_exists('MAD4B_SCP_Relationship_Owner_Write_Adapter')||
  !class_exists('MAD4B_SCP_Central_Write_Acceptance'))
 throw new RuntimeException('original_owner_contract_missing');
$c='MAD4B_SCP_Relationship_Owner_Write_Adapter';
$now=1793649000;
$trusted=[
 'site_uuid'=>(string)(MAD4B_SCP_Central_Write_Acceptance::site_scope()['site_uuid']??'fixture-site'),
 'job_id'=>'e64bb725-9c43-41a4-a026-bbad511bd3d3',
 'source_post_id'=>112,'target_post_id'=>213,
 'original_ability'=>'jetengine/update-post-meta',
 'native_source_sha256'=>str_repeat('a',64),
 'binding_sha256'=>str_repeat('b',64),
 'provider_id'=>'jetengine'
];
$cert=$trusted+[
 'contract'=>$c::CERT_CONTRACT,
 'verified_at'=>$now-120,
 'evidence_sha256'=>str_repeat('c',64),
 'independent_verification'=>true,'quality_pass'=>true,'rights_pass'=>true
];
$checks=[];
$case=static function($name,$proof,$good)use($c,$trusted,$now,&$checks){
 $checks[$name]=($c::certificateValid($proof,$trusted,$now)===$good);
};
$case('valid_independently_bound_original_owner_certificate',$cert,true);
$case('no_certificate_default_denied',null,false);
$wrong=$cert;unset($wrong['rights_pass']);$case('rights_not_implicitly_inferred',$wrong,false);
$wrong=$cert;unset($wrong['quality_pass']);$case('quality_not_inferred_from_wpml_readback',$wrong,false);
$wrong=$cert;$wrong['independent_verification']=false;$case('independent_provider_proof_required',$wrong,false);
$wrong=$cert;$wrong['provider_id']='elementor';$case('wrong_owner_provider_denied',$wrong,false);
$wrong=$cert;$wrong['job_id']='wrong';$case('cross_job_rejected',$wrong,false);
$wrong=$cert;$wrong['site_uuid']='another-site';$case('cross_site_rejected',$wrong,false);
$wrong=$cert;$wrong['source_post_id']=111;$case('source_object_changed_rejected',$wrong,false);
$wrong=$cert;$wrong['target_post_id']=211;$case('target_object_changed_rejected',$wrong,false);
$wrong=$cert;$wrong['original_ability']='elementor/set-dynamic-tag';$case('ability_changed_rejected',$wrong,false);
$wrong=$cert;$wrong['native_source_sha256']=str_repeat('d',64);$case('source_sha_changed_rejected',$wrong,false);
$wrong=$cert;$wrong['binding_sha256']=str_repeat('d',64);$case('wpml_binding_changed_rejected',$wrong,false);
$wrong=$cert;$wrong['verified_at']=$now-259201;$case('expired_evidence_rejected',$wrong,false);
$wrong=$cert;$wrong['verified_at']=$now+61;$case('future_evidence_rejected',$wrong,false);
$wrong=$cert;$wrong['verified_at']=null;$case('missing_timestamp_denied',$wrong,false);
$wrong=$cert;$wrong['evidence_sha256']='not-sha';$case('malformed_evidence_rejected',$wrong,false);
$fail=array_keys(array_filter($checks,fn($pass)=>!$pass));
echo wp_json_encode(['contract'=>'mad4b.typed-owner-certificate-acceptance.v1',
 'checks'=>count($checks),'pass'=>!$fail,'failed'=>$fail,
 'fixture_only'=>true,'cms_mutations'=>0],
 JSON_UNESCAPED_SLASHES).PHP_EOL;
if($fail)throw new RuntimeException('typed_owner_certificate_validation_failed');
