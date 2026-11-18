<?php
/**
 * Plugin Name: MAD4B Public Archive Stable CSS Source v1
 * Version: 1.0.0
 * Description: Isolate per-render JetEngine Elementor dynamic CSS from immutable LiteSpeed bundles on published anonymous category archives.
 */
defined('ABSPATH') || exit;
final class MAD4B_Public_Archive_CSS_Source_V1 {
    private static bool $admitted=false;
    public static function admit(): void {
        self::$admitted=false;
        if ((string)wp_parse_url(home_url('/'),PHP_URL_HOST)!=='wayfatours.com'
            || wp_get_environment_type()!=='production'
            || ($_SERVER['REQUEST_METHOD']??'GET')!=='GET'
            || !empty($_SERVER['HTTP_COOKIE'])
            || !empty($_SERVER['HTTP_AUTHORIZATION'])
            || !empty($_SERVER['QUERY_STRING'])
            || is_admin() || wp_doing_ajax() || is_user_logged_in()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || !is_category()
            || get_option('mad4b_public_category_stable_css_source_v1','on')!=='on'
            || (string)get_option('litespeed.conf.optm-css_comb','0')!=='1'
            || (string)get_option('litespeed.conf.optm-css_min','0')!=='1')
            return;
        $term=get_queried_object();
        if (!($term instanceof WP_Term) || $term->taxonomy!=='category'
            || (int)$term->term_id<1)
            return;
        $url=get_term_link($term);
        if (is_wp_error($url)) return;
        $requested=(string)wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
        $canonical=(string)wp_parse_url($url,PHP_URL_PATH);
        if ($canonical==='' || $requested!==$canonical) return;
        self::$admitted=true;
    }
    public static function exclude_volatile($value) {
        if (!self::$admitted) return $value;
        $list=is_array($value)?$value:[];
        if (!in_array('elementor-dcss-',$list,true))
            $list[]='elementor-dcss-';
        return $list;
    }
    public static function readback(): array {
        return ['contract'=>'mad4b.public-archive.stable-css-source.v1',
            'enabled'=>get_option('mad4b_public_category_stable_css_source_v1','on'),
            'source'=>'native-litespeed-css-excludes',
            'route'=>'original-public-category-only',
            'html_cache_policy'=>'unchanged'];
    }
}
add_action('wp',['MAD4B_Public_Archive_CSS_Source_V1','admit'],115);
add_filter('litespeed_optimize_css_excludes',
    ['MAD4B_Public_Archive_CSS_Source_V1','exclude_volatile'],PHP_INT_MAX);
