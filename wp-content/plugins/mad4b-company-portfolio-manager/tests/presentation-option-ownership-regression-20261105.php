<?php
defined('ABSPATH')||exit;
if(wp_get_environment_type()!=='staging'||
    untrailingslashit(home_url())!=='https://tours.dev.mad4b.com')
    throw new RuntimeException('CENTRAL_STAGING_REQUIRED');
$cls=MAD4B_Company_Presentation_Option_Ownership_V1::class;
$expect=function($pass,$name) {
 if(!$pass)throw new RuntimeException('FAILED_'.$name);
 echo 'PASS '.$name.PHP_EOL;
};
$expect($cls::valid_registry(),'registry_shape');
$expect(count($cls::registry())===24,'24_canonical_owners');
$plan=$cls::plan('wayfatours','production');
$expect(!is_wp_error($plan),'registered_wayfatours_scope');
$expect(($plan['target_origin']??'')==='https://wayfatours.com','wayfatours_origin_bound');
$expect(($plan['target_site_uuid']??'')==='a3a7cc36-db7e-4a14-8456-b41857def05c','wayfatours_site_uuid_bound');
$expect($plan['production_mutation']===false && $plan['target_readback_verified']===false &&
        $plan['native_write_authorized']===false,'read_only_by_contract');
$fake=[
 'site_uuid'=>$plan['target_site_uuid'],'origin'=>$plan['target_origin'],
 'environment'=>'production','observed_language'=>'en',
 'canonical'=>[
   'elementor_kit'=>['site_name'=>'Wo Vacation',
     'site_description'=>'Journeys to imagine. Places to discover. | Wo Vacation Demo',
     'site_logo.id'=>67442,'site_favicon.id'=>68932],
   'woocommerce'=>['currency'=>'USD'],
   'jetengine_contact'=>['site_facebook_page_link'=>'',
       'site_instagram_page_link'=>'','site_email'=>'','site_phone'=>''],
 ],
 'aliases'=>[
  'site.brand_name'=>'Wayfa Tours',
  'site.tagline'=>'From Someday to Somewhere',
  'site.brand_logo'=>67442,'site.brand_favicon'=>68932,
  'site.default_currency'=>'USD',
 ],
];
$conflict=$cls::compare_snapshot($plan,$fake);
$expect(($conflict['drift_count']??0)===2,'kit_brand_and_tagline_conflicts_identified');
$expect(in_array('site.name',$conflict['drift_keys'],true) &&
 in_array('site.tagline',$conflict['drift_keys'],true),'exact_brand_drift_keys');
$expect(($conflict['statuses']['site.currency']['status']??'')===
 'RUNTIME_OWNER_PRESENT_OR_TARGET_PROOF_REQUIRED','woo_is_canonical_and_untouched');
$expect(($conflict['statuses']['site.facebook']['status']??'')==='OPTIONAL_UNSET',
 'missing_social_only_advisory');
$expect(($conflict['statuses']['site.primary_cta_label']['status']??'')==='RUNTIME_WIDGET_BINDING_REQUIRED',
 'cta_needs_real_widget_binding');
$expect($conflict['ready_to_apply']===false && $conflict['writes_performed']===false,
 'comparison_never_issues_apply');
$fake['canonical']['elementor_kit']['site_name']='Wayfa Tours';
$fake['canonical']['elementor_kit']['site_description']='From Someday to Somewhere';
$aligned=$cls::compare_snapshot($plan,$fake);
$expect(!in_array('site.name',$aligned['drift_keys'],true)&&
 !in_array('site.tagline',$aligned['drift_keys'],true),'aligned_brand_no_false_conflict');
$fake['aliases']['site.facebook_url']='https://example.org/business';
$missing_canon=$cls::compare_snapshot($plan,$fake);
$expect(in_array('site.facebook',$missing_canon['drift_keys'],true),
 'empty_jetengine_social_with_populated_duplicate_detected');
$fake['canonical']['jetengine_contact']['site_whatsapp_num']='';
$fake['aliases']['site.contact_phone']='+20 000 000';
$phone=$cls::compare_snapshot($plan,$fake);
$expect(in_array('site.contact_phone',$phone['drift_keys'],true),
    'real_jetengine_whatsapp_phone_owner_detected');
unset($fake['aliases']['site.contact_phone']);
$fake['aliases']['jetengine_look_and_feel.mad4b_brand_tagline']='Old demo tagline';
$jet=$cls::compare_snapshot($plan,$fake);
$expect(in_array('site.tagline',$jet['drift_keys'],true) &&
    in_array('jetengine_look_and_feel.mad4b_brand_tagline',
      (array)($jet['drift_aliases']['site.tagline']??[]),true),
    'second_jetengine_brand_source_conflict_detected');
unset($fake['aliases']['jetengine_look_and_feel.mad4b_brand_tagline']);
$fake['aliases']['jetengine_look_and_feel.mad4b_brand_logo_media']=['id'=>67442];
$logo=$cls::compare_snapshot($plan,$fake);
$expect(!in_array('site.logo',$logo['drift_keys'],true),
    'jetengine_media_structures_normalized_to_kit_attachment_id');
$fake['aliases']['jetengine_look_and_feel.mad4b_brand_logo_media']='';
$emptyMirror=$cls::compare_snapshot($plan,$fake);
$expect(($emptyMirror['statuses']['site.logo']['status']??'')==='EDITOR_ALIAS_NOT_PROJECTING_CANONICAL',
    'empty_legacy_editor_field_reported_as_advisory_not_runtime_breakage');
$expect(!in_array('site.logo',$emptyMirror['drift_keys'],true),
    'empty_editor_never_forces_runtime_brand_overwrite');
$fake['consumer_bindings']['site.tagline']=['active'=>true,'reads_canonical_owner'=>false];
$widgetUnbound=$cls::compare_snapshot($plan,$fake);
$expect(in_array('site.tagline',$widgetUnbound['active_widget_binding_breaks'],true),
    'same_saved_values_do_not_hide_unbound_elementor_widget');
$expect(($widgetUnbound['statuses']['site.tagline']['status']??'')===
    'ACTIVE_UI_NOT_READING_CANONICAL_OWNER',
    'frontend_binding_is_a_separate_acceptance_check');
$fake['consumer_bindings']['site.tagline']=['active'=>true,'reads_canonical_owner'=>true];
$widgetBound=$cls::compare_snapshot($plan,$fake);
$expect(!in_array('site.tagline',$widgetBound['active_widget_binding_breaks'],true),
    'matching_dynamic_widget_owner_is_observed');
$expect($widgetBound['target_readback_verified']===false && $widgetBound['ready_to_apply']===false,
    'read_only_widget_evidence_is_not_signed_313_authority');
unset($fake['consumer_bindings']);
$fake['site_uuid']='00000000-0000-0000-0000-000000000000';
$reject=$cls::compare_snapshot($plan,$fake);
$expect(($reject['state']??'')==='SNAPSHOT_IDENTITY_MISMATCH','cross_site_snapshot_rejected');
$expect($reject['ready_to_apply']===false,'cross_site_cannot_apply');
$invalid=$cls::plan('invalid/company','production');
$expect(is_wp_error($invalid),'unsafe_company_code_rejected');
$projection=MAD4B_Company_Start_Reconcile_Completeness_V1::project('wayfatours',[]);
$expect(isset($projection['presentation_option_ownership']['production']),
 'readiness_projects_presentation_ownership');
$expect(in_array('PRESENTATION_CANONICAL_OWNER_TARGET_READBACK_REQUIRED',
 (array)($projection['unverified_checks']??[]),true),
 'readiness_requires_target_consumer_readback');
$expect($projection['ready_to_publish']===false &&
 $projection['mutation_performed']===false,'no_false_ready_or_write');
$pluginPlan=MAD4B_Company_Plugin_Live_Reconcile::ensure_current(
 'wayfatours','a3a7cc36-db7e-4a14-8456-b41857def05c');
$expect(!is_wp_error($pluginPlan),'plugin_reconcile_plan_current');
$expect(($pluginPlan['presentation_ownership_sha256']??null)===$cls::digest(),
 'plugin_reconcile_plan_bound_to_current_ownership_schema');
$expect(($pluginPlan['presentation_owner_target_readback_required']??false)===true,
 'plugin_sync_requires_runtime_consumer_readback');
$expect($pluginPlan['production_mutation_authorized']===false &&
 $pluginPlan['target_inventory_verified']===false,
 'source_refresh_never_authorizes_target_apply');
$pluginPlan2=MAD4B_Company_Plugin_Live_Reconcile::ensure_current(
 'wayfatours','a3a7cc36-db7e-4a14-8456-b41857def05c');
$expect(!is_wp_error($pluginPlan2)&&hash_equals($pluginPlan['plan_hash'],$pluginPlan2['plan_hash']),
 'reconcile_plan_hash_idempotent');
echo 'VERDICT PRESENTATION_OWNERSHIP_SOURCE_REGRESSION_PASS'.PHP_EOL;
