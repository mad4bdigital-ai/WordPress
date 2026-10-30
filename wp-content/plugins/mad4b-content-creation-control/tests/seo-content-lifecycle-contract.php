<?php
/**
 * Source-only regression contract. Runtime behavior is separately verified on
 * disposable WordPress Staging fixtures; this test cannot certify live apply.
 */
$dir=dirname(__DIR__);
$engine=file_get_contents($dir.'/class-mad4b-seo-content-lifecycle.php');
$ui=file_get_contents($dir.'/class-mad4b-seo-cycle-admin.php');
$plugin=file_get_contents($dir.'/mad4b-content-creation-control.php');
$checks=[
  'plugin version'=>str_contains($plugin,'0.2.5-seo-cycle-staging'),
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
foreach($checks as $name=>$ok)if(!$ok){fwrite(STDERR,"FAIL SEO Lifecycle: $name\n");exit(1);}
echo 'PASS MAD4B SEO Content Lifecycle source-only contract: '.count($checks).' checks'.PHP_EOL;
