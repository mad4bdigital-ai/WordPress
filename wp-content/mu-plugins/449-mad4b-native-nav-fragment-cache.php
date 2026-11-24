<?php
/**
 * Plugin Name: MAD4B Native Navigation SSR Fragment Memo
 * Description: Short-lived, request-context-scoped native Elementor Mega Menu HTML + asset enqueue memo.
 * Version: 1.0.0
 * Use only for one well-identified Tour header component; never cache commercial decisions.
 */
defined('ABSPATH') || exit;

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
  $key='nav_'.hash('sha256',wp_json_encode([
    'v'=>3,'site'=>home_url('/'),'host'=>(string)($_SERVER['HTTP_HOST']??''),'theme'=>get_stylesheet(),
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
  $t=microtime(true);
  $cached=wp_cache_get($key,'mad4b_native_nav_fragment_v1');
  if (is_array($cached) && isset($cached['html'],$cached['js'],$cached['css'])
      && is_string($cached['html']) && strlen($cached['html'])>40000
      && is_string($cached['digest']??null)
      && hash_equals($cached['digest'],hash('sha256',$cached['html']))
      && strpos($cached['html'],'navigation-editor-grid')!==false
      && is_array($cached['js']) && is_array($cached['css'])) {
    foreach($cached['js'] as $handle) if(is_string($handle))wp_enqueue_script($handle);
    foreach($cached['css'] as $handle) if(is_string($handle))wp_enqueue_style($handle);
    $GLOBALS['mad4b_nav_bench_record'][]=['status'=>'HIT','ms'=>round((microtime(true)-$t)*1000,2),
      'bytes'=>strlen($cached['html']),'scripts'=>count($cached['js']),'styles'=>count($cached['css'])];
    return apply_filters('do_shortcode_tag',$cached['html'],$tag,$attrs,$match);
  }
  global $shortcode_tags, $wp_scripts, $wp_styles;
  $callback=$shortcode_tags[$tag]??null;
  if(!is_callable($callback))return $pre;
  $prevJS=$wp_scripts instanceof WP_Scripts?$wp_scripts->queue:[];
  $prevCSS=$wp_styles instanceof WP_Styles?$wp_styles->queue:[];
  $out=call_user_func($callback,$attrs,null,$tag);
  if(!is_string($out))return $pre;
  $freshJS=$wp_scripts instanceof WP_Scripts?array_values(array_diff($wp_scripts->queue,$prevJS)):[];
  $freshCSS=$wp_styles instanceof WP_Styles?array_values(array_diff($wp_styles->queue,$prevCSS)):[];
  $hasSensitive=preg_match('/nonce|woocommerce|data-cart|add-to-cart|\bprice\b|\bform\b|data-user|wp-admin|customer|checkout|payment|email=/i',$out);
  $store=strlen($out)>40000 && strlen($out)<500000 && !$hasSensitive && strpos($out,'navigation-editor-grid')!==false;
  if($store)wp_cache_set($key,['html'=>$out,'digest'=>hash('sha256',$out),
    'js'=>$freshJS,'css'=>$freshCSS],
    'mad4b_native_nav_fragment_v1',get_current_user_id()?15:60);
  $GLOBALS['mad4b_nav_bench_record'][]=['status'=>$store?'MISS_STORED':'MISS_NOT_STORED',
    'ms'=>round((microtime(true)-$t)*1000,2),'bytes'=>strlen($out),'js'=>$freshJS,'css'=>$freshCSS,
    'sensitive'=>$hasSensitive?1:0];
  return apply_filters('do_shortcode_tag',$out,$tag,$attrs,$match);
},50,4);
register_shutdown_function(static function() {
 if (empty($GLOBALS['mad4b_nav_bench_record']) || ($_SERVER['HTTP_X_MAD4B_NAV_CACHE_RECEIPT']??'')!=='receipt-20261124')return;
 @file_put_contents('/tmp/mad4b-native-nav-cache-receipts-20261124.jsonl',
  json_encode($GLOBALS['mad4b_nav_bench_record'],JSON_UNESCAPED_SLASHES).PHP_EOL,FILE_APPEND|LOCK_EX);
});
