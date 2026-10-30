<?php
/**
 * Read-only preflight for proposed JetSmartFilters SEO rule on Staging.
 * Run: wp eval-file tests/jsf-seo-preflight-wp-cli.php
 * No WordPress settings, filters, posts or SEO rules are modified.
 */
if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run via WP-CLI only');
}
$file=dirname(__DIR__).'/docs/jsf-seo-preflight-staging.json';
$plan=json_decode((string)file_get_contents($file),true);
if (!is_array($plan)) throw new RuntimeException('Manifest missing');
$checks=array();
$checks['staging_site_exact']=untrailingslashit(site_url())===untrailingslashit((string)$plan['site_url'])
    && function_exists('wp_get_environment_type') && wp_get_environment_type()==='staging';
$checks['configuration_is_draft_only']=($plan['mode']??'')==='discovery_and_draft_only'
    && empty($plan['seo_rules_write']) && empty($plan['allow_filtered_url_indexing'])
    && empty($plan['pilot_rule']['enabled']);
$jsf=function_exists('jet_smart_filters')?jet_smart_filters():null;
$checks['native_jsf_available']=$jsf!==null;
$checks['seo_settings_still_disabled']=$jsf && empty($jsf->settings->is_seo_enabled)
    && count((array)($jsf->seo->sitemap->rules??array()))===0;
$pilot=$plan['pilot_rule'];
$archive=get_post_type_archive_link((string)$pilot['source_cpt']);
$checks['tour_archive_url_matches']=untrailingslashit((string)$archive)===
    untrailingslashit(site_url('/'.$pilot['page_slug'].'/'));
$checks['pilot_query_bound_to_template']=strpos((string)get_post_meta(40069,'_elementor_data',true),
    (string)$pilot['query_id'])!==false && strpos((string)get_post_meta(40069,'_elementor_data',true),
    (string)$pilot['provider'])!==false;
$checks['jsf_max_three_filters']=count($pilot['allowed_filters'])>0
    && count($pilot['allowed_filters'])<=3
    && count($pilot['allow_filter_ids'])===count($pilot['allowed_filters']);
foreach ($pilot['allowed_filters'] as $expected) {
    $id=(int)$expected['id'];
    $post=get_post($id);
    $instance=$jsf?$jsf->filter_types->get_filter_instance($id):null;
    $args=is_object($instance)?(array)$instance->args:array();
    $taxonomy=get_taxonomy((string)$expected['taxonomy']);
    $checks['filter_'.$id.'_runtime']=
        $post && $post->post_type==='jet-smart-filters' && $post->post_status==='publish'
        && in_array($id,(array)$pilot['allow_filter_ids'],true)
        && (string)($args['query_type']??'')===$expected['resolved_query_type']
        && (string)($args['query_var']??'')===$expected['resolved_query_var']
        && isset($args['options']) && is_array($args['options']) && count($args['options'])>0
        && $taxonomy && !empty($taxonomy->public);
}
$checks['legacy_package_unmodified']=defined('DFSB_VERSION')
    && DFSB_VERSION==='0.4.0-alpha.14-local.1';
$checks['rank_math_active']=is_plugin_active('seo-by-rank-math/rank-math.php');
$checks['jetengine_active']=is_plugin_active('jet-engine/jet-engine.php');
foreach($checks as $name=>$ok) {
    echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;
}
echo 'PREPARATION_CHECKS='.count(array_filter($checks)).'/'.count($checks).PHP_EOL;
echo 'LIVE_JSF_SEO_RULES_ENABLED='.(int)!empty($jsf->settings->is_seo_enabled).PHP_EOL;
echo 'LIVE_JSF_SEO_RULE_COUNT='.count((array)($jsf->seo->sitemap->rules??array())).PHP_EOL;
if(in_array(false,$checks,true)) exit(1);
