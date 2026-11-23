<?php
/**
 * Plugin Name: MAD4B Wayfa Verified Public JetEngine Term Response Cache
 * Version: 1.0.1
 * Description: Native post-validation read-only cache for a signed, market-partitioned public home Attractions listing; sensitive requests fail closed.
 */
defined('ABSPATH') || exit;

final class MAD4B_Wayfa_Verified_Term_Response_Cache_V1 {
    private const GROUP='mad4b-wf-jet-term-response-v1';
    private const TTL=60;
    private static string $active_key='';

    public static function scope():string {
        $mode=(string)get_option('mad4b_wayfa_signed_jet_fragment_cache_v1','off');
        if($mode!=='on' && $mode!=='canary')return '';
        if($mode==='canary' && (string)($_SERVER['HTTP_X_MAD4B_JET_FRAGMENT_QA']??'')!=='v1')return '';
        if((defined('WP_CLI')&&WP_CLI) || !wp_using_ext_object_cache()
            || is_user_logged_in() || !wp_doing_ajax()
            || (string)wp_parse_url(home_url('/'),PHP_URL_HOST)!=='wayfatours.com'
            || (string)($_SERVER['REQUEST_METHOD']??'')!=='POST'
            || (string)($_REQUEST['action']??'')!=='jet_engine_ajax'
            || (string)($_REQUEST['handler']??'')!=='get_listing'
            || (string)($_REQUEST['listing_type']??'')!=='elementor'
            || filter_var($_REQUEST['isEditMode']??false,FILTER_VALIDATE_BOOLEAN))return '';
        $uri=(string)($_SERVER['REQUEST_URI']??'');
        $path=(string)wp_parse_url($uri,PHP_URL_PATH);
        $query_string=(string)wp_parse_url($uri,PHP_URL_QUERY);
        if(!in_array($path,['/','/es/'],true) || !in_array($query_string,['','nocache=1'],true))return '';
        if(!empty($_SERVER['HTTP_AUTHORIZATION']) || !empty($_SERVER['PHP_AUTH_USER'])
            || !empty($_SERVER['HTTP_X_WP_NONCE']) || !empty($_SERVER['HTTP_X_MAD4B_MARKET_CONTEXT'])
            || !empty($_SERVER['HTTP_X_MAD4B_CURRENCY']) || !empty($_SERVER['HTTP_X_MAD4B_PASSPORT'])
            || !empty($_SERVER['HTTP_CF_IPCOUNTRY']) || !empty($_SERVER['HTTP_X_COUNTRY_CODE'])
            || !empty($_SERVER['HTTP_X_GEOIP_COUNTRY']))return '';
        foreach($_SERVER as $name=>$value) {
            if(str_starts_with((string)$name,'HTTP_X_MAD4B_')
                && !in_array($name,['HTTP_X_MAD4B_JET_FRAGMENT_QA'],true)
                && is_scalar($value) && (string)$value!=='')return '';
        }
        if(function_exists('WC') && WC() && is_object(WC()->session??null)
            && method_exists(WC()->session,'has_session') && WC()->session->has_session())return '';
        $lang=(string)apply_filters('wpml_current_language','');
        if(!in_array($lang,['en','es'],true) || $lang!==($path==='/es/'?'es':'en'))return '';
        foreach(array_keys((array)($_REQUEST??[])) as $key) {
            if(!in_array((string)$key,['action','handler','query','widget_settings','page_settings','listing_type','isEditMode','nocache','woocommerce-login-nonce','_wpnonce','woocommerce-reset-password-nonce','post_id','queried_id','element_id','page'],true))return '';
        }
        $page=(array)($_REQUEST['page_settings']??[]);
        $widget=(array)($_REQUEST['widget_settings']??[]);
        $query=(array)($_REQUEST['query']??[]);
        $document=($lang==='es'?26001:25927);
        $queried=($lang==='es'?'43775|WP_Post':'35138|WP_Post');
        if((isset($_REQUEST['nocache']) && (string)$_REQUEST['nocache']!=='1')
            || (isset($_REQUEST['post_id']) && absint($_REQUEST['post_id'])!==$document)
            || (isset($_REQUEST['queried_id']) && (string)$_REQUEST['queried_id']!==$queried)
            || (isset($_REQUEST['element_id']) && (string)$_REQUEST['element_id']!=='78d62e0')
            || (isset($_REQUEST['page']) && absint($_REQUEST['page'])!==1))return '';
        if((int)($page['post_id']??0)!==$document
            || (string)($page['queried_id']??'')!==$queried
            || (string)($page['element_id']??'')!=='78d62e0'
            || (int)($page['page']??0)!==1
            || (int)($widget['lisitng_id']??0)!==45938
            || (int)($widget['custom_query_id']??0)!==291
            || !filter_var($widget['custom_query']??false,FILTER_VALIDATE_BOOLEAN)
            || !empty($widget['_mad4b_inner_batch'])
            || (int)($query['query_id']??0)!==291
            || (int)($query['custom_query_id']??0)!==291
            || !preg_match('/^[a-f0-9]{64}$/D',(string)($query['signature']??'')))return '';
        if(get_post_status($document)!=='publish' || get_post_status(45938)!=='publish')return '';
        // Do not cache any personalized or unclassified browser cookie. Site
        // country is accepted only via its original HMAC-verified switcher.
        $country='base';
        foreach((array)($_COOKIE??[]) as $name=>$value){
            if(!is_string($name) || !is_scalar($value))return '';
            if(preg_match('/\A(?:wordpress_|wp_woocommerce_session_|woocommerce_|PHPSESSID)/i',$name))return '';
            if($name==='mad4b_country_selected_v1'){
                if(!class_exists('MAD4B_Country_Switcher',false))return '';
                $country=(string)MAD4B_Country_Switcher::saved_country();
                if(!preg_match('/^[A-Z]{2}$/D',$country))return '';
                continue;
            }
            if($name==='wp-wpml_current_language'){
                if((string)$value!==$lang)return '';
                continue;
            }
            if(!preg_match('/\A(?:_ga(?:_[A-Za-z0-9]+)?|_gid|_gat(?:_[A-Za-z0-9]+)?|_gcl_(?:au|aw|dc|gb)|_fbp|_fbc|_clck|_clsk|sbjs_(?:migrations|current_add|first_add|current|first|udata|session))\z/D',$name))return '';
        }
        if(!function_exists('mad4b_render_query_market_identity')
            || !class_exists('Jet_Engine\\Query_Builder\\Manager',false))return '';
        $market=mad4b_render_query_market_identity();
        if(!is_array($market) || !preg_match('/^(?:base|[A-Z]{2})$/D',(string)($market['country']??''))
            || !preg_match('/^[A-Z]{3}$/D',(string)($market['currency']??'')))return '';
        if($country==='base' && ($market['country']??'')!=='base')return '';
        $proof=get_option('mad4b_secondary_grid_lazy_layout_proofs_v1',[]);
        $entry=is_array($proof)?($proof['entries']['45938|'.$lang.'|mobile']??null):null;
        $listing=(string)get_post_meta(45938,'_elementor_data',true);
        $parent=(string)get_post_meta($document,'_elementor_data',true);
        $css=WP_CONTENT_DIR.'/uploads/elementor/css/post-45938.css';
        if(!is_array($entry) || ($entry['status']??'')!=='pass'
            || !is_string($entry['listing_source_sha256']??null)
            || !hash_equals((string)$entry['listing_source_sha256'],hash('sha256',$listing))
            || is_link($css) || !is_file($css) || !hash_equals((string)($entry['listing_css_sha256']??''),(string)@hash_file('sha256',$css))
            || strlen($parent)<200)return '';
        $q=\Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id(291);
        if(!is_object($q) || ($q->query_type??'')!=='terms')return '';
        $query_src=wp_json_encode($q->query??[]);
        if(!is_string($query_src) || strlen($query_src)>12000
            || !str_contains($query_src,'destination_type')
            || !str_contains($query_src,'place_attraction')
            || !str_contains($query_src,'destinations')
            || preg_match('/(?:price|rate|stock|booking|payment|availability|supplier|capacity|checkout|deposit|customer)/i',$query_src))return '';
        $generation=hash('sha256',$query_src);
        $request_data=(array)$_REQUEST;
        foreach(['woocommerce-login-nonce','woocommerce-reset-password-nonce','_wpnonce','nocache'] as $system_key)
            unset($request_data[$system_key]);
        $request=wp_json_encode($request_data);
        if(!is_string($request)||strlen($request)>32000)return '';
        $key=wp_json_encode([
            'contract'=>'mad4b.wayfa.signed-public-terms-response.v1',
            'locale'=>$lang,'country'=>$country,'market'=>$market,
            'mobile'=>wp_is_mobile(),'query_generation'=>$generation,
            'data_generation'=>(string)get_option('mad4b_render_query_data_generation','initial'),
            'term_last_changed'=>(string)wp_cache_get_last_changed('terms'),
            'document_sha256'=>hash('sha256',$parent),
            'listing_sha256'=>hash('sha256',$listing),
            'request_sha256'=>hash('sha256',$request),
        ]);
        return is_string($key)?'f1-'.hash('sha256',$key):'';
    }
    public static function serve():void {
        self::$active_key=self::scope();
        if(self::$active_key==='')return;
        $cached=wp_cache_get(self::$active_key,self::GROUP);
        if(is_array($cached) && isset($cached['html'],$cached['scripts'],$cached['styles'])
            && is_string($cached['html']) && strlen($cached['html'])>=50000) {
            if(!headers_sent())header('X-MAD4B-Jet-Public-Fragment: HIT');
            wp_send_json_success($cached);
        }
        if(!headers_sent())header('X-MAD4B-Jet-Public-Fragment: MISS');
    }
    public static function store($response,$settings,$query){
        if(self::$active_key==='' || !is_array($response) || !isset($response['html'])
            || !is_string($response['html']) || !isset($response['scripts'],$response['styles'])
            || !is_array($response['scripts']) || !is_array($response['styles']))return $response;
        $html=$response['html'];
        if(strlen($html)<50000 || strlen($html)>1250000
            || substr_count($html,'data-post-id=')<36
            || str_contains($html,'mad4b-native-inner-')
            || preg_match('/(?:_wpnonce|wp-nonce|checkout|billing_|wc_cart|cart_hash|woocommerce-session|authorization:|csrf_token|personalized_price|price_calculator)/i',$html))return $response;
        // No fragment outside the native signed, verified query result; cache
        // exact response arrays, including JetEngine's script/style handles.
        wp_cache_set(self::$active_key,$response,self::GROUP,self::TTL);
        return $response;
    }
    public static function boot():void {
        add_action('jet-engine/ajax-handlers/before-call-handler',[__CLASS__,'serve'],999,0);
        add_filter('jet-engine/ajax/get_listing/response',[__CLASS__,'store'],PHP_INT_MAX,3);
    }
}
MAD4B_Wayfa_Verified_Term_Response_Cache_V1::boot();
