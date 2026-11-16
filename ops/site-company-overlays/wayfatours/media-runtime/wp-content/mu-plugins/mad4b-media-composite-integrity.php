<?php
/** Plugin Name: MAD4B Composite Media Integrity
 * Description: JetEngine gallery and repeater read-only structural inspection for Configuration Jobs.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Composite_Media_Integrity_V1',false)) {
final class MAD4B_Composite_Media_Integrity_V1 {
 public static function fields(string $kind,string $type):array {
  if(!function_exists('jet_engine'))return [];
  $jet=jet_engine();
  try {
   $rows=$kind==='term'?$jet->taxonomies->get_meta_fields_for_object($type):$jet->meta_boxes->get_meta_fields_for_object($type);
  }catch(Throwable $e){return [];}
  $out=[];
  foreach((array)$rows as $row){
   if(!is_array($row)||!in_array(($row['type']??''),['gallery','repeater'],true))continue;
   $name=$row['name']??'';
   if(!is_string($name)||!preg_match('/^[A-Za-z_][A-Za-z0-9_-]{1,79}$/D',$name))continue;
   $out[$name]=['type'=>$row['type'],'format'=>$row['value_format']??null,
    'repeater_fields'=>array_map(static fn($r)=>['name'=>$r['name']??'','type'=>$r['type']??'','value_format'=>$r['value_format']??null],(array)($row['repeater-fields']??[]))];
  }
  return $out;
 }
 public static function inspect(string $kind,string $type,int $id,string $field):array|WP_Error {
  $schema=self::fields($kind,$type)[$field]??null;
  if(!$schema)return new WP_Error('mad4b_composite_field_not_registered');
  $value=$kind==='term'?get_term_meta($id,$field,true):get_post_meta($id,$field,true);
  // get_term_meta() may rewrite legacy company domains dynamically. Read
  // the stored value separately so the audit detects latent dependencies.
  global $wpdb;
  $table=$kind==='term'?$wpdb->termmeta:$wpdb->postmeta;
  $col=$kind==='term'?'term_id':'post_id';
  $raw=$wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$table} WHERE {$col}=%d AND meta_key=%s ORDER BY meta_id ASC LIMIT 1",$id,$field));
  $hash=hash('sha256',wp_json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  $issue=[];$items=[];$format='';$raw_foreign_count=0;$local_missing_count=0;
  $raw_value=is_string($raw)?maybe_unserialize($raw):$raw;
  if($schema['type']==='gallery' && is_array($raw_value)){
   $home_host=strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
   foreach($raw_value as $row){
    if(!is_array($row)||!isset($row['url']))continue;
    $host=strtolower((string)wp_parse_url((string)$row['url'],PHP_URL_HOST));
    if($host!==''&&$host!==$home_host)$raw_foreign_count++;
   }
   if($raw_foreign_count)$issue[]='gallery_stored_foreign_host';
  }
  if($schema['type']==='gallery' && is_string($raw_value) && $raw_value!==''){
   $site_host=strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
   foreach(explode(',',$raw_value) as $raw_item){
    $raw_host=strtolower((string)wp_parse_url(trim($raw_item),PHP_URL_HOST));
    if($raw_host!=='' && $raw_host!==$site_host)$raw_foreign_count++;
   }
   if($raw_foreign_count>0)$issue[]='gallery_stored_foreign_host';
   $uploads=wp_get_upload_dir();$root=realpath((string)$uploads['basedir']);
   foreach(explode(',',$raw_value) as $raw_item){
    $p=(string)wp_parse_url(trim($raw_item),PHP_URL_PATH);
    $needle='/wp-content/uploads/';$pos=strpos($p,$needle);
    if($pos===false){$local_missing_count++;continue;}
    $relative=rawurldecode(substr($p,$pos+strlen($needle)));
    if($relative==='' || str_contains($relative,'..') || str_contains($relative,"\\")){$local_missing_count++;continue;}
    $f=$root?realpath($root.'/'.$relative):false;
    if(!$f || !str_starts_with($f,$root.DIRECTORY_SEPARATOR) || !is_file($f))$local_missing_count++;
   }
   if($local_missing_count>0)$issue[]='gallery_local_file_missing';
  }
  if($value===''||$value===null)return ['field'=>$field,'kind'=>$kind,'type'=>$type,'id'=>$id,'schema'=>$schema,
   'stored_format'=>'empty','items'=>[],'issues'=>[],'value_hash'=>$hash];
  if($schema['type']==='repeater'){
   $format=is_array($value)?'array':gettype($value);
   if(!is_array($value))$issue[]='repeater_unexpected_shape';
   elseif(!array_is_list($value) && count(array_filter(array_keys($value),static fn($k)=>!is_string($k)||!preg_match('/^item-[0-9]+$/D',$k)))>0)$issue[]='repeater_unknown_row_keys';
   else {
    $allowed=array_column($schema['repeater_fields'],'name');
    foreach($value as $i=>$row){
     if(!is_array($row)){$issue[]='repeater_row_not_array';break;}
     // Keep legacy/addon fields intact. Scan only explicitly declared
     // embedded media slots; never reserialize or replace repeater rows.
     if(!$row)$issue[]='repeater_empty_row';
     foreach($schema['repeater_fields'] as $nested){
      if(!in_array($nested['type'],['media','gallery'],true))continue;
      $v=$row[$nested['name']]??null;
      if($v===null||$v==='')continue;
      $urls=is_array($v)?$v:(is_string($v)?explode(',',$v):[]);
      foreach($urls as $media){
       if(is_array($media))$media=$media['url']??$media['id']??null;
       if(!is_scalar($media)){$issue[]='repeater_media_invalid_value';continue;}
       if(ctype_digit((string)$media))continue;
       $host=strtolower((string)wp_parse_url((string)$media,PHP_URL_HOST));
       if($host!==''&&$host!==strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST)))$issue[]='repeater_media_foreign_host';
      }
     }
    }
   }
  }else{
   $format=is_string($value)?'comma_urls':(is_array($value)?'array':'unknown');
   if(is_string($value)){
    if(str_starts_with(trim($value),'a:')||str_starts_with(trim($value),'['))$issue[]='gallery_serialization_ambiguous';
    else $items=array_map('trim',explode(',',$value));
   }elseif(is_array($value))$items=array_values($value);
   else $issue[]='gallery_unsupported_shape';
   $host=strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
   foreach($items as $item){
    if(is_array($item)){
     if(array_key_exists('id',$item) && (int)$item['id']>0 && isset($item['url']) && is_string($item['url'])){
      $v=$item['url'];$format='id_url_objects';
     }else{$issue[]='gallery_invalid_object';continue;}
    }elseif(is_scalar($item)&&trim((string)$item)!=='')$v=(string)$item;
    else{$issue[]='gallery_invalid_item';continue;}
    if(ctype_digit($v))continue;
    $h=strtolower((string)wp_parse_url($v,PHP_URL_HOST));
    if($h===''||!in_array((string)wp_parse_url($v,PHP_URL_SCHEME),['https','http'],true))$issue[]='gallery_invalid_url';
    elseif($h!==$host)$issue[]='gallery_foreign_host_media';
   }
  }
  return ['field'=>$field,'kind'=>$kind,'type'=>$type,'id'=>$id,'schema'=>$schema,'stored_format'=>$format,
   'items_count'=>count($items),'raw_foreign_count'=>$raw_foreign_count,'local_missing_count'=>$local_missing_count,'runtime_rewritten'=>$raw!==null && $raw!==$value,'issues'=>array_values(array_unique($issue)),'value_hash'=>$hash];
 }
 public static function signals(array $rows):array {
  $gallery_groups=[];$batch=[];
  foreach(['term','post'] as $kind){
   $types=$kind==='term'?get_taxonomies(['public'=>true],'names'):get_post_types(['public'=>true],'names');
   foreach($types as $type){
    if($kind==='post' && $type==='attachment')continue;
    $fields=self::fields($kind,$type);if(!$fields)continue;
    global $wpdb;
    foreach($fields as $field=>$schema){
     // Inspect only objects with a real non-empty stored composite field.
     // Avoid scanning all published products/pages every 30 minutes.
     if($kind==='term'){
      $ids=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT m.term_id FROM {$wpdb->termmeta} m JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=m.term_id WHERE tt.taxonomy=%s AND m.meta_key=%s AND m.meta_value<>'' ORDER BY m.term_id LIMIT 2001",$type,$field));
     }else{
      $ids=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE p.post_type=%s AND p.post_status='publish' AND m.meta_key=%s AND m.meta_value<>'' ORDER BY m.post_id LIMIT 2001",$type,$field));
     }
     if(!is_array($ids)||$wpdb->last_error){$GLOBALS['mad4b_configuration_discovery_incomplete']=true;continue;}
     if(count($ids)>2000){$GLOBALS['mad4b_configuration_discovery_incomplete']=true;$ids=array_slice($ids,0,2000);}
     foreach($ids as $id){
      $check=self::inspect($kind,$type,(int)$id,$field);
      if(is_wp_error($check))continue;
      if($kind==='post' && $schema['type']==='gallery' && ($check['items_count']??0)>=3){
       $key=$type.'|'.$field.'|'.$check['value_hash'];
       $trid=apply_filters('wpml_element_trid',null,(int)$id,'post_'.$type);
       $gallery_groups[$key][(string)($trid?:'post-'.$id)][]=(int)$id;
      }
     if(!$check['issues'])continue;
     foreach($check['issues'] as $issue){
      if(in_array($issue,['gallery_foreign_host_media','gallery_stored_foreign_host'],true)){
       $batch[$kind.'|'.$type.'|'.$field.'|'.$issue][]=(int)$id;
       continue;
      }
      $rows[]=['provider'=>'composite-media','source'=>'probe','code'=>$issue,
       'scope'=>$kind.'_'.$type.'_'.$id.'_'.$field,
       'severity'=>$issue==='gallery_local_file_missing'?'high':'low',
       'label'=>'Composite media integrity: '.$type.' #'.$id.' / '.$field,
       'guidance'=>'Inspect the original field and review source rights; do not drop existing gallery or repeater rows. Issue: '.$issue.'.'];
     }
     }
    }
   }
  }
  foreach($batch as $key=>$ids){
   [$kind,$type,$field,$code]=explode('|',$key,4);
   $ids=array_values(array_unique($ids));sort($ids,SORT_NUMERIC);
   $rows[]=['provider'=>'composite-media','source'=>'probe','code'=>$code,
    'scope'=>$kind.'_'.$type.'_'.$field.'_batch','severity'=>'medium',
    'label'=>'Composite gallery portability: '.count($ids).' affected '.$type.' records',
    'guidance'=>'Raw metadata contains foreign-host media references. Verify local files, exact entity identity and original photo rights before controlled normalization. Field: '.$field.'; issue: '.$code.'; sample IDs: '.implode(',',array_slice($ids,0,25)).'. Use the read-only composite media inspector for exact records.'];
  }
  foreach($gallery_groups as $key=>$families){
   if(count($families)<3)continue;
   $ids=[];foreach($families as $members)$ids[]=(int)$members[0];
   sort($ids,SORT_NUMERIC);
   [$type,$field,$fingerprint]=explode('|',$key,3);
   $rows[]=['provider'=>'composite-media','source'=>'probe',
     'code'=>'gallery_shared_across_distinct_posts',
     'scope'=>'post_'.$type.'_'.$field.'_'.substr($fingerprint,0,16),
     'severity'=>'medium',
     'label'=>'Gallery media repeated across '.count($ids).' distinct '.$type.' posts',
     'guidance'=>'Potential placeholder or copied supplier gallery. Verify exact property/tour subject and reuse rights before normalizing or changing images. Post IDs: '.implode(',',array_slice($ids,0,25))];
  }
  return $rows;
 }
}
add_filter('mad4b/configuration_jobs/discover',[MAD4B_Composite_Media_Integrity_V1::class,'signals'],40,1);
}
