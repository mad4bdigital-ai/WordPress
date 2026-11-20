<?php
/** Authoritative read-only source for price-bearing public path invalidation. */
defined('ABSPATH')||exit;
global $wpdb;
$host=(string)wp_parse_url(get_option('home'),PHP_URL_HOST);
$initial=apply_filters('wpml_current_language','');
$paths=[];$errors=[];$counts=[];
$accept=static function($url,$kind)use(&$paths,&$errors,$host){
 if(!is_string($url)||$url==='')return;
 $parts=wp_parse_url($url);
 if(!is_array($parts)||($parts['scheme']??'')!=='https'||($parts['host']??'')!==$host
  ||isset($parts['query'])||isset($parts['fragment'])||isset($parts['user']))return;
 $p=(string)($parts['path']??'');
 if(!$p||strlen($p)>500||!preg_match('~^/(?:[a-z]{2}/)?(?:[a-zA-Z0-9%._/\\-]+)?$~D',$p)
  ||str_contains($p,'..')||str_contains($p,'//'))return;
 if(preg_match('~/(?:checkout|cart|my-account|account|favourites|login|wp-json|wp-admin|booking|orders?)(?:/|$)~i',$p))return;
 $paths[$p]=$kind;
};
$rows=$wpdb->get_results("SELECT p.ID,p.post_type,COALESCE(tr.language_code,'en') AS lang
 FROM {$wpdb->posts} p LEFT JOIN {$wpdb->prefix}icl_translations tr
 ON tr.element_id=p.ID AND tr.element_type=CONCAT('post_',p.post_type)
 WHERE p.post_status='publish' AND p.post_type IN('tours-and-activities','page','post')
 ORDER BY lang,p.post_type,p.ID",ARRAY_A);
if(!is_array($rows))throw new RuntimeException('WPML_POST_LIST_FAILED');
$group=[];foreach($rows as $row){$l=(string)$row['lang'];if(preg_match('/^[a-z]{2}$/D',$l))$group[$l][]=$row;}
try{
 foreach($group as $lang=>$subset) {
   do_action('wpml_switch_language',$lang);
   $accept(home_url('/'),'home');
   $accept(get_post_type_archive_link('tours-and-activities'),'archive');
   foreach($subset as $row) {
     $before=count($paths);
     $accept(get_permalink((int)$row['ID']),$row['post_type']);
     if($row['post_type']==='tours-and-activities')$counts[$lang]=($counts[$lang]??0)+1;
   }
   foreach(['destinations','category','tour-types'] as $tax) {
     if(!taxonomy_exists($tax))continue;
     $terms=get_terms(['taxonomy'=>$tax,'hide_empty'=>false,'fields'=>'ids']);
     if(is_wp_error($terms))continue;
     foreach(array_slice((array)$terms,0,1500) as $id){
        $url=get_term_link((int)$id,$tax);
        if(!is_wp_error($url))$accept($url,'tax:'.$tax);
     }
   }
 }
}finally{do_action('wpml_switch_language',$initial);}
ksort($paths,SORT_STRING);
$expected=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}
 WHERE post_status='publish' AND post_type='tours-and-activities'");
if($expected<40||$expected>2000||count($paths)<$expected)
 throw new RuntimeException('INCOMPLETE_PUBLIC_PRICE_PATHS:'.count($paths).':'.$expected);
echo 'MAD4B_PRICE_PATHS='.wp_json_encode(['contract'=>'mad4b.pricing-cache-public-paths.v1',
 'source_host'=>$host,'published_tours'=>$expected,'public_paths'=>array_keys($paths),
 'counts_by_locale'=>$counts,'count'=>count($paths)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
