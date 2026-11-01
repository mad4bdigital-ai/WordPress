<?php
/**
 * Plugin Name: MAD4B JetBlog Server-Rendered First Paint (Staging)
 * Description: Avoid duplicate initial mobile JetBlog requests when server-rendered posts already match.
 * Version: 1.0.0
 * Bound to one Staging origin and six pinned WPML homepage templates.
 */
defined('ABSPATH')||exit;
if(PHP_SAPI==='cli'||wp_get_environment_type()!=='staging'
 ||($_SERVER['REQUEST_METHOD']??'')!=='GET'
 ||($_SERVER['HTTP_HOST']??'')!=='tours.dev.mad4b.com'
 ||($_SERVER['HTTP_X_MAD4B_JETBLOG_FIRST_PAINT_CONTROL']??'')==='native-v1'
 ||!empty($_SERVER['HTTP_COOKIE'])||!empty($_SERVER['QUERY_STRING'])
 ||!empty($_SERVER['HTTP_AUTHORIZATION'])||!empty($_SERVER['HTTP_X_MAD4B_CURRENCY'])
 ||!empty($_SERVER['HTTP_X_GEO_COUNTRY'])||!empty($_SERVER['HTTP_CF_IPCOUNTRY'])
 ||!empty($_SERVER['HTTP_X_FORWARDED_GEO_COUNTRY']))return;
add_action('elementor/frontend/after_enqueue_scripts', static function(){
 if(!is_front_page()||is_preview()||is_user_logged_in()||is_admin()||wp_doing_ajax())return;
 $paths=['/'=>'en','/fr/'=>'fr','/de/'=>'de','/ar/'=>'ar','/es/'=>'es','/it/'=>'it'];
 $path=(string)wp_parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
 $locale=apply_filters('wpml_current_language',null);
 if(!isset($paths[$path])||$paths[$path]!==$locale)return;
 if(!function_exists('get_woocommerce_currency')||get_woocommerce_currency()!=='USD')return;
 if(!function_exists('jet_blog')||jet_blog()->get_version()!=='2.4.10.1')return;
 if(!wp_script_is('jet-blog','enqueued'))return;
 $pins=['en'=>[43704,'3b41c9966d41c4256a78a172cbb8ecfee18063757ac08269aa99fa64ef226681'],
 'de'=>[43704,'3b41c9966d41c4256a78a172cbb8ecfee18063757ac08269aa99fa64ef226681'],
 'fr'=>[43777,'7d831e828fdae1fc3b9637276fcad935fdab4f3b32dbf149f0135b5351102682'],
 'ar'=>[44251,'024ba24f4c310e7a545346088e8de739bebaf45bd7e083a10f35f49fc998c8ca'],
 'es'=>[56139,'5a0f491f6e2834962dedcceae6afe7d41a734b2b67343a3c066a90e78418a81c'],
 'it'=>[57097,'27d6f0e559ff89e6d8f7d762cfd17cdf09814115d8ffad06cd3132247874ea90']];
 [$id,$hash]=$pins[$locale];
 if(hash('sha256',(string)get_post_meta($id,'_elementor_data',true))!==$hash)return;
 $profile=get_option('mad4b_scp_brand_context_profile_v1',[]);
 if(!is_array($profile)||($profile['site_uuid']??'')!=='e5e64bca-ed62-48d2-b2b3-dff693cd702a')return;
 $script=<<<'JS'
(function($){
"use strict";
$(document).on('jet-blog-smart-list/init.mad4b-first-paint',function(event,$scope){
 var id=String($scope&&$scope.data('id')||'');
 if(id!=='b44fd5b'&&id!=='1330604')return;
 var $wrap=$scope.find('.jet-smart-listing-wrap').first();
 if(!$wrap.length||$wrap.hasClass('jet-processing'))return;
 var opts=$wrap.data('settings')||{};
 if(Number(opts.posts_columns)!==1||Number(opts.posts_rows)!==3||opts.featured_post!=='yes'||opts.is_archive_template==='yes')return;
 var breakpoints=['widescreen','laptop','tablet_extra','tablet','mobile_extra','mobile'];
 for(var i=0;i<breakpoints.length;i++){
  var k=breakpoints[i];
  if(Number(opts['posts_columns_'+k])>0||Number(opts['posts_rows_'+k])>0)return;
 }
 if(!$wrap.find('.jet-smart-listing').children().length)return;
 $wrap.addClass('jet-processing');
 $wrap.attr('data-mad4b-server-first-paint','1');
 Promise.resolve().then(function(){$wrap.removeClass('jet-processing');});
});
})(jQuery);
JS;
 wp_add_inline_script('jet-blog',$script,'before');
},999);
