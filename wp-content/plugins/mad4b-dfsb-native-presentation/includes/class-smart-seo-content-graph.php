<?php
namespace MAD4B\DFSB\NativePresentation;
if(!defined('ABSPATH'))exit;
final class SmartSeoContentGraph {
 const CONTRACT='mad4b.seo.content-graph.v1';
 public static function term(int $id,string $tax='') {
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
  $incoming=[];$scanned=0;
  foreach(['post','page'] as $type) {
   foreach(get_posts(['post_type'=>$type,'post_status'=>'publish','posts_per_page'=>100,'suppress_filters'=>true]) as $p) {
    $scanned++;
    $content=(string)$p->post_content;
    if(str_contains($content,(string)wp_parse_url($url,PHP_URL_PATH))
      && preg_match('~<a[^>]+href=["\x27][^"\x27]*'.preg_quote((string)wp_parse_url($url,PHP_URL_PATH),'~').'[^"\x27]*["\x27]~i',$content))
      $incoming[]=['id'=>$p->ID,'url'=>get_permalink($p),'title'=>$p->post_title,'type'=>$type];
   }
  }
  $characters=mb_strlen(trim($description));
  $public=(bool)($taxobj->public??false);
  return ['contract'=>self::CONTRACT,'type'=>'taxonomy_term','id'=>$id,
    'taxonomy'=>$t->taxonomy,'title'=>$t->name,'url'=>$url,
    'public_taxonomy'=>$public,'description_characters'=>$characters,
    'hierarchy_depth'=>count($parents),'parents'=>$parents,'children'=>$children,
    'assigned_published_posts'=>$posts,'linked_editorial_posts'=>$incoming,
    'editorial_scan_posts'=>$scanned,
    'classification'=>!$public?'private_taxonomy':($characters>=180?'editorial_hub_candidate':'thin_term'),
    'indexing_approved'=>false,'scope'=>'Saved taxonomy, published post assignments and literal editorial anchors'];
 }
 public static function combination(array $record): array {
  $nodes=[];$rich=[];$thin=[];
  foreach((array)($record['selections']??[]) as $x) {
   $n=self::term((int)$x['term_id'],(string)$x['taxonomy']);
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