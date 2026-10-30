<?php
/** Read-only, repeatable JSF discovery and qualification smoke test. */
if (!defined('WP_CLI') || !WP_CLI) throw new RuntimeException('WP-CLI required');
if (!class_exists('MAD4B\\DFSB\\NativePresentation\\DynamicQualification'))
    require_once dirname(__DIR__).'/includes/class-dynamic-qualification.php';
use MAD4B\DFSB\NativePresentation\DynamicQualification as D;
$valid=D::qualifyFilter(array('id'=>15035,'title'=>'Tour Types',
    'post_status'=>'publish','filter_type'=>'checkboxes',
    'query_type'=>'tax_query','query_var'=>'tour-types',
    'taxonomy_public'=>true,'options_count'=>4));
$price=D::qualifyFilter(array('id'=>24515,'title'=>'Price',
    'post_status'=>'publish','filter_type'=>'range','query_type'=>'meta_query',
    'query_var'=>'start_price','taxonomy_public'=>false,'options_count'=>8,
    'restricted_pricing_or_availability'=>true));
$draft=D::qualifyFilter(array('id'=>400,'title'=>'Draft',
    'post_status'=>'draft','filter_type'=>'checkboxes','query_type'=>'tax_query',
    'query_var'=>'destinations','taxonomy_public'=>true,'options_count'=>2));
$routes=array('tours-and-activities'=>'https://example.org/tours-and-activities/',
    'properties'=>'https://example.org/properties/',
    'transportations'=>'https://example.org/transportations/');
$a=D::inferRoute('tours_query_archive',$routes);
$b=D::inferRoute('property_query_archive',$routes);
$c=D::inferRoute('trans_query_archive',$routes);
$d=D::inferRoute('tour_rates',$routes);
$checks=array(
    'published_taxonomy_allowed'=>!empty($valid['eligible']),
    'commercial_range_blocked'=>empty($price['eligible']) && in_array('price_or_availability_filter',$price['reasons'],true),
    'draft_filter_blocked'=>empty($draft['eligible']),
    'tour_archive_inferred'=>($a['post_type']??'')==='tours-and-activities',
    'property_archive_inferred'=>($b['post_type']??'')==='properties',
    'transport_archive_inferred'=>($c['post_type']??'')==='transportations',
    'non_archive_group_display_only'=>($d['status']??'')==='not_archive_query',
);
$original=jet_smart_filters()->settings->is_seo_enabled;
$report=D::scan(false);
$checks['report_contract']=($report['contract']??'')===D::CONTRACT;
$checks['discovery_has_real_groups']=($report['groups_discovered']??0)>5;
$checks['no_rule_writes']=empty($report['seo_rules_write']) && empty($report['indexing_enabled_by_scanner']);
$checks['seo_setting_unchanged']=$original===jet_smart_filters()->settings->is_seo_enabled;
foreach($report['groups'] as $g) {
    if ($g['provider']==='jet-engine' && $g['query_id']==='tours_query_archive') {
        $checks['pilot_discovered']=count($g['templates'])>0;
        $checks['pilot_route_match']=($g['route']['post_type']??'')==='tours-and-activities';
        $checks['pilot_not_automatically_approved']=empty($g['indexing_approved']);
    }
}
foreach($checks as $name=>$ok) echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;
echo 'TESTS_PASSED='.count(array_filter($checks)).'/'.count($checks).PHP_EOL;
echo 'GROUPS_DISCOVERED='.$report['groups_discovered'].PHP_EOL;
echo 'TEMPLATES_SCANNED='.$report['templates_scanned'].PHP_EOL;
echo 'STATES='.wp_json_encode($report['states']).PHP_EOL;
if(in_array(false,$checks,true))exit(1);
