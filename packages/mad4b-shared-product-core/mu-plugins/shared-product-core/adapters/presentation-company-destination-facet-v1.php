<?php
/**
 * Dynamic destination choices for native JetSmartFilters.
 * Read-only projection from existing Company Destination Activation and
 * Service Activation sale eligibility. Does not mutate taxonomy term parents.
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'local') return;

function shared_presentation_company_location_facet_v1(array $options, int $filter_id, bool $native_binding_verified=false): array {
    if (!$filter_id || !function_exists('shared_presentation_provider_v2') ||
        !function_exists('native_destination_approved') ||
        !function_exists('native_destinations_sale_term_index') ||
        !function_exists('service_channel_allowed') ||
        !service_channel_allowed('tours','destination_discovery')) return $options;
    if (!$native_binding_verified) {
    $provider=shared_presentation_provider_v2('tours.archive');
    $ids=array_map('intval',(array)($provider['facet_filter_ids']['destinations']??[]));
    // WPML locales may mount translated JSF filter CPT IDs rather than the
    // canonical one stored on the reference Elementor template.
    $registered=array_fill_keys($ids,true);
    foreach($ids as $originId) {
        $trid=apply_filters('wpml_element_trid',null,$originId,'post_jet-smart-filters');
        if(!$trid)continue;
        foreach((array)apply_filters('wpml_get_element_translations',null,$trid,'post_jet-smart-filters') as $translation) {
            $other=absint($translation->element_id??0);
            if($other)$registered[$other]=true;
        }
    }
    if (!isset($registered[$filter_id]))return $options;
    }
    if (get_post_status($filter_id)!=='publish' ||
        get_post_meta($filter_id,'_source_taxonomy',true)!=='destinations' ||
        get_post_meta($filter_id,'_data_source',true)!=='taxonomies')return $options;

    $language=(string)apply_filters('wpml_current_language',null);
    if ($language==='' || !preg_match('/^[a-z]{2}$/D',$language))$language='en';
    // Approvals are authored against canonical English destination terms.
    $countries=native_destination_approved($language);
    $roots=[];
    foreach($countries as $country){
        $canonical=absint($country['id']??0);
        if($canonical)$roots[$canonical]=true;
    }
    $sellable=native_destinations_sale_term_index('en');
    if(!$roots || !$sellable)return [];

    $result=[];
    $canonicalize=static function(int $id):int {
        $en=(int)apply_filters('wpml_object_id',$id,'destinations',false,'en');
        return $en?:$id;
    };
    foreach($options as $key=>$option){
        // Native JSF checkboxes use WP_Term objects for nested trees.
        // Native JSF selects/radio use value+label arrays: never replace their
        // authored labels or input types; only filter permitted term options.
        $term=$option instanceof WP_Term?$option:null;
        if(!$term && is_array($option)){
            $raw=$option['value']??null;
            if(is_scalar($raw)){
                if(ctype_digit((string)$raw))$term=get_term((int)$raw,'destinations');
                else $term=get_term_by('slug',(string)$raw,'destinations');
            }
        }
        if(!$term instanceof WP_Term || $term->taxonomy!=='destinations')continue;
        $canon=$canonicalize((int)$term->term_id);
        if(!$canon || !isset($sellable[$canon]) ||
            (function_exists('destinations_activation_allowed') &&
                !destinations_activation_allowed((int)$term->term_id)))continue;

        // WPML can return mixed-language parent IDs in get_ancestors().
        // Normalize EVERY ancestor to EN before consulting company approvals.
        $chain=array_merge([(int)$term->term_id],array_map('intval',
            get_ancestors((int)$term->term_id,'destinations','taxonomy')));
        $country=0;
        foreach($chain as $ancestor){
            $canonicalAncestor=$canonicalize($ancestor);
            if(isset($roots[$canonicalAncestor])){$country=$canonicalAncestor;break;}
        }
        if(!$country)continue;
        $public=(string)get_term_meta((int)$term->term_id,'is_public',true);
        if(in_array($public,['0','false','no','disabled'],true))continue;
        $kind=(string)get_term_meta($canon,'destination_type',true);
        if($kind==='continent')continue;

        // In the checkbox Walker only, promote each approved Country to a
        // root. Egypt is shown instead of the global Africa hierarchy.
        // Clone: never write/modify WP_Term from the shared object cache.
        if($option instanceof WP_Term){
            $copy=clone $option;
            // Only a tree-mode checkbox may rebase a Country virtual root.
            if($kind==='country' && get_post_meta($filter_id,'_group_by_parent',true)==='1')
                $copy->parent=0;
            $result[$key]=$copy;
        }else $result[$key]=$option;
    }
    return $result;
}

add_filter('jet-smart-filters/filters/filter-options',static function($options,$filterId) {
    if (is_admin() && !wp_doing_ajax())return $options;
    if (!is_array($options))return $options;
    return shared_presentation_company_location_facet_v1($options,(int)$filterId);
},35,2);
