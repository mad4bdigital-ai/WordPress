<?php
namespace MAD4B\DFSB\NativePresentation;
if(!defined('ABSPATH'))exit;
final class SmartSeoContentGraph {
 const CONTRACT='mad4b.seo.content-graph.v1';
 public static function term(int $id,string $tax='',string $language='') {
  if($language!==''){
   if($tax==='')return new \WP_Error('taxonomy_required','Taxonomy required for language-specific term',['status'=>400]);
   $source=(int)apply_filters('wpml_object_id',$id,$tax,false,'en');
   if(!$source)return new \WP_Error('source_not_found','English source term missing',['status'=>404]);
   $localized=SmartSeoTranslation::translationId($source,$tax,$language);
   if(!$localized)return new \WP_Error('locale_term_missing','Translated term unavailable',['status'=>404]);
   return SmartSeoTranslation::withLanguage($language,
      static fn()=>self::term($localized,$tax));
  }
  if($language==='' && $tax!=='') {
   $assigned=(string)apply_filters('wpml_element_language_code',null,[
     'element_id'=>$id,'element_type'=>'tax_'.$tax]);
   $current=(string)apply_filters('wpml_current_language','en');
   if($assigned!==''&&$assigned!==$current)
     return self::term($id,$tax,$assigned);
  }
  $t=$tax?get_term($id,$tax):get_term($id);
  if(!$t instanceof \WP_Term)return new \WP_Error('term_missing','Unknown term',['status'=>404]);
  $taxobj=get_taxonomy($t->taxonomy);
  $url=get_term_link($t);
  if(is_wp_error($url))return $url;
  $description=wp_strip_all_tags(term_description($id,$t->taxonomy));
  $ancestors=get_ancestors($id,$t->taxonomy,'taxonomy');
  $parents=[];$children=[];
  foreach($ancestors as $a){$v=get_term($a,$t->taxonomy);if($v instanceof \WP_Term){$link=get_term_link($v);$parents[]=['id'=>$a,'url'=>is_wp_error($link)?'':$link];}}
  $terms=get_terms(['taxonomy'=>$t->taxonomy,'parent'=>$id,'hide_empty'=>false,'number'=>40]);
  if(!is_wp_error($terms))foreach($terms as $v){$u=get_term_link($v);$children[]=['id'=>$v->term_id,'url'=>is_wp_error($u)?'':$u,'title'=>$v->name];}
  $posts=[];
  foreach(['tours-and-activities','post','page','properties','transportations'] as $type) {
   if(!post_type_exists($type))continue;
   $q=new \WP_Query(['post_type'=>$type,'post_status'=>'publish','posts_per_page'=>4,'fields'=>'ids',
     'no_found_rows'=>false,'tax_query'=>[['taxonomy'=>$t->taxonomy,'field'=>'term_id','terms'=>[$id],'include_children'=>false]]]);
   if($q->found_posts)$posts[$type]=['count'=>(int)$q->found_posts,'sample_ids'=>array_map('intval',$q->posts)];
  }
  $incoming=[];$topicCandidates=[];$elementorCandidates=[];$scanned=0;
  foreach(['post','page'] as $type) {
   foreach(get_posts(['post_type'=>$type,'post_status'=>'publish','posts_per_page'=>100,'suppress_filters'=>false]) as $p) {
    $scanned++;
    $content=(string)$p->post_content;
    $elementor=get_post_meta($p->ID,'_elementor_data',true);
    $rawElementor=is_string($elementor)?$elementor:(is_array($elementor)?wp_json_encode($elementor):'');
    $rawElementor=str_replace('\\/','/',$rawElementor);
    $termPath=(string)wp_parse_url($url,PHP_URL_PATH);
    if($termPath!==''&&$rawElementor!==''&&str_contains($rawElementor,$termPath))
      $elementorCandidates[]=['id'=>$p->ID,'url'=>get_permalink($p),
       'title'=>$p->post_title,'evidence'=>'elementor_configuration_reference_not_rendered_anchor'];
    if($type==='post' && mb_strlen($t->name)>=5 && mb_stripos($p->post_title.' '.wp_strip_all_tags($content),$t->name)!==false)
      $topicCandidates[]=['id'=>$p->ID,'title'=>$p->post_title,'url'=>get_permalink($p),'evidence'=>'topic_mention_only'];
    if(str_contains($content,(string)wp_parse_url($url,PHP_URL_PATH))
      && preg_match('~<a[^>]+href=["\x27][^"\x27]*'.preg_quote((string)wp_parse_url($url,PHP_URL_PATH),'~').'[^"\x27]*["\x27]~i',$content))
      $incoming[]=['id'=>$p->ID,'url'=>get_permalink($p),'title'=>$p->post_title,'type'=>$type];
   }
  }
  $characters=mb_strlen(trim($description));
  $public=(bool)($taxobj->public??false);
  $readLanguage=(string)apply_filters('wpml_current_language','en');
  $actualLanguage=apply_filters('wpml_element_language_code',null,[
   'element_id'=>$t->term_id,'element_type'=>'tax_'.$t->taxonomy]);
  $wrongLocale= $actualLanguage!==$readLanguage;
  $richInformationalPage=$public&&!$wrongLocale&&$characters>=180;
  $tourCount=(int)($posts['tours-and-activities']['count']??0);
  return ['contract'=>self::CONTRACT,'type'=>'taxonomy_term','id'=>$id,
    'taxonomy'=>$t->taxonomy,'title'=>$t->name,'url'=>$url,
    'public_taxonomy'=>$public,'description_characters'=>$characters,
    'hierarchy_depth'=>count($parents),'parents'=>$parents,'children'=>$children,
    'assigned_published_posts'=>$posts,'linked_editorial_posts'=>$incoming,
    'editorial_scan_posts'=>$scanned,
    'wpml_read_language'=>$readLanguage,'term_language'=>$actualLanguage,
    'translation_valid'=>!$wrongLocale && $characters>0,
    'translation_issue'=>$wrongLocale?'wrong_locale_term':($characters===0?'description_missing':null),
    'related_blog_topic_candidates'=>array_slice($topicCandidates,0,15),
    'topic_mention_not_verified_link'=>true,
    'page_purpose'=>'informational_taxonomy_term',
    'information_page_content_candidate'=>$richInformationalPage,
    'information_page_can_be_useful_without_tours'=>$richInformationalPage && $tourCount===0,
    'tour_result_count'=>$tourCount,
    'actual_editorial_inlinks_count'=>count($incoming),
    'elementor_configuration_link_candidates'=>array_slice($elementorCandidates,0,20),
    'elementor_configuration_links_not_html_verified'=>true,
    'blog_topic_mentions_count'=>count($topicCandidates),
    'link_depth_measured_here'=>false,
    'link_depth_audit_route'=>'/wp-json/mad4b/v1/seo-intelligence/content-graph/crawl',
    'link_gap_status'=>count($incoming)?'verified_editorial_inlink':'no_editorial_inlink_found_in_scanned_posts',
    'page_crawl_orphan_status'=>'not_determined',
    'classification'=>!$public?'private_taxonomy':($wrongLocale?'wrong_language':($characters===0&&$actualLanguage!=='en'?'missing_translation':($characters>=180?'editorial_hub_candidate':'thin_term'))),
    'indexing_approved'=>false,'scope'=>'Saved taxonomy, published post assignments and literal editorial anchors'];
 }
 /**
  * Bounded localized content graph, distinct from the commercial result graph.
  * A term may have editorial value without any published tours.
  */
 public static function siteAudit(string $taxonomy,string $language,array $sources):array {
  $terms=[];$edges=[];$seenPostIds=[];
  foreach($sources as $englishId) {
   $n=self::term((int)$englishId,$taxonomy,$language);
   if(is_wp_error($n))continue;
   $info=[
    'id'=>$n['id'],'source_english_id'=>(int)$englishId,
    'title'=>$n['title'],'url'=>$n['url'],
    'taxonomy'=>$n['taxonomy'],'taxonomy_hierarchy_depth'=>$n['hierarchy_depth'],
    'description_chars'=>$n['description_characters'],
    'classification'=>$n['classification'],
    'tour_result_count'=>$n['tour_result_count'],
    'editorial_hub_candidate'=>$n['information_page_content_candidate'],
    'editorial_without_tours'=>$n['information_page_can_be_useful_without_tours'],
    'verified_editorial_inlinks_count'=>$n['actual_editorial_inlinks_count'],
    'elementor_reference_candidates'=>count($n['elementor_configuration_link_candidates']),
    'blog_topic_mentions_without_link'=>count($n['related_blog_topic_candidates']),
    'crawl_click_depth'=>null,'term_url_rendering_qa'=>'not_verified_in_this_batch',
    'auto_index_approved'=>false,
   ];
   $terms[]=$info;
   foreach($n['parents']as $parent) {
    $edges[]=['kind'=>'taxonomy_parent','from'=>$parent['id'],'to'=>$n['id'],
      'evidence'=>'wp_term_taxonomy_ancestor'];
   }
   foreach($n['linked_editorial_posts']as $link) {
    $seenPostIds[(int)$link['id']]=true;
    $edges[]=['kind'=>'rendered_source_anchor_in_post_content',
      'from'=>(int)$link['id'],'to'=>$n['id'],'target_url'=>$n['url'],
      'evidence'=>'literal_html_anchor'];
   }
   foreach($n['related_blog_topic_candidates']as $topic) {
    $seenPostIds[(int)$topic['id']]=true;
    $edges[]=['kind'=>'topic_mention_only_not_link',
      'from'=>(int)$topic['id'],'to'=>$n['id'],
      'evidence'=>'article_text_mentions_term'];
   }
  }
  $articles=SmartSeoTranslation::withLanguage($language,static function() {
   $posts=get_posts(['post_type'=>'post','post_status'=>'publish','posts_per_page'=>100,
     'suppress_filters'=>false]);
   $out=[];
   foreach($posts as $post)
    $out[]=['id'=>(int)$post->ID,'title'=>$post->post_title,
      'url'=>get_permalink($post),'content_chars'=>mb_strlen(wp_strip_all_tags($post->post_content)),
      'content'=>$post->post_content];
   return $out;
  });
  if(!is_array($articles))$articles=[];
  $index=[];
  foreach($articles as $post)$index[$post['id']]=$post;
  foreach($articles as $src)foreach($articles as $target) {
    if($src['id']===$target['id'])continue;
    $path=(string)wp_parse_url($target['url'],PHP_URL_PATH);
    if($path!==''&&str_contains($src['content'],$path)&&
      preg_match('~<a[^>]+href=["\\x27][^"\\x27]*'.preg_quote($path,'~').'[^"\\x27]*["\\x27]~i',$src['content'])) {
      $edges[]=['kind'=>'article_to_article','from'=>$src['id'],'to'=>$target['id'],
        'evidence'=>'literal_html_anchor'];
    }
  }
  $outArticles=[];
  foreach($articles as $article) {
    unset($article['content']);
    $outArticles[]=$article;
  }
  $rich=0;$missing=0;$zeroRich=0;$anchors=0;
  foreach($terms as $term){
   if($term['editorial_hub_candidate'])$rich++;
   if($term['classification']==='missing_translation')$missing++;
   if($term['editorial_without_tours'])$zeroRich++;
  }
  foreach($edges as $e)if($e['kind']==='rendered_source_anchor_in_post_content')$anchors++;
  return ['contract'=>'mad4b.seo.site-content-graph.v1','taxonomy'=>$taxonomy,
   'language'=>$language,'terms'=>$terms,'articles'=>$outArticles,'edges'=>$edges,
   'summary'=>['terms_examined'=>count($terms),'rich_term_candidates'=>$rich,
     'rich_terms_without_tours'=>$zeroRich,'missing_localized_descriptions'=>$missing,
     'verified_editorial_anchor_edges'=>$anchors,'published_articles'=>count($articles)],
   'scope'=>'Published WordPress articles and native taxonomy relationships; no fabricated links',
   'taxonomy_depth_is_not_click_depth'=>true,
   'crawl_route'=>'/wp-json/mad4b/v1/seo-intelligence/content-graph/crawl',
   'all_pages_auto_indexing_approved'=>false];
 }
 public static function combination(array $record): array {
  $nodes=[];$rich=[];$thin=[];
  foreach((array)($record['selections']??[]) as $x) {
   $n=self::term((int)$x['term_id'],(string)$x['taxonomy'],(string)($record['language']??'en'));
   if(is_wp_error($n))continue;
   $nodes[]=$n;
   if($n['classification']==='editorial_hub_candidate')$rich[]=$n['url'];
   else $thin[]=$n['url'];
  }
  $count=(int)($record['result_evidence']['matched_published_count']??0);
  return ['contract'=>self::CONTRACT,'combination_id'=>$record['id']??'',
   'classification'=>$count>0?'commercial_results':($rich?'empty_search_with_editorial_hubs':'empty_search_without_rich_hub'),
   'matched_published'=>$count,'nodes'=>$nodes,'editorial_hubs'=>array_values(array_unique($rich)),
   'thin_term_urls'=>array_values(array_unique($thin)),
   'empty_search_indexing'=>false,'approval'=>false];
 }
}
