<?php
/** Exact ETG Staging acceptance for independent structural-only QA.
 * Zero Elementor/WPML content mutations; all assertions use original site state.
 */
if (!defined('ABSPATH') || wp_get_environment_type()!=='staging' ||
    rtrim(home_url(),'/')!=='https://tours.dev.mad4b.com')
 throw new RuntimeException('structural_qa_staging_scope_missing');
$jobs=[
 'ar'=>['45076890-9fc9-410d-9dba-7a4231729647',40547,11],
 'de'=>['57679eb4-1843-46b4-99a7-294e30e4a67e',34597,11],
 'es'=>['720cb9e8-ec58-4e0c-8c8c-2540927a1580',34605,10],
 'fr'=>['4a302e58-4e06-42b3-9563-4b052d21a511',34599,11],
 'it'=>['1c946b72-1cbb-4f5e-b671-484db19cf5e7',39502,10]
];
$checks=[];
$checks['native_qa_plan_ability_registered']=wp_has_ability('mad4b/content-job-structural-qa-plan');
$checks['native_qa_attest_ability_registered']=wp_has_ability('mad4b/content-job-structural-qa-attest');
$checks['group_write_not_enabled']=get_option('mad4b_contentjob_group_apply_enabled','no')!=='yes';
foreach($jobs as $locale=>[$jobId,$target,$expectedChanges]){
 $report=MAD4B_SCP_Content_Job_Structural_QA::inspect($jobId);
 $attest=MAD4B_SCP_Content_Job_Structural_QA::receipt($jobId);
 $checks['exact_document_verified_'.$locale]=
  ($report['state']??'')==='STRUCTURAL_DOCUMENT_VERIFIED' &&
  ($report['target_post_id']??0)===$target &&
  ($report['changed_path_count']??0)===$expectedChanges &&
  ($report['missing_anchor_restored']??false)===true &&
  ($report['render_or_interactive_verified']??true)===false &&
  ($report['editorial_translation_approved']??true)===false;
 $checks['immutable_current_receipt_'.$locale]=
  ($attest['state']??'')==='STRUCTURAL_DOCUMENT_ATTESTED' &&
  hash_equals((string)($report['evidence_sha256']??''),
   (string)($attest['evidence_sha256']??'')) &&
  ($attest['cwa_write_authorized']??true)===false &&
  ($attest['browser_render_verified']??true)===false;
}
$diff=new ReflectionMethod('MAD4B_SCP_Content_Job_Structural_QA','diff');
$cases=[
 'reject_text_changes'=>[['settings'=>['title'=>'Hello']],['settings'=>['title'=>'Changed']],false],
 'reject_image_changes'=>[['settings'=>['image'=>['id'=>14]]],['settings'=>['image'=>['id'=>17]]],false],
 'reject_url_changes'=>[['settings'=>['url'=>'/a']],['settings'=>['url'=>'/b']],false],
 'reject_widget_identity'=>[['id'=>'widget1'],['id'=>'widget2'],false],
 'permit_responsive_padding'=>[['settings'=>['padding_mobile'=>['top'=>'0']]],['settings'=>['padding_mobile'=>['top'=>'50']]],true],
 'permit_new_safe_anchor'=>[['settings'=>[]],['settings'=>['_element_id'=>'state_governorate']],true],
 'reject_existing_anchor_replacement'=>[['settings'=>['_element_id'=>'custom']],['settings'=>['_element_id'=>'changed']],false]
];
foreach($cases as $name=>$case) {
 $changes=[];
 $result=$diff->invokeArgs(null,[$case[0],$case[1],'/0',&$changes]);
 $checks[$name]=$result===$case[2];
}
$fail=array_keys(array_filter($checks,static fn($ok)=>!$ok));
echo wp_json_encode(['contract'=>'mad4b.structural-qa-staging-acceptance.v1',
 'pass'=>!$fail,'checks'=>count($checks),'failed'=>$fail,
 'content_mutations'=>0,'publication_mutations'=>0,
 'render_certification_not_inferred'=>true],
 JSON_UNESCAPED_SLASHES).PHP_EOL;
if($fail)throw new RuntimeException('structural_qa_staging_acceptance_failed');
