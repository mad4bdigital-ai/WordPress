<?php
/** Source-aligned global-offer wrapper eligibility, without enabling commerce or campaign features. */
if (!defined('ABSPATH')) exit;
add_filter('elementor/frontend/builder_content_data', static function($elements,$document_id) {
    if (!is_array($elements) || is_admin()) return $elements;
    if (class_exists('Elementor\\Plugin') && \Elementor\Plugin::$instance->preview->is_preview_mode()) return $elements;
    // Respect original source promotion certification when its provider exists.
    if ((function_exists('global_offer_resolved') && global_offer_resolved()) ||
        (function_exists('shared_offer_seasonal_visible') && shared_offer_seasonal_visible())) return $elements;
    $walk=static function($nodes) use (&$walk) {
        $result=[];
        foreach($nodes as $node) {
            if (!is_array($node)) continue;
            if (!empty($node['settings']['global_offer_bar_section'])) continue;
            if(isset($node['elements']) && is_array($node['elements'])) $node['elements']=$walk($node['elements']);
            $result[]=$node;
        }
        return $result;
    };
    return $walk($elements);
},1200,2);
