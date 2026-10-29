<?php
/**
 * Source-level persistence guard for exact Elementor / JetEngine field types.
 * Never converts composite tag values to strings and never rewrites contents.
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'local' || !class_exists('Shared_Product_Field_Shape_Contract_v1')) return;

add_filter('elementor/document/save/data',static function($data,$document){
    if (!is_array($data) || !isset($data['elements']) || !is_array($data['elements'])) return $data;
    $issues=Shared_Product_Field_Shape_Contract_v1::inspect_elementor($data['elements']);
    if (!$issues) return $data;
    $first=$issues[0];
    // Elementor Ajax wraps thrown exceptions and reports the failure to its editor.
    // Never report successful save while persisting known broken bindings.
    throw new \InvalidArgumentException('Elementor source contract: '.(string)$first['code']
        .' at node '.(string)($first['node']??'unknown')
        .' / '.(string)($first['control']??'elements')
        .' — replace composite image dynamic tag with a metadata field/key or an appropriate image control.');
},999,2);

$source_meta_guard=static function($pre,$postId,$metaKey,$metaValue) {
    if ($pre!==null || !is_string($metaKey)) return $pre;
    $issues=[];
    if ($metaKey==='_elementor_data') {
        // A deliberately cleared legacy blog post may contain empty meta.
        // An empty document is not a malformed composite dynamic binding.
        if ($metaValue === '') return $pre;
        if (!is_string($metaValue)) {
            $issues=[['code'=>'ELEMENTOR_DATA_REQUIRES_JSON_STRING']];
        } else {
            $tree=json_decode(wp_unslash($metaValue),true);
            if (!is_array($tree)) $issues=[['code'=>'INVALID_ELEMENTOR_JSON']];
            else $issues=Shared_Product_Field_Shape_Contract_v1::inspect_elementor($tree);
        }
    } elseif (Shared_Product_Field_Shape_Contract_v1::is_guarded_repeater((int)$postId,$metaKey)) {
        $issues=Shared_Product_Field_Shape_Contract_v1::inspect_repeater_value($metaValue);
    }
    if (!$issues) return $pre;
    // Metadata API pre-update filters can only return null (continue) or a
    // non-null result. Explicit false prevents invalid data being stored.
    error_log('[MAD4B_FIELD_CONTRACT] denied post='.(int)$postId.' key='.$metaKey
        .' code='.(string)($issues[0]['code']??'INVALID_SOURCE_SHAPE'));
    return false;
};
add_filter('update_post_metadata',$source_meta_guard,20,4);
add_filter('add_post_metadata',$source_meta_guard,20,4);
