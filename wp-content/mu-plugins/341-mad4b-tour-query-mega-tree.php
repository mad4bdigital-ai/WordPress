<?php
/**
 * Plugin Name: MAD4B Shared Tours Query Mega Tree (Staging)
 * Description: Elementor Tours menu and JSF archive share JetEngine Query #5 / tour-types; recursive desktop mega flyouts and mobile dropdowns.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Tour_Query_Mega_Tree {
 const UUID='e5e64bca-ed62-48d2-b2b3-dff693cd702a';
 const HOST='tours.dev.mad4b.com';
 const NAV='6072b3f';
 const KEY='tours';
 const QUERY=5;
 const FILTER=15035;
 const TAX='tour-types';
 const ARCHIVE_ID='tours_query_archive';
 private static $in_render=false;
 private static $memo=[];
 public static function facet_specs():array {
  return [
   ['key'=>'tour-type','label'=>'Tour Type','taxonomy'=>'tour-types','filter_id'=>15035],
   ['key'=>'tour-season','label'=>'Tour Season','taxonomy'=>'season','filter_id'=>66031],
   ['key'=>'good-for','label'=>'Good for','taxonomy'=>'good-for','filter_id'=>15902],
   ['key'=>'tour-style','label'=>'Tour Style','taxonomy'=>'tour-styles','filter_id'=>15032],
   ['key'=>'day-of-week','label'=>'Day of Week','taxonomy'=>'schudle-days-of-weak','filter_id'=>15903],
   ['key'=>'day-time','label'=>'Day Time','taxonomy'=>'day-times','filter_id'=>15904],
  ];
 }
 public static function active_facets():array {
  $result=[];
  $specs=apply_filters('mad4b_tour_menu_facet_specs',self::facet_specs());
  if(!is_array($specs))return [];
  foreach($specs as $f){
   if(!is_array($f)||!isset($f['filter_id'],$f['taxonomy'],$f['key'],$f['label']))continue;
   $id=absint($f['filter_id']);$tax=(string)$f['taxonomy'];
   if(!$id||get_post_status($id)!=='publish'||get_post_type($id)!=='jet-smart-filters'
    ||!taxonomy_exists($tax)||get_post_meta($id,'_source_taxonomy',true)!==$tax
    ||get_post_meta($id,'_data_source',true)!=='taxonomies')continue;
   $result[]=$f;
  }
  return $result;
 }

 public static function scope():bool {
  if(wp_get_environment_type()!=='staging'||strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST))!==self::HOST)return false;
  $profile=get_option('mad4b_scp_brand_context_profile_v1',[]);
  return is_array($profile)&&($profile['site_uuid']??'')===self::UUID;
 }
 public static function source_valid():bool {
  if(!self::scope()||!function_exists('product_navigation_eligible_post_ids')||
     !function_exists('product_navigation_source')||
     !class_exists('Presentation_Policy_Context_v1'))return false;
  $binding=self::provider_binding();
  $tax=(string)($binding['taxonomy']??'');
  $query_id=absint($binding['query_builder_id']??0);
  $filter_id=absint($binding['filter_id']??0);
  return $query_id>0 && $tax===self::TAX
    && $filter_id>0 && (string)get_post_meta($filter_id,'_data_source',true)==='taxonomies'
    && post_type_exists('tours-and-activities')
    && taxonomy_exists(self::TAX)
    && (bool)Presentation_Policy_Context_v1::navigation_query(self::KEY);
 }
 public static function language():string {
  $language=(string)(apply_filters('wpml_current_language',null) ?: apply_filters('wpml_default_language',null) ?: 'en');
  return preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/D',$language)?$language:'en';
 }
 public static function provider_binding(string $taxonomy=self::TAX):array {
  $source=function_exists('product_navigation_source')?(array)product_navigation_source(self::KEY):[];
  $filter_id=0;
  foreach(self::active_facets() as $facet)
   if($facet['taxonomy']===$taxonomy){$filter_id=absint($facet['filter_id']);break;}
  if(!$filter_id)return [];

  return [
   'query_builder_id'=>absint($source['query_id']??0),
   'filter_id'=>$filter_id,
   'taxonomy'=>(string)get_post_meta($filter_id,'_source_taxonomy',true),
   'provider_query'=>(string)apply_filters('mad4b_tour_menu_provider_query',self::ARCHIVE_ID),
   'provider'=>'jet-engine',
  ];
 }
 public static function route(WP_Term $term,string $language):string {
  $b=self::provider_binding($term->taxonomy);
  $qid=(string)($b['provider_query']??'');
  $taxonomy=(string)($b['taxonomy']??'');
  if(empty($b['query_builder_id'])||!preg_match('/^[a-z0-9_-]{1,80}$/D',$qid)
    ||$taxonomy!==$term->taxonomy||!taxonomy_exists($taxonomy))return '';
  $base=get_post_type_archive_link('tours-and-activities');
  if(!$base)return '';
  $base=(string)apply_filters('wpml_permalink',$base,$language,true);
  if(!self::safe_url($base)||!function_exists('jet_smart_filters'))return '';
  $vendor=jet_smart_filters();
  if(!isset($vendor->utils)||!is_callable([$vendor->utils,'get_filtered_url']))return '';
  $mode=(string)$vendor->settings->get('url_structure_type');
  if(!in_array($mode,['plain','permalink'],true))return '';
  $term_mode=(string)$vendor->settings->get('url_taxonomy_term_name');
  $value=$term_mode==='term_id'?(string)$term->term_id:$term->slug;
  try {
   // Vendor owns plain/permalink structure, URL symbols, term encoding and
   // alias rules. Never synthesize a permalink that may become obsolete.
   $native=$vendor->utils->get_filtered_url($base,$qid,'jet-engine',[
    ['query_type'=>'tax_query','query_var'=>$taxonomy,
     'value'=>$value,'filter_type'=>'checkboxes']
   ]);
  }catch(\Throwable $e){return '';}
  if(!is_string($native)||!self::safe_url($native))return '';
  $native=esc_url_raw($native);
  // Dynamic Filter SEO's published-route filter returns the source URL
  // unchanged when IndexFlow is inactive or lacks an approved route.
  if(has_filter('etg_filter_seo_publication_url')){
   try {
    $candidate=apply_filters('etg_filter_seo_publication_url',$native);
    if(is_string($candidate)&&self::safe_url($candidate))
      return esc_url_raw($candidate);
   }catch(\Throwable $e){}
  }
  return $native;
 }
 public static function safe_url(string $url):bool {
  if(strlen($url)>2048)return false;
  $u=wp_parse_url($url);
  return is_array($u)
   &&($u['scheme']??'')==='https'
   &&strtolower((string)($u['host']??''))===self::HOST
   &&empty($u['user'])&&empty($u['pass'])
   &&empty($u['fragment'])
   &&str_starts_with((string)($u['path']??''),'/')
   &&!str_contains(rawurldecode((string)($u['path']??'')),'..')
   &&!preg_match('#/(?:wp-admin|wp-json|wp-login\.php)(?:/|$)#i',(string)($u['path']??''));
 }
 /** Tree shape and availability are separate: new roots appear immediately,
     but cannot navigate to an empty archive until Query #5 supplies a tour. */
 public static function prepare_tree(array $terms,array $eligible_ids,string $taxonomy=self::TAX):array {
  $terms_by_id=[];
  foreach($terms as $term)if($term instanceof WP_Term
    &&$term->taxonomy===$taxonomy
    &&$term->term_id>0)$terms_by_id[(int)$term->term_id]=$term;
  if(count($terms_by_id)>180)return []; // bounded menu footprint
  $active=array_fill_keys(array_map('intval',$eligible_ids),true);
  $children=[];$roots=[];
  foreach($terms_by_id as $id=>$term){
   $parent=(int)$term->parent;
   if($parent===$id)$parent=0;
   if($parent && isset($terms_by_id[$parent]))$children[$parent][]=$id;
   else $roots[]=$id;
  }
  $sort=static function(&$ids)use($terms_by_id){
   usort($ids,static function($a,$b)use($terms_by_id){
    $rank=(int)$terms_by_id[$a]->term_order<=>(int)$terms_by_id[$b]->term_order;
    return $rank?:strnatcasecmp($terms_by_id[$a]->name,$terms_by_id[$b]->name);
   });
  };
  $sort($roots);foreach($children as &$ids)$sort($ids);unset($ids);
  $build=static function($ids,array $ancestors=[])use(&$build,$terms_by_id,$children,$active){
   $out=[];
   foreach($ids as $id){
    if(isset($ancestors[$id]))continue; // fail closed on cycles
    $next=$ancestors;$next[$id]=true;
    $sub=$build($children[$id]??[],$next);
    $enabled=isset($active[$id]);
    foreach($sub as $child)if($child['available']){$enabled=true;break;}
    $out[]=['id'=>$id,'term'=>$terms_by_id[$id],'available'=>$enabled,'direct'=>isset($active[$id]),'children'=>$sub];
   }
   return $out;
  };
  return $build($roots);
 }
 public static function data():array {
  if(!self::source_valid())return [];
  $lang=self::language();
  if(isset(self::$memo[$lang]))return self::$memo[$lang];
  // ONE shared eligible-post set from the existing Query Builder #5 authority.
  $eligible=product_navigation_eligible_post_ids(self::KEY,$lang);
  if(!is_array($eligible))return [];
  $ids=array_values(array_unique(array_map('intval',$eligible)));
  $facets=[];
  foreach(self::active_facets() as $facet){
   $tax=$facet['taxonomy'];
   $terms=get_terms(['taxonomy'=>$tax,'hide_empty'=>false,'number'=>0,'lang'=>$lang]);
   if(is_wp_error($terms)||!is_array($terms))continue;
   $assigned=[];
   if($ids){
    $found=get_terms(['taxonomy'=>$tax,'hide_empty'=>false,'number'=>0,
     'fields'=>'ids','object_ids'=>$ids,'lang'=>$lang]);
    if(!is_wp_error($found))$assigned=array_map('intval',$found);
   }
   $facet['nodes']=self::prepare_tree($terms,$assigned,$tax);
   $facets[]=$facet;
  }
  return self::$memo[$lang]=[
   'language'=>$lang,
   'query'=>self::provider_binding()['query_builder_id']??0,
   'eligible_posts'=>count($ids),
   'facets'=>$facets,
  ];
 }
 private static function child_html(array $nodes,int $depth,string $language,string $taxonomy):string {
  if($depth>8||!$nodes)return '';
  $html='<ul class="mad4b-tour-tree-level" role="list">';
  foreach($nodes as $node){
   $term=$node['term'];$id=(int)$node['id'];
   $sub=$node['children'];$children=!empty($sub);
   $available=(bool)$node['available'];
   $classes=['mad4b-tour-tree-item','mad4b-tour-tree-depth-'.$depth];
   if($children)$classes[]='mad4b-tour-tree-parent';
   if(!$available)$classes[]='mad4b-tour-tree-empty';
   $html.='<li class="'.esc_attr(implode(' ',$classes)).'" data-term-id="'.$id.'">';
   $html.='<div class="mad4b-tour-tree-row">';
   if($available){
    $url=self::route($term,$language);
    if(!$url){$html.='<span class="mad4b-tour-tree-label">'.esc_html($term->name).'</span>';}
    else{$html.='<a class="mad4b-tour-tree-link" href="'.esc_url($url).'">'.esc_html($term->name).'</a>';}
   }else{
    $html.='<span class="mad4b-tour-tree-label" aria-disabled="true" title="'.esc_attr__('No tours available yet','mad4b').'">'.esc_html($term->name).'</span>';
   }
   if($children){
    $html.='<button class="mad4b-tour-tree-toggle" type="button" aria-expanded="false" aria-label="'.esc_attr(sprintf(__('Expand %s','mad4b'),$term->name)).'" aria-controls="mad4b-tour-children-'.esc_attr($taxonomy).'-'.$id.'"><span aria-hidden="true">⌄</span></button>';
   }
   $html.='</div>';
   if($children){
    $html.='<div id="mad4b-tour-children-'.esc_attr($taxonomy).'-'.$id.'" class="mad4b-tour-tree-children">';
    $html.=self::child_html($sub,$depth+1,$language,$taxonomy);
    $html.='</div>';
   }
   $html.='</li>';
  }
  return $html.'</ul>';
 }
 public static function markup(array $data):string {
  if(empty($data['facets'])||!isset($data['language']))return '';
  $language=$data['language'];
  $all=(string)apply_filters('wpml_translate_single_string','Explore all tours','Product navigation','tours-all',$language);
  $html='<nav class="mad4b-tour-query-mega mad4b-tour-facet-nav" aria-label="'
   .esc_attr__('Explore tours by category','mad4b').'" data-source-query="'.absint($data['query'])
   .'" data-archive-provider="'.esc_attr(self::provider_binding()['provider_query']??'').'">';
  $html.='<div class="mad4b-tour-facet-tabs" role="tablist" aria-label="'.esc_attr__('Tour filters','mad4b').'">';
  foreach($data['facets'] as $index=>$facet){
   $key=$facet['key'];$selected=$index===0;
   $label=(string)apply_filters('wpml_translate_single_string',$facet['label'],'Product navigation','tour-facet-'.$key,$language);
   $html.='<button type="button" role="tab" class="mad4b-tour-facet-tab"'
    .' id="mad4b-tour-tab-'.esc_attr($key).'"'
    .' aria-controls="mad4b-tour-panel-'.esc_attr($key).'"'
    .' aria-selected="'.($selected?'true':'false').'"'
    .' tabindex="'.($selected?'0':'-1').'"'
    .' data-taxonomy="'.esc_attr($facet['taxonomy']).'"'
    .' data-filter-id="'.absint($facet['filter_id']).'">'.esc_html($label).'</button>';
  }
  $html.='</div><div class="mad4b-tour-facet-panels">';
  foreach($data['facets'] as $index=>$facet){
   $key=$facet['key'];$selected=$index===0;
   $html.='<section role="tabpanel" id="mad4b-tour-panel-'.esc_attr($key).'"'
    .' aria-labelledby="mad4b-tour-tab-'.esc_attr($key).'"'
    .' tabindex="0" data-facet-taxonomy="'.esc_attr($facet['taxonomy']).'"'
    .' data-source-filter="'.absint($facet['filter_id']).'"'
    .' class="mad4b-tour-facet-panel"'.($selected?'':' hidden').'>';
   $html.=self::child_html($facet['nodes'],0,$language,$facet['taxonomy']);
   $html.='</section>';
  }
  $html.='</div>';
  $archive=get_post_type_archive_link('tours-and-activities');
  if($archive)$html.='<a class="mad4b-tour-query-all" href="'.esc_url(apply_filters('wpml_permalink',$archive,$language,true)).'">'.esc_html($all).' <span aria-hidden="true">↗</span></a>';
  return $html.'</nav>';
 }
 public static function project($nodes,$document_id){
  if(!self::scope()||self::$in_render||!is_array($nodes)||is_admin())return $nodes;
  $data=self::data();
  if(!$data||!$html=self::markup($data))return $nodes;
  self::$in_render=true;
  $visit=static function($items)use(&$visit,$html){
   foreach($items as &$node){
    if(($node['id']??'')===MAD4B_Tour_Query_Mega_Tree::NAV
      &&($node['widgetType']??'')==='mega-menu'
      &&strpos((string)($node['settings']['_css_classes']??''),'product-native-navigation')!==false){
      foreach(($node['settings']['menu_items']??[]) as $i=>$item){
       if(($item['product_navigation_key']??'')!==MAD4B_Tour_Query_Mega_Tree::KEY)continue;
       if(!isset($node['elements'][$i]))continue;
       $panel=&$node['elements'][$i];
       $panel['elements']=[
        ['id'=>'m4b'.substr(md5('tour-query-nested-menu'),0,4),'elType'=>'widget','widgetType'=>'html',
         'settings'=>['html'=>$html,'_css_classes'=>'mad4b-tour-tree-native-elementor','_element_width'=>'100%'],
         'elements'=>[]]
       ];
       unset($panel);
      }
    }else if(!empty($node['elements'])&&is_array($node['elements']))$node['elements']=$visit($node['elements']);
   }unset($node);
   return $items;
  };
  $nodes=$visit($nodes);
  self::$in_render=false;
  return $nodes;
 }
 public static function assets():void {
  if(!self::scope()||is_admin())return;
  $root=WP_CONTENT_DIR.'/mu-plugins/assets/';
  $url=content_url('/mu-plugins/assets/');
  $css=$root.'mad4b-tour-query-tree.css';$js=$root.'mad4b-tour-query-tree.js';
  if(is_readable($css))wp_enqueue_style('mad4b-tour-query-tree',$url.'mad4b-tour-query-tree.css',[],(string)filemtime($css));
  if(is_readable($js))wp_enqueue_script('mad4b-tour-query-tree',$url.'mad4b-tour-query-tree.js',[],(string)filemtime($js),['in_footer'=>true,'strategy'=>'defer']);
 }
 public static function flush_on_term():void {
  if(!self::scope())return;
  wp_cache_flush();
  if(function_exists('do_action'))do_action('litespeed_purge_all');
 }
 public static function boot():void {
  if(!self::scope())return;
  add_filter('elementor/frontend/builder_content_data',[__CLASS__,'project'],75,2);
  add_action('wp_enqueue_scripts',[__CLASS__,'assets'],95);
  add_filter('elementor/element/is_dynamic_content',static function($dynamic,$raw){
   if(str_contains((string)($raw['settings']['_css_classes']??''),'mad4b-tour-tree-native-elementor'))return true;
   return $dynamic;
  },45,2);
  foreach(self::active_facets() as $facet)
   foreach(['created_','edited_','delete_'] as $event)
    add_action($event.$facet['taxonomy'],[__CLASS__,'flush_on_term'],30);
  add_action('set_object_terms',static function($object_id,$terms,$tt_ids,$taxonomy){
   if(in_array($taxonomy,array_merge(array_column(MAD4B_Tour_Query_Mega_Tree::active_facets(),'taxonomy'),['tour_categories','destinations']),true))MAD4B_Tour_Query_Mega_Tree::flush_on_term();
  },30,4);
 }
}
add_action('init',['MAD4B_Tour_Query_Mega_Tree','boot'],22);
