<?php
/**
 * Plugin Name: MAD4B Verified Private-Session Critical CSS
 * Version: 0.1.0
 * Description: Exact-bundle-only private mobile critical CSS, independent of FastCGI HTML admission. Off until separately certified.
 */
defined('ABSPATH') || exit;

final class MAD4B_Private_Session_Critical_CSS_V1 {
    private const OPT = 'mad4b_private_session_critical_css_v1';
    private const BASE = '/mad4b-private-session-critical-css-v1';
    private const MAX_CSS_BYTES = 250000;
    private static bool $rewritten = false;

    public static function eligible(): bool {
        if (get_option(self::OPT, 'off') !== 'on'
            || wp_get_environment_type() !== 'production'
            || strtolower((string)wp_parse_url((string)get_option('home', ''), PHP_URL_HOST)) !== 'wayfatours.com'
            || (string)($_SERVER['MAD4B_TRUSTED_DEVICE_CLASS'] ?? '') !== 'mobile'
            || (string)($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
            || (string)($_SERVER['REQUEST_URI'] ?? '') !== '/'
            || !empty($_SERVER['QUERY_STRING'])
            || empty($_SERVER['HTTP_COOKIE'])
            || !is_user_logged_in()
            || is_admin() || wp_doing_ajax() || is_preview() || is_feed()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || !class_exists('MAD4B_Shared_First_Paint_Performance',false)
            || !MAD4B_Shared_First_Paint_Performance::home_view()
            || !class_exists('MAD4B_Experience_Site_Scope_V1',false)
            || !MAD4B_Experience_Site_Scope_V1::bound()) return false;
        // Do not move user-specific inline scripts, forms or checkout markup
        // into a publicly cached HTML response. These are CSS-only gates.
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])
            || !empty($_SERVER['HTTP_X_WP_NONCE'])
            || !empty($_SERVER['HTTP_X_MAD4B_CURRENCY'])
            || !empty($_SERVER['HTTP_X_MAD4B_MARKET_CONTEXT'])
            || !empty($_SERVER['HTTP_X_GEO_COUNTRY'])
            || !empty($_SERVER['HTTP_CF_IPCOUNTRY'])) return false;
        if (function_exists('WC') && WC() && WC()->session
            && method_exists(WC()->session,'has_session') && WC()->session->has_session())
            return false;
        return true;
    }

    private static function policy(): array {
        $path=WP_CONTENT_DIR.self::BASE.'/manifest.json';
        if (is_link($path) || !is_file($path) || @filesize($path)>16384) return [];
        $data=json_decode((string)@file_get_contents($path),true);
        if (!is_array($data)
            || ($data['contract']??'')!=='mad4b.private-session-critical-css.v1'
            || ($data['site_host']??'')!=='wayfatours.com'
            || ($data['environment']??'')!=='production'
            || !is_array($data['entries']??null)) return [];
        return $data;
    }

    public static function certified_asset(string $bundle, string $sha, string $locale): ?string {
        if (!preg_match('/^[a-f0-9]{32}\.css$/D',$bundle)
            || !preg_match('/^[a-f0-9]{64}$/D',$sha)
            || !preg_match('/^[a-z]{2}$/D',$locale)) return null;
        $data=self::policy();
        $key=hash('sha256','wayfatours.com|/|'.$locale.'|mobile|authenticated|'.$sha);
        $entry=$data['entries'][$key]??null;
        if (!is_array($entry)
            || ($entry['route']??'')!=='/'
            || ($entry['language']??'')!==$locale
            || ($entry['device']??'')!=='mobile'
            || ($entry['privacy_scope']??'')!=='authenticated'
            || ($entry['source_file']??'')!==$bundle
            || ($entry['source_sha256']??'')!==$sha
            || ($entry['state']??'')!=='certified'
            || ($entry['browser_visual_qa']??'')!=='pass'
            || ($entry['interaction_qa']??'')!=='pass'
            || !in_array(390,(array)($entry['viewports']??[]),true)
            || !in_array(768,(array)($entry['viewports']??[]),true)
            || !is_string($entry['css_file']??null)
            || !preg_match('/^[a-f0-9]{64}\.css$/D',$entry['css_file'])
            || !is_string($entry['critical_sha256']??null)
            || !preg_match('/^[a-f0-9]{64}$/D',$entry['critical_sha256'])) return null;
        $path=WP_CONTENT_DIR.self::BASE.'/'.$entry['css_file'];
        if (is_link($path) || !is_file($path)
            || @filesize($path)<10000 || @filesize($path)>self::MAX_CSS_BYTES
            || !hash_equals($entry['critical_sha256'],(string)@hash_file('sha256',$path)))
            return null;
        return $path;
    }

    public static function transform($html) {
        if (!is_string($html) || !self::eligible()
            || strlen($html)<25000 || strlen($html)>6000000
            || !str_contains($html,'</head>')
            || str_contains($html,'id="mad4b-critical-css-v1"')
            || str_contains($html,'id="mad4b-private-critical-css-v1"')) return $html;
        preg_match_all('~<link\b[^>]*>~i',$html,$all,PREG_OFFSET_CAPTURE);
        $matches=[];
        foreach (($all[0]??[]) as [$tag,$pos]) {
            if (!preg_match('~\brel=["\x27]stylesheet["\x27]~i',$tag)
                || !preg_match('~\bhref=["\x27]([^"\x27]+)["\x27]~i',$tag,$m)) continue;
            $url=html_entity_decode($m[1],ENT_QUOTES,'UTF-8');
            if (strcasecmp((string)wp_parse_url($url,PHP_URL_HOST),'wayfatours.com')!==0) continue;
            $path=(string)wp_parse_url($url,PHP_URL_PATH);
            if (preg_match('~^/wp-content/litespeed/css/([a-f0-9]{32}\.css)$~D',$path,$name))
                $matches[]=[$tag,$pos,$url,$name[1]];
        }
        if (count($matches)!==1) return $html;
        [$originalTag,$pos,$url,$name]=$matches[0];
        $file=WP_CONTENT_DIR.'/litespeed/css/'.$name;
        if (is_link($file) || !is_file($file) || @filesize($file)>6291456) return $html;
        $sha=@hash_file('sha256',$file);
        if (!is_string($sha)) return $html;
        $cssFile=self::certified_asset($name,$sha,(string)apply_filters('wpml_current_language',''));
        if ($cssFile===null) return $html; // Always fall back to native, blocking CSS.
        $css=(string)@file_get_contents($cssFile);
        if ($css==='' || stripos($css,'</style')!==false) return $html;
        $safe=esc_url($url);
        $markup='<style id="mad4b-private-critical-css-v1">'.$css.'</style>';
        $markup.='<link id="mad4b-private-css-rest-v1" rel="stylesheet" href="'.$safe.'" media="print" fetchpriority="low" onload="this.onload=null;this.media=&#39;all&#39;" />';
        $markup.='<noscript>'.$originalTag.'</noscript>';
        $markup.='<script data-no-optimize="1">(function(){var e=document.getElementById("mad4b-private-css-rest-v1");if(!e)return;window.addEventListener("load",function(){if(e.media!=="all")e.media="all"},{once:true});setTimeout(function(){if(e.media!=="all")e.media="all"},6500)})()</script>';
        self::$rewritten=true;
        return substr_replace($html,$markup,$pos,strlen($originalTag));
    }

    public static function certify($html) {
        if (!self::$rewritten || !is_string($html) || headers_sent()) return $html;
        $ready=substr_count($html,'id="mad4b-private-critical-css-v1"')===1
            && substr_count($html,'id="mad4b-private-css-rest-v1"')===1;
        // This *never* authorizes the shared HTML cache.
        header_remove('X-Wayfa-Public-Page');
        header_remove('X-MAD4B-Cache-Eligibility');
        header_remove('X-MAD4B-Browser-HTML');
        header('Cache-Control: private, no-store, max-age=0',true);
        header('X-Accel-Expires: 0',true);
        header('X-MAD4B-Private-CSS-Ready: '.($ready?'1':'0'),true);
        return $html;
    }
    public static function boot(): void {
        add_filter('litespeed_buffer_finalize',[__CLASS__,'transform'],11900,1);
        add_filter('litespeed_buffer_finalize',[__CLASS__,'certify'],13200,1);
    }
}
add_action('init',['MAD4B_Private_Session_Critical_CSS_V1','boot'],33);
