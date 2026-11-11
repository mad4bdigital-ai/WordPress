<?php
/**
 * Plugin Name: MAD4B Stable Dynamic Listing CSS Identity
 * Description: Deterministic JetEngine listing CSS selectors from object identity without altering vendor code.
 * Version: 0.2.2
 */
defined('ABSPATH') || exit;
final class MAD4B_Stable_Listing_CSS_Identity {
    private static function enabled(): bool {
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)
            || is_user_logged_in() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
            || !class_exists('MAD4B_Experience_Site_Scope_V1',false)
            || !MAD4B_Experience_Site_Scope_V1::bound()) return false;
        if (!class_exists('MAD4B_Shared_First_Paint_Performance', false)
            || !MAD4B_Shared_First_Paint_Performance::home_view()) return false;
        // QA query strings are never public Production feature switches.
        if ((string)($_GET['mad4b_stable_dcss'] ?? '') === '1')
            return wp_get_environment_type()==='staging';
        // The selective-render canary must exercise exactly the same stable
        // selector lifecycle as a future anonymous, cache-enabled request.
        if (wp_get_environment_type()==='staging'
            && count($_GET) === 1 && (string)($_GET['mad4b_public_fragment_qa'] ?? '') === '1'
            && (string)get_option('mad4b_render_public_fragments_enabled', '0') !== '1') return true;
        return get_option('mad4b_perf_stable_dcss_v1','off') === 'on'
            && empty($_SERVER['QUERY_STRING']);
    }
    public static function cache_ready(): bool { return self::enabled(); }
    private static function identifier($object): string {
        if (!is_object($object) || !function_exists('jet_engine')) return '';
        $app = jet_engine();
        if (!isset($app->listings->data)) return '';
        $id = $app->listings->data->get_current_object_id($object);
        if (!is_scalar($id) || (string)$id === '' || strlen((string)$id) > 128) return '';
        // Stable per site, object type and ID; never cache runtime hrtime-based selectors.
        return substr(hash('sha256', get_class($object).':'.(string)$id), 0, 16);
    }
    public static function css_content($content,$listing_id,$inner_templates) {
        if (!self::enabled() || !is_string($content)) return $content;
        $app=jet_engine();
        $object=$app->listings->data->get_current_object();
        $id=self::identifier($object);
        if (!$id) return $content;
        $volatile=$app->listings->data->get_current_object_unique_id($object);
        if (!is_scalar($volatile) || !preg_match('/^[0-9]{5,22}$/D',(string)$volatile)) return $content;
        return str_replace('elementor-dcss-'.(string)$volatile,
            'elementor-dcss-mad4b-'.$id,$content);
    }
    public static function item_classes($classes,$object,$i,$render) {
        if (!self::enabled() || !is_array($classes))return $classes;
        $id=self::identifier($object);
        if (!$id)return $classes;
        $app=jet_engine();
        $volatile=$app->listings->data->get_current_object_unique_id($object);
        if (!is_scalar($volatile) || !preg_match('/^[0-9]{5,22}$/D',(string)$volatile))return $classes;
        $old='elementor-dcss-'.(string)$volatile;
        foreach($classes as &$class){
            if(is_string($class) && $class === $old)$class='elementor-dcss-mad4b-'.$id;
        }
        unset($class);
        return $classes;
    }
    public static function normalize_buffer($buffer) {
        if (!self::enabled() || !is_string($buffer) || !str_contains($buffer,'elementor-dcss-'))return $buffer;
        $map=[];
        // Restrict to actual JetEngine listing-item classes and verify the
        // following data-post-id. Do not normalize unrelated inline styles.
        if (!preg_match_all('/jet-listing-dynamic-post-([0-9]{1,12})\\s+elementor-dcss-([0-9]{10,22})(?=[\\s"\\x27>])/D',
            $buffer,$matches,PREG_OFFSET_CAPTURE))return $buffer;
        foreach ($matches[2] as $i=>$match) {
            $volatile=$match[0];
            if (isset($map[$volatile]))continue;
            $postId=$matches[1][$i][0];
            $tail=substr($buffer,$matches[0][$i][1],430);
            if (!preg_match('/\\bdata-post-id=["\\x27]'.preg_quote($postId,'/').'["\\x27]/D',$tail))continue;
            $template='listing';
            if (preg_match('/\\bdata-elementor-id=["\\x27]([0-9]{1,12})["\\x27]/',$tail,$m))$template=$m[1];
            $map[$volatile]='elementor-dcss-mad4b-'.substr(hash('sha256',$postId.':'.$template),0,16);
        }
        if (!$map)return $buffer;
        $replace=[];
        foreach ($map as $volatile=>$stable)$replace['elementor-dcss-'.$volatile]=$stable;
        return strtr($buffer,$replace);
    }
    public static function boot(): void {
        add_filter('jet-engine/elementor-views/frontend/listing-content',[__CLASS__,'css_content'],9999,3);
        add_filter('jet-engine/listing/item-classes',[__CLASS__,'item_classes'],9999,4);
        add_filter('litespeed_buffer_before',[__CLASS__,'normalize_buffer'],10000,1);
    }
}
add_action('init',['MAD4B_Stable_Listing_CSS_Identity','boot'],30);
