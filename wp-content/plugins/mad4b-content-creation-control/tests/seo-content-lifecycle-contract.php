<?php
/**
 * Source-only regression contract. Runtime behavior is separately verified on
 * disposable WordPress Staging fixtures; this test cannot certify live apply.
 */
$dir=dirname(__DIR__);
$engine=file_get_contents($dir.'/class-mad4b-seo-content-lifecycle.php');
$ui=file_get_contents($dir.'/class-mad4b-seo-cycle-admin.php');
$image=file_get_contents($dir.'/class-mad4b-seo-image-lifecycle.php');
$plugin=file_get_contents($dir.'/mad4b-content-creation-control.php');
$checks=[
  'plugin version'=>str_contains($plugin,'0.2.6-seo-image-brand-staging'),
  'SEO engine loaded'=>str_contains($plugin,"require_once __DIR__ . '/class-mad4b-seo-content-lifecycle.php'"),
  'SEO UI loaded'=>str_contains($plugin,"require_once __DIR__ . '/class-mad4b-seo-cycle-admin.php'"),
  'staging fence'=>str_contains($engine,"wp_get_environment_type()!=='staging'"),
  'exact central origin'=>str_contains($engine,'https://tours.dev.mad4b.com/'),
  'central principal permission'=>str_contains($engine,'MAD4B_Content_Creation_Control_Bridge::can_write()'),
  'admin capability'=>str_contains($engine,"current_user_can('manage_options')"),
  'Plan ability'=>str_contains($engine,'mad4b/seo-cycle-plan'),
  'Generate ability'=>str_contains($engine,'mad4b/seo-cycle-generate'),
  'Apply ability'=>str_contains($engine,'mad4b/seo-cycle-apply'),
  'Verify ability'=>str_contains($engine,'mad4b/seo-cycle-verify'),
  'exact object CAS'=>str_contains($engine,'hash_equals')&&str_contains($engine,'stale_target'),
  'native reversible SEO adapter'=>str_contains($engine,"wp_get_ability('seo/update-meta')"),
  'native SEO CAS'=>str_contains($engine,'expected_sha256')&&str_contains($engine,'current'),
  'no postmeta direct bypass'=>!str_contains($engine,'update_post_meta('),
  'term meta scoped write'=>str_contains($engine,'update_term_meta('),
  'term compensation'=>str_contains($engine,'delete_term_meta('),
  'term exact WPML language'=>str_contains($engine,'wpml_element_language_code'),
  'language mismatch denied'=>str_contains($engine,'job_language_mismatch'),
  'research job not write authority'=>str_contains($engine,'research_job_not_content_mutation_authority'),
  'only draft content changes'=>str_contains($engine,"$"."target['status']!=='draft'"),
  'elementor preserved'=>str_contains($engine,'is_elementor'),
  'blocks preserved'=>str_contains($engine,'is_blocks'),
  'content sanitizer'=>str_contains($engine,'wp_kses_post($append)'),
  'Rank Math cached score'=>str_contains($engine,'rank_math_seo_score'),
  'no fake scoring'=>str_contains($engine,"'rank_math_score_recomputed'=>false"),
  'audit on apply'=>str_contains($engine,'MAD4B_SCP_Audit::record'),
  'no publishing calls'=>!str_contains($engine,'wp_publish_post(')&&!str_contains($engine,'wp_insert_post('),
  'nonce protected apply'=>str_contains($ui,"check_admin_referer('mad4b_seo_cycle_apply')"),
  'no raw fallback in admin'=>str_contains($ui,'No raw post-meta fallback'),
];
$checks['image plan ability']=str_contains($image,'mad4b/seo-image-plan');
$checks['image generate ability']=str_contains($image,'mad4b/seo-image-generate');
$checks['image apply ability']=str_contains($image,'mad4b/seo-image-apply');
$checks['image verify ability']=str_contains($image,'mad4b/seo-image-verify');
$checks['rank math native site name variable']=str_contains($engine,'%sitename%');
$checks['title source wordpress site name']=str_contains($engine,"get_bloginfo('name')");
$checks['SEO preview resolves token']=str_contains($engine,"str_replace('%sitename%'");
$checks['image contextual site token']=str_contains($image,'{{site_name}}');
$checks['context token resolves live site name']=str_contains($image,"get_bloginfo('name')");
$checks['image requires visual review']=str_contains($image,'visual_confirmation_required');
$checks['image exact revision']=str_contains($image,'stale_snapshot');
$checks['image only draft contextual edit']=str_contains($image,'context_requires_draft');
$checks['media global uses native adapter']=str_contains($image,"wp_get_ability('media/update-metadata')");
$checks['image no direct global alt write']=!str_contains($image,'_wp_attachment_image_alt');
$checks['image scoped usage meta']=str_contains($image,'_mad4b_seo_image_usage_v1');
$checks['render integration explicitly limited']=str_contains($image,'wp_get_attachment_image_attributes');
$checks['image metadata original preserved']=str_contains($image,'global_media_metadata_changed');
$checks['image review UI']=str_contains($ui,'Image SEO &amp; Alt Text');
$checks['image visual approval nonce']=str_contains($ui,"check_admin_referer('mad4b_seo_image_apply')");
foreach($checks as $name=>$ok)if(!$ok){fwrite(STDERR,"FAIL SEO Lifecycle: $name\n");exit(1);}
echo 'PASS MAD4B SEO Content Lifecycle source-only contract: '.count($checks).' checks'.PHP_EOL;
