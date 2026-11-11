<?php
/**
 * Plugin Name: MAD4B Tour Optional Sections — Native Visibility + Deferred Listings
 * Description: Gate the authored Tour Extras/Transport templates before Elementor renders them; prepare native signed JetEngine lazy loading for eligible, parent-bound grids.
 * Version: 1.2.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Tour_Optional_Sections_Lazy_V1 {
    private static array $template_kinds = [];
    private static array $availability = [];
    private static array $blocked_css = [];
    private static array $blocked_nested_css = [];
    private static array $used_listing_css = [];
    private const FLAGS = [
        'extras' => ['tour_extras_status', 'tour_optional_extras', 'tours-and-activities'],
        'vehicles' => ['tour_vehicles_transport_status', 'vehicles_transport_services', 'transportations'],
        'train' => ['tour_train_transport_status', 'train_transport_services', 'transportations'],
        'air' => ['tour_air_transport_status', 'air_transport_services', 'transportations'],
    ];
    private const PROVIDERS = [
        'extras'=>'current_tour_extras',
        'vehicles'=>'tours_related_transportations_vehicles',
        'train'=>'tours_related_transportations_train',
        'air'=>'tours_related_transportations_air',
    ];

    public static function tour(): int {
        if (!is_singular('tours-and-activities') || is_admin()
            || (defined('REST_REQUEST') && REST_REQUEST)) return 0;
        $id = absint(get_queried_object_id());
        return $id && get_post_type($id)==='tours-and-activities'
            && get_post_status($id)==='publish' ? $id : 0;
    }
    public static function editor(): bool {
        if (is_user_logged_in() || is_preview() || isset($_GET['elementor-preview'])
            || (defined('WP_CLI') && WP_CLI)) return true;
        return class_exists('\Elementor\Plugin')
            && isset(\Elementor\Plugin::$instance->editor)
            && \Elementor\Plugin::$instance->editor->is_edit_mode();
    }
    private static function meta(int $tour, string $key) {
        if (metadata_exists('post',$tour,$key))return get_post_meta($tour,$key,true);
        // Preserve an explicitly translated value. Only missing translated
        // fields inherit the original Tour's value.
        $default=(string)apply_filters('wpml_default_language','');
        $source=$default!==''?absint(apply_filters('wpml_object_id',$tour,'tours-and-activities',false,$default)):0;
        return $source && $source!==$tour && get_post_type($source)==='tours-and-activities'
            ? get_post_meta($source,$key,true) : '';
    }
    private static function ids($raw): array {
        if (is_string($raw))$raw=maybe_unserialize($raw);
        if (is_string($raw))$raw=$raw===''?[]:explode(',',$raw);
        if (!is_array($raw) || count($raw)>80)return [];
        $ids=[];
        foreach ($raw as $value) {
            if (!is_scalar($value) || !ctype_digit(trim((string)$value)))return [];
            $id=absint(trim((string)$value));
            if ($id)$ids[$id]=$id;
        }
        return array_values($ids);
    }
    public static function available(int $tour,string $group): bool {
        $key=$tour.'|'.$group;
        if (isset(self::$availability[$key]))return self::$availability[$key];
        self::$availability[$key]=false;
        if (!$tour || get_post_type($tour)!=='tours-and-activities'
            || get_post_status($tour)!=='publish' || !isset(self::FLAGS[$group]))return false;
        [$status,$relation,$type]=self::FLAGS[$group];
        if ((string)self::meta($tour,$status)!=='1')return false;
        if ($type==='transportations' && function_exists('service_channel_allowed')
            && !service_channel_allowed('transportations','tour_component'))return false;
        foreach(self::ids(self::meta($tour,$relation)) as $id) {
            if ($id===$tour || get_post_type($id)!==$type
                || get_post_status($id)!=='publish')continue;
            if ($group==='extras') {
                if (!in_array((string)get_post_meta($id,'tour_mode',true),['activity','extra_trip'],true)
                    || (string)get_post_meta($id,'tour_public_status',true)==='0')continue;
            } else {
                if ((string)get_post_meta($id,'trans_available_status',true)!=='1')continue;
                if (function_exists('native_component_selection_public')
                    && !native_component_selection_public($id,'transportations'))continue;
            }
            return self::$availability[$key]=true;
        }
        return false;
    }
    private static function include_nested_styles_from_blocked_template(int $template): void {
        $raw=get_post_meta($template,'_elementor_data',true);
        $items=is_string($raw)?json_decode($raw,true):$raw;
        if (!is_array($items))return;
        $walk=static function($nodes)use(&$walk):void{
            foreach((array)$nodes as $node){
                if(!is_array($node))continue;
                if(($node['widgetType']??'')==='jet-listing-grid'){
                    $listing=absint($node['settings']['lisitng_id']??0);
                    if($listing && get_post_type($listing)==='jet-engine')
                        self::$blocked_nested_css[$listing]=true;
                }
                if(!empty($node['elements']))$walk($node['elements']);
            }
        };
        $walk($items);
    }
    public static function record_visible_listing($widget): void {
        if(!self::tour() || !is_object($widget)
           || !method_exists($widget,'get_name')
           || $widget->get_name()!=='jet-listing-grid')return;
        $settings=$widget->get_settings();
        $id=absint(is_array($settings)?($settings['lisitng_id']??0):0);
        if($id)self::$used_listing_css[$id]=true;
    }
    private static function template_kind(int $template_id): string {
        if (array_key_exists($template_id,self::$template_kinds))
            return self::$template_kinds[$template_id];
        $result='';
        if (get_post_type($template_id)!=='elementor_library'
            || get_post_status($template_id)!=='publish')return self::$template_kinds[$template_id]='';
        $raw=get_post_meta($template_id,'_elementor_data',true);
        if (is_string($raw)) {
            if (str_contains($raw,'booking-component-extras')
                && str_contains($raw,'current_tour_extras'))$result='extras';
            elseif(str_contains($raw,'booking-component-vehicles')
                && str_contains($raw,'booking-component-train')
                && str_contains($raw,'booking-component-air'))$result='transport';
        }
        return self::$template_kinds[$template_id]=$result;
    }
    private static function slot(array $settings): string {
        $classes=preg_split('/\s+/',(string)($settings['_css_classes']??''));
        $provider=(string)($settings['_element_id']??'');
        foreach (self::PROVIDERS as $kind=>$key) {
            if ($provider===$key && in_array('booking-component-'.$kind,$classes,true))
                return $kind;
        }
        return '';
    }
    public static function template_guard($render,$widget) {
        if (!$render || !self::tour() || self::editor() || !is_object($widget)
            || !method_exists($widget,'get_name')
            || $widget->get_name()!=='template')return $render;
        $template_id=absint($widget->get_settings('template_id'));
        $kind=$template_id?self::template_kind($template_id):'';
        $tour=self::tour();
        $allowed=$kind==='extras' ? self::available($tour,'extras')
            : ($kind==='transport' ? (self::available($tour,'vehicles')
                ||self::available($tour,'train')||self::available($tour,'air')) : true);
        if (!$allowed){
            self::$blocked_css[$template_id]=true;
            self::include_nested_styles_from_blocked_template($template_id);
        }
        return $allowed?$render:false;
    }
    public static function widget_guard($render,$widget) {
        if (!$render || !self::tour() || self::editor() || !is_object($widget)
            || !method_exists($widget,'get_name')
            || $widget->get_name()!=='jet-listing-grid')return $render;
        // Authored Elementor settings are sufficient; resolving dynamic tags
        // just to reject an inactive optional grid would waste render time.
        $settings=$widget->get_settings();
        $slot=is_array($settings)?self::slot($settings):'';
        return $slot && !self::available(self::tour(),$slot)?false:$render;
    }
    public static function mode_guard($render,$element) {
        if (!$render || !self::tour() || self::editor() || !is_object($element))return $render;
        $classes=preg_split('/\s+/',(string)$element->get_settings('css_classes'));
        $list=in_array('shared-extra-standard-listing',$classes,true);
        $carousel=in_array('shared-extra-feature-carousel',$classes,true);
        if (!$list && !$carousel)return $render;
        $tour=self::tour();
        if (!self::available($tour,'extras'))return false;
        $mode=(string)self::meta($tour,'extra_options_display_mode');
        return $list && $mode==='carousel' || $carousel && $mode!=='carousel'
            ? false : $render;
    }
    // Optional template CSS may still be enqueued even when Elementor omits
    // the entire authored widget. Do not send styles belonging exclusively to
    // a server-denied template. Other styles, shared listing items and active
    // templates remain untouched (including head/preview styles).
    public static function stylesheet($tag,$handle) {
        if (!$tag || !self::$blocked_css || !is_string($handle))return $tag;
        if (!preg_match('/^elementor-post-([1-9][0-9]*)$/D',$handle,$m))return $tag;
        $id=(int)$m[1];
        if (isset(self::$blocked_css[$id]))return '';
        if (isset(self::$blocked_nested_css[$id])
            && !isset(self::$used_listing_css[$id]))return '';
        return $tag;
    }
    private static function context(): bool {
        if (!self::tour() || self::editor() || wp_doing_ajax()
            || (defined('DOING_CRON')&&DOING_CRON))return false;
        if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET')return false;
        foreach(array_keys((array)$_GET) as $param)
            if ($param!=='mad4b_optional_lazy_canary'
                && !preg_match('/^(utm_[a-z0-9_]+|gclid|fbclid|msclkid)$/i',(string)$param))
                return false;
        $mode=(string)get_option('mad4b_tour_optional_lazy_mode','canary');
        return $mode==='active' || ($mode==='canary' && isset($_GET['mad4b_optional_lazy_canary']));
    }
    private static function lazy_eligible($settings): bool {
        if (!is_array($settings))return false;
        $slot=self::slot($settings);
        if (!$slot || !self::context() || !self::available(self::tour(),$slot))return false;
        if (empty($settings['custom_query'])
            || !filter_var($settings['custom_query'],FILTER_VALIDATE_BOOLEAN)
            || !absint($settings['custom_query_id']??0)
            || !absint($settings['lisitng_id']??0))return false;
        foreach(['is_archive_template','pagination','use_load_more','use_load_more_pagination'] as $flag)
            if (!empty($settings[$flag]) && filter_var($settings[$flag],FILTER_VALIDATE_BOOLEAN))
                return false;
        $listing=absint($settings['lisitng_id']);
        if (get_post_status($listing)!=='publish')return false;
        if (class_exists('MAD4B_Company_Adaptive_Controls',false)
            && MAD4B_Company_Adaptive_Controls::parent_object_context_required())return false;
        if (!class_exists('\Jet_Engine\Query_Builder\Manager'))return false;
        $query=\Jet_Engine\Query_Builder\Manager::instance()
            ->get_query_by_id(absint($settings['custom_query_id']));
        return $query && $query->query_type==='posts';
    }
    public static function native_lazy($enabled,$settings) {
        return $enabled || self::lazy_eligible($settings);
    }
    public static function options($options,$settings) {
        if (is_array($options) && self::lazy_eligible($settings))
            $options['offset']='650px';
        return $options;
    }
}
add_filter('elementor/frontend/widget/should_render',
    ['MAD4B_Tour_Optional_Sections_Lazy_V1','template_guard'],30,2);
add_filter('elementor/frontend/widget/should_render',
    ['MAD4B_Tour_Optional_Sections_Lazy_V1','widget_guard'],31,2);
add_filter('elementor/frontend/container/should_render',
    ['MAD4B_Tour_Optional_Sections_Lazy_V1','mode_guard'],35,2);
add_filter('jet-engine/listing/grid/is_lazy_load',
    ['MAD4B_Tour_Optional_Sections_Lazy_V1','native_lazy'],920,2);
add_filter('jet-engine/listing/grid/lazy-load/options',
    ['MAD4B_Tour_Optional_Sections_Lazy_V1','options'],920,2);

add_filter('style_loader_tag',
    ['MAD4B_Tour_Optional_Sections_Lazy_V1','stylesheet'],999,2);

add_action('elementor/frontend/widget/before_render',
    ['MAD4B_Tour_Optional_Sections_Lazy_V1','record_visible_listing'],5,1);
