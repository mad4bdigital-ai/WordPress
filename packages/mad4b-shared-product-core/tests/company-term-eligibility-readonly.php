<?php
/**
 * Run: wp eval-file tests/company-term-eligibility-readonly.php --allow-root
 * Read-only catalog + consumer contract regression. No persistent fixtures.
 */
if (!defined('ABSPATH')) exit(2);
$checks=[];
$checks['contract_loaded']=class_exists('MAD4B_Company_Term_Eligibility_V1');
if (!$checks['contract_loaded']) { echo "CONTRACT_NOT_LOADED\n"; exit(1); }
$cls=MAD4B_Company_Term_Eligibility_V1::class;
$sources=$cls::catalog_sources();
$editable=$cls::editable_taxonomies();
$checks['dynamic_post_types']=isset($sources['tours-and-activities'],$sources['properties'],$sources['transportations']);
$checks['public_catalog_taxonomies']=in_array('tour-types',$editable,true) &&
    in_array('property-types',$editable,true) && in_array('transportation-types',$editable,true);
$checks['no_internal_taxonomies']=!array_intersect($editable,
    ['season','group-size-tiers','product_type','product_visibility','product_shipping_class','translation_priority']);
$checks['units_inherited']=(($cls::owners('unit-type')['units']??null)==='properties');
$checks['woocommerce_catalog']=(($cls::owners('product_cat')['product']??null)==='woocommerce')
    && in_array('product_brand',$editable,true) && in_array('product_tag',$editable,true);
$checks['admin_editor_integration']=has_action('product_cat_edit_form_fields')!==false &&
    has_action('tour-types_edit_form_fields')!==false;

register_taxonomy('mad4b_readonly_catalog_probe',['properties','product'],
    ['public'=>true,'show_ui'=>true]);
$probe=$cls::owners('mad4b_readonly_catalog_probe');
$checks['dynamic_taxonomy_registration']=($probe['properties']??null)==='properties'
    && ($probe['product']??null)==='woocommerce';

$fake=new class {
    function get_name(): string {return 'jet-ajax-search';}
    function get_id(): string {return 'readonly-catalog-probe';}
    function get_settings_for_display():array {return ['search_source'=>['tours-and-activities']];}
};
$cls::capture_search_widget($fake);
$args=apply_filters('jet-search/ajax-search/categories-select/args',
    ['taxonomy'=>'tour-types','id'=>'jet_ajax_search_categories_readonly-catalog-probe']);
$expected=[];
foreach (get_terms(['taxonomy'=>'tour-types','hide_empty'=>false]) as $term)
    if ($term instanceof WP_Term && $cls::decide($term,'tours-and-activities')['allowed'])
        $expected[]=(int)$term->term_id;
$actual=array_map('intval',(array)($args['include']??[]));
sort($actual);sort($expected);
$checks['widget_source_scoped']=$actual===$expected;
$checks['woocommerce_empty_catalog_no_false_options']=true;
if ((int)(wp_count_posts('product')->publish??0)===0) {
    $productArgs=apply_filters('jet-search/ajax-search/categories-select/args',
        ['taxonomy'=>'product_cat']);
    $checks['woocommerce_empty_catalog_no_false_options']=($productArgs['include']??null)===[0];
}
$failed=array_keys(array_filter($checks,static fn($ok)=>!$ok));
echo wp_json_encode(['status'=>$failed?'FAIL':'PASS','site'=>home_url(),
    'catalog_sources'=>count($sources),'editable_taxonomies'=>count($editable),
    'checks'=>$checks,'failed'=>$failed],JSON_UNESCAPED_SLASHES).PHP_EOL;
if ($failed) exit(1);
