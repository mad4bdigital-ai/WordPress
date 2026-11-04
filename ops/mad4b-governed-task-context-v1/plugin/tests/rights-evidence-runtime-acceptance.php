<?php
/** Feature009 P1-H9: readonly rights-evidence and company catalog acceptance. */
defined('WP_CLI') && WP_CLI || exit(2);
$site=MAD4B_SCP_Site_Profile::status();
if(wp_get_environment_type()!=='staging'
    ||($site['site_uuid']??null)!=='e5e64bca-ed62-48d2-b2b3-dff693cd702a'
    ||untrailingslashit(home_url('/'))!=='https://tours.dev.mad4b.com')
    WP_CLI::error('EXACT_CENTRAL_STAGING_REQUIRED');
if(!class_exists('MAD4B_Hub_Company_Rights_Evidence_V1',false)
 ||!class_exists('MAD4B_Hub_Company_Owned_Catalog_V1',false))
 WP_CLI::error('ORIGINAL_RIGHTS_RUNTIME_NOT_BOOTED');
$checks=[];
$evidence=[];
$checks['staging_site_verified']=true;
global $wpdb;
foreach(['tours-central','wayfatours'] as $company) {
    $binding=MAD4B_Company_Portfolio_Manager_V1::company_binding($company);
    if(is_wp_error($binding))WP_CLI::error('COMPANY_BINDING_FAILED_'.$company);
    $uuid=$binding['company_site_uuid'];
    $catalog=MAD4B_Hub_Company_Owned_Catalog_V1::catalog($company,1);
    if(is_wp_error($catalog))WP_CLI::error('COMPANY_CATALOG_FAILED_'.$company);
    $r=$catalog['company_rights_evidence']??[];
    $checks[$company.'_rights_contract']=($r['contract']??null)==='mad4b.hub.company-rights-evidence.v1';
    $checks[$company.'_scope_is_exact']=($r['company_site_uuid']??null)===$uuid
       &&($r['company_code']??null)===$company;
    $canonical=$wpdb->prefix.'mad4b_hub_canonical_entities';
    $pub=$wpdb->prefix.'mad4b_hub_publication_targets';
    $records=$wpdb->prefix.'mad4b_hub_content_records';
    $approval=(int)$wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM $canonical WHERE company_id=%s AND state='approved'",$company));
    $releases=(int)$wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM $records WHERE brand_id=%s AND site_uuid=%s AND status='approved'",
      $company,$site['site_uuid']));
    $targets=(int)$wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM $pub WHERE company_id=%s AND destination_site_uuid=%s",$company,$uuid));
    $verified=(int)$wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM $pub WHERE company_id=%s AND destination_site_uuid=%s
       AND source_verified IN ('local_wordpress','verified_remote_connector')",$company,$uuid));
    $checks[$company.'_canonical_count_from_db']=
       (int)($r['canonical_company_content_states']['approved']??-1)===$approval;
    $checks[$company.'_approved_releases_from_db']=
       (int)($r['source_approved_release_records']??-1)===$releases;
    $checks[$company.'_publication_targets_from_db']=
       (int)($r['publication_target_records']??-1)===$targets;
    $checks[$company.'_verified_destinations_from_db']=
       (int)($r['verified_destination_links']??-1)===$verified;
    $checks[$company.'_rights_dont_self_certify']=($r['verified_owned_records']??-1)===0
      &&($r['certified_source_content_grants']??-1)===0
      &&($r['full_distribution_authorized']??true)===false
      &&($catalog['full_company_distribution_authorized']??true)===false;
    $checks[$company.'_catalog_compatible']=($catalog['company_owned_verified_count']??-1)===0
      &&($catalog['company_verified_distribution_count']??-1)===0
      &&($catalog['remote_or_local_writes']??-1)===0;
    $checks[$company.'_advisories_exposed']=in_array('SOURCE_POST_RIGHTS_BINDING_NOT_CERTIFIED',
      (array)($r['advisories']??[]),true)
      &&($r['blockers']??null)===[]
      &&($r['operational_blockers']??null)===[];
    $checks[$company.'_rights_optional_for_operations']=
       ($r['rights_evidence_mode']??null)==='optional_advisory'
       &&($r['rights_evidence_required_for_provisioning']??true)===false
       &&($r['rights_evidence_required_for_schema_transfer']??true)===false
       &&($r['rights_evidence_required_for_reconcile']??true)===false
       &&($catalog['rights_review_required']??true)===false
       &&($catalog['rights_documentation_optional']??false)===true
       &&($catalog['rights_evidence_blocks_provisioning']??true)===false
       &&($catalog['rights_evidence_blocks_schema_transfer']??true)===false
       &&($catalog['rights_evidence_blocks_vendor_adapter']??true)===false
       &&($catalog['rights_evidence_blocks_reconcile']??true)===false
       &&($catalog['rights_review_recommended']??false)===true;
    $qa=$catalog['native_schema_qa_preflight']??[];
    $checks[$company.'_qa_schema_preflight_independent_of_rights']=
      ($qa['mode']??null)==='metadata_only'
      &&($qa['rights_documents_required']??true)===false
      &&($qa['site_binding_verified']??false)===true
      &&($qa['original_provider_validation_required']??false)===true
      &&($qa['source_to_target_id_readback_required']??false)===true
      &&($qa['source_content_transfer_authorized']??true)===false
      &&($qa['production_write_authorized']??true)===false;
    $checks[$company.'_rights_no_publication']=($r['publication_performed']??true)===false
      &&($r['records_created']??-1)===0
      &&($catalog['publication_performed']??true)===false;
    $checks[$company.'_catalog_snapshot_stable']=
       MAD4B_Hub_Company_Owned_Catalog_V1::catalog($company,1)['snapshot_sha256']
       ===$catalog['snapshot_sha256'];
    $evidence[$company]=[
      'uuid'=>$uuid,'canonical_approved'=>$approval,'approved_releases'=>$releases,
      'targets'=>$targets,'destination_verified'=>$verified,
      'licensed_redistribution_count'=>$r['registered_redistribution_licenses']??null,
      'distribution_authorized'=>false];
}
$invalid=MAD4B_Hub_Company_Rights_Evidence_V1::inspect('bad!', 
  '00000000-0000-4000-8000-000000000000',$site['site_uuid']);
$checks['invalid_company_rejected']=($invalid['state']??null)==='INVALID_SCOPE'
  &&($invalid['full_distribution_authorized']??true)===false;
$checks['no_cross_company_content_leakage']=
   MAD4B_Hub_Company_Owned_Catalog_V1::catalog('wayfatours',1)['items'] === [];
$checks['permission_default_denied']=is_wp_error(
  MAD4B_Hub_Company_Owned_Catalog_V1::permission());
$pass=!in_array(false,$checks,true);
echo wp_json_encode(['contract'=>'mad4b.feature009.p1h9.runtime-acceptance.v1',
 'pass'=>$pass,'checks'=>$checks,'company_evidence'=>$evidence,
 'source_site_uuid'=>$site['site_uuid'],'rights_granted'=>false,
 'production_changed'=>false],JSON_UNESCAPED_SLASHES),PHP_EOL;
if(!$pass)exit(8);
