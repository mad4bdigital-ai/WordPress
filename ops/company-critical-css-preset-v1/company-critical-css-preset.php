<?php
/**
 * Company Performance Preset: staging-only, revision-pinned noncritical CSS and
 * LiteSpeed WebFontLoader deferral. Default disabled for new tenants.
 *
 * Never defers jQuery, JetSmartFilters, WooCommerce, WPML or other JS libraries.
 */
defined('ABSPATH') || exit;

if (!function_exists('mad4b_company_critical_css_context')) {
    function mad4b_company_critical_css_context(): array {
        // Do not cache a failed determination before WordPress initializes.
        if (PHP_SAPI === 'cli' || wp_get_environment_type() !== 'staging'
            || !did_action('wp') || is_admin() || wp_doing_ajax()
            || is_preview() || is_user_logged_in()
            || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
            || !empty($_SERVER['QUERY_STRING'])) return [];
        static $reviewed = null;
        if ($reviewed !== null) return $reviewed;
        $reviewed = [];
        $file=__DIR__.'/company-critical-css-preset.json';
        if (!is_readable($file)) return [];
        $cfg=json_decode((string)@file_get_contents($file),true);
        if (!is_array($cfg)
            || ($cfg['contract'] ?? '') !== 'mad4b.company-critical-css-preset.v1'
            || ($cfg['enabled'] ?? false) !== true
            || ($cfg['environment'] ?? '') !== 'staging'
            || !is_array($cfg['handles'] ?? null)
            || !is_array($cfg['routes'] ?? null)
            || !is_array($cfg['document_guard'] ?? null)
            || !is_bool($cfg['defer_webfontloader'] ?? null)) return [];
        $host=strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
        if ($host==='' || $host!==strtolower((string)($cfg['site_host']??'')) ||
            strtolower((string)($_SERVER['HTTP_HOST']??''))!==$host) return [];
        if (!defined('UAEL_VER') || (string)UAEL_VER!==(string)($cfg['uael_version']??'')) return [];
        $route=(string)wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
        $lang=(string)apply_filters('wpml_current_language','');
        $found=false;
        foreach($cfg['routes'] as $entry){
            if (!is_array($entry) || $route!==($entry['path']??null)
                || $lang!==($entry['language']??null)
                || (int)get_queried_object_id()!==(int)($entry['page_id']??0)) continue;
            $found=true;
            break;
        }
        if (!$found) return [];
        // Fail closed on any revised Home or nested Elementor source.
        foreach($cfg['document_guard'] as $id=>$hash){
            if (!ctype_digit((string)$id) || !is_string($hash) ||
                !preg_match('/^[a-f0-9]{64}$/D',$hash)) return [];
            $doc=get_post((int)$id);
            if (!$doc || $doc->post_status!=='publish'
                || !hash_equals($hash,hash('sha256',(string)get_post_meta((int)$id,'_elementor_data',true)))) return [];
        }
        return $reviewed=$cfg;
    }
}
add_filter('style_loader_tag',static function($tag,$handle,$href,$media) {
    if ($handle!=='uael-frontend') return $tag;
    if (($_SERVER['HTTP_X_MAD4B_CRITICAL_CSS_AB']??'')==='control') return $tag;
    $cfg=mad4b_company_critical_css_context();
    if (!$cfg || !in_array($handle,$cfg['handles'],true)) return $tag;
    $host=strtolower((string)($cfg['site_host']??''));
    if (strtolower((string)wp_parse_url($href,PHP_URL_HOST))!==$host)return $tag;
    $asset=(string)wp_parse_url($href,PHP_URL_PATH);
    if (!preg_match('~^/wp-content/(?:litespeed/css/[a-f0-9]{32}\.css|plugins/ultimate-elementor/.+\.css)$~D',$asset))return $tag;
    if (!preg_match('/\brel=["\']stylesheet["\']/', $tag))return $tag;
    $url=esc_url($href);
    $id=esc_attr($handle.'-css');
    return '<link rel="stylesheet" id="'.$id.'" href="'.$url.
       '" media="print" onload="this.onload=null;this.media=\'all\'">'."\n".
       '<noscript><link rel="stylesheet" href="'.$url.'" media="all"></noscript>'."\n";
},10,4);

add_filter('litespeed_optm_html_head',static function($html) {
    if (($_SERVER['HTTP_X_MAD4B_WEBFONT_AB']??'')==='control') return $html;
    $cfg=mad4b_company_critical_css_context();
    if (!$cfg || !$cfg['defer_webfontloader'] || !is_string($html)) return $html;
    // LiteSpeed's supported HTML-head optimization filter; no vendor edit.
    $host=preg_quote('https://'.$cfg['site_host'],'~');
    $pattern='~(<script\b[^>]*\bsrc="'.$host.'/wp-content/plugins/litespeed-cache/assets/js/webfontloader\.min\.js")(\s*></script>)~i';
    return preg_replace($pattern,'$1 defer$2',$html,1);
},20);
