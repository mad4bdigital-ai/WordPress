<?php
/**
 * Read-only per-tenant sales/content variant inventory.
 * This module deliberately does not read or export customer, credential,
 * email, GTM, OAuth, order or payment values.
 */
if (!defined('WP_CLI') || !WP_CLI) throw new RuntimeException('WP CLI only');
global $wpdb;
$domain=(string)getenv('MAD4B_TENANT_DOMAIN');
if($domain!=='allroyalegypt.com') throw new RuntimeException('Unexpected tenant');
$home=(int)get_option('page_on_front',0);
$homePost=$home?get_post($home):null;
$icl=get_option('icl_sitepress_settings',[]);
$active=array_values(array_filter((array)($icl['active_languages']??[]),'is_string'));
sort($active);
$hidden=array_values(array_filter((array)($icl['hidden_languages']??[]),'is_string'));
sort($hidden);
$about=$wpdb->get_results($wpdb->prepare("SELECT ID,post_status,post_name FROM {$wpdb->posts} WHERE post_type='page' AND post_name IN (%s,%s) ORDER BY ID",
'about-us','about-us-2'),ARRAY_A);
$sectionLinks=[];
$dynamicWidgets=0;
$shortcodes=0;
if($homePost){
 $data=json_decode((string)get_post_meta($home,'_elementor_data',true),true);
 $walk=function($nodes)use(&$walk,&$sectionLinks,&$dynamicWidgets,&$shortcodes){
  if(!is_array($nodes))return;
  foreach($nodes as $node){
   if(!is_array($node))continue;
   $settings=$node['settings']??[];
   $type=(string)($node['widgetType']??'');
   if($type==='template'){
    $id=(int)($settings['template_id']??0);
    $p=$id?get_post($id):null;
    $sectionLinks[]=['kind'=>'template','source_slug'=>$p?$p->post_name:null,
      'source_title'=>$p?$p->post_title:null,'target_present'=>(bool)$p,
      'target_post_type'=>$p?$p->post_type:null];
   } elseif($type==='shortcode') {
    $shortcodes++;
    $text=(string)($settings['shortcode']??'');
    preg_match('/\[\s*([a-z0-9_-]+)/i',$text,$m);
    $sectionLinks[]=['kind'=>'shortcode','shortcode_name'=>$m[1]??null,'source_values_exported'=>false];
   } elseif($type==='jet-listing-grid') $dynamicWidgets++;
   $walk($node['elements']??[]);
  }
 };
 $walk($data);
}
$combined=array_values(array_filter($sectionLinks,static fn($r)=>
 ($r['kind']==='template') && in_array(strtolower((string)($r['source_title']??'')),['combined tours','multi country tours','multi-country tours'],true)));
$rateCounts=$wpdb->get_results("SELECT meta_key,COUNT(DISTINCT post_id) AS n FROM {$wpdb->postmeta} WHERE meta_key IN ('base_currency','has_egyptians_price','tour_base_currency','egyptians_currency','prices_policy') GROUP BY meta_key",ARRAY_A);
$translated=$wpdb->get_results("SELECT language_code,COUNT(*) AS n FROM {$wpdb->prefix}icl_translations GROUP BY language_code",ARRAY_A);
$aboutSlug=array_column($about,'post_name');
$flags=[
 'current_home_page_exists'=>($homePost&&$homePost->post_type==='page'&&$homePost->post_status==='publish'),
 'about_us_published'=>in_array('about-us',$aboutSlug,true),
 'multi_country_combined_tours_exists'=>count($combined)>0,
 'six_languages_configured'=>count($active)===6 && !array_diff(['ar','de','en','es','fr','it'],$active),
 'english_default'=>is_array($icl)&&($icl['default_language']??null)==='en',
 'arabic_visibility_preserved_in_snapshot'=>in_array('ar',$hidden,true),
];
echo wp_json_encode([
 'contract'=>'mad4b.company.all-royal-variant-audit.v1',
 'tenant'=>$domain,'read_only'=>true,'data_mutated'=>false,
 'home'=>['exists'=>(bool)$homePost,'published'=>$flags['current_home_page_exists'],
  'template_bindings'=>$sectionLinks,'shortcode_count'=>$shortcodes,
  'multi_country_variant'=>'combined_tours','combined_tours_count'=>count($combined),
  'demo_daytour_your_way_automatic_fallback_allowed'=>false],
 'about'=>['page_slugs'=>$aboutSlug,'about_us_published'=>$flags['about_us_published']],
 'wpml'=>['configured_languages'=>$active,'hidden_languages'=>$hidden,
  'default_language'=>$icl['default_language']??null,'translated_language_counts'=>$translated],
 'sales'=>['rate_policy_meta_key_counts'=>$rateCounts,'multi_country_checkout_behavior_verified'=>false],
 'local_source_flags'=>$flags,
 'pending'=>['JetEngine custom query provider resolution','sales market/currency and nationality functional QA',
 'about translation/navigation QA','home dynamic data and external origin QA'],
 'go_live_allowed'=>false
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),PHP_EOL;
