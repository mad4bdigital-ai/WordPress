<?php
/** WordPress CLI read-only route enumeration — published public content. */
if(!defined('ABSPATH')){exit(1);}
$host = wp_parse_url(home_url('/'),PHP_URL_HOST);
$routes = [];
foreach(['page','post','tours-and-activities'] as $type){
  foreach(get_posts(['post_type'=>$type,'post_status'=>'publish','posts_per_page'=>-1,
           'suppress_filters'=>true,'fields'=>'ids','orderby'=>'ID']) as $id){
    $url=get_permalink($id);
    $path=wp_parse_url($url,PHP_URL_PATH);
    if(!is_string($path) || $path==='' || wp_parse_url($url,PHP_URL_HOST)!==$host)continue;
    $routes[$path]=['type'=>$type,'id'=>(int)$id];
  }
}
foreach(['destinations','category','product_cat'] as $taxonomy){
 if(!taxonomy_exists($taxonomy))continue;
 $terms=get_terms(['taxonomy'=>$taxonomy,'hide_empty'=>false,'fields'=>'ids']);
 if(is_wp_error($terms))continue;
 foreach($terms as $id){
   $link=get_term_link($id,$taxonomy);if(is_wp_error($link))continue;
   $path=wp_parse_url($link,PHP_URL_PATH);
   if(is_string($path)&&$path!==''&&wp_parse_url($link,PHP_URL_HOST)===$host)
     $routes[$path]=['type'=>'tax:'.$taxonomy,'id'=>(int)$id];
 }
}
ksort($routes,SORT_STRING);
echo wp_json_encode(['contract'=>'mad4b.public-route-inventory.v1','home'=>home_url('/'),
                    'routes'=>$routes],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
