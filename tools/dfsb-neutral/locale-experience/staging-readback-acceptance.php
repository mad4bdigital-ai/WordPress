<?php
if(!defined('ABSPATH')||wp_get_environment_type()!=='staging'||
 rtrim(home_url(),'/')!=='https://tours.dev.mad4b.com'||
 get_current_user_id()!==26||!current_user_can('manage_options')) {
 throw new RuntimeException('staging_exact_test_authority_required');
}
$tests=array();
$check=static function($name,$valid)use(&$tests){$tests[$name]=(bool)$valid;};
$snapshot=MAD4B_SCP_Locale_Experience_Alerts::current();
$check('fresh_signed_site_bound_browser_snapshot',is_array($snapshot)&&
 count($snapshot['screens']??[])===24 &&
 ($snapshot['site_uuid']??'')===MAD4B_SCP_Site_Profile::site_uuid());
$scan=MAD4B_SCP_Locale_Experience_Alerts::discover();
$check('read_only_discovery_complete',!empty($scan['complete'])&&!empty($scan['fresh']));
$check('discovery_fingerprints_unique',count((array)$scan['issues'])===
 count(array_unique(array_column((array)$scan['issues'],'id'))));
$check('observed_five_localized_404_assets',count(array_filter((array)$scan['issues'],
 static fn($x)=>str_starts_with($x['code'],'lexp_asset_http_error_')))===5);
$check('parity_candidate_not_asserted_missing_translation',
 count(array_filter((array)$scan['issues'],static fn($x)=>str_starts_with($x['code'],'lexp_visible_term_parity_')
 && $x['severity']==='medium' && $x['priority']===78
 && ($x['source_evidence']['remediation_auto_authorized']??true)===false))===5);
$check('cross_locale_links_observed_in_default_and_five_locales',
 count(array_filter((array)$scan['issues'],static fn($x)=>str_starts_with($x['code'],'lexp_cross_locale_link_')))===6);
$check('archive_filter_drift_from_original_source',
 count(array_filter((array)$scan['issues'],static fn($x)=>str_starts_with($x['code'],'lexp_filter_visibility_drift_')))===4);
$check('source_scan_still_complete',MAD4B_SCP_Content_Readiness_Alerts::discover()['complete']===true);
$ledger=(array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,[]);
$localeLedger=array_filter($ledger,static fn($r)=>($r['source']??'')==='mad4b-locale-experience'
 && ($r['status']??'')==='open');
$check('all_23_locale_alerts_in_original_ledger',count($localeLedger)===23);
$requests=0;$valid=true;
foreach($localeLedger as $r){
 $req=(array)($r['content_job_request']??[]);
 if(($req['status']??'')==='PRIORITY_INTAKE')$requests++;
 if(($req['auto_translation_authorized']??true)!==false ||
   ($req['auto_publication_authorized']??true)!==false ||
   ($req['requires_original_wpml_and_query_evidence']??false)!==true||
   ($req['requires_governed_content_job_admission']??false)!==true||
   ($req['priority']??-1)!==($r['priority']??null))$valid=false;
}
$check('23_requests_original_contentjobs_queue', $requests===23);
$check('never_auto_publish_without_original_owner', $valid);
$ledgerBefore=hash('sha256',wp_json_encode($ledger,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
$before=(array)get_option(MAD4B_SCP_Locale_Experience_Alerts::OPTION,[]);
$validSnapshot=json_decode(file_get_contents('/tmp/mad4b-locale-browser-merged-20261103.json'),true);
$bad=$validSnapshot;$bad['site_uuid']='bad-other-company-site-uuid';
$r=MAD4B_SCP_Locale_Experience_Alerts::import_observations($bad);
$check('reject_other_company_site',is_wp_error($r));
$bad=$validSnapshot;$bad['captured_at']='2020-01-01T00:00:00+00:00';
$r=MAD4B_SCP_Locale_Experience_Alerts::import_observations($bad);
$check('reject_stale_browser_claim',is_wp_error($r));
$bad=$validSnapshot;$bad['screens']['home|fr|desktop']['cross_locale_urls']=['https://outside.invalid/leak'];
$r=MAD4B_SCP_Locale_Experience_Alerts::import_observations($bad);
$check('reject_external_browser_urls',is_wp_error($r));
$bad=$validSnapshot;unset($bad['screens']['home|ar|mobile']);
$r=MAD4B_SCP_Locale_Experience_Alerts::import_observations($bad);
$check('reject_missing_locale_device_observation',is_wp_error($r));
$check('no_data_mutation_from_invalid_import',
 get_option(MAD4B_SCP_Locale_Experience_Alerts::OPTION,[])===$before &&
 hash('sha256',wp_json_encode(get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,[]),
 JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))===$ledgerBefore);
$req=new WP_REST_Request('GET','/mad4b-content-readiness/v1/issues');
$req->set_param('status','open');$req->set_param('code','lexp_asset_http_error_fr');
$res=rest_do_request($req);$data=$res->get_data();$item=$data['items'][0]??[];
$check('original_authenticated_rest_readback', $res->get_status()===200 &&
 ($data['total']??0)===1 && ($item['priority']??0)===94 &&
 ($item['source']??'')==='mad4b-locale-experience' &&
 ($item['content_job_request']['status']??'')==='PRIORITY_INTAKE' &&
 count($item['locale_evidence']['observations']??[])===2);
$failed=array_keys(array_filter($tests,static fn($x)=>!$x));
echo wp_json_encode(['contract'=>'mad4b.locale-experience-acceptance.v1',
 'success'=>!$failed,'passed'=>count($tests)-count($failed),'total'=>count($tests),
 'failed'=>$failed,'active_alerts'=>count($localeLedger),'never_published'=>true],
 JSON_UNESCAPED_SLASHES),PHP_EOL;
if($failed)throw new RuntimeException('locale_experience_test_failure');
