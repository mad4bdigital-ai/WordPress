<?php
/**
 * Plugin Name: Portable Term Featured Media (Local Lab)
 * Description: Resolve copied term media URLs to this site's verified uploads without rewriting term metadata.
 */
if(!defined('ABSPATH'))exit;
add_filter('get_term_metadata',static function($override,$term_id,$meta_key,$single){
 if(null!==$override||is_admin())return $override;
 $allowed_keys=(array)apply_filters('portable_term_featured_image_fields',['featured_image']);
 if(!in_array((string)$meta_key,$allowed_keys,true))return $override;
 $taxonomies=(array)apply_filters('portable_term_featured_image_taxonomies',['destinations']);
 $term=get_term((int)$term_id);
 if(!$term || is_wp_error($term) || !in_array($term->taxonomy,$taxonomies,true))return $override;
 static $lookup=[];
 $key=(int)$term_id.':'.$meta_key;
 if(!array_key_exists($key,$lookup)){
  $cache=wp_cache_get((int)$term_id,'term_meta');
  if(false===$cache){
   $populated=update_meta_cache('term',[(int)$term_id]);
   $cache=$populated[(int)$term_id]??[];
  }
  $raw=is_array($cache)?($cache[$meta_key][0]??''):'';
  $lookup[$key]=null;
  if(is_string($raw) && preg_match('~^https?://[^/]+/wp-content/uploads/(.+)$~iu',$raw,$found)){
   $relative=rawurldecode(explode('?',$found[1],2)[0]);
   $segments=explode('/',$relative);
   if(!str_contains($relative,"\0") && !array_intersect($segments,['..','.']) && $relative!==''){
    $u=wp_get_upload_dir();
    if(empty($u['error'])){
     $root=realpath($u['basedir']);
     $candidate=realpath(trailingslashit($u['basedir']).$relative);
     if($root && $candidate && str_starts_with($candidate,$root.DIRECTORY_SEPARATOR) && is_file($candidate)){
      $lookup[$key]=trailingslashit($u['baseurl']).ltrim($found[1],'/');
     }
    }
   }
  }
 }
 $url=$lookup[$key];
 return $url!==null?($single?$url:[$url]):$override;
},99,4);
