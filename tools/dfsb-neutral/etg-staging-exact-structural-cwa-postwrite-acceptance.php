<?php
/** Verified original Staging CWA + browser readback, entirely read-only. */
defined('ABSPATH')||exit;
if(wp_get_environment_type()!=='staging' ||
 rtrim(home_url(),'/')!=='https://tours.dev.mad4b.com')
 throw new RuntimeException('exact_staging_site_required');
$jobs=[
 'ar'=>['45076890-9fc9-410d-9dba-7a4231729647',40547],
 'de'=>['57679eb4-1843-46b4-99a7-294e30e4a67e',34597],
 'es'=>['720cb9e8-ec58-4e0c-8c8c-2540927a1580',34605],
 'fr'=>['4a302e58-4e06-42b3-9563-4b052d21a511',34599],
 'it'=>['1c946b72-1cbb-4f5e-b671-484db19cf5e7',39502]];
$site=MAD4B_SCP_Site_Profile::site_uuid();
$checks=[
 'site_uuid_original'=>is_string($site)&&strlen($site)===36,
 'general_group_apply_remains_disabled'=>get_option('mad4b_contentjob_group_apply_enabled','no')==='no',
 'structural_cwa_closed'=>get_option('mad4b_contentjob_structural_cwa_enabled','no')==='no',
 'no_exact_job_remains'=>get_option('mad4b_contentjob_structural_cwa_exact_job','')===''];
$group=MAD4B_SCP_Content_Job_Group_Orchestrator::plan(['source_post_id'=>25909]);
$checks['five_original_children_synced']=($group['state']??'')==='ALREADY_SYNCED' &&
 ($group['counts']['already_synced']??0)===5 &&
 ($group['counts']['hold']??-1)===0;
$checks['five_original_children_present']=count($group['children']??[])===5;
foreach($jobs as $lang=>[$job,$target]){
 $marker=$lang==='ar'?'mad4b_cj_structural_one_shot_canary_40547_20261103':
   'mad4b_cj_structural_one_shot_'.$lang.'_20261103';
 $m=get_option($marker,[]);
 $cwa=$lang==='ar'?($m['result']['cwa_result']??[]):($m['result']??[]);
 $read=get_option('mad4b_cj_structural_final_browser_'.hash('sha256',$site.'|'.$job),[]);
 $sha=$cwa['receipt_sha256']??null;
 $checks[$lang.'_original_cwa_verified']=($cwa['reason']??'')==='VERIFIED_WRITE_AND_READBACK' &&
    MAD4B_SCP_Central_Write_Acceptance::sha($sha);
 $checks[$lang.'_independent_browser_verified']=($read['state']??'')==='POSTWRITE_BROWSER_VERIFIED' &&
    ($read['contract']??'')==='mad4b.contentjob.structural-final-browser.v1' &&
    ($read['locale']??'')===$lang && ($read['target_post_id']??0)===$target &&
    ($read['job_id']??'')===$job &&
    ($read['original_cwa_receipt_sha256']??'')===$sha &&
    ($read['editorial_translation_approved']??true)===false &&
    MAD4B_SCP_Central_Write_Acceptance::sha($read['evidence_sha256']??null);
 $post=(string)get_post_meta($target,'_elementor_data',true);
 $normalized=hash('sha256',wp_json_encode(json_decode($post,true),
   JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
 $checks[$lang.'_native_document_reverified']=($read['normalized_document_sha256']??'')===$normalized &&
   substr_count($post,'state_governorate')===1 &&
   get_post_status($target)==='publish';
}
$failed=array_keys(array_filter($checks,static fn($p)=>$p!==true));
echo wp_json_encode(['contract'=>'mad4b.verified-exact-structural-cwa-five.v1',
 'passed'=>count($checks)-count($failed),'total'=>count($checks),
 'all_passed'=>!$failed,'failed'=>$failed,'state'=>$group['state']??'UNKNOWN',
 'no_content_mutation_performed_in_this_acceptance'=>true],
 JSON_UNESCAPED_SLASHES),PHP_EOL;
if($failed)throw new RuntimeException('original_structural_cwa_receipts_not_complete');
