<?php
/**
 * Plugin Name: MAD4B — Universal Reviews Section Visibility
 * Description: Dynamically suppress only empty Elementor Reviews sections across page types, WPML and companies.
 * Version: 2.0.1
 */
defined('ABSPATH') || exit;

final class MAD4B_Universal_Reviews_Visibility_V2 {
    private const SOURCE = 27219;
    private static array $template_cache = [];
    private static array $cache_policy = [];

    private static function is_frontend(): bool {
        if (is_admin() || wp_doing_ajax()) return false;
        // WordPress post previews should reflect published visibility.
        // Elementor's live editor stays exempt so authors can adjust templates.
        static $is_editor = null;
        if ($is_editor === null) {
            $is_editor = false;
            try {
                if (class_exists('\\Elementor\\Plugin') &&
                    isset(\Elementor\Plugin::$instance->editor) &&
                    method_exists(\Elementor\Plugin::$instance->editor, 'is_edit_mode')) {
                    $is_editor = (bool) \Elementor\Plugin::$instance->editor->is_edit_mode();
                }
            } catch (\Throwable $ignored) {
                $is_editor = false;
            }
        }
        return !$is_editor;
    }

    private static function has_content(): bool {
        // Same eligibility used for the homepage: JetEngine Query 257,
        // valid gallery attachments, or a registered review shortcode.
        return !class_exists('MAD4B_Home_Reviews_Native_Visibility_V1')
            || MAD4B_Home_Reviews_Native_Visibility_V1::has_eligible_content();
    }

    public static function family_ids(): array {
        static $cache=null;
        if ($cache!==null)return $cache;
        $ids=[self::SOURCE];
        $langs=apply_filters('wpml_active_languages',null,['skip_missing'=>0]);
        if (is_array($langs)) {
            foreach (array_keys($langs) as $lang) {
                $id=(int)apply_filters('wpml_object_id',self::SOURCE,'elementor_library',false,(string)$lang);
                if ($id>0)$ids[]=$id;
            }
        }
        return $cache=array_values(array_unique($ids));
    }

    public static function is_review_template(int $id): bool {
        if ($id<1)return false;
        if (array_key_exists($id,self::$template_cache))return self::$template_cache[$id];
        if ($id===self::SOURCE || in_array($id,self::family_ids(),true))
            return self::$template_cache[$id]=true;
        if (get_post_type($id)!=='elementor_library')
            return self::$template_cache[$id]=false;
        $english=(int)apply_filters('wpml_object_id',$id,'elementor_library',false,'en');
        return self::$template_cache[$id]=($english===self::SOURCE);
    }

    private static function shortcode_id(string $value): int {
        if (!preg_match('~^\s*\[elementor-template\b[^]]*\bid\s*=\s*["\']?(\d+)~i',$value,$m))
            return 0;
        return (int)$m[1];
    }

    private static function review_widget($node): bool {
        if (!is_array($node)||($node['elType']??'')!=='widget')return false;
        $settings=(array)($node['settings']??[]);
        $type=(string)($node['widgetType']??'');
        if ($type==='template')
            return self::is_review_template((int)($settings['template_id']??0));
        if ($type==='shortcode')
            return self::is_review_template(self::shortcode_id((string)($settings['shortcode']??'')));
        return false;
    }

    private static function review_only_container($node): bool {
        if (!is_array($node)||($node['elType']??'')!=='container')return false;
        $children=$node['elements']??[];
        // A parent containing exactly one review embed is safe to remove.
        // Never remove parents that contain other widgets, headings or content.
        return is_array($children) && count($children)===1
            && self::review_widget(reset($children));
    }

    private static function prune(array $nodes): array {
        $out=[];
        foreach ($nodes as $node) {
            if (!is_array($node)){$out[]=$node;continue;}
            if (self::review_widget($node) || self::review_only_container($node))continue;
            if (is_array($node['elements']??null))
                $node['elements']=self::prune($node['elements']);
            $out[]=$node;
        }
        return $out;
    }

    public static function prune_data($nodes,$document_id) {
        if (!self::is_frontend() || !is_array($nodes) || self::has_content())
            return $nodes;
        return self::prune($nodes);
    }

    public static function shortcode_preflight($pre,$tag,$attrs,$match) {
        if ($pre!==false || !self::is_frontend()
            || $tag!=='elementor-template' || !is_array($attrs)
            || self::has_content())return $pre;
        return self::is_review_template((int)($attrs['id']??0))?'':$pre;
    }

    public static function widget_should_render($render,$widget) {
        if (!$render || !self::is_frontend() || self::has_content()
            || !is_object($widget)
            || !method_exists($widget,'get_name')
            || !method_exists($widget,'get_settings_for_display'))return $render;
        $type=(string)$widget->get_name();
        if (!in_array($type,['shortcode','template'],true))return $render;
        return self::review_widget([
            'elType'=>'widget','widgetType'=>$type,
            'settings'=>(array)$widget->get_settings_for_display(),
        ]) ? false : $render;
    }

    private static function contains_review_widget(array $nodes): bool {
        foreach ($nodes as $node) {
            if (self::review_widget($node))return true;
            if (is_array($node['elements']??null) && self::contains_review_widget($node['elements']))
                return true;
        }
        return false;
    }

    private static function document_has_reviews(int $id): bool {
        if ($id<1)return false;
        if (array_key_exists($id,self::$cache_policy))return self::$cache_policy[$id];
        if (self::is_review_template($id))return self::$cache_policy[$id]=true;
        $raw=get_post_meta($id,'_elementor_data',true);
        if (!is_string($raw)||$raw==='')return self::$cache_policy[$id]=false;
        $candidate=false;
        foreach (self::family_ids() as $template_id) {
            if (str_contains($raw,(string)$template_id)){$candidate=true;break;}
        }
        if (!$candidate)return self::$cache_policy[$id]=false;
        $nodes=json_decode($raw,true);
        return self::$cache_policy[$id]=is_array($nodes)&&self::contains_review_widget($nodes);
    }

    public static function element_cache_policy($previous) {
        if (!self::is_frontend() || !class_exists('\Elementor\Plugin')
            || !isset(\Elementor\Plugin::$instance)
            || !is_object(\Elementor\Plugin::$instance->documents))return $previous;
        $document=\Elementor\Plugin::$instance->documents->get_current();
        if (!is_object($document)||!method_exists($document,'get_id'))return $previous;
        // Avoid shared/stale cached HTML for dynamic review sections.
        return self::document_has_reviews((int)$document->get_id())?'disable':$previous;
    }

    public static function uses_reviews(int $document_id): bool {
        return self::document_has_reviews($document_id);
    }

    public static function refresh_references(): void {
        global $wpdb;
        $terms=[];$arguments=[];
        foreach (self::family_ids() as $id) {
            $terms[]='pm.meta_value LIKE %s';
            $arguments[]='%'.$wpdb->esc_like((string)$id).'%';
        }
        if (!$terms)return;
        $sql="SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON p.ID=pm.post_id
            WHERE pm.meta_key='_elementor_data' AND p.post_status='publish'
            AND (".implode(' OR ',$terms).")";
        $found=$wpdb->get_col($wpdb->prepare($sql,...$arguments));
        $ids=array_unique(array_merge(array_map('intval',$found),self::family_ids()));
        foreach ($ids as $id) {
            if (!$id)continue;
            delete_post_meta($id,'_elementor_element_cache');
            clean_post_cache($id);
            do_action('litespeed_purge_post',$id);
            if (get_post_type($id)==='page') {
                $url=get_permalink($id);
                if (is_string($url) && $url!=='')do_action('litespeed_purge_url',$url);
            }
        }
        foreach (['tours-and-activities','properties','transportation','post'] as $type) {
            $url=get_post_type_archive_link($type);
            if (is_string($url)&&$url!=='')do_action('litespeed_purge_url',$url);
        }
    }
}

add_filter('elementor/frontend/builder_content_data',
    ['MAD4B_Universal_Reviews_Visibility_V2','prune_data'],95,2);
add_filter('pre_do_shortcode_tag',
    ['MAD4B_Universal_Reviews_Visibility_V2','shortcode_preflight'],25,4);
add_filter('elementor/frontend/widget/should_render',
    ['MAD4B_Universal_Reviews_Visibility_V2','widget_should_render'],45,2);
add_filter('pre_option_elementor_element_cache_ttl',
    ['MAD4B_Universal_Reviews_Visibility_V2','element_cache_policy'],65,1);
add_action('mad4b/home_reviews/source_changed',
    ['MAD4B_Universal_Reviews_Visibility_V2','refresh_references'],25,0);
