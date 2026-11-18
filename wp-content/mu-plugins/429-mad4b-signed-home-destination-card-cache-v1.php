<?php
/**
 * Plugin Name: MAD4B Signed Home Top Cities Card Cache
 * Version: 1.0.0
 * Description: Audited market-private object-cache fragments; never shared full HTML, Woo or price.
 */
defined('ABSPATH') || exit;
final class MAD4B_Signed_Home_Card_Cache_V1 {
    private const OPTION='mad4b_perf_signed_home_cards_v1';
    private const GROUP='mad4b_signed_market_dest_cards_v1';
    public static function admission(): ?string {
        $mode=(string)get_option(self::OPTION,'off');
        if ($mode!=='canary' && $mode!=='on')return null;
        if ($mode==='canary' && (string)($_SERVER['HTTP_X_MAD4B_PRIVATE_CARDS']??'')!=='v1')return null;
        if ((string)wp_parse_url((string)get_option('home',''),PHP_URL_HOST)!=='wayfatours.com'
            || !in_array((string)($_SERVER['REQUEST_URI']??''),['/','/es/'],true)
            || ($_SERVER['REQUEST_METHOD']??'GET')!=='GET'
            || is_admin() || is_user_logged_in() || is_preview() || wp_doing_ajax()
            || !is_front_page() || !wp_using_ext_object_cache()
            || !empty($_SERVER['HTTP_AUTHORIZATION']) || !empty($_SERVER['HTTP_X_WP_NONCE'])
            || !empty($_SERVER['HTTP_X_MAD4B_CURRENCY'])
            || !empty($_SERVER['HTTP_X_MAD4B_MARKET_CONTEXT'])
            || !empty($_SERVER['HTTP_X_GEO_COUNTRY'])
            || !empty($_SERVER['HTTP_CF_IPCOUNTRY'])
            || !class_exists('MAD4B_Country_Switcher',false)
            || !class_exists('MAD4B_Public_Cookie_Cache_Contract_V1',false))return null;
        // Dynamic price, passport, geo, auth and manual QA headers must never
        // create a reusable fragment in the signed anonymous scope.
        foreach ([
            'HTTP_X_MAD4B_PASSPORT','HTTP_X_COUNTRY_CODE',
            'HTTP_X_GEOIP_COUNTRY','HTTP_X_FORWARDED_GEO_COUNTRY',
            'HTTP_X_MAD4B_GEO_COUNTRY','HTTP_X_MAD4B_BYPASS_CACHE',
            'HTTP_X_MAD4B_DYNAMIC_CSS','HTTP_X_MAD4B_CSS_PRIORITY',
            'HTTP_X_REQUESTED_WITH','HTTP_PHP_AUTH_USER',
        ] as $header) {
            if (!empty($_SERVER[$header])) return null;
        }
        if (function_exists('WC') && WC()->session
            && method_exists(WC()->session,'has_session')
            && WC()->session->has_session())return null;
        $raw=(string)($_SERVER['HTTP_COOKIE']??'');
        if (strlen($raw)<40 || strlen($raw)>4096)return null;
        $parts=explode(';',$raw);$marketCount=0;$marketing=[];
        foreach ($parts as $pair) {
            $pair=trim($pair);
            [$name,$value]=array_pad(explode('=',$pair,2),2,'');
            if ($name==='mad4b_country_selected_v1') {
                if (++$marketCount!==1 || $value==='' || strlen($value)>1500)return null;
                continue;
            }
            // Reject all Woo, auth, favourite, passport, consent and unknown cookies.
            if ($name==='' || $value==='')return null;
            $marketing[]=$pair;
        }
        if ($marketCount!==1)return null;
        $marketingHeader=implode('; ',$marketing);
        if ($marketingHeader!=='' && !MAD4B_Public_Cookie_Cache_Contract_V1::safe_marketing_cookie($marketingHeader))return null;
        $country=(string)MAD4B_Country_Switcher::saved_country();
        if (!preg_match('/^[A-Z]{2}$/D',$country))return null; // verifies host HMAC
        $marketKey=(string)($_COOKIE['mad4b_country_selected_v1']??'');
        if ($marketKey==='')return null;
        // Per-exact-signed-cookie namespace. No raw cookie or nationality in Redis keys.
        $lang=(string)apply_filters('wpml_current_language',null);
        if (!in_array($lang,['en','es'],true))return null;
        $pathLang=($_SERVER['REQUEST_URI']==='/es/'?'es':'en');
        if ($lang!==$pathLang)return null;
        return hash('sha256',$marketKey.'|'.$lang.'|'.$country);
    }
    public static function run():void {
        $scope=self::admission();if($scope===null)return;
        $stats=['read'=>0,'hit'=>0,'write'=>0];
        $read=static function($key) use($scope,&$stats){
            $stats['read']++;
            $result=wp_cache_get(hash('sha256',$scope.'|'.$key),self::GROUP);
            if (is_string($result))$stats['hit']++;
            return $result;
        };
        $write=static function($key,$html) use($scope,&$stats){
            $stats['write']++;
            return wp_cache_set(hash('sha256',$scope.'|'.$key),$html,self::GROUP,45);
        };
        require_once WPMU_PLUGIN_DIR.'/mad4b-shared-render/public-term-cards.php';
        // WPML owns translated Elementor documents and JetEngine listing
        // definitions. Each language needs its exact, observed source graph.
        $lang = (string)apply_filters('wpml_current_language',null);
        $graph = [
            'en' => ['doc'=>25909,'widget'=>'b25dbff','listing'=>45927],
            'es' => ['doc'=>34605,'widget'=>'b25dbff','listing'=>68823],
        ][$lang] ?? null;
        if (!$graph)return;
        mad4b_register_public_term_cards(
            $graph['doc'],$graph['widget'],$graph['listing'],'destinations',$read,$write);
        if (get_option(self::OPTION,'off')==='canary') {
            add_action('shutdown',static function() use(&$stats) {
                // Timings/counts only, no cookie, person or market value ever logged.
                $entry=['contract'=>'mad4b.signed-market-term-cache.canary.v1',
                    'hits'=>$stats['hit'],'reads'=>$stats['read'],'writes'=>$stats['write']];
                $path='/tmp/mad4b-signed-term-cache-canary-20261118.jsonl';
                if (is_file($path) && (int)@filesize($path)>131072)return;
                @file_put_contents($path,
                    json_encode($entry)."\n",FILE_APPEND|LOCK_EX);
            },9999);
        }
    }
}
add_action('wp',[MAD4B_Signed_Home_Card_Cache_V1::class,'run'],20);
