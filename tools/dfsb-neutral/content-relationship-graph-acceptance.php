<?php
if (!defined('ABSPATH') || !class_exists('MAD4B_SCP_Content_Relationship_Graph') ||
    !class_exists('MAD4B_SCP_Content_Jobs'))throw new RuntimeException('native_graph_runtime_missing');
$c='MAD4B_SCP_Content_Relationship_Graph';
$cases=[];
$check=static function($name,$pass,$data=[])use(&$cases){
 $cases[]=['name'=>$name,'pass'=>(bool)$pass]+$data;
};
$post=$c::resolve('post',57562,'tours-and-activities','es');
$check('reuse_certified_translation_bridge',
 ($post['state']??'')==='RESOLVED' &&
 ($post['identity_authority']??'')===MAD4B_SCP_Translation_Bridge_Adapter::CONTRACT &&
 ($post['target_id']??0)===57563 &&($post['canonical_trid']??0)===1218037);
$other=$c::resolve('post',57562,'tours-and-activities','it');
$check('other_existing_language_from_same_trid',
 ($other['state']??'')==='RESOLVED' &&($other['target_id']??0)===57564);
$absent=$c::resolve('post',57562,'tours-and-activities','fr');
$check('missing_language_cannot_guess_another_post',
 ($absent['state']??'')==='HOLD' &&($absent['reason']??'')==='target_language_member_missing');
$wrong=$c::resolve('post',57562,'elementor_library','es');
$check('post_type_identity_mismatch_denied',
 ($wrong['state']??'')==='HOLD');
$term=$c::resolve('term',2352,'destinations','fr');
$check('native_term_taxonomy_identity_reused',
 ($term['state']??'')==='RESOLVED' &&($term['target_id']??0)===2951 &&
 ($term['canonical_trid']??0)===419051);
$termMissing=$c::resolve('term',2352,'destinations','de');
$check('missing_term_translation_held',
 ($termMissing['state']??'')==='HOLD');
$job=MAD4B_SCP_Content_Jobs::relationship_plan([
 'job_id'=>'fe3d871e-ee50-435c-93fb-63bcdb1bf3b7']);
$check('actual_wpml_contentjob_issue_source_binding',
 ($job['state']??'')==='WAITING_ARTIFACT' &&
 ($job['candidate_local_id']??0)===2951 &&
 ($job['source_reference']['evidence_issue_id']??'')===
 'fbe96c19822da33bccd77520554d66e4d4bfa3ad8518ff62a138747445639a63');
$jobEl=MAD4B_SCP_Content_Jobs::relationship_plan([
 'job_id'=>'2158e5c3-7534-4435-a311-93b5da3542d4']);
$check('existing_elementor_contentjob_uses_bridge',
 ($jobEl['state']??'')==='WAITING_ARTIFACT' &&
 ($jobEl['candidate_local_id']??0)===39535 &&
 ($jobEl['family']['identity_authority']??'')===MAD4B_SCP_Translation_Bridge_Adapter::CONTRACT);
$wrongArtifact=MAD4B_SCP_Content_Jobs::relationship_plan([
 'job_id'=>'2158e5c3-7534-4435-a311-93b5da3542d4',
 'artifact_id'=>57563,'artifact_kind'=>'post']);
$check('wrong_family_artifact_blocked',
 ($wrongArtifact['state']??'')==='HOLD' &&
 ($wrongArtifact['reason']??'')==='artifact_not_same_translation_family');
$auth=wp_get_ability('mad4b/content-job-relationship-plan');
$check('native_contentjob_read_ability_registered',
 is_object($auth));
$legacy=class_exists('MAD4B_Central_Relationship_Derivation');
$check('parallel_experimental_write_router_not_loaded',!$legacy);
$j=MAD4B_SCP_Content_Jobs::get_job([
 'job_id'=>'fe3d871e-ee50-435c-93fb-63bcdb1bf3b7']);
$check('original_job_state_and_revision_unchanged',
 ($j['job']['state']??'')==='WAITING_REVIEW' &&
 ($j['job']['job_revision']??0)===4);
$failed=array_values(array_filter($cases,static fn($x)=>!$x['pass']));
echo wp_json_encode(['contract'=>$c::CONTRACT.'.acceptance',
 'pass'=>!$failed,'checks'=>count($cases),'failed'=>$failed,
 'no_content_mutation'=>true,'source'=>'native_wpml_bridge_and_jobs'],
 JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
if($failed)throw new RuntimeException('native_relationship_graph_acceptance_failed');
