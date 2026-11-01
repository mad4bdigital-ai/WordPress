<?php
/**
 * Discover SEO candidate mapping without creating rules or changing WP options.
 * Command: wp eval-file tests/jsf-dynamic-discovery-report-wp-cli.php
 */
if (!defined('WP_CLI') || !WP_CLI) throw new RuntimeException('WP-CLI only');
if (!class_exists('MAD4B\\DFSB\\NativePresentation\\DynamicQualification'))
    require_once dirname(__DIR__).'/includes/class-dynamic-qualification.php';
use MAD4B\DFSB\NativePresentation\DynamicQualification as D;
$before=jet_smart_filters()->settings->get('seo_sitemap_rules',array());
$enabled=jet_smart_filters()->settings->is_seo_enabled;
$report=D::scan(true);
$after=jet_smart_filters()->settings->get('seo_sitemap_rules',array());
if ($before!==$after || $enabled!==jet_smart_filters()->settings->is_seo_enabled)
    throw new RuntimeException('SEO settings changed during read-only discovery');
$path='/tmp/mad4b-jsf-dynamic-qualification.json';
if (file_put_contents($path,wp_json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))===false)
    throw new RuntimeException('Cannot write isolated diagnostic file');
echo 'DISCOVERED='.$report['groups_discovered'].PHP_EOL;
echo 'TEMPLATES='.$report['templates_scanned'].PHP_EOL;
echo 'STATES='.wp_json_encode($report['states']).PHP_EOL;
echo 'JSF_SEO_UNCHANGED='.(int)($before===$after).PHP_EOL;
foreach($report['groups'] as $g) {
    if (!in_array($g['status'],array('candidate_for_manual_rule_review','route_blocked','needs_validation'),true))
        continue;
    echo 'GROUP '.wp_json_encode(array(
      'provider'=>$g['provider'],'query_id'=>$g['query_id'],'status'=>$g['status'],
      'http'=>$g['route_http'],'matched'=>$g['public_widget_match'],
      'suggested_filters'=>$g['suggested_max_three_filter_ids'],
      'reason'=>$g['reasons'],'warnings'=>$g['warnings']
    ),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
}
