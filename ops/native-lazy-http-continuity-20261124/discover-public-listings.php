<?php
/**
 * Site-local WP-CLI discovery. No hard-coded language, route, doc, listing,
 * company, query, market or widget IDs. Read-only: does not issue certificates.
 */
if(!defined('WP_CLI') || !WP_CLI)exit(9);
if(!function_exists('mad4b_render_query_market_identity')
  ||!function_exists('mad4b_secondary_grid_lazy_parent_condition_proven'))
 throw new RuntimeException('Native company listing engine not installed');
$host=(string)wp_parse_url(home_url('/'),PHP_URL_HOST);
$scheme=(string)wp_parse_url(home_url('/'),PHP_URL_SCHEME);
if($host===''||!in_array($scheme,['https','http'],true))throw new RuntimeException('Invalid site');
$kit=(int)get_option('elementor_active_kit',0);
$kitSettings=(array)get_post_meta($kit,'_elementor_page_settings',true);
$mobile=(int)($kitSettings['viewport_mobile']??0);
if($mobile<480||$mobile>1100)throw new RuntimeException('No supported Elementor breakpoint');
$cssEpoch=(int)get_option('mad4b_css_ai_epoch_v1',-1);
$registry=get_option('mad4b_secondary_grid_lazy_layout_proofs_v1',[]);
$wpmlOptions=get_option('icl_sitepress_settings',[]);
$langs=array_values(array_unique((array)($wpmlOptions['active_languages']??[])));
sort($langs);
$homeId=(int)get_option('page_on_front',0);
if(!$homeId)throw new RuntimeException('Public static homepage not configured');
$rootLanguage=(string)apply_filters('wpml_current_language','');
$context=mad4b_render_query_market_identity();
$currencyOptions=get_option('_wcml_settings',[]);
$currencies=array_keys((array)($currencyOptions['currency_options']??[]));
$currencies=array_values(array_filter($currencies,
 static fn($x)=>is_string($x)&&preg_match('/^[A-Z]{3}$/D',$x)));
sort($currencies);
$discovered=[];
$routes=[];
foreach($langs as $lang){
 if(!is_string($lang)||!preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/D',$lang))continue;
 do_action('wpml_switch_language',$lang);
 $page=(int)apply_filters('wpml_object_id',$homeId,'page',false,$lang);
 if($page&&get_post_status($page)==='publish')$routes[$lang.'|'.$page]=['locale'=>$lang,'page'=>$page];
}
// Rotate through public pages in bounded chunks; home translations are
// always in the daily cycle. Other public routes converge over successive
// runs, even after the number of languages/pages grows.
global $wpdb;
$cursor=(int)(getenv('MAD4B_COVERAGE_CURSOR')?:0);
$cursor=max(0,$cursor);
$pageBatch=max(10,min(120,(int)(getenv('MAD4B_COVERAGE_PAGE_BATCH')?:60)));
$pageIds=$wpdb->get_col($wpdb->prepare(
 "SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_status=%s AND ID>%d ORDER BY ID ASC LIMIT %d",
 'page','publish',$cursor,$pageBatch+1
));
$hasMore=count($pageIds)>$pageBatch;
if($hasMore)array_pop($pageIds);
$nextCursor=$hasMore?(int)end($pageIds):0;
foreach($pageIds as $page){
 $page=(int)$page;
 if(!$page || get_post_status($page)!=='publish')continue;
 $details=apply_filters('wpml_post_language_details',null,$page);
 $lang=is_array($details)?(string)($details['language_code']??''):'';
 if(!in_array($lang,$langs,true))continue;
 $routes[$lang.'|'.$page]=['locale'=>$lang,'page'=>$page];
}
try{
 foreach($routes as $route){
  $lang=$route['locale'];$page=$route['page'];
  do_action('wpml_switch_language',$lang);
  $url=(string)get_permalink($page);
  if((string)wp_parse_url($url,PHP_URL_HOST)!==$host)continue;
  $visited=[];
  $scan=function($document,$depth=0) use (&$scan,&$visited,&$discovered,$url,$lang,$page,
      $registry,$context,$host,$cssEpoch,$mobile){
   $document=(int)$document;
   if($depth>6||!$document||isset($visited[$document]))return;
   $visited[$document]=true;
   if(get_post_status($document)!=='publish')return;
   $raw=get_post_meta($document,'_elementor_data',true);
   if(!is_string($raw)||strlen($raw)<200||strlen($raw)>3000000)return;
   $tree=json_decode($raw,true);
   if(!is_array($tree))return;
   $walk=function($nodes)use(&$walk,$scan,$document,$depth,&$discovered,$url,$lang,$page,$registry,
     $context,$host,$cssEpoch,$mobile){
    foreach((array)$nodes as $node){
     if(!is_array($node))continue;
     $settings=(array)($node['settings']??[]);
     if(($node['widgetType']??'')==='template'){
      $ref=(int)($settings['template_id']??0);
      if($ref)$scan($ref,$depth+1);
     }
     if(($node['widgetType']??'')==='jet-listing-grid'){
      $query=(int)($settings['custom_query_id']??0);
      $listing=(int)($settings['lisitng_id']??0);
      $widget=(string)($node['id']??'');
      $isEligible=$query>0&&$listing>0
       &&get_post_status($listing)==='publish'
       &&preg_match('/^[a-f0-9]{7,9}$/D',$widget)
       &&mad4b_secondary_grid_lazy_parent_condition_proven($query,$listing,$document);
      if($isEligible){
       $engine=\Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id($query);
       $isEligible=$engine
        &&($engine->query_type??'')==='terms'
        &&!mad4b_render_query_sensitive_semantics($engine);
      }
      if($isEligible){
       $listingRaw=get_post_meta($listing,'_elementor_data',true);
       $style=WP_CONTENT_DIR.'/uploads/elementor/css/post-'.$listing.'.css';
       if(!is_string($listingRaw)||strlen($listingRaw)<200||!is_file($style)||is_link($style)
           ||filesize($style)<100||filesize($style)>1048576)continue;
       $market=is_array($context)&&isset($context['country'],$context['currency'])
        ? strtolower((string)$context['country'].'-'.(string)$context['currency']):'';
       $key=$lang.'|'.$page.'|'.$document.'|'.$widget.'|'.$query.'|'.$listing;
       $entry=$registry['entries'][$listing.'|'.$lang.'|mobile']['ajax_parity_by_market'][$market]??null;
       $discovered[$key]=[
        'locale'=>$lang,'url'=>$url,'page_id'=>$page,'document_id'=>$document,
        'widget_id'=>$widget,'query_id'=>$query,'listing_id'=>$listing,
        'query_type'=>'terms',
        'document_sha256'=>hash('sha256',(string)get_post_meta($document,'_elementor_data',true)),
        'listing_sha256'=>hash('sha256',$listingRaw),
        'listing_css_sha256'=>hash_file('sha256',$style),
        'query_generation'=>function_exists('mad4b_render_query_generation_for_id')?mad4b_render_query_generation_for_id($query):null,
        'market'=>$market,
        'status'=>is_array($entry)?(string)($entry['status']??'unknown'):'uncovered',
        'version_match'=>function_exists('mad4b_render_query_generation_for_id')&&is_array($entry)
          &&($entry['query_generation']??null)===mad4b_render_query_generation_for_id($query),
        'runtime_admitted'=>mad4b_secondary_grid_lazy_ajax_parity_proven($query,$listing,$document),
        'css_epoch'=>$cssEpoch,'mobile_breakpoint'=>$mobile,
       ];
      }
     }
     if(isset($node['elements'])&&is_array($node['elements']))$walk($node['elements']);
    }
   };
   $walk($tree);
  };
  $scan($page);
 }
} finally {
 if($rootLanguage!=='')do_action('wpml_switch_language',$rootLanguage);
}
$records=array_values($discovered);
usort($records,static fn($a,$b)=>strcmp($a['locale'].'|'.$a['url'].'|'.$a['widget_id'],$b['locale'].'|'.$b['url'].'|'.$b['widget_id']));
if(count($records)>256)throw new RuntimeException('Excessive discovered widget count');
echo wp_json_encode([
 'contract'=>'mad4b.native-lazy.auto-discovery.v1',
 'site_host'=>$host,'home_url'=>home_url('/'),
 'site_environment'=>wp_get_environment_type(),
 'css_epoch'=>$cssEpoch,'mobile_breakpoint'=>$mobile,
 'engine_min_items'=>max(18,min(100,(int)get_option('mad4b_secondary_grid_lazy_heavy_terms_threshold',24))),
 'wpml_languages'=>$langs,
 'wcml_configured_currencies'=>$currencies,
 'observed_wcml_market'=>$context,
 'page_scan'=>['cursor'=>$cursor,'next_cursor'=>$nextCursor,'batch_size'=>$pageBatch,
  'page_ids_scanned'=>count($pageIds),'public_routes_in_cycle'=>count($routes)],
 'discovered_count'=>count($records),
 'candidates'=>$records
],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";
