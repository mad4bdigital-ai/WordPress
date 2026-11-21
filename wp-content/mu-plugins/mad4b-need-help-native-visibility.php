<?php
/**
 * Plugin Name: MAD4B — Company-Aware Need Help Visibility
 * Description: Show Help sections only when company support destinations are configured; supports WPML and all public templates.
 * Version: 1.0.2
 */
defined('ABSPATH') || exit;

final class MAD4B_Need_Help_Visibility_V1 {
    private const EN_TEMPLATE = 25213;
    private static array $templates = [];
    private static array $documents = [];

    private static function frontend(): bool {
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

    public static function valid_destination($raw): bool {
        if (!is_string($raw)) return false;
        $url=trim($raw);
        if ($url==='' || $url==='#')return false;
        // Valid, site-relative navigation is supported as well as HTTPS
        // absolute URLs; placeholders and protocol-relative URLs are not.
        if (str_starts_with($url,'/') && !str_starts_with($url,'//'))
            return (bool)preg_match('~^/[^\s<>"\x00-\x1F]*$~D',$url);
        if (!filter_var($url,FILTER_VALIDATE_URL))return false;
        $scheme=strtolower((string)wp_parse_url($url,PHP_URL_SCHEME));
        $host=(string)wp_parse_url($url,PHP_URL_HOST);
        return in_array($scheme,['http','https'],true) && $host!=='';
    }

    public static function available_in($config): bool {
        if (!is_array($config))return false;
        return self::valid_destination($config['support_group_url']??null)
            || self::valid_destination($config['support_chat_url']??null);
    }

    public static function available(): bool {
        // Source-of-truth: JetEngine Contact and Help Info, per company.
        return self::available_in(get_option('contact-and-help-info',[]));
    }

    public static function translated_ids(): array {
        static $ids=null;
        if ($ids!==null)return $ids;
        $ids=[self::EN_TEMPLATE];
        $langs=apply_filters('wpml_active_languages',null,['skip_missing'=>0]);
        if (is_array($langs)) foreach (array_keys($langs) as $lang) {
            $id=(int)apply_filters('wpml_object_id',self::EN_TEMPLATE,'elementor_library',false,(string)$lang);
            if ($id>0)$ids[]=$id;
        }
        return $ids=array_values(array_unique($ids));
    }

    public static function help_template(int $id): bool {
        if ($id<1)return false;
        if (array_key_exists($id,self::$templates))return self::$templates[$id];
        if ($id===self::EN_TEMPLATE || in_array($id,self::translated_ids(),true))
            return self::$templates[$id]=true;
        if (get_post_type($id)!=='elementor_library')return self::$templates[$id]=false;
        $english=(int)apply_filters('wpml_object_id',$id,'elementor_library',false,'en');
        return self::$templates[$id]=($english===self::EN_TEMPLATE);
    }

    private static function embed($node): bool {
        if (!is_array($node)||($node['elType']??'')!=='widget')return false;
        $settings=(array)($node['settings']??[]);
        $type=(string)($node['widgetType']??'');
        if ($type==='template')return self::help_template((int)($settings['template_id']??0));
        if ($type!=='shortcode')return false;
        $code=(string)($settings['shortcode']??'');
        return preg_match('~^\s*\[elementor-template\b[^]]*\bid\s*=\s*["\']?(\d+)~i',$code,$matches)
            && self::help_template((int)$matches[1]);
    }

    private static function only_help($node): bool {
        if (!is_array($node)||($node['elType']??'')!=='container')return false;
        $children=$node['elements']??[];
        return is_array($children)&&count($children)===1&&self::embed(reset($children));
    }

    private static function prune(array $nodes): array {
        $out=[];
        foreach ($nodes as $node) {
            if (!is_array($node)){$out[]=$node;continue;}
            if (self::embed($node)||self::only_help($node))continue;
            if (is_array($node['elements']??null))$node['elements']=self::prune($node['elements']);
            $out[]=$node;
        }
        return $out;
    }

    public static function prune_data($data,$document_id) {
        if (!self::frontend()||self::available()||!is_array($data))return $data;
        if (self::help_template((int)$document_id))return [];
        return self::prune($data);
    }

    public static function shortcode_preflight($pre,$tag,$atts,$match) {
        if ($pre!==false||!self::frontend()||self::available()
            ||$tag!=='elementor-template'||!is_array($atts))return $pre;
        return self::help_template((int)($atts['id']??0))?'':$pre;
    }

    public static function should_render_widget($render,$widget) {
        if (!$render||!self::frontend()||self::available()
            ||!is_object($widget)||!method_exists($widget,'get_name')
            ||!method_exists($widget,'get_settings_for_display'))return $render;
        $type=(string)$widget->get_name();
        if (!in_array($type,['shortcode','template'],true))return $render;
        return self::embed(['elType'=>'widget','widgetType'=>$type,
            'settings'=>(array)$widget->get_settings_for_display()])?false:$render;
    }

    private static function document_embeds_help(int $id): bool {
        if ($id<1)return false;
        if (array_key_exists($id,self::$documents))return self::$documents[$id];
        if (self::help_template($id))return self::$documents[$id]=true;
        $raw=get_post_meta($id,'_elementor_data',true);
        if (!is_string($raw)||$raw==='')return self::$documents[$id]=false;
        $candidate=false;
        foreach (self::translated_ids() as $template) {
            if (str_contains($raw,(string)$template)){$candidate=true;break;}
        }
        if (!$candidate)return self::$documents[$id]=false;
        $data=json_decode($raw,true);
        if (!is_array($data))return self::$documents[$id]=false;
        $has=static function($nodes)use(&$has):bool {
            foreach ($nodes as $node) {
                if (self::embed($node))return true;
                if (is_array($node['elements']??null)&&$has($node['elements']))return true;
            }
            return false;
        };
        return self::$documents[$id]=$has($data);
    }

    public static function element_cache_policy($prior) {
        if (!self::frontend()||!class_exists('\Elementor\Plugin')
            ||!isset(\Elementor\Plugin::$instance)
            ||!is_object(\Elementor\Plugin::$instance->documents))return $prior;
        $doc=\Elementor\Plugin::$instance->documents->get_current();
        if (!is_object($doc)||!method_exists($doc,'get_id'))return $prior;
        // A single shared cache must not retain a layout from another
        // company or an older Contact and Help Info option value.
        return self::document_embeds_help((int)$doc->get_id())?'disable':$prior;
    }

    public static function option_changed($old,$new): void {
        $keys=['support_group_url','support_chat_url'];
        if (!is_array($old))$old=[];
        if (!is_array($new))$new=[];
        $changed=false;
        foreach ($keys as $key) {
            if (($old[$key]??null)!==($new[$key]??null)){$changed=true;break;}
        }
        if (!$changed)return;
        // Invalidate only on changed support destinations, including
        // switching between two valid URLs, not cosmetic settings saves.
        do_action('mad4b/public_html/safe_purge_all', 'mad4b_need_help_native_visibility');
        $signal=WP_CONTENT_DIR.'/.mad4b-home-reviews-purge.signal';
        if (is_writable(WP_CONTENT_DIR))
            @file_put_contents($signal,gmdate('c').' need-help-destination-change'."\n",LOCK_EX);
    }
}

add_filter('elementor/frontend/builder_content_data',
    ['MAD4B_Need_Help_Visibility_V1','prune_data'],94,2);
add_filter('pre_do_shortcode_tag',
    ['MAD4B_Need_Help_Visibility_V1','shortcode_preflight'],24,4);
add_filter('elementor/frontend/widget/should_render',
    ['MAD4B_Need_Help_Visibility_V1','should_render_widget'],44,2);
add_filter('pre_option_elementor_element_cache_ttl',
    ['MAD4B_Need_Help_Visibility_V1','element_cache_policy'],64,1);
add_action('update_option_contact-and-help-info',
    ['MAD4B_Need_Help_Visibility_V1','option_changed'],24,2);
