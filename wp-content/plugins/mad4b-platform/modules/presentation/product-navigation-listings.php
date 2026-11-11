<?php
/** Shared Query Builder eligibility for authored navigation listings. */
defined('ABSPATH') || exit;
// A tenant may already load its adapted provider through portable-modules.
// Never redeclare functions if the canonical source file is copied again.
if (function_exists('product_navigation_source')) return;
function product_navigation_source(string $service): array {
    $sources=get_option('product_navigation_sources',[]);
    return is_array($sources[$service]??null)?$sources[$service]:[];
}
function product_navigation_eligible_post_ids(string $service,string $language=''): ?array {
    $source=product_navigation_source($service);
    if(empty($source['query_id']))return [];
    if(!class_exists('Jet_Engine\\Query_Builder\\Manager'))return [];
    $query=Presentation_Policy_Context_v1::navigation_query($service);
    if(!$query)return [];
    $cache_key=hash('sha256',serialize([$service,$language,$query->query,$query->dynamic_query]));
    static $cache=[];if(isset($cache[$cache_key]))return $cache[$cache_key];
    // Navigation spans the company's catalogue, rather than the current archive filter.
    foreach($query->query['tax_query']??[]as$index=>$row){
        $id=$row['_id']??null;$dynamic=$query->dynamic_query['tax_query'][$id]['terms']??'';
        if(empty($row['terms'])&&str_contains($dynamic,'%queried_term%')){unset($query->query['tax_query'][$index],$query->dynamic_query['tax_query'][$id]);}
    }
    $query->final_query=null;$query->final_query_raw=null;
    $args=$query->get_query_args();
    $owner=product_navigation_registry()[$service]['post_type']??null;
    if(!$owner || !in_array($owner,(array)($args['post_type']??[]),true))return [];
    $args=array_replace($args,['post_type'=>$owner,'post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids','no_found_rows'=>true,'suppress_filters'=>false,'lang'=>$language]);
    unset($args['paged'],$args['offset']);
    return $cache[$cache_key]=array_map('intval',get_posts($args));
}
function product_navigation_term_args(string $service,string $taxonomy,string $language='',array $args=[]): array {
    $source=product_navigation_source($service);$rule=$source['terms'][$taxonomy]??[];
    $args=array_replace($args,['taxonomy'=>$taxonomy,'hide_empty'=>true,'lang'=>$language]);
    $ids=product_navigation_eligible_post_ids($service,$language);
    if($ids!==null)$args['object_ids']=$ids?:[0];
    if(isset($rule['parent'])){
        $parent=apply_filters('wpml_object_id',(int)$rule['parent'],$taxonomy,false,$language);
        if(!$parent){$args['include']=[0];}else{$args['parent']=(int)$parent;}
    }
    if(!empty($rule['meta_query']))$args['meta_query']=empty($args['meta_query'])?$rule['meta_query']:['relation'=>'AND',$args['meta_query'],$rule['meta_query']];
    return apply_filters('product_navigation_term_args', $args, $service, $taxonomy, $language);
}
function product_navigation_term_visible(string $service, WP_Term $term, string $language=''): bool {
    $source=product_navigation_source($service);
    if(!$source)return false;
    $rule=$source['terms'][$term->taxonomy]??[];
    $parent=isset($rule['parent'])?apply_filters('wpml_object_id',(int)$rule['parent'],$term->taxonomy,false,$language):null;
    if($parent && (int)$parent===$term->term_id){
        // A navigation root is visible when its configured eligible child collection exists.
        $args=product_navigation_term_args($service,$term->taxonomy,$language,['fields'=>'ids','number'=>1]);
    } else {
        $args=product_navigation_term_args($service,$term->taxonomy,$language,['include'=>[$term->term_id],'fields'=>'ids','number'=>1]);
    }
    $terms=get_terms($args);
    return !is_wp_error($terms)&&!empty($terms);
}
add_filter('jet-engine/listing/grid/terms-query-args',function($args,$render,$settings){
    $service=$settings['product_navigation_service']??'';
    if(!$service)return $args;
    $channel=$settings['product_navigation_channel']??'standalone';
    if(!function_exists('service_channel_allowed') || !service_channel_allowed($service,$channel)){$args['include']=[0];return $args;}
    $taxonomy=is_array($args['taxonomy']??null)?reset($args['taxonomy']):($args['taxonomy']??'');
    $language=apply_filters('wpml_current_language',null)?:'';
    return product_navigation_term_args($service,$taxonomy,$language,$args);
},30,3);
add_action('elementor/element/jet-listing-grid/section_general/before_section_end',function($element){
    $options=[''=>'None'];foreach(product_navigation_registry()as$key=>$service)$options[$key]=$service['label'];
    $element->add_control('product_navigation_service',['label'=>'Navigation service eligibility','type'=>Elementor\Controls_Manager::SELECT,'options'=>$options,'default'=>'']);
    $element->add_control('product_navigation_channel',['label'=>'Navigation service channel','type'=>Elementor\Controls_Manager::SELECT,'options'=>['standalone'=>'Independent service','destination_discovery'=>'Destination discovery','tour_component'=>'Tour component'],'default'=>'standalone','condition'=>['product_navigation_service!'=>'']]);
});

/**
 * Query Builder has a separate listing path from the legacy Terms Query.
 * Intersect its result at the listing boundary, leaving the shared query untouched.
 * Intended for navigation collections without pagination; totals belong to the
 * authored query and may exceed the eligible collection after this intersection.
 */
function product_navigation_custom_query_items($items, $settings, $widget) {
    $service=$settings['product_navigation_service']??'';
    if(!$service)return $items;
    $channel=$settings['product_navigation_channel']??'standalone';
    if(!function_exists('service_channel_allowed') || !service_channel_allowed($service,$channel) || !is_array($items))return [];
    $source=product_navigation_source($service);
    $language=apply_filters('wpml_current_language',null)?:'';
    $groups=[];
    foreach($items as$item){
        // A menu term template cannot safely render posts, SQL rows or raw IDs.
        if(!$item instanceof WP_Term || !isset($source['terms'][$item->taxonomy]))return [];
        $groups[$item->taxonomy][]=(int)$item->term_id;
    }
    $eligible=[];
    foreach($groups as$taxonomy=>$ids){
        $args=product_navigation_term_args($service,$taxonomy,$language,['include'=>$ids,'fields'=>'ids','number'=>0]);
        $found=get_terms($args);
        if(is_wp_error($found))continue;
        foreach($found as$id)$eligible[$taxonomy][(int)$id]=true;
    }
    // Filtering the authored sequence preserves its order, exclusions and limit.
    return array_values(array_filter($items,static fn($item)=>isset($eligible[$item->taxonomy][(int)$item->term_id])));
}
add_filter('jet-engine/listing/grid/query/query','product_navigation_custom_query_items',30,3);
