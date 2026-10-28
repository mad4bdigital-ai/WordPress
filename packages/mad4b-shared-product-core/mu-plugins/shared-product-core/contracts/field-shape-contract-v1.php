<?php
/**
 * Versioned source contract for Elementor bindings / JetEngine repeater meta.
 * Pure inspection; never coerces arrays to strings or mutates stored content.
 */
defined('ABSPATH') || exit;
final class Shared_Product_Field_Shape_Contract_v1 {
    public const VERSION = '1.0.0';
    private const SCALAR_CONTROLS = [
        'jedv_field'=>true,'field_fallback'=>true,'title'=>true,'description'=>true,
        'editor'=>true,'aria_label_attr'=>true,'link_label'=>true,'shortcode'=>true,
        'infocard_text_link'=>true,'address'=>true,
    ];
    private const REPEATER_RULES = ['transportations'=>['trans_train_stops'=>true,'trans_faq_item'=>true], 'tours-and-activities'=>['faq_item'=>true]];
    public static function tag_category(string $name): array {
        static $cache=[];
        if (isset($cache[$name])) return $cache[$name];
        if (!class_exists('Elementor\\Plugin')) return [];
        try {
            $tag=\Elementor\Plugin::$instance->dynamic_tags->create_tag('shape_guard_'.$name,$name,[]);
            $categories=$tag && method_exists($tag,'get_categories') ? $tag->get_categories() : [];
            $cache[$name]=is_array($categories)?$categories:[];
        } catch (\Throwable $error) { $cache[$name]=[]; }
        return $cache[$name];
    }
    public static function inspect_elementor(array $elements): array {
        $issues=[];$seen=0;
        $check_settings=static function(array $settings,string $nodeId,string $widget)use(&$issues,&$check_settings):void {
            foreach($settings as$key=>$value) {
                if ($key==='__dynamic__' && is_array($value)) {
                    foreach($value as$control=>$markup) {
                        if (!isset(self::SCALAR_CONTROLS[$control])||!is_string($markup))continue;
                        if (!preg_match_all('/\[elementor-tag\b[^\]]*\bname="([^"]+)"/',$markup,$matches))continue;
                        foreach($matches[1] as$tagName) {
                            $categories=self::tag_category($tagName);
                            if (in_array($tagName,['jet-post-custom-image','jet-object-property-image','post-featured-image','jet-term-image','jet-options-image','jet-cct-image'],true)) {
                                $issues[]=['code'=>'COMPOSITE_TAG_IN_SCALAR_CONTROL','node'=>$nodeId,
                                    'widget'=>$widget,'control'=>$control,'tag'=>$tagName];
                            }
                        }
                    }
                } elseif (is_array($value)) {
                    $check_settings($value,$nodeId,$widget);
                }
            }
        };
        $walk=static function(array $tree,int $depth)use(&$walk,&$seen,&$issues,$check_settings):void {
            if ($depth>40) { $issues[]=['code'=>'ELEMENTOR_TREE_TOO_DEEP']; return; }
            foreach($tree as $node) {
                if (++$seen>8000) {$issues[]=['code'=>'ELEMENTOR_TREE_TOO_LARGE'];return;}
                if (!is_array($node)) { $issues[]=['code'=>'INVALID_ELEMENTOR_NODE'];continue; }
                $id=is_scalar($node['id']??null)?(string)$node['id']:'unknown';
                $widget=is_scalar($node['widgetType']??null)?(string)$node['widgetType']:'container';
                if (isset($node['settings']) && is_array($node['settings'])) $check_settings($node['settings'],$id,$widget);
                if (isset($node['elements']) && !is_array($node['elements'])) {
                    $issues[]=['code'=>'INVALID_CHILD_ELEMENTS','node'=>$id];
                } elseif (is_array($node['elements']??null)) {
                    $walk($node['elements'],$depth+1);
                }
            }
        };
        $walk($elements,0);
        return $issues;
    }
    public static function is_guarded_repeater(int $postId,string $key): bool {
        $type=get_post_type($postId);
        return is_string($type) && isset(self::REPEATER_RULES[$type][$key]);
    }
    public static function inspect_repeater_value($value): array {
        if ($value === '' || $value === [] || $value === null) return [];
        if (!is_array($value)) return [['code'=>'REPEATER_REQUIRES_ARRAY_NOT_SCALAR','type'=>get_debug_type($value)]];
        foreach($value as $index=>$row) {
            if (!is_array($row)) return [['code'=>'REPEATER_ROW_REQUIRES_ARRAY','row'=>(string)$index,'type'=>get_debug_type($row)]];
        }
        return [];
    }
}
