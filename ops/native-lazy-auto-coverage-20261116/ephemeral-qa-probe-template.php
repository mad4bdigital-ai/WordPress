<?php
/** MAD4B short-lived private browser QA; generated with a SHA-only token. */
defined('ABSPATH')||exit;
if(wp_get_environment_type()!=='staging'
 || !isset($_SERVER['HTTP_X_MAD4B_COVERAGE_PROBE'])
 || !preg_match('/^[a-f0-9]{64}$/D',(string)$_SERVER['HTTP_X_MAD4B_COVERAGE_PROBE']))return;
$cfg=json_decode(base64_decode('__MAD4B_PROBE_B64__',true),true);
if(!is_array($cfg) || time()>(int)($cfg['expires_at']??0)
 || time()<(int)($cfg['expires_at']??0)-900
 || !hash_equals((string)($cfg['token_sha256']??''),
    hash('sha256',(string)$_SERVER['HTTP_X_MAD4B_COVERAGE_PROBE']))
 || (string)wp_parse_url(home_url('/'),PHP_URL_HOST)!==($cfg['host']??'')
 || (int)get_option('mad4b_css_ai_epoch_v1',-1)!==(int)($cfg['css_epoch']??-2))return;
add_action('template_redirect',static function()use($cfg){
 if(is_user_logged_in()||wp_doing_ajax()||is_admin()
  ||(int)get_queried_object_id()!==(int)$cfg['page_id'])return;
 $lang=(string)apply_filters('wpml_current_language','');
 $identity=function_exists('mad4b_render_query_market_identity')
  ?mad4b_render_query_market_identity():[];
 $market=is_array($identity) && isset($identity['country'],$identity['currency'])
  ?strtolower((string)$identity['country'].'-'.(string)$identity['currency']):'';
 if($lang!==$cfg['locale'] || $market!==$cfg['market'])return;
 $d=(int)$cfg['document_id'];$l=(int)$cfg['listing_id'];$q=(int)$cfg['query_id'];
 $source=(string)get_post_meta($l,'_elementor_data',true);
 $css=WP_CONTENT_DIR.'/uploads/elementor/css/post-'.$l.'.css';
 if(get_post_status($d)!=='publish'||get_post_status($l)!=='publish'
  ||hash('sha256',$source)!==$cfg['listing_sha256']
  ||!is_file($css)||is_link($css)||hash_file('sha256',$css)!==$cfg['listing_css_sha256']
  ||mad4b_render_query_generation_for_id($q)!==$cfg['query_generation']
  ||!mad4b_secondary_grid_lazy_parent_condition_proven($q,$l,$d))return;
 $GLOBALS['mad4b_coverage_probe_verified']=$cfg;
 nocache_headers();
 header('X-MAD4B-Native-Grid-QA: 1');
 header('X-MAD4B-Native-Grid-QA-Market: '.$market);
},1);
add_filter('jet-engine/listing/grid/is_lazy_load',static function($on,$settings)use($cfg){
 if(empty($GLOBALS['mad4b_coverage_probe_verified'])||is_admin()||wp_doing_ajax()
  ||is_user_logged_in()||(int)get_queried_object_id()!==(int)$cfg['page_id']
  ||!is_array($settings))return $on;
 if((int)($settings['custom_query_id']??0)===(int)$cfg['query_id']
  &&(int)($settings['lisitng_id']??0)===(int)$cfg['listing_id'])return true;
 return $on;
},9999,2);
add_action('wp_head',static function()use($cfg){
 if(empty($GLOBALS['mad4b_coverage_probe_verified']))return;
 $widget=(string)$cfg['widget_id'];
 if(!preg_match('/^[a-f0-9]{7,9}$/D',$widget))return;
 $boundary=(int)$cfg['mobile_breakpoint'];
 $mob=(int)$cfg['mobile_height'];$wide=(int)$cfg['wide_height'];
 if($boundary<480||$boundary>1100||$mob<100||$wide<100||$mob>1600||$wide>1600)return;
 $sel='.elementor-element-'.$widget.' .jet-listing-grid--lazy-load:not(.jet-listing-grid--lazy-load-completed)';
 echo '<style id="mad4b-native-lazy-qa-reservation" data-no-optimize="1">'
  .'.elementor-widget-template:has(.elementor-element-'.$widget.'){width:100%!important;min-width:0!important}'
  .'.elementor-element-'.$widget.'{width:100%!important;min-width:0!important}'
  .'@media(max-width:'.$boundary.'px){'.$sel.'{min-block-size:'.$mob.'px!important;width:100%!important;box-sizing:border-box!important}}'
  .'@media(min-width:'.($boundary+1).'px){'.$sel.'{min-block-size:'.$wide.'px!important;width:100%!important;box-sizing:border-box!important}}'
  .'</style>';
},18);
