<?php
/**
 * Run with WP CLI on an authorized company site. No posts, options, taxonomy,
 * filters, pricing, users or caches are permanently changed.
 */
if (!defined('ABSPATH')) exit(2);
$class='MAD4B_Company_Term_Eligibility_V1';
$checks=[];
if (!class_exists($class)) {echo "MISSING_CONTRACT\n";exit(1);}
$ids=get_posts(['post_type'=>'tours-and-activities','post_status'=>'publish',
    'posts_per_page'=>1,'fields'=>'ids','suppress_filters'=>true]);
$tour=absint($ids[0]??0);
if (!$tour) { echo "NO_PUBLISHED_TOUR\n"; exit(1); }
$original=$GLOBALS['wp_the_query']??null;
$checks['unverified_not_component']=$class::verified_tour_id()===0;
$q=new WP_Query(['post_type'=>'tours-and-activities','p'=>$tour,
    'suppress_filters'=>true,'posts_per_page'=>1]);
$GLOBALS['wp_the_query']=$q;
// WP_Query lazily initializes queried_object in WP-CLI; the web main query
// is already primed by the normal WordPress dispatch before Elementor render.
$q->get_queried_object_id();
$verified=$class::verified_tour_id();
$diagnostics=['candidate_count'=>count($ids),'is_singular'=>$q->is_singular('tours-and-activities'),'posts'=>$q->post_count,'queried_id'=>$q->get_queried_object_id(),'main_reference'=>($GLOBALS['wp_the_query']??null)===$q,'candidate_post_status'=>get_post_status($tour),'object_class'=>is_object($q->get_queried_object())?get_class($q->get_queried_object()):gettype($q->get_queried_object()),'object_status'=>is_object($q->get_queried_object())?($q->get_queried_object()->post_status??'NONE'):null,'object_id'=>is_object($q->get_queried_object())?($q->get_queried_object()->ID??null):null,'direct_verified'=>function_exists('shared_product_verified_presentation_tour_id')?shared_product_verified_presentation_tour_id():-1];
$checks['tour_server_verified']=$verified===$tour &&
    $class::surface_channel()==='tour_component';
$checks['tour_only_service_usable_in_tour']=$class::query_service_allowed(
    'properties','tour_component') &&
    $class::query_service_allowed('transportations','tour_component');
$checks['disabled_service_stays_blocked']=!$class::query_service_allowed(
    'visas','tour_component');
$terms=get_terms(['taxonomy'=>'stars','hide_empty'=>false,'number'=>1]);
$term=is_array($terms)&&$terms?reset($terms):null;
if ($term instanceof WP_Term) {
    $mock=(object)['query_type'=>'terms',
        'query'=>['taxonomy'=>[$term->taxonomy]],
        'query_id'=>'read_only_term_query'];
    $ret=apply_filters('jet-engine/query-builder/query/items',[$term],$mock);
    $checks['query_builder_terms_keep_component']=$ret===[$term];
    $checks['single_query_hook_not_duplicated']=count(
        ($GLOBALS['wp_filter']['jet-engine/query-builder/query/items']->callbacks[1400]??[])
    )===1;
    $unmodified=apply_filters('jet-engine/query-builder/query/items',
        [$term],(object)['query_type'=>'terms',
         'query'=>['taxonomy'=>['__unrelated_test_taxonomy__']],
         'query_id'=>'unrelated_readonly']);
    $checks['unrelated_query_results_untouched']=$unmodified===[$term];
    $filterId=991234;
    $testBinding=['taxonomy'=>$term->taxonomy,'source'=>'taxonomies',
        'providers'=>['component_term_query'=>[
            'post_type'=>'','query_type'=>'terms',
            'taxonomies'=>[$term->taxonomy]]]];
    $pre=function()use($filterId,$testBinding){
        return ['version'=>2,'filters'=>[$filterId=>$testBinding]];
    };
    add_filter('pre_transient_shared_presentation_native_consumers_v1',$pre,99,0);
    $queryOptions=[['value'=>$term->term_id,'label'=>$term->name]];
    $filtered=$class::smart_options($queryOptions,$filterId);
    remove_filter('pre_transient_shared_presentation_native_consumers_v1',$pre,99);
    $checks['term_query_smartfilter_respects_tour']=
        $filtered===$queryOptions;
}
$published=get_terms(['taxonomy'=>'property-types','hide_empty'=>false,'number'=>0]);
$filterId=0;
foreach ((array)shared_presentation_native_consumers_v1() as $id=>$b) {
    if (($b['taxonomy']??'')!=='property-types') continue;
    foreach (($b['providers']??[]) as $provider) {
        if (($provider['post_type']??'')==='properties') { $filterId=(int)$id;break 2; }
    }
}
$checks['registered_property_filter_found']=$filterId>0;
$inTour=0;
if ($filterId) {
    $opts=jet_smart_filters()->data->get_terms_for_options(
        'property-types',false,false,['hide_empty'=>false]);
    $inTour=count(apply_filters(
        'jet-smart-filters/filters/filter-options',$opts,$filterId,false));
    $checks['tour_component_filter_has_options']=$inTour>0;
}
$GLOBALS['wp_the_query']=$original;
if ($filterId && service_channel_mode('properties')==='tour_only') {
    $opts=jet_smart_filters()->data->get_terms_for_options(
        'property-types',false,false,['hide_empty'=>false]);
    $outside=count(apply_filters(
        'jet-smart-filters/filters/filter-options',$opts,$filterId,false));
    $checks['standalone_does_not_inherit_tour_grant']=$outside===0;
}
$failed=array_keys(array_filter($checks,static fn($ok)=>!$ok));
echo wp_json_encode(['status'=>$failed?'FAIL':'PASS',
    'site'=>home_url(),'tour_id'=>$tour,'verified_tour'=>$verified,
    'property_filter_id'=>$filterId,'diagnostics'=>$diagnostics,
    'inside_property_options'=>$inTour,'checks'=>$checks,'failed'=>$failed],
    JSON_UNESCAPED_SLASHES).PHP_EOL;
if ($failed) exit(1);
