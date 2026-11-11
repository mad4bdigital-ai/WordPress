<?php
/**
 * Usage (read-only):
 * wp eval-file wp-content/plugins/mad4b-platform/tests/wpml-family-coverage-regression.php \
 *    --path=/var/www/html --skip-themes
 * No creation, translation, publication, AJAX, API write or theme rendering.
 */
if (!defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI')) {
    throw new RuntimeException('WP_CLI_ONLY');
}
if (!defined('ICL_SITEPRESS_VERSION')) {
    WP_CLI::error('TEST_REQUIRES_WPML');
}
$assert=static function(bool $ok,string $message):void {
    if (!$ok)WP_CLI::error('MAD4B_WPML_REGRESSION_FAIL: '.$message);
};
$owner='MAD4B_WPML_Design_Reconciliation_V1';
$assert(class_exists($owner),'Original WPML reconciliation owner missing');
$policy=$owner::required_languages_readback();
$assert($policy['consistent'],'Configured and runtime WPML languages disagree');
$assert($policy['default']!=='' && in_array($policy['default'],$policy['required'],true),
    'Default language missing');
$assert(count($policy['required'])===count(array_unique($policy['required'])),
    'Required language has duplicates');
$source=46193;
$assert(get_post_type($source)==='jet-engine','Fixture source no longer exists');
$family=$owner::family_coverage_readback($source);
$assert($family['required_languages']===$policy['required'],
    'Family omitted required company languages');
$assert(count($family['by_language'])===count($policy['required']),
    'Family coverage count differs from active languages');
$missing=array_values(array_filter($family['by_language'],
    static fn($v)=>$v['state']==='MISSING_TRANSLATION'));
$assert(count($missing)===count($family['summary']['missing_languages']),
    'Missing translation count not surfaced');
$assert($family['business_pass_allowed']===false,
    'A static family audit must never claim business completion');
if ($family['summary']['unmet_language_count']>0) {
    $assert($family['structural_readback_pass']===false,
        'Missing/draft/broken language incorrectly marked complete');
}
$manifest=$owner::dependency_family_manifest($source,12);
$assert($manifest['family_count']>=1,'Nested manifest empty');
$assert(isset($manifest['families'][$source]),'Nested manifest omitted source');
$assert(!$manifest['overall_reconciliation_pass_allowed'],
    'Static nested manifest claimed overall completion');
$assert(!$manifest['mutation_performed'],'Readback mutated source data');
$assert($manifest['summary']['unmet_locale_instances']>=
    $family['summary']['unmet_language_count'],
    'Nested manifest lost root language gaps');
$readback=MAD4B_Hub_Field_Fusion_V1::semantic_snapshot($source);
$assert(!is_wp_error($readback),'Field Fusion returned an error');
$assert(isset($readback['wpml_family_coverage'],$readback['wpml_dependency_manifest']),
    'Field Fusion snapshot missing original WPML family evidence');
$assert($readback['wpml_dependency_manifest']['summary']['unmet_locale_instances']===
    $manifest['summary']['unmet_locale_instances'],
    'Field Fusion and WPML owner disagree on missing locales');
$assert(!$readback['automatic_apply_eligible'] && !$readback['mutation_performed'],
    'Readback unexpectedly allowed writes');
$extra='pt';
$hadExtra=in_array($extra,$policy['required'],true);
add_filter('wpml_active_languages',static function($rows)use($extra){
    $rows=is_array($rows)?$rows:[];
    $rows[$extra]=['code'=>$extra];return $rows;
},999);
$simulated=$owner::required_languages_readback();
$assert(in_array($extra,$simulated['required'],true),
    'New runtime language was silently dropped');
$assert(count($simulated['required'])===count($policy['required'])+($hadExtra?0:1),
    'Unexpected loss of company languages during new-locale discovery');
remove_all_filters('wpml_active_languages',999);
$bridge_before=(array)get_option('mad4b_wpml_content_job_map_v1',[]);
$section_scan=MAD4B_WPML_ContentJob_Adapter::discover();
$assert(!empty($section_scan['counts']['section_outbox_observed']),
    'Section owner 355 is not visible to ContentJob intake 350');
$structure_cases=array_values(array_filter($section_scan['items'],
    static fn($r)=>in_array('STRUCTURE_ONLY',(array)($r['section_states']??[]),true)));
$assert(count($structure_cases)>0,'Existing technical structure gaps are missing');
foreach($structure_cases as $item){
    if(!empty($item['section_outbox_only']))
        $assert(!$item['eligible'],
            'Section-only technical job must not create duplicate ContentJobs');
}
$assert(get_option('mad4b_wpml_content_job_map_v1',[])===$bridge_before,
    'Discovery-only path unexpectedly wrote ContentJob mappings');
WP_CLI::line(wp_json_encode([
    'contract'=>'mad4b.wpml.family-regression.v1','result'=>'PASS',
    'required_languages'=>$policy['required'],
    'source_id'=>$source,
    'family_missing_languages'=>$family['summary']['missing_languages'],
    'nested_family_count'=>$manifest['family_count'],
    'nested_unmet_locale_instances'=>$manifest['summary']['unmet_locale_instances'],
    'synthetic_additional_locale_supported'=>true,
    'section_outbox_observed'=>$section_scan['counts']['section_outbox_observed'],
    'structural_items_observed'=>count($structure_cases),
    'business_completion_allowed'=>false,'mutation_performed'=>false
],JSON_UNESCAPED_SLASHES));
