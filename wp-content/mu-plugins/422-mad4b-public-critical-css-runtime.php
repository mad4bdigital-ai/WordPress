<?php
/**
 * Plugin Name: MAD4B Verified Public Page Critical CSS
 * Version: 1.0.1
 * Description: Apply certified, content-addressed first-paint CSS on exactly matching anonymous public WPML routes.
 */
defined('ABSPATH') || exit;

final class MAD4B_Public_Critical_CSS_Runtime_V1 {
    private const OPT = 'mad4b_perf_critical_css_all_public_v1';
    private const REL = '/mad4b-public-critical-css-v1';
    private const MAX_CSS = 1048576;
    private static bool $verified_request = false;
    /**
     * Percent escape HEX casing has no URI semantic meaning. WordPress
     * catalogs can store %d8... while browsers/origin URI serializers send
     * %D8... for the same native WPML Arabic route. Normalize only those
     * escape hex digits, not the actual slug/case/market or URL decoded
     * bytes. Every signed cert still requires exact object, currency,
     * trusted market, CSS SHA, Elementor graph and five original QA views.
     */
    public static function normalized_escaped_path(string $path): string {
        return (string)preg_replace_callback('/%[A-Fa-f0-9]{2}/',
            static fn(array $m): string => strtolower($m[0]),$path);
    }
    private static function diagnostic(string $code): void {
        if (function_exists('wp_get_environment_type')
            && wp_get_environment_type()==='staging'
            && get_option('mad4b_perf_public_css_debug_v1','off')==='on'
            && (string)($_SERVER['REQUEST_URI']??'')==='/contact-us/'
            && !headers_sent()) {
            header('X-MAD4B-Public-CCSS-Diagnostic: '.$code,true);
        }
    }


    private static function content_has_market_deps(string $html): bool {
        $body=stripos($html,'<body');
        if ($body!==false) $html=substr($html,$body);
        foreach (['jet-listing-grid','woocommerce-Price-amount',
            'mad4b-pricing','mad4b-rate','tour-rate','data-price='] as $token)
            if (str_contains($html,$token)) return true;
        return false;
    }
    private static function context(bool $hasMarketDependentHTML=false): ?array {
        if (get_option(self::OPT,'off')!=='on'
            || (string)($_SERVER['MAD4B_TRUSTED_DEVICE_CLASS']??'')!=='mobile'
            || is_admin() || wp_doing_ajax() || is_user_logged_in()
            || (defined('REST_REQUEST') && REST_REQUEST)
            || (string)($_SERVER['REQUEST_METHOD']??'GET')!=='GET'
            || !empty($_SERVER['QUERY_STRING'])
            || get_option('litespeed.conf.optm-css_async','0')==='1')
            return null;
        if (!empty($_SERVER['HTTP_COOKIE'])
            && (!class_exists('MAD4B_Public_Cookie_Cache_Contract_V1',false)
                || !MAD4B_Public_Cookie_Cache_Contract_V1::allow(
                    (string)$_SERVER['HTTP_COOKIE']))) return null;
        if (!class_exists('MAD4B_Public_Critical_CSS_Coverage_V1',false)
            || !MAD4B_Public_Critical_CSS_Coverage_V1::authorized()) return null;
        // Published posts protected by a WordPress password are never
        // anonymous public pages, even when a browser sends a valid
        // post-password cookie. Do not depend on the visitor's password
        // success to determine the shared certificate namespace.
        if (function_exists('is_preview') && is_preview()) return null;
        if (is_singular(['page','post','tours-and-activities','properties'])) {
            $post=get_post((int)get_queried_object_id());
            if (!is_object($post) || (string)($post->post_status??'')!=='publish'
                || (string)($post->post_password??'')!=='') return null;
        }
        if (is_front_page() || is_home() || function_exists('is_checkout') && is_checkout()
            || function_exists('is_cart') && is_cart()
            || function_exists('is_account_page') && is_account_page()
            || function_exists('is_wc_endpoint_url') && is_wc_endpoint_url()) return null;

        $type='';$id=(int)get_queried_object_id();
        $commerce=false;
        if (is_singular(['page','post','tours-and-activities','properties'])) {
            $type=(string)get_post_type($id);
            if (!in_array($type,['page','post','tours-and-activities','properties'],true)) return null;
            $commerce=in_array($type,['tours-and-activities','properties'],true);
        }elseif (is_tax(['destinations','tour-types','property-types','tour-categories'])
            || is_category()) {
            $tax=get_queried_object();
            $type=is_object($tax)?(string)($tax->taxonomy??''):'';
            if (!in_array($type,['destinations','tour-types','property-types','tour-categories','category'],true))
                return null;
            $commerce=true;
        }elseif (is_post_type_archive(['tours-and-activities','properties'])) {
            $object=get_queried_object();
            $type='archive:'.(is_object($object)?(string)($object->name??''):'');
            $id=0;
            $commerce=true;
        }else return null;

        $host=strtolower((string)wp_parse_url((string)get_option('home',''),PHP_URL_HOST));
        $site=['tours.dev.mad4b.com','wayfatours.com'];
        if (!in_array($host,$site,true)) return null;
        $path=self::normalized_escaped_path((string)wp_parse_url(
            (string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH));
        if ($path==='' || !MAD4B_Public_Critical_CSS_Coverage_V1::allowed_path($path)) return null;
        $language=(string)apply_filters('wpml_current_language',null);
        if (!preg_match('/^[a-z]{2}$/D',$language)) return null;
        // Context-specific prices, availability and market-sensitive listings
        // are not globally certified when the original server cannot prove
        // an independent trusted market dimension. Never trust a query/cookie.
        $currency=function_exists('get_woocommerce_currency')
            ? (string)get_woocommerce_currency() : 'NONE';
        if (!preg_match('/^[A-Z]{3}$|^NONE$/D',$currency)) return null;
        $marketCountry='static';
        if ($commerce || $hasMarketDependentHTML) {
            // The platform's existing WCML/Geo first-party identity is the
            // sole authority, never incoming X-Country or cart query strings.
            if (!function_exists('mad4b_render_query_market_identity'))return null;
            $market=mad4b_render_query_market_identity();
            if (!is_array($market) || ($market['currency']??'')!==$currency)return null;
            $marketCountry=(string)($market['country']??'');
            if ($marketCountry==='base') {
                if ((function_exists('wcml_is_multi_currency_on')
                        && wcml_is_multi_currency_on())
                    || $currency!==(string)get_option('woocommerce_currency',''))
                    return null;
            }elseif (!preg_match('/^[A-Z]{2}$/D',$marketCountry))return null;
        }
        return ['host'=>$host,'path'=>$path,'language'=>$language,'type'=>$type,
            'object_id'=>$id,'market_country'=>$marketCountry,
            'currency'=>$currency,'css_epoch'=>(int)get_option('mad4b_css_ai_epoch_v1',0)];
    }

    private static function certificate(array $ctx): ?array {
        // The same public WPML route may produce different CSS/layout for
        // each WCML currency and trusted country. Route-only keys are unsafe.
        $key=hash('sha256',$ctx['host'].'|'.$ctx['language'].'|'.$ctx['path']
            .'|'.$ctx['market_country'].'|'.$ctx['currency']);
        $file=WP_CONTENT_DIR.self::REL.'/certs/'.$key.'.json';
        if (is_link($file) || !is_file($file) || filesize($file)>8192) {
            self::diagnostic('no-certificate-file-'.substr($key,0,8));
            return null;
        }
        $json=json_decode((string)@file_get_contents($file),true);
        if (!is_array($json)) {
            self::diagnostic('invalid-certificate-json');
            return null;
        }
        if (is_array($json)) {
            foreach (['host','path','language','type','object_id',
                     'market_country','currency','css_epoch'] as $field) {
                if (($json[$field]??null)!==($ctx[$field]??null)) {
                    self::diagnostic('mismatch-'.$field);
                    return null;
                }
            }
        }
        if (is_array($json) && ($json['currency']??'')!==$ctx['currency']) {
            self::diagnostic('currency-'.preg_replace('/[^A-Z]/','', $ctx['currency']));
            return null;
        }
        if (!is_array($json)
            || ($json['contract']??'')!=='mad4b.public-critical-css.route-certificate.v1'
            || ($json['status']??'')!=='certified'
            || ($json['host']??'')!==$ctx['host']
            || ($json['path']??'')!==$ctx['path']
            || ($json['language']??'')!==$ctx['language']
            || ($json['type']??'')!==$ctx['type']
            || ($json['object_id']??null)!==$ctx['object_id']
            || ($json['market_country']??'')!==$ctx['market_country']
            || ($json['currency']??'')!==$ctx['currency']
            || ($json['css_epoch']??null)!==$ctx['css_epoch']) {
            self::diagnostic('certificate-contract');
            return null;
        }
        // Any change to this first-party native HTML transformer invalidates
        // the previous Chromium browser proof even when the CSS bundle
        // and post revisions are unchanged. This prevents pre-upgrade CSS
        // certificates from silently being reused after runtime updates.
        $certifiedRuntime=$json['runtime_sha']??null;
        if (!is_string($certifiedRuntime)
            || !preg_match('/^[a-f0-9]{64}$/D',$certifiedRuntime)
            || !hash_equals($certifiedRuntime,(string)hash_file('sha256',__FILE__))) {
            self::diagnostic('runtime-sha');
            return null;
        }
        // Event generations prove that no source entity or first-screen
        // listing changed after the Chromium certificate was issued.
        // This is independent of the CSS SHA: image/itinerary data can change
        // LCP while all CSS bytes remain identical.
        $deps=$json['dependencies']??null;
        if (!is_array($deps)) {self::diagnostic('missing-deps');return null;}
        if ((int)($deps['global_generation']??-1)!==
                (int)get_option('mad4b_ccss_global_generation_v1',0)) {
            self::diagnostic('global-deps');return null;
        }
        if (!is_array($deps)
            || (int)($deps['global_generation']??-1)!==
                (int)get_option('mad4b_ccss_global_generation_v1',0))
            return null;
        $postRev=in_array($ctx['type'],['page','post','tours-and-activities','properties'],true)
            ? (int)get_post_meta($ctx['object_id'],'_mad4b_ccss_route_generation_v1',true) : 0;
        if ((int)($deps['route_generation']??-1)!==$postRev) {
            self::diagnostic('route-deps');return null;
        }
        if (!empty($deps['contains_dynamic_listing'])
            || in_array($ctx['type'],['destinations','tour-types','property-types',
                'tour-categories','category','tours-and-activities','properties',
                'archive:tours-and-activities','archive:properties'],true)) {
            if ((int)($deps['listing_generation']??-1)!==
                (int)get_option('mad4b_ccss_listing_generation_v1',0)) {
                self::diagnostic('listing-deps');return null;
            }
        }
        $cap=$json['critical_cap_px']??null;
        if (!is_int($cap) || $cap!==767 || ($json['native_mobile_breakpoint']??null)!==767) {
            self::diagnostic('cap');return null;
        } // Independent narrower-cap path requires its own QA contract.
        $proof=$json['evidence']??null;
        if (!is_array($proof) || ($proof['decision']??'')!=='passed_evidence'
            || ($proof['viewports']??null)!==[320,390,414,767,768]
            || ($proof['tested']??null)!==5) {
            self::diagnostic('qa-proof');return null;
        }
        foreach (['css_sha','critical_sha','elementor_doc_fingerprint'] as $field)
            if (!preg_match('/^[a-f0-9]{64}$/D',(string)($json[$field]??''))) {
                self::diagnostic('invalid-'.$field);return null;
            }
        return $json;
    }

    public static function transform($html) {
        self::$verified_request=false;
        if (!is_string($html) || strlen($html)<1000 || strlen($html)>6000000
            || str_contains($html,'id="mad4b-critical-css-v1"')
            || str_contains($html,'id="mad4b-public-critical-css-v1"')
            || !str_contains($html,'</head>')) return $html;
        $ctx=self::context(self::content_has_market_deps($html));
        if ($ctx && !headers_sent()) {
            // This is the native WCML resolved currency, not a client-provided
            // header. Chromium uses it to bind its measured currency variant.
            header('X-MAD4B-Public-CCSS-Currency: '.$ctx['currency'],true);
            header('X-MAD4B-Public-CCSS-Market: '.$ctx['market_country'],true);
        }
        if (!$ctx) {
            self::diagnostic('context');return $html;
        }
        $cert=self::certificate($ctx);
        if (!$cert) return $html;

        $body=stripos($html,'<body');
        if ($body===false) return $html;
        preg_match_all('/(?<![a-z0-9_-])elementor-(\d+)\b/i',substr($html,$body),$found);
        $docs=array_values(array_unique(array_map('intval',$found[1])));
        sort($docs,SORT_NUMERIC);
        if (!hash_equals($cert['elementor_doc_fingerprint'],hash('sha256',implode(',',$docs))))
            return $html;

        preg_match_all('~<link\b[^>]*>~i',$html,$all,PREG_OFFSET_CAPTURE);
        $styles=[];
        foreach ($all[0] as [$tag,$pos]) {
            if (!preg_match('~\brel=["\x27]stylesheet["\x27]~i',$tag))continue;
            if (!preg_match('~\bhref=["\x27]([^"\x27]+)["\x27]~i',$tag,$href))continue;
            $url=html_entity_decode($href[1],ENT_QUOTES,'UTF-8');
            if (strtolower((string)wp_parse_url($url,PHP_URL_HOST))!==$ctx['host'])continue;
            $path=(string)wp_parse_url($url,PHP_URL_PATH);
            if (!preg_match('~^/wp-content/litespeed/css/([a-f0-9]{32}\.css)$~D',$path,$m))
                continue;
            $styles[]=[$tag,$pos,$url,$m[1]];
        }
        if (count($styles)!==1) return $html;
        [$tag,$pos,$url,$filename]=$styles[0];
        if (($cert['css_file']??null)!==$filename) return $html;
        $source=WP_CONTENT_DIR.'/litespeed/css/'.$filename;
        if (is_link($source) || !is_file($source)) {
            $source=WP_CONTENT_DIR.'/litespeed/.mad4b-asset-retention/css/'.$filename;
        }
        if (is_link($source) || !is_file($source) || filesize($source)>6291456
            || !hash_equals($cert['css_sha'],(string)hash_file('sha256',$source)))
            return $html;
        $criticalFile=(string)($cert['critical_file']??'');
        if (!preg_match('/^[a-f0-9]{64}\.css$/D',$criticalFile))return $html;
        $criticalPath=WP_CONTENT_DIR.self::REL.'/assets/'.$criticalFile;
        if (is_link($criticalPath) || !is_file($criticalPath)
            || filesize($criticalPath)<16000
            || filesize($criticalPath)>self::MAX_CSS
            || (int)($cert['critical_bytes']??0)!==filesize($criticalPath)
            || !hash_equals($cert['critical_sha'],(string)hash_file('sha256',$criticalPath)))
            return $html;
        $critical=(string)file_get_contents($criticalPath);
        if (str_contains(strtolower($critical),'</style')) return $html;
        $safe=esc_url($url);
        $markup='<style id="mad4b-public-critical-css-v1" media="(max-width:767px)">'
           .$critical.'</style>';
        $markup.='<link rel="preload" as="style" fetchpriority="high" media="(max-width:767px)" href="'.$safe.'" />';
        $markup.='<link id="mad4b-public-css-rest-v1" rel="stylesheet" href="'.$safe.'" media="(min-width:768px)" fetchpriority="auto" onload="this.onload=null;this.media=&#39;all&#39;" />';
        $markup.='<noscript>'.$tag.'</noscript>';
        $markup.='<script data-no-optimize="1">(function(){var e=document.getElementById("mad4b-public-css-rest-v1");if(!e)return;window.addEventListener("load",function(){if(e.media!=="all")e.media="all"},{once:true});setTimeout(function(){if(e.media!=="all")e.media="all"},6500)})()</script>';
        $result=substr_replace($html,$markup,$pos,strlen($tag));
        self::$verified_request=true;
        self::diagnostic('transformed');
        return $result;
    }
    public static function readback($html) {
        if (!self::$verified_request || !is_string($html)) return $html;
        $ready=(substr_count($html,'id="mad4b-public-critical-css-v1"')===1
            && substr_count($html,'id="mad4b-public-css-rest-v1"')===1
            && str_contains($html,'media="(max-width:767px)"')
            && str_contains($html,'media="(min-width:768px)"'));
        if ($ready && !headers_sent()) header('X-MAD4B-Public-CCSS-Ready: 1',true);
        return $html;
    }
    public static function boot(): void {
        add_filter('litespeed_buffer_finalize',[__CLASS__,'transform'],1090,1);
        add_filter('litespeed_buffer_finalize',[__CLASS__,'readback'],13100,1);
    }
}
add_action('init',['MAD4B_Public_Critical_CSS_Runtime_V1','boot'],32);
