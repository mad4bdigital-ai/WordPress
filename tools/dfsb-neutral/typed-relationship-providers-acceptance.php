<?php
if(!class_exists('MAD4B_SCP_Content_Relationship_Providers')||
   !class_exists('MAD4B_SCP_Content_Relationship_Graph'))
 throw new RuntimeException('typed_relationship_runtime_not_loaded');
$cases=[];
$check=static function($name,$ok)use(&$cases){$cases[$name]=(bool)$ok;};
$jobId='ee20798f-4e3c-41de-aafc-96a73c05bae7';
$job=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$jobId]);
$plan=MAD4B_SCP_Content_Jobs::relationship_plan(['job_id'=>$jobId]);
$typed=$plan['extensions']['native_typed_relations']??[];
$check('native_job_binding',($plan['candidate_local_id']??0)===56139);
$check('typed_extension_loaded',($typed['state']??'')==='INSPECTED');
$check('existing_elementor_owner',isset($typed['relationships']['elementor_template_refs'])&&
 ($typed['relationships']['elementor_template_refs']['owner']??'')===
 'MAD4B_WPML_Structural_Sync_V2+MAD4B_SCP_Elementor_Adapter');
$check('existing_meta_contract_owner',
 ($typed['relationships']['typed_meta']['owner']??'')===
 'MAD4B_SCP_Semantic_Content_Field_Contracts');
$check('no_generic_unsafe_meta_guessing',
 ($typed['relationships']['typed_meta']['unregistered_field_guessing']??true)===false);
$family=MAD4B_SCP_Content_Relationship_Graph::resolve(
 'post',57562,'tours-and-activities','es');
$pair=MAD4B_SCP_Content_Relationship_Providers::inspect(
 $job['job'],$family,['kind'=>'post','id'=>57562]);
$media=$pair['relationships']['featured_image']??[];
$check('tour_pair_resolved',($family['target_id']??0)===57563);
$check('preserve_existing_spanish_image',($media['status']??'')==='LOCAL_MEDIA_PRESERVED'
 &&($media['source_attachment_id']??0)===57505
 &&($media['actual_target_attachment_id']??0)===57571);
$check('no_featured_image_overwrite',
 ($pair['automatic_overwrite_existing_authoring']??true)===false);
$check('taxonomy_provider_comparison_present',
 isset($pair['relationships']['taxonomy_destinations']));
$check('no_wholesale_term_replace',
 ($pair['relationships']['taxonomy_destinations']['automatic_replace_entire_term_set']??true)===false);
$check('all_inspections_read_only',
 ($pair['mutation_performed']??true)===false &&
 ($typed['mutation_performed']??true)===false);
$bad=MAD4B_SCP_Content_Relationship_Providers::inspect(
 $job['job'],['target_id'=>56139,'target_locale'=>'es'],['kind'=>'post','id'=>57562]);
$check('mismatched_post_type_denied',($bad['state']??'')==='HOLD');
$failed=array_keys(array_filter($cases,static fn($value)=>!$value));
echo wp_json_encode(['contract'=>'mad4b.typed-relationship-acceptance.v1',
 'pass'=>!$failed,'checks'=>count($cases),'failed'=>$failed,
 'read_only'=>true],JSON_UNESCAPED_SLASHES).PHP_EOL;
if($failed)throw new RuntimeException('typed_relationship_acceptance_failed');
