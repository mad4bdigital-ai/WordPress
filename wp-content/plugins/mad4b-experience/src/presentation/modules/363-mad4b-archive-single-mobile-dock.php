<?php
/**
 * Plugin Name: MAD4B Archive Single Mobile Dock Authority
 * Description: Uses one viewport-anchored mobile archive dock; prevents three Elementor sticky engines from controlling the same CTA.
 * Source module: live WordPress implementation, generalized by site profile.
 */
defined('ABSPATH') || exit;
if (!MAD4B_Presentation_Experience::enabled('archive-mobile-dock')) return;
add_filter('elementor/frontend/builder_content_data', static function($nodes, $document_id) {
    // CSS handles the breakpoint. Keep original desktop Elementor sticky behavior;
    // only prevent nested mobile sticky controllers on real mobile user agents.
    if (!is_array($nodes) || is_admin() || !is_post_type_archive('tours-and-activities')
        || !wp_is_mobile()) return $nodes;
    $target=['650c69'=>true,'5194c112'=>true,'5f71a5d5'=>true];
    $walk=static function(array $rows) use (&$walk,$target):array {
        foreach($rows as &$node){
            if (!is_array($node)) continue;
            if (isset($target[(string)($node['id']??'')])){
                $settings=(array)($node['settings']??[]);
                foreach(['sticky','sticky_on','sticky_offset','sticky_offset_mobile','sticky_offset_tablet','sticky_effects_offset','sticky_effects_offset_mobile','sticky_effects_offset_tablet','sticky_parent','sticky_anchor_link_offset'] as $key)
                    unset($settings[$key]);
                $node['settings']=$settings;
            }
            if (!empty($node['elements'])&&is_array($node['elements']))
                $node['elements']=$walk($node['elements']);
        }
        unset($node);
        return $rows;
    };
    return $walk($nodes);
}, 4500, 2);
