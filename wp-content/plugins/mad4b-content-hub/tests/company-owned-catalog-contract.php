<?php
/** Feature009 owner versus reference catalog; no entitlement granted. */
if(!class_exists('MAD4B_Hub_Company_Owned_Catalog_V1',false))
 require_once '/tmp/feature009-company-owned-catalog.php';
$cls='MAD4B_Hub_Company_Owned_Catalog_V1';
$checks=['loaded'=>class_exists($cls,false)];
$before=get_option('mad4b_company_network_memberships_v1',null);
$demo=$cls::catalog('tours-central',1);
$wayfa=$cls::catalog('wayfatours',1);
$checks['demo_catalog_read']=!is_wp_error($demo);
$checks['wayfa_catalog_read']=!is_wp_error($wayfa);
if(!$checks['demo_catalog_read'] || !$checks['wayfa_catalog_read']){
 echo wp_json_encode(['pass'=>false,'codes'=>[
 is_wp_error($demo)?$demo->get_error_code():null,
 is_wp_error($wayfa)?$wayfa->get_error_code():null]]),PHP_EOL;exit(8);
}
$checks['source_host_bound']=$demo['source_equals_company_site']===true
 &&$wayfa['source_equals_company_site']===false;
$checks['no_cross_company_export']=$wayfa['items']===[] &&
 $wayfa['company_owned_verified_count']===0 &&$wayfa['public_local_source_count']===0;
$checks['demo_candidates_visible']=$demo['unverified_candidate_total']>=3
 &&count($demo['items'])===25;
$checks['no_implicit_ownership']=count(array_filter($demo['items'],static fn($item)=>
 $item['ownership_state']!=='NOT_CERTIFIED'
 ||$item['rights_to_distribute']!=='NOT_VERIFIED'
 ||$item['company_portable']!==false))===0;
$checks['not_authorized']=$demo['full_company_distribution_authorized']===false
 &&$wayfa['full_company_distribution_authorized']===false
 &&$demo['remote_or_local_writes']===0;
$checks['version_hints_not_grants']=count(array_filter($demo['items'],static fn($item)=>
 preg_match('/^[a-f0-9]{64}$/D',$item['source_version_hint_sha256'])===1
 &&$item['release_state']==='CANDIDATE_ONLY'))===25;
$checks['reproducible']=$cls::catalog('tours-central',1)['snapshot_sha256']
 ===$demo['snapshot_sha256'];
$checks['pagination']=$cls::catalog('tours-central',2)['page']===2;
$checks['invalid_company_denied']=is_wp_error($cls::catalog('wrong!',1));
$checks['page_limit_denied']=is_wp_error($cls::catalog('tours-central',201));
$checks['permission_default_denied']=is_wp_error($cls::permission());
$checks['membership_unchanged']=$before===get_option('mad4b_company_network_memberships_v1',null);
echo wp_json_encode(['pass'=>!in_array(false,$checks,true),'checks'=>$checks,
 'demo'=>['total'=>$demo['unverified_candidate_total'],
   'source_types'=>$demo['source_type_counts'],'rows'=>count($demo['items']),
   'reference_candidates'=>$demo['central_reference_research_candidate_count'],
   'owned_verified'=>$demo['company_owned_verified_count']],
 'wayfatours'=>['rows'=>count($wayfa['items']),
   'remote_required'=>$wayfa['external_company_source_requires_signed_native_connector']]],JSON_UNESCAPED_SLASHES),PHP_EOL;
if(in_array(false,$checks,true))exit(8);
