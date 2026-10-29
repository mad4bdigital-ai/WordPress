<?php
/**
 * Elementor/WPML input contract — Local Lab trial.
 * Elementor's builder tree permits third-party widgets without `elements`.
 * The installed WPML translator unconditionally recurses into `elements`.
 * Normalize missing/non-array child collections before translators execute.
 * Do NOT change widgets, IDs, content, settings, language or stored meta.
 */
defined('ABSPATH') || exit;

add_filter('elementor/frontend/builder_content_data', static function ($tree) {
    if (!is_array($tree)) return $tree;
    $normalize=static function(array $nodes)use(&$normalize):array {
        foreach ($nodes as &$node) {
            if (!is_array($node)) continue;
            if (!array_key_exists('elements',$node) || !is_array($node['elements'])) {
                $node['elements']=[];
            } else {
                $node['elements']=$normalize($node['elements']);
            }
        }
        unset($node);
        return $nodes;
    };
    return $normalize($tree);
}, 5, 1);
