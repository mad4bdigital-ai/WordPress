<?php
/**
 * Plugin Name: MAD4B Private Mobile Critical CSS Family
 * Version: 1.0.0
 * Description: Verified first-paint family for private, signed visitor preference pages; never a shared-cache permission.
 */
defined('ABSPATH') || exit;

final class MAD4B_Private_Mobile_CSS_Family_V1 {
    private const POLICY = '/mad4b-private-mobile-css-family-v1.json';
    private const PREFIX_BYTES = 2500000;
    private static bool $changed = false;
    private static ?string $before = null;

    private static function private_cookie_allowed(): bool {
        $raw = (string) ($_SERVER['HTTP_COOKIE'] ?? '');
        if ($raw === '' || strlen($raw) > 5000 || preg_match('/[\x00-\x1f\x7f]/', $raw)) return false;
        $found = ['country'=>false,'passport'=>false];
        $seen=[];
        foreach (explode(';', $raw) as $pair) {
            if (!str_contains($pair, '=')) return false;
            [$name, $value] = explode('=', trim($pair), 2);
            if (isset($seen[$name]) || $name === '' || strlen($value) > 1600) return false;
            $seen[$name]=true;
            if ($name==='mad4b_country_selected_v1') {
                if (!class_exists('MAD4B_Country_Switcher',false)
                    || !MAD4B_Country_Switcher::saved_country()) return false;
                $found['country']=true; continue;
            }
            if ($name==='travel_booking_nationality_preference_v1') {
                if (!class_exists('Travel_Booking_Traveller_Nationality_Switcher',false)
                    || !Travel_Booking_Traveller_Nationality_Switcher::selected_manual()) return false;
                $found['passport']=true; continue;
            }
            // These cookies cannot authorize a personalized public-cache entry.
            // They are ignored solely for private-CSS eligibility.
            if ($name==='wp-wpml_current_language' || $name==='mad4b_locale_seen'
                || preg_match('/\A(?:sbjs_(?:migrations|current_add|first_add|current|first|udata|session)|_ga(?:_[A-Za-z0-9]+)?|_gid|_gat(?:_[A-Za-z0-9]+)?|_gcl_(?:au|aw|dc|gb)|_fbp|_fbc|_clck|_clsk)\z/D', $name))
                continue;
            // Woo cart/session, logged-in, private-price, credentials, and unknown
            // visitor cookies never enter this narrow CSS family.
            return false;
        }
        return $found['country'] || $found['passport'];
    }

    private static function eligible(): bool {
        if ((string)($_SERVER['MAD4B_TRUSTED_DEVICE_CLASS'] ?? '') !== 'mobile'
            || (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET')
            || (string)($_SERVER['QUERY_STRING'] ?? '') !== ''
            || is_admin() || wp_doing_ajax() || is_user_logged_in()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || (defined('WP_CLI') && WP_CLI)
            || !function_exists('wp_is_mobile') || !wp_is_mobile()
            || !function_exists('is_front_page') || !is_front_page()
            || !class_exists('MAD4B_Shared_First_Paint_Performance',false)
            || !MAD4B_Shared_First_Paint_Performance::home_view()
            || !class_exists('MAD4B_Mobile_CSS_Inline_Contract_V1',false)
            || !MAD4B_Mobile_CSS_Inline_Contract_V1::active()
            || !empty($_SERVER['HTTP_AUTHORIZATION'])
            || !empty($_SERVER['HTTP_X_WP_NONCE'])
            || !empty($_SERVER['HTTP_X_MAD4B_MARKET_CONTEXT'])
            || !empty($_SERVER['HTTP_X_MAD4B_CURRENCY'])
            || !self::private_cookie_allowed()) return false;
        if (function_exists('WC') && WC() && WC()->session
            && method_exists(WC()->session,'has_session') && WC()->session->has_session()) return false;
        return true;
    }

    private static function family(string $locale): ?array {
        // Each company's WPML active language list defines eligible locales.
        // The manifest still has to carry a signed/verified source family for
        // the exact locale: adding a language never inherits another's CSS.
        if (!preg_match('/^[a-z]{2,8}(?:[-_][a-z0-9]{2,8})?$/Di',$locale))return null;
        $wpml=get_option('icl_sitepress_settings',[]);
        if (!is_array($wpml)
            || !in_array($locale,(array)($wpml['active_languages']??[]),true)
            || in_array($locale,(array)($wpml['hidden_languages']??[]),true))return null;
        $policy = WPMU_PLUGIN_DIR . self::POLICY;
        if (is_link($policy) || !is_file($policy) || @filesize($policy) > 8192) return null;
        $doc = json_decode((string)@file_get_contents($policy), true);
        if (!is_array($doc) || ($doc['contract']??'') !== 'mad4b.private-mobile-css-family.v1'
            || ($doc['status']??'') !== 'certified'
            || ($doc['prefix_bytes']??0) !== self::PREFIX_BYTES
            || ($doc['css_epoch']??null)!==(int)get_option('mad4b_css_ai_epoch_v1',0)
            || ($doc['home_dirty_generation']??null)!==(int)get_option('mad4b_critical_css_home_dirty_v1',0)
            || ($doc['site_host']??'') !== strtolower((string)wp_parse_url((string)get_option('home',''),PHP_URL_HOST))
            || ($doc['qa_viewports']??null) !== [320,390,414,767]
            || !is_array($doc['families'][$locale]??null)) return null;
        $row=$doc['families'][$locale];
        foreach (['source_sha','prefix_sha','critical_sha'] as $f) {
            if (!is_string($row[$f]??null) || !preg_match('/^[a-f0-9]{64}$/D',$row[$f]))return null;
        }
        if (!is_string($row['critical_file']??null)
            || !preg_match('/^[a-f0-9]{64}\.css$/D',$row['critical_file'])
            || !is_string($row['public_css_file']??null)
            || !preg_match('/^[a-f0-9]{32}\.css$/D',$row['public_css_file']))return null;
        // The original public Critical CSS gate is the provenance of this
        // viewport-level candidate; no case-specific cookie or market value
        // may grant CSS equivalence.
        $manifestPath=WP_CONTENT_DIR.'/mad4b-critical-css-v1/manifest.json';
        if (is_link($manifestPath) || !is_file($manifestPath) || @filesize($manifestPath)>32768)return null;
        $manifest=json_decode((string)@file_get_contents($manifestPath),true);
        if (!is_array($manifest) || ($manifest['version']??0)!==1
            || ($manifest['site']??'')!==($doc['site_host']??'')) return null;
        $entry=$manifest['entries'][$row['source_sha']]??null;
        if (!is_array($entry) || ($entry['gate_status']??'')!=='pass'
            || !in_array($locale,(array)($entry['languages']??[]),true)
            || !in_array('mobile',(array)($entry['devices']??[]),true)
            || ($entry['file']??null)!==$row['critical_file']
            || ($entry['critical_sha']??null)!==$row['critical_sha'])return null;

        $baseCSS=WP_CONTENT_DIR.'/litespeed/css/'.$row['public_css_file'];
        if (!is_file($baseCSS) || is_link($baseCSS))
            $baseCSS=WP_CONTENT_DIR.'/litespeed/.mad4b-asset-retention/css/'.$row['public_css_file'];
        if (!is_file($baseCSS) || is_link($baseCSS) || filesize($baseCSS) < self::PREFIX_BYTES
            || hash_file('sha256',$baseCSS)!==$row['source_sha'])return null;
        $prefix=@file_get_contents($baseCSS,false,null,0,self::PREFIX_BYTES);
        if (!is_string($prefix) || strlen($prefix)!==self::PREFIX_BYTES
            || hash('sha256',$prefix)!==$row['prefix_sha'])return null;
        $critical=WP_CONTENT_DIR.'/mad4b-critical-css-v1/'.$row['critical_file'];
        if (is_link($critical) || !is_file($critical)
            || filesize($critical)<16000 || filesize($critical)>1048576
            || hash_file('sha256',$critical)!==$row['critical_sha'])return null;
        return ['prefix'=>$row['prefix_sha'],'critical'=>$critical];
    }

    public static function transform($html) {
        self::$changed=false;self::$before=null;
        if (!self::eligible() || !is_string($html) || strlen($html)<25000
            || strlen($html)>6000000 || !str_contains($html,'</head>')
            || str_contains($html,'id="mad4b-critical-css-v1"')
            || str_contains($html,'id="mad4b-css-rest-v1"')) return $html;
        $locale=(string)apply_filters('wpml_current_language','');
        $family=self::family($locale);
        if (!$family) return $html;
        preg_match_all('~<link\b[^>]*>~i',$html,$links,PREG_OFFSET_CAPTURE);
        $matches=[];
        foreach (($links[0]??[]) as [$tag,$position]) {
            if (!preg_match('~\brel=["\\x27]stylesheet["\\x27]~i',$tag)
                || !preg_match('~\bhref=["\\x27]([^"\\x27]+)["\\x27]~i',$tag,$out))continue;
            $url=html_entity_decode($out[1],ENT_QUOTES,'UTF-8');
            $host=(string)wp_parse_url($url,PHP_URL_HOST);
            $site=(string)wp_parse_url((string)get_option('home',''),PHP_URL_HOST);
            if ($site===''||strcasecmp($host,$site)!==0)continue;
            $path=(string)wp_parse_url($url,PHP_URL_PATH);
            if (!preg_match('~^/wp-content/litespeed/css/([a-f0-9]{32}\.css)$~D',$path,$file))continue;
            if (preg_match('~\bonload\s*=~i',$tag)
                || preg_match('~\bmedia=["\\x27](?!all["\\x27])~i',$tag))return $html;
            $matches[]=[$tag,$position,$url,$file[1]];
        }
        if (count($matches)!==1)return $html;
        [$tag,$position,$url,$file]=$matches[0];
        $css=WP_CONTENT_DIR.'/litespeed/css/'.$file;
        if (!is_file($css) || is_link($css))$css=WP_CONTENT_DIR.'/litespeed/.mad4b-asset-retention/css/'.$file;
        if (!is_file($css) || is_link($css)
            || filesize($css)<self::PREFIX_BYTES || filesize($css)>6291456)return $html;
        $first=@file_get_contents($css,false,null,0,self::PREFIX_BYTES);
        if (!is_string($first)||strlen($first)!==self::PREFIX_BYTES
            || !hash_equals($family['prefix'],hash('sha256',$first)))return $html;
        // No cross-origin, user-generated URL, or arbitrary dynamic CSS is
        // promoted. The prefix must be identical to a certified source file.
        $loader=MAD4B_Mobile_CSS_Inline_Contract_V1::inline_loader('mad4b-css-rest-v1');
        if ($loader==='')return $html;
        $critical=(string)@file_get_contents($family['critical']);
        if ($critical===''||stripos($critical,'</style')!==false
            || stripos($critical,'<script')!==false)return $html;
        $safe=esc_url($url);
        $markup='<style id="mad4b-critical-css-v1">'.$critical.'</style>'
            .'<link id="mad4b-css-rest-v1" rel="stylesheet" href="'.$safe
            .'" media="print" fetchpriority="low" onload="this.onload=null;this.media=&#39;all&#39;">'
            .'<noscript>'.$tag.'</noscript>'.$loader;
        self::$changed=true;
        self::$before=$html;
        if (!headers_sent()) {
            header('Cache-Control: private, no-store, max-age=0',true);
            header('X-Accel-Expires: 0',true);
        }
        return substr_replace($html,$markup,$position,strlen($tag));
    }

    public static function readback($html) {
        if (!self::$changed)return $html;
        $complete=is_string($html)
            && substr_count($html,'id="mad4b-critical-css-v1"')===1
            && substr_count($html,'id="mad4b-css-rest-v1"')===1
            && substr_count($html,'data-mad4b-css-async-lib="inline-v1"')===1
            && str_contains($html,'media="print"')
            && str_contains($html,'/wp-content/litespeed/css/');
        if (!headers_sent()) {
            header('Cache-Control: private, no-store, max-age=0',true);
            header('X-Accel-Expires: 0',true);
            header('X-MAD4B-Private-Mobile-CSS-Ready: '.($complete?'1':'0'),true);
        }
        $fallback=self::$before;
        self::$before=null;
        self::$changed=false;
        return $complete?$html:($fallback??$html);
    }
    public static function boot(): void {
        add_filter('litespeed_buffer_finalize',[__CLASS__,'transform'],12700,1);
        add_filter('litespeed_buffer_finalize',[__CLASS__,'readback'],12990,1);
    }
}
add_action('init',['MAD4B_Private_Mobile_CSS_Family_V1','boot'],32);
