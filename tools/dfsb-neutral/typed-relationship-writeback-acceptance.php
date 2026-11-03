<?php
if(!class_exists('MAD4B_SCP_Content_Relationship_Writeback'))
 throw new RuntimeException('typed_writeback_runtime_unavailable');
$c='MAD4B_SCP_Content_Relationship_Writeback';
$checks=[];
$assert=static function(string $label,bool $ok)use(&$checks){$checks[$label]=$ok;};
$base='ee20798f-4e3c-41de-aafc-96a73c05bae7';
$unbound='2158e5c3-7534-4435-a311-93b5da3542d4';
$term='fe3d871e-ee50-435c-93fb-63bcdb1bf3b7';
$assert('read_ability_registered',wp_has_ability('mad4b/content-job-typed-relation-write-plan'));
$assert('write_ability_registered',wp_has_ability('mad4b/content-job-typed-relation-write-apply'));
foreach(['featured_image','elementor_template_refs','typed_meta'] as $type){
 $args=['job_id'=>$base,'relationship_kind'=>$type];
 if($type==='typed_meta')$args['meta_key']='related_template';
 $p=$c::plan($args);
 $assert($type.'_native_artifact_guard',
   ($p['state']??'')==='HOLD' &&
   ($p['reason']??'')==='native_contentjob_artifact_not_bound');
}
$foreign=$c::plan(['job_id'=>$unbound,'relationship_kind'=>'elementor_template_refs']);
$assert('unbound_original_elementor_held',($foreign['state']??'')==='HOLD');
$forged=$c::apply([
 'job_id'=>$base,'relationship_kind'=>'featured_image',
 'expected_source_sha256'=>str_repeat('a',64)]);
$assert('forged_plan_sha_cannot_execute',
 ($forged['state']??'')==='HOLD' &&($forged['reason']??'')==='owner_qa_or_artifact_binding_not_ready');
$unknown=$c::plan(['job_id'=>$base,'relationship_kind'=>'arbitrary']);
$assert('unknown_owner_type_denied',($unknown['state']??'')==='HOLD' &&
 ($unknown['reason']??'')==='original_owner_adapter_missing');
$missing=$c::plan([
 'job_id'=>$base,'relationship_kind'=>'typed_meta','meta_key'=>'']);
$assert('meta_requires_exact_owner_field',($missing['state']??'')==='HOLD');
$j=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$term]);
$graph=MAD4B_SCP_Content_Jobs::relationship_plan(['job_id'=>$term]);
$assert('original_wpml_term_bound',($j['job']['current_artifact_id']??'')==='2951' &&
 ($graph['state']??'')==='READY' &&($j['job']['job_revision']??0)===5);
$assert('editorial_and_source_visibility_preserved',
 ($j['job']['state']??'')==='WAITING_REVIEW' &&
 (string)get_term_meta(2951,'is_public',true)==='0' &&
 (string)get_term_meta(2352,'is_public',true)==='1');
$before=$j['job']['job_revision'];
do_action('mad4b_scp_content_job_transitioned',[
 'job_id'=>$term,'job_revision'=>$before,
 'state'=>'WAITING_REVIEW','stage'=>'EDITORIAL_QA']);
$j2=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$term]);
$assert('synchronous_owner_hook_does_not_mutate_unqualified_term',
 ($j2['job']['job_revision']??0)===$before);
$errors=array_keys(array_filter($checks,static fn($v)=>!$v));
echo wp_json_encode(['contract'=>'mad4b.typed-relationship-writeback-acceptance.v1',
 'pass'=>!$errors,'checks'=>count($checks),'failures'=>$errors,
 'staging_only'=>true,'no_content_mutation_in_tests'=>true],
 JSON_UNESCAPED_SLASHES).PHP_EOL;
if($errors)throw new RuntimeException('typed_writeback_regression');
