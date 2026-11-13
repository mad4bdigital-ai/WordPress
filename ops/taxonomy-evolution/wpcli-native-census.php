<?php
/**
 * Read-only bounded census for MAD4B taxonomy evolution.
 * This is diagnostic material, not a cryptographically signed provider receipt.
 * Requires enrolled, authenticated WP-CLI on one exact Staging origin.
 */
if (!defined('WP_CLI') || !WP_CLI || !defined('ABSPATH')) throw new RuntimeException('wp_cli_required');
$old=getenv('MAD4B_EVOLVE_OLD'); $new=getenv('MAD4B_EVOLVE_NEW');
$exact=getenv('MAD4B_EVOLVE_EXACT_HOME');
if (!is_string($old) || !preg_match('/^[a-z][a-z0-9_]{2,60}$/D',$old) ||
    !is_string($new) || !preg_match('/^[a-z][a-z0-9_]{2,60}$/D',$new) ||
    $old===$new || $exact!==home_url() || wp_get_environment_type()!=='staging')
    throw new RuntimeException('exact_staging_scope_required');
global $wpdb;
$one=function(string $sql,array $args=[] )use($wpdb):int {
    $query=$args?$wpdb->prepare($sql,$args):$sql;
    $result=$wpdb->get_var($query);
    if($wpdb->last_error)throw new RuntimeException('database_census_error');
    return (int)$result;
};
$termCount=$one("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy=%s",[$old]);
$translationTable=$wpdb->prefix.'icl_translations';
$jetTable=$wpdb->prefix.'jet_taxonomies';
$meta=$wpdb->postmeta;
$filterMetaCounts=[];
foreach(['_source_taxonomy','_menu_item_object','_listing_data','_elementor_data','_ih_source_map','_custom_query_var'] as $key){
    $filterMetaCounts[$key]=$one("SELECT COUNT(*) FROM $meta WHERE meta_key=%s AND meta_value LIKE %s",[$key,'%'.$wpdb->esc_like($old).'%']);
}
$optionCount=$one("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s",['%'.$wpdb->esc_like($old).'%']);
$w=get_option('icl_sitepress_settings',[]);
$hidden=(is_array($w)&&is_array($w['hidden_languages']??null))?array_values($w['hidden_languages']):[];
sort($hidden);
$taxOld=get_taxonomy($old);$taxNew=get_taxonomy($new);
$referenceCount=array_sum($filterMetaCounts);
$report=[
 'contract'=>'mad4b.taxonomy-evolution-native-census.v1',
 'site_origin'=>home_url(),
 'environment'=>wp_get_environment_type(),
 'scope_verified'=>true,
 'taxonomy'=>[
  'old'=>$old,'new'=>$new,'old_registered'=>(bool)$taxOld,'new_registered'=>(bool)$taxNew,
  'old_rewrite_slug'=>$taxOld&&is_array($taxOld->rewrite)?($taxOld->rewrite['slug']??null):null,
  'target_rewrite_slug'=>$taxNew&&is_array($taxNew->rewrite)?($taxNew->rewrite['slug']??null):null
 ],
 'counts'=>[
  'jetengine_legacy_definitions'=>$one("SELECT COUNT(*) FROM $jetTable WHERE slug=%s",[$old]),
  'legacy_terms'=>$termCount,
  'legacy_wpml_rows'=>$one("SELECT COUNT(*) FROM $translationTable WHERE element_type=%s",['tax_'.$old]),
  'legacy_term_relationships'=>$one("SELECT COUNT(*) FROM {$wpdb->term_relationships} r INNER JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id=r.term_taxonomy_id WHERE t.taxonomy=%s",[$old]),
  'legacy_terms_without_wpml'=>$one("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} t LEFT JOIN $translationTable x ON x.element_type=%s AND x.element_id=t.term_taxonomy_id WHERE t.taxonomy=%s AND x.translation_id IS NULL",['tax_'.$old,$old]),
  'canonical_terms'=>$one("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy=%s",[$new]),
  'canonical_wpml_rows'=>$one("SELECT COUNT(*) FROM $translationTable WHERE element_type=%s",['tax_'.$new]),
  'postmeta_references'=>$referenceCount,'option_rows_referencing_legacy'=>$optionCount
 ],
 'dependent_meta_counts'=>$filterMetaCounts,
 'wpml'=>['default_language'=>is_array($w)?($w['default_language']??null):null,'hidden_languages'=>$hidden],
 'observation_only'=>true,'signed_original_executor_receipt'=>false,
 'inventory_complete'=>false,'mutation_performed'=>false
];
echo wp_json_encode($report,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),PHP_EOL;
