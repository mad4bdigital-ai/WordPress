<?php
/**
 * Plugin Name: MAD4B Native Navigation SSR Fragment Memo
 * Description: Short-lived, request-context-scoped native Elementor Mega Menu HTML + asset enqueue memo.
 * Version: 1.1.1
 * Use only for one well-identified Tour header component; never cache commercial decisions.
 */
defined('ABSPATH') || exit;

/** Source-bound extension of the existing native navigation memo.
 * Explicit staging canary; never broadens the original route/visitor key.
 */
final class MAD4B_Navigation_Shell_Continuity_V2 {
    public static string $reason='';
    const GROUP='mad4b_native_nav_fragment_v1';
    const CONTRACT='mad4b.navigation.shell.source-bound.v2';
    public static function canary():bool {
        return wp_get_environment_type()==='staging'
            && ($_SERVER['HTTP_X_MAD4B_NAV_CONTINUITY']??'')==='observe-v2'
            && ($_SERVER['HTTP_X_MAD4B_BYPASS_CACHE']??'')==='1';
    }
    public static function eligible():bool {
        self::$reason='';
        if(!self::canary()||!wp_using_ext_object_cache()||is_user_logged_in()){self::$reason='context';return false;}
        if(!class_exists('MAD4B_Experience_Site_Scope_V1',false)
            || !MAD4B_Experience_Site_Scope_V1::bound()){self::$reason='site_binding';return false;}
        if(!function_exists('mad4b_render_query_market_identity')
            || !function_exists('mad4b_render_query_has_untrusted_market_header')
            || mad4b_render_query_has_untrusted_market_header()){self::$reason='market_header';return false;}
        $lang=(string)apply_filters('wpml_current_language','');
        $wire=(string)($_SERVER['HTTP_COOKIE']??'');
        if($wire!==''&&$wire!=='wp-wpml_current_language='.$lang){self::$reason='wire_cookie';return false;}
        foreach((array)$_COOKIE as $k=>$v)if($k!=='wp-wpml_current_language'||$v!==$lang){self::$reason='runtime_cookie';return false;}
        foreach(['HTTP_AUTHORIZATION','PHP_AUTH_USER','HTTP_X_WP_NONCE','HTTP_X_REQUESTED_WITH','HTTP_ORIGIN','HTTP_REFERER'] as $h)
            if(!empty($_SERVER[$h])){self::$reason='private_header';return false;}
        return true;
    }
    // Even the previous short-lived replay is invalidated by the existing
    // content epochs and native menu/activation policy, without flushing Redis.
    public static function revision():string {
        $deps=[wp_cache_get_last_changed('posts'),wp_cache_get_last_changed('terms')];
        foreach(['mad4b_render_query_data_generation','mad4b_css_ai_epoch_v1','mad4b_shell_cache_epoch_v1',
            'mad4b_compiled_termmeta_epoch_v1','service-activation','destination-activation',
            'product_navigation_sources','icl_sitepress_settings','elementor_active_kit',
            'permalink_structure','_elementor_element_cache_unique_id'] as $name)
            $deps[$name]=get_option($name);
        return hash('sha256',serialize($deps));
    }
    public static function listing_ids(array $nodes):?array {
        $ids=[];$count=0;$walk=function($items,$depth=0)use(&$walk,&$ids,&$count):bool{
            if(!is_array($items)||$depth>12){self::$reason='structure_depth';return false;}
            foreach($items as $node){
                if(!is_array($node)||++$count>128){self::$reason='structure_count';return false;}
                $type=$node['widgetType']??$node['elType']??'';
                if(!in_array($type,['mega-menu','container','jet-listing-grid','button','nested-tabs'],true)){self::$reason='structure_type:'.(preg_match('/^[a-z0-9_-]{1,80}$/D',(string)$type)?$type:'unknown');return false;}
                if($type==='jet-listing-grid'){
                    $s=$node['settings']??[];
                    if(!is_array($s)||!in_array('navigation-editor-grid',preg_split('/\s+/',(string)($s['_css_classes']??'')),true)
                        ||!empty($s['custom_query'])){self::$reason='structure_grid_unknown_query';return false;}
                    $id=(int)($s['lisitng_id']??0);if($id<1)return false;
                    if(empty($s['product_navigation_service'])){
                        // This is the native country projection, not a generic
                        // service-less grid. Verify the original authority and
                        // the final native SSR guard for every projected branch.
                        $parent=(int)($s['terms_parent']??0);
                        if($id!==67125||$parent<1||!function_exists('native_destinations_eligible_children')
                            ||!function_exists('mad4b_nav_grid_requires_native_ssr')
                            ||!mad4b_nav_grid_requires_native_ssr($s)
                            ||!native_destinations_eligible_children($parent,(string)apply_filters('wpml_current_language',''))){
                            self::$reason='projected_grid_authority';return false;
                        }
                    }elseif(!empty($s['lazy_load'])){self::$reason='service_grid_lazy';return false;}
                    $ids[$id]=true;
                }
                if(!$walk($node['elements']??[],$depth+1))return false;
            }
            return true;
        };
        if(!$walk($nodes)||!$ids||count($ids)>8){if(!self::$reason)self::$reason='structure_listing_count';return null;}
        return array_keys($ids);
    }
    public static function source(array $root):?array {
        $ids=self::listing_ids([$root]);if(!$ids)return null;
        $market=mad4b_render_query_market_identity();
        if(!is_array($market)||!preg_match('/^(?:[A-Z]{2}|base)$/D',(string)($market['country']??''))
            ||!preg_match('/^[A-Z]{3}$/D',(string)($market['currency']??''))){self::$reason='market_identity';return null;}
        $gen=(string)get_option('mad4b_render_query_data_generation','');
        if($gen===''){self::$reason='generation';return null;}
        $deps=['contract'=>self::CONTRACT,'root_sha'=>hash('sha256',serialize($root)),
            'market'=>$market,'generation'=>$gen,'posts'=>wp_cache_get_last_changed('posts'),
            'terms'=>wp_cache_get_last_changed('terms'),'nav_options'=>[],'listings'=>[]];
        foreach(['service-activation','destination-activation','product_navigation_sources','icl_sitepress_settings',
            'page_on_front','page_for_posts','permalink_structure','elementor_active_kit','elementor_css_print_method',
            'mad4b_css_ai_epoch_v1','mad4b_shell_cache_epoch_v1','mad4b_compiled_termmeta_epoch_v1','_elementor_element_cache_unique_id'] as $k)
            $deps['nav_options'][$k]=hash('sha256',serialize(get_option($k)));
        $sources=get_option('product_navigation_sources',[]);
        if(!is_array($sources)||!class_exists('Jet_Engine\Query_Builder\Manager')){self::$reason='query_provider';return null;}
        foreach($sources as $service=>$cfg){
            $id=(int)($cfg['query_id']??0);if(!$id)continue;
            $q=\Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id($id);
            if(!is_object($q)||!is_array($q->query??null)){self::$reason='query_definition';return null;}
            $deps['query_definitions'][$id]=hash('sha256',serialize([$q->query,$q->dynamic_query??[]]));
        }
        $approved=['destinations'=>'079e0deb501c27ccbd92f74418e03f69716d6372b6da104b4a853a57a0b892f6',
            'tour-types'=>'f410b3efaa94b8e89f945117b69e5e276aace68ee9b11639165bbeed7da9bb36'];
        foreach($ids as $id){
            $raw=get_post_meta($id,'_elementor_data',true);$data=get_post_meta($id,'_listing_data',true);
            if(get_post_status($id)!=='publish'||get_post_type($id)!=='jet-engine'
                ||!is_string($raw)||!is_array($data)||($data['source']??'')!=='terms'){self::$reason='listing_type';return null;}
            $tax=(string)($data['tax']??'');$sha=hash('sha256',$raw);
            if(!isset($approved[$tax])||!hash_equals($approved[$tax],$sha)){self::$reason='listing_source';return null;}
            $css=get_post_meta($id,'_elementor_css',true);if(is_array($css))unset($css['time']);
            $deps['listings'][$id]=[$tax,$sha,hash('sha256',serialize([$data,$css]))];
        }
        $projection=(int)get_option('_native_destinations_elementor_source_id',0);
        if($projection<1||get_post_status($projection)!=='publish'||get_post_type($projection)!=='elementor_library'){
            self::$reason='projection_document';return null;
        }
        $deps['projection_source']=[$projection,hash('sha256',(string)get_post_meta($projection,'_elementor_data',true))];
        $kit=(int)get_option('elementor_active_kit');
        $deps['kit']=hash('sha256',serialize(get_post_meta($kit,'_elementor_page_settings',true)));
        $deps['runtime']=[defined('ELEMENTOR_VERSION')?ELEMENTOR_VERSION:'',defined('ELEMENTOR_PRO_VERSION')?ELEMENTOR_PRO_VERSION:'',
            defined('JET_ENGINE_VERSION')?JET_ENGINE_VERSION:'',defined('ICL_SITEPRESS_VERSION')?ICL_SITEPRESS_VERSION:'',
            wp_get_theme()->get('Version'),hash_file('sha256',__FILE__)];
        return $deps;
    }
    private static array $card_rows=[];
    private static bool $card_scope=false;
    public static function observe_card($html,$listing_id){
        if(!self::$card_scope||!is_string($html)||!in_array((int)$listing_id,[67124,67125],true)
            ||count(self::$card_rows)>=512||!function_exists('jet_engine'))return $html;
        $term=jet_engine()->listings->data->get_current_object();
        if(!$term instanceof WP_Term||$term->taxonomy!==((int)$listing_id===67125?'destinations':'tour-types'))return $html;
        $unsafe=preg_match('~<\s*(?:script|form|input|iframe)\b|nonce|data-(?:price|rate|amount|session|user)|checkout|payment|aria-current|\be-current\b~i',$html);
        $volatile=preg_match('/elementor-dcss-[0-9]{10,22}(?![0-9])/',$html);
        $key=(int)$listing_id.'|'.(int)$term->term_id;
        $sha=hash('sha256',$html);
        $r=self::$card_rows[$key]??['listing'=>(int)$listing_id,'term'=>(int)$term->term_id,'taxonomy'=>$term->taxonomy,
            'sha'=>$sha,'count'=>0,'exact'=>true,'unsafe'=>false,'volatile_css'=>false,'bytes'=>strlen($html)];
        $r['count']++;$r['exact']=$r['exact']&&hash_equals($r['sha'],$sha);
        $r['unsafe']=$r['unsafe']||(bool)$unsafe;$r['volatile_css']=$r['volatile_css']||(bool)$volatile;
        self::$card_rows[$key]=$r;return $html;
    }
    public static function render_observed(callable $native,array $attrs,$tag){
        self::$card_rows=[];self::$card_scope=true;
        $route=(int)get_queried_object_id();
        add_filter('jet-engine/listing/content/elementor',[self::class,'observe_card'],PHP_INT_MAX,2);
        try{return $native($attrs,null,$tag);}
        finally{
            remove_filter('jet-engine/listing/content/elementor',[self::class,'observe_card'],PHP_INT_MAX);
            self::$card_scope=false;
            $GLOBALS['mad4b_nav_bench_record'][]=['status'=>'V2_CARD_OBSERVATION','route'=>$route,
                'language'=>(string)apply_filters('wpml_current_language',''),'device'=>wp_is_mobile()?'mobile':'desktop',
                'cards'=>array_values(self::$card_rows),'replay_authorized'=>false];
        }
    }
    public static function safe_html($html):bool {
        return is_string($html)&&strlen($html)>40000&&strlen($html)<500000
            && str_contains($html,'navigation-editor-grid')
            && !preg_match('~<\s*(?:script|form|input|iframe)\b|nonce|woocommerce|data-cart|add-to-cart|\bprice\b|data-user|wp-admin|customer|checkout|payment|email=|data-session|data-rate|data-amount~i',$html);
    }
    public static function witness(string $key,array $entry):string {
        if(!self::safe_html($entry['html']??null))return 'V2_NATIVE_REJECTED';
        $signature=hash('sha256',serialize([$entry['sha'],$entry['js'],$entry['css']]));
        static $seen=[];
        if(isset($seen[$key]))return 'V2_AWAIT_SECOND';
        $seen[$key]=true;
        $sample=wp_cache_get('witness_'.$key,self::GROUP);
        if(!is_array($sample)||($sample['signature']??null)!==$signature){
            wp_cache_set('witness_'.$key,['signature'=>$signature,'at'=>microtime(true)],self::GROUP,900);
            return 'V2_FIRST_WITNESS';
        }
        if(empty($sample['at'])||microtime(true)-(float)$sample['at']<0.2)return 'V2_AWAIT_SECOND';
        $entry['qualified']=true;$entry['contract']=self::CONTRACT;
        if(!wp_cache_set($key,$entry,self::GROUP,600))return 'V2_WRITE_FAILED';
        return 'V2_SECOND_WITNESS_STORED';
    }
}

$GLOBALS['mad4b_nav_bench_record']=[];
add_filter('pre_do_shortcode_tag',static function($pre,$tag,$attrs,$match) {
  if ($pre !== false || $tag !== 'elementor-element' || !is_array($attrs)) return $pre;
  if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)
      || (defined('WP_CLI') && WP_CLI) || is_preview() ||
      strtoupper((string)($_SERVER['REQUEST_METHOD']??'')) !== 'GET' ||
      !is_singular('tours-and-activities') || !empty($_GET) || !empty($_POST) ||
      !empty($_SERVER['QUERY_STRING']) || !empty($_SERVER['HTTP_AUTHORIZATION']) ||
      !empty($_SERVER['HTTP_X_WP_NONCE']) || !empty($_SERVER['PHP_AUTH_USER'])) return $pre;
  if (!isset($attrs['data']) || !is_string($attrs['data']) || strlen($attrs['data'])>400000) return $pre;
  $d=json_decode(base64_decode($attrs['data'],true)?:'',true);
  if (!is_array($d) || ($d['id']??'')!=='6072b3f' || ($d['widgetType']??'')!=='mega-menu' ||
      strpos((string)($d['settings']['_css_classes']??''),'navigation-editor-menu')===false)return $pre;
  if (($attrs['k']??'') !== get_option('_elementor_element_cache_unique_id')) return $pre;
  $uid=get_current_user_id();
  $session=$uid && function_exists('wp_get_session_token') ? wp_get_session_token() : '';
  if($uid && (!is_string($session) || $session==='')) return $pre;
  $revision=MAD4B_Navigation_Shell_Continuity_V2::revision();
  $key='nav_'.hash('sha256',wp_json_encode([
    'v'=>4,'revision'=>$revision,'site'=>home_url('/'),'host'=>(string)($_SERVER['HTTP_HOST']??''),'theme'=>get_stylesheet(),
    'element'=>hash('sha256',$attrs['data']),'post'=>(int)get_queried_object_id(),
    'locale'=>get_locale(),'wpml'=>apply_filters('wpml_current_language',null),
    'ua'=>(string)($_SERVER['HTTP_USER_AGENT']??''),
    'lang'=>(string)($_SERVER['HTTP_ACCEPT_LANGUAGE']??''),
    'ip'=>(string)($_SERVER['REMOTE_ADDR']??''),
    'forwarded'=>(string)($_SERVER['HTTP_X_FORWARDED_FOR']??''),
    'cf_ipcountry'=>(string)($_SERVER['HTTP_CF_IPCOUNTRY']??''),
    'geoip'=>(string)($_SERVER['GEOIP_COUNTRY_CODE']??''),
    'geoheader'=>(string)($_SERVER['HTTP_X_GEOIP_COUNTRY']??''),
    'countryheader'=>(string)($_SERVER['HTTP_X_COUNTRY_CODE']??''),
    'visitor_market'=>(string)($_SERVER['HTTP_X_MAD4B_COUNTRY']??''),
    'origin'=>(string)($_SERVER['HTTP_ORIGIN']??''),
    'referer'=>(string)($_SERVER['HTTP_REFERER']??''),
    'cookies'=>hash('sha256',(string)($_SERVER['HTTP_COOKIE']??'')),
    'cookie_state'=>hash('sha256',wp_json_encode(is_array($_COOKIE)?$_COOKIE:[])),
    'user'=>$uid,'session'=>hash('sha256',$session),
    'mobile'=>function_exists('wp_is_mobile')?wp_is_mobile():null
  ]));
  $v2=MAD4B_Navigation_Shell_Continuity_V2::canary();
  $source=null;
  if($v2){
    if(!MAD4B_Navigation_Shell_Continuity_V2::eligible()||!($source=MAD4B_Navigation_Shell_Continuity_V2::source($d))){
      $GLOBALS['mad4b_nav_bench_record'][]=['status'=>'V2_SCOPE_REJECTED','reason'=>MAD4B_Navigation_Shell_Continuity_V2::$reason];
      return $pre;
    }
    $key='nav_v2_'.hash('sha256',serialize([$key,$source]));
  }
  $t=microtime(true);
  $observe_cards=$v2&&($_SERVER['HTTP_X_MAD4B_NAV_CARD_OBSERVE']??'')==='native-v1';
  $cached=$observe_cards?false:wp_cache_get($key,'mad4b_native_nav_fragment_v1');
  if (is_array($cached) && isset($cached['html'],$cached['js'],$cached['css'])
      && is_string($cached['html']) && strlen($cached['html'])>40000
      && is_string($cached['digest']??null)
      && hash_equals($cached['digest'],hash('sha256',$cached['html']))
      && strpos($cached['html'],'navigation-editor-grid')!==false
      && is_array($cached['js']) && is_array($cached['css'])
      && (!$v2||(!empty($cached['qualified'])
          && ($cached['contract']??'')===MAD4B_Navigation_Shell_Continuity_V2::CONTRACT
          && MAD4B_Navigation_Shell_Continuity_V2::safe_html($cached['html'])))) {
    if($v2){
      foreach($cached['js'] as $h)if(!is_string($h)||!wp_script_is($h,'registered')){$GLOBALS['mad4b_nav_bench_record'][]=['status'=>'V2_ASSET_REJECTED'];return $pre;}
      foreach($cached['css'] as $h)if(!is_string($h)||!wp_style_is($h,'registered')){$GLOBALS['mad4b_nav_bench_record'][]=['status'=>'V2_ASSET_REJECTED'];return $pre;}
    }
    foreach($cached['js'] as $handle) if(is_string($handle))wp_enqueue_script($handle);
    foreach($cached['css'] as $handle) if(is_string($handle))wp_enqueue_style($handle);
    $GLOBALS['mad4b_nav_bench_record'][]=['status'=>$v2?'V2_HIT':'HIT','ms'=>round((microtime(true)-$t)*1000,2),
      'bytes'=>strlen($cached['html']),'scripts'=>count($cached['js']),'styles'=>count($cached['css'])];
    return apply_filters('do_shortcode_tag',$cached['html'],$tag,$attrs,$match);
  }
  global $shortcode_tags, $wp_scripts, $wp_styles;
  $callback=$shortcode_tags[$tag]??null;
  if(!is_callable($callback))return $pre;
  $prevJS=$wp_scripts instanceof WP_Scripts?$wp_scripts->queue:[];
  $prevCSS=$wp_styles instanceof WP_Styles?$wp_styles->queue:[];
  $out=$v2?MAD4B_Navigation_Shell_Continuity_V2::render_observed($callback,$attrs,$tag):call_user_func($callback,$attrs,null,$tag);
  if(!is_string($out))return $pre;
  $freshJS=$wp_scripts instanceof WP_Scripts?array_values(array_diff($wp_scripts->queue,$prevJS)):[];
  $freshCSS=$wp_styles instanceof WP_Styles?array_values(array_diff($wp_styles->queue,$prevCSS)):[];
  $hasSensitive=preg_match('/nonce|woocommerce|data-cart|add-to-cart|\bprice\b|\bform\b|data-user|wp-admin|customer|checkout|payment|email=/i',$out);
  $store=strlen($out)>40000 && strlen($out)<500000 && !$hasSensitive && strpos($out,'navigation-editor-grid')!==false;
  $status=$store?'MISS_STORED':'MISS_NOT_STORED';
  $entry=['html'=>$out,'digest'=>hash('sha256',$out),'sha'=>hash('sha256',$out),'js'=>$freshJS,'css'=>$freshCSS];
  if($v2){
    $status=$observe_cards?'V2_NATIVE_OBSERVED':'V2_NATIVE_REJECTED';
    if(!$observe_cards && $store && $source===MAD4B_Navigation_Shell_Continuity_V2::source($d))
        $status=MAD4B_Navigation_Shell_Continuity_V2::witness($key,$entry);
  }elseif($store && hash_equals($revision,MAD4B_Navigation_Shell_Continuity_V2::revision()))wp_cache_set($key,$entry,'mad4b_native_nav_fragment_v1',get_current_user_id()?15:60);
  elseif($store)$status='MISS_SOURCE_CHANGED';
  $GLOBALS['mad4b_nav_bench_record'][]=['status'=>$status,
    'ms'=>round((microtime(true)-$t)*1000,2),'bytes'=>strlen($out),'js'=>$freshJS,'css'=>$freshCSS,
    'sensitive'=>$hasSensitive?1:0];
  return apply_filters('do_shortcode_tag',$out,$tag,$attrs,$match);
},50,4);
register_shutdown_function(static function() {
 if (empty($GLOBALS['mad4b_nav_bench_record']) || ($_SERVER['HTTP_X_MAD4B_NAV_CACHE_RECEIPT']??'')!=='receipt-20261124')return;
 @file_put_contents('/tmp/mad4b-native-nav-cache-receipts-20261124.jsonl',
  json_encode($GLOBALS['mad4b_nav_bench_record'],JSON_UNESCAPED_SLASHES).PHP_EOL,FILE_APPEND|LOCK_EX);
});
