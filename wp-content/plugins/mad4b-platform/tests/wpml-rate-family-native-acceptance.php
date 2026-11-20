<?php
/** Read-only six-language JetEngine/WPML/Elementor source family acceptance.
 * Run: wp eval-file wp-content/plugins/mad4b-platform/tests/wpml-rate-family-native-acceptance.php --user=1 --path=/var/www/html --skip-themes
 */
if (!defined('WP_CLI') || !WP_CLI)throw new RuntimeException('CLI_ONLY');
$check=static function(bool $ok,string $label):void {
    if(!$ok)WP_CLI::error('MAD4B_LOCALE_ACCEPTANCE_FAIL_'.$label);
};
$owner='MAD4B_WPML_Design_Reconciliation_V1';
$policy=$owner::required_languages_readback();
$check($policy['consistent'],'WPML_POLICY_NOT_CONSISTENT');
$check(count($policy['required'])===6,'EXPECTED_SIX_LOCALES_FOR_THIS_SITE');
$roots=[46193,46402,66565,69004];
$allIds=[];$reported=[];
foreach($roots as $id){
    $family=$owner::family_coverage_readback($id);
    $check($family['structural_readback_pass'],'INCOMPLETE_FAMILY_'.$id);
    $check($family['summary']['unmet_language_count']===0,
           'UNMET_LOCALE_'.$id);
    foreach($policy['required'] as $lang){
        $row=$family['by_language'][$lang]??null;
        $check(is_array($row)&&($row['state']??'')==='PUBLISHED',
               'UNPUBLISHED_'.$id.'_'.$lang);
        $check(empty($row['missing_native_configuration'])&&
               empty($row['unresolved_reference_count']),
               'META_OR_REFERENCES_'.$id.'_'.$lang);
        $allIds[(int)$row['post_id']]=true;
        if($id===69004){
           $term=$family['term_translation_coverage']['by_language'][$lang]??null;
           $check(is_array($term)&&$term['linked_term_count']===7&&
               $term['missing_term_count']===0&&
               $term['same_text_review_count']===0,
               'TERM_LANGUAGE_'.$lang);
        }
    }
    $reported[$id]=['pass'=>true,'languages'=>count($family['by_language'])];
}
$manifest=$owner::dependency_family_manifest(46193,12);
$check($manifest['family_count']===4,'FAMILY_DISCOVERY_INCOMPLETE');
$check($manifest['summary']['all_families_structurally_ready']===true,
       'DEPENDENCY_RECONCILIATION_INCOMPLETE');
$check($manifest['summary']['unmet_locale_instances']===0,'NESTED_LOCALE_GAPS');
$check($manifest['summary']['same_text_term_translation_reviews']===0,
       'TERM_LOCALE_TEXT_PENDING');
$oldJobs=get_option(MAD4B_WPML_Section_Content_Jobs::OUTBOX,[]);
$readback=[];
foreach($oldJobs as $row){
    if((int)($row['post_id']??0)!==46193)continue;
    $lang=(string)($row['language']??'');
    $readback[$lang]=[
        'post_id'=>(int)($row['target_id']??0),
        'state'=>$row['state']??''
    ];
}
foreach($policy['required'] as $lang) {
    if($lang===$policy['default'])continue;
    $check(isset($readback[$lang]) &&
       $readback[$lang]['state']==='RESOLVED_VERIFIED_BY_ELEMENTOR_READBACK',
       'CONTENTJOB_SECTION_UNVERIFIED_'.$lang);
}
foreach([69043,69044,69045,69046] as $id) {
    $check(get_post_status($id)==='publish','EDITORIAL_LOCALE_UNPUBLISHED_'.$id);
    $check(strlen((string)get_post_field('post_content',$id))===0,
        'LEGACY_SOURCE_HTML_UNEXPECTEDLY_COPIED_'.$id);
    $check(metadata_exists('post',$id,'_mad4b_wpml_editorial_publish_receipt_sha256'),
        'EDITORIAL_NATIVE_RECEIPT_MISSING_'.$id);
}
$cssFailed=[]; $checked=0;
foreach(array_keys($allIds) as $id){
    $file=new \Elementor\Core\Files\CSS\Post((int)$id);
    $path=$file->get_path();
    $compiled=$file->get_content();
    $meta=get_post_meta((int)$id,'_elementor_css',true);
    if(!is_file($path)||!is_array($meta)||($meta['status']??'')!=='file'||
       !hash_equals(hash('sha256',$compiled),
                    is_file($path)?hash_file('sha256',$path):''))
       $cssFailed[]=$id;
    $checked++;
}
$check(!$cssFailed,'CSS_READBACK_DRIFT_'.implode('_',$cssFailed));
WP_CLI::line(wp_json_encode([
    'contract'=>'mad4b.wpml.rate-family-native-acceptance.v1',
    'result'=>'PASS',
    'policy_languages'=>$policy['required'],
    'families'=>$reported,'family_count'=>$manifest['family_count'],
    'required_locale_slots'=>$manifest['family_count']*count($policy['required']),
    'unmet_locale_instances'=>0,
    'linked_weekday_terms_per_language'=>7,
    'same_english_text_in_locale_weekdays'=>0,
    'source_editorial_locales_published'=>4,
    'native_section_job_readback_verified_locales'=>count($readback),
    'css_templates_verified'=>$checked,
    'css_drift_count'=>count($cssFailed),
    'browser_interaction_verified'=>false,
    'native_cross_site_jetengine_sync_stage_completed'=>false,
    'mutation_performed'=>false,
    'production_mutation_performed'=>false
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
