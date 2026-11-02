<?php
if(!class_exists('MAD4B_SCP_Capability_Transport_Routing')||
 !class_exists('MAD4B_SCP_Unified_Capability_Gateway'))
 throw new RuntimeException('central_transport_gateway_missing');
$c='MAD4B_SCP_Capability_Transport_Routing';
$cases=[
 ['name'=>'read_http_first','effect'=>'read','state'=>['http_read_authenticated'=>true,
    'vps_read_verified'=>true,'rest_read_authenticated'=>true],'want'=>$c::HTTP],
 ['name'=>'read_stdio_second','effect'=>'read','state'=>['vps_read_verified'=>true],'want'=>$c::VPS],
 ['name'=>'read_rest_discovery_only','effect'=>'read','state'=>['rest_read_authenticated'=>true],'want'=>$c::REST],
 ['name'=>'write_http_first','effect'=>'write','state'=>['http_read_authenticated'=>true,'http_write_authenticated'=>true,
    'vps_read_verified'=>true,'vps_exact_write_verified'=>true],'want'=>$c::HTTP],
 ['name'=>'write_stdio_exact','effect'=>'write','state'=>['vps_read_verified'=>true,'vps_exact_write_verified'=>true],'want'=>$c::VPS],
 ['name'=>'vps_write_without_read_proof_denied','effect'=>'write','state'=>['vps_exact_write_verified'=>true],'want'=>null],
 ['name'=>'http_write_without_read_proof_denied','effect'=>'write','state'=>['http_write_authenticated'=>true],'want'=>null],
 ['name'=>'read_only_stdio_write_denied','effect'=>'write','state'=>['vps_read_verified'=>true],'want'=>null],
 ['name'=>'rest_read_write_denied','effect'=>'write','state'=>['rest_read_authenticated'=>true],'want'=>null],
 ['name'=>'rest_exact_local_or_remote_write_allowed','effect'=>'write','state'=>[
    'rest_read_authenticated'=>true,'rest_exact_write_verified'=>true,
    'rest_site_identity_verified'=>true,'rest_operation_permission_verified'=>true],'want'=>$c::REST],
 ['name'=>'rest_no_site_binding_denied','effect'=>'write','state'=>[
    'rest_read_authenticated'=>true,'rest_exact_write_verified'=>true,
    'rest_operation_permission_verified'=>true],'want'=>null],
 ['name'=>'rest_no_operation_permission_denied','effect'=>'write','state'=>[
    'rest_read_authenticated'=>true,'rest_exact_write_verified'=>true,
    'rest_site_identity_verified'=>true],'want'=>null],
 ['name'=>'rest_write_claim_without_auth_denied','effect'=>'write','state'=>[
    'rest_exact_write_verified'=>true,'rest_site_identity_verified'=>true,
    'rest_operation_permission_verified'=>true],'want'=>null],

 ['name'=>'historic_audit_without_current_proof','effect'=>'write','state'=>[
    'historical_portfolio_read_verified'=>true],'want'=>null],
 ['name'=>'unknown_result_never_retry','effect'=>'write','state'=>[
    'http_write_authenticated'=>true,'vps_exact_write_verified'=>true,
    'previous_attempt_outcome'=>'unknown'],'want'=>null],
 ['name'=>'partial_result_never_retry','effect'=>'write','state'=>[
    'http_write_authenticated'=>true,'previous_attempt_outcome'=>'partial'],'want'=>null],
 ['name'=>'committed_result_readback_only','effect'=>'write','state'=>[
    'vps_exact_write_verified'=>true,'previous_attempt_outcome'=>'committed'],'want'=>null],
 ['name'=>'confirmed_not_started_safe','effect'=>'write','state'=>[
    'vps_read_verified'=>true,'vps_exact_write_verified'=>true,'previous_attempt_outcome'=>'confirmed_not_started'],'want'=>$c::VPS],
];
$errors=[];$out=[];
foreach($cases as $case){
 $r=$c::choose($case['effect'],$case['state']);
 $pass=array_key_exists('selected_transport',$r)&&$r['selected_transport']===$case['want']
   &&($r['authority_created']??null)===false;
 if(($case['name']==='unknown_result_never_retry'||$case['name']==='partial_result_never_retry')
   &&($r['retry_allowed']??true)!==false)$pass=false;
 $out[]=['test'=>$case['name'],'pass'=>$pass,'selected'=>$r['selected_transport']??null,
   'status'=>$r['status']??''];
 if(!$pass)$errors[]=$case['name'];
}
// A CLI administrator must NOT be able to manufacture a current REST
// write proof by passing an arbitrary request body or WP-CLI --user flag.
$synthetic=new WP_REST_Request('POST','/mad4b/v1/content-readiness/sync-one');
$proof=$c::rest_write_evidence($synthetic);
if(($proof['rest_exact_write_verified']??true)!==false)
 $errors[]='wpcli_generated_rest_write_authority';
$manifest=MAD4B_SCP_Unified_Capability_Gateway::public_manifest();
if(($manifest['central_transport_routing']['contract']??'')!==$c::CONTRACT)
 $errors[]='gateway_manifest_missing';
if(!in_array($c::REST,$manifest['central_transport_routing']['write_channels']??[],true))
 $errors[]='rest_write_missing_from_central_manifest';
$nego=MAD4B_SCP_Unified_Capability_Gateway::negotiate([], 'internal');
if(($nego['central_transport_routing']['contract']??'')!==$c::CONTRACT)
 $errors[]='gateway_negotiation_missing';
$live=$c::current_request();
if(($live['http_mcp_authenticated_current_request']??true)!==false ||
   ($live['vps_current_request_write_verified']??true)!==false ||
   ($live['historical_vps_read_is_authority']??true)!==false)
 $errors[]='wpcli_misclassified_as_mcp_or_vps';
$feature=class_exists('MAD4B_Translation_Transport_Router')?
   MAD4B_Translation_Transport_Router::choose(false,[
       'read_verified_in_current_request'=>true,'write_certified_in_current_request'=>false]):[];
if(!array_key_exists('selected_transport',$feature) || $feature['selected_transport']!==null ||
   ($feature['central_policy']['contract']??'')!==$c::CONTRACT)
   $errors[]='translation_not_using_central_router';
echo wp_json_encode([
  'contract'=>'mad4b.central-capability-transport-acceptance.v1',
  'pass'=>count($errors)===0,
  'cases'=>count($cases)+6,'failures'=>$errors,
  'results'=>$out,'central_gateway_manifest'=>true,
  'central_gateway_negotiation'=>true,
  'translation_provider_reuse'=>true,
  'no_network'=>true,'no_mutation'=>true
],JSON_UNESCAPED_SLASHES).PHP_EOL;
if($errors)throw new RuntimeException('central_transport_regression');
