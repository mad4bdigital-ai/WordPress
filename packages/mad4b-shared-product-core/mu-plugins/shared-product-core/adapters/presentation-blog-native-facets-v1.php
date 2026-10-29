<?php
/**
 * Native JetSmartFilters category controls, source-owned taxonomy option list.
 * Preserves the original Color/Image Filter ID, Elementor popup and JSF AJAX.
 * This provider is read-only and adds no cross-service taxonomy permissions.
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'local') return;

add_filter('jet-smart-filters/filters/filter-options', static function($options, $filter_id){
    if ((int)$filter_id !== 16374 ||
        get_post_meta(16374,'_data_source',true)!=='taxonomies' ||
        get_post_meta(16374,'_source_taxonomy',true)!=='category') return $options;

    $language=apply_filters('wpml_current_language',null);
    $parent=1; // English source parent "Interests"; WPML term translations own localized IDs.
    if ($language && has_filter('wpml_object_id')) {
        $translation=apply_filters('wpml_object_id',1,'category',true,$language);
        if ($translation && term_exists((int)$translation,'category')) $parent=(int)$translation;
    }
    $terms=get_terms(['taxonomy'=>'category','parent'=>$parent,
                      'hide_empty'=>false,'orderby'=>'name','order'=>'ASC']);
    if (is_wp_error($terms) || !is_array($terms)) return [];
    $rows=[];
    foreach ($terms as $term) {
        if (!($term instanceof WP_Term) || (int)$term->parent!==$parent) continue;
        $img=get_term_meta($term->term_id,'image',true);
        $rows[]=['value'=>(int)$term->term_id,
                 'label'=>$term->name,
                 'image'=>is_string($img)?$img:'',
                 'color'=>''];
    }
    return $rows;
},220,2);
