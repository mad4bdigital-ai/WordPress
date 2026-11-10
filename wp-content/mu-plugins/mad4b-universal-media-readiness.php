<?php
/**
 * Plugin Name: MAD4B Universal Media Readiness
 * Description: Registry-driven media readiness and safe candidate intake for any public WordPress post type or taxonomy term.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Universal_Media_Readiness_V1',false)) {
final class MAD4B_Universal_Media_Readiness_V1 {
 const CONTRACT='mad4b.universal-media-readiness.v1';
 const MEDIA_KEYS=['featured_image','thumbnail_id','_thumbnail_id','image','image_id','term_image','category_image','jet_engine_image'];
 const MAX_SCAN=2500;
 private static function policy(string $kind,string $type):array {
  $defaults=['scan'=>true,'required'=>false,'field'=>'','commercial'=>false,'scan_limit'=>self::MAX_SCAN];
  // Registered objects are all supported. Visibility alone does not mandate
  // a hero image, especially pages, posts, taxonomies and service records.
  if($kind==='post' && in_array($type,['tours-and-activities','product'],true)) {
   $defaults['required']=true;$defaults['commercial']=true;$defaults['field']='_thumbnail_id';
  }
  if($kind==='term' && $type==='destinations') {
   $defaults['required']=true;$defaults['field']='featured_image';$defaults['commercial']=true;
  }
  $policy=apply_filters('mad4b/universal_media/policy',$defaults,$kind,$type);
  if(!is_array($policy))$policy=$defaults;
  return array_merge($defaults,array_intersect_key($policy,$defaults));
 }
 private static function public_types(string $kind):array {
  $types=$kind==='post'?get_post_types(['public'=>true],'names'):get_taxonomies(['public'=>true],'names');
  // Scope is dynamically determined from registered public WordPress types,
  // not a fixed brand list. Company policy can narrow *checks* per type.
  $types=array_values(array_filter(array_map('strval',$types),static fn($v)=>!in_array($v,['attachment','revision','nav_menu_item','post_format'],true)));
  return array_slice($types,0,100);
 }
 private static function language_is_canonical(string $kind,string $type,int $id):bool {
  $default=(string)apply_filters('wpml_default_language','en');
  if(!has_filter('wpml_object_id'))return true;
  $translated=apply_filters('wpml_object_id',$id,$type,false,$default);
  return !$translated || (int)$translated===$id;
 }
 private static function meta_values(string $kind,int $id,string $field):array {
  $getter=$kind==='term'?'get_term_meta':'get_post_meta';
  if($field!=='')return [$field=>$getter($id,$field,true)];
  $out=[];
  $keys=apply_filters('mad4b/universal_media/media_keys',self::MEDIA_KEYS,$kind,$id);
  if(!is_array($keys))$keys=self::MEDIA_KEYS;
  foreach(array_slice(array_unique(array_filter($keys,static fn($v)=>is_string($v)&&preg_match('/^[A-Za-z0-9_-]{2,90}$/D',$v))),0,35) as $key){
   $v=$getter($id,$key,true);
   if($v!==''&&$v!==null&&$v!==[])$out[$key]=$v;
  }
  return $out;
 }
 private static function local_file(int $id):bool {
  if($id<1||get_post_type($id)!=='attachment')return false;
  $mime=(string)get_post_mime_type($id);
  if(!str_starts_with($mime,'image/'))return false;
  $file=get_attached_file($id);
  return is_string($file)&&is_file($file)&&is_readable($file);
 }
 private static function value_state($value):string {
  if(is_array($value)) {
   foreach(['id','ID','attachment_id','url'] as $k)if(isset($value[$k])&&$value[$k]!=='')return self::value_state($value[$k]);
   return 'missing';
  }
  if(is_int($value) || (is_string($value) && ctype_digit($value)))return self::local_file((int)$value)?'ready':'invalid_attachment';
  if(!is_string($value)||trim($value)==='')return 'missing';
  $url=trim($value);
  $parts=wp_parse_url($url);
  if(!is_array($parts)||!in_array(strtolower((string)($parts['scheme']??'')),['http','https'],true)||!empty($parts['user'])||!empty($parts['pass']))return 'invalid_url';
  $host=strtolower((string)($parts['host']??''));
  if($host!==strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST)))return 'external_unverified';
  $uploads=wp_get_upload_dir();$base=(string)wp_parse_url($uploads['baseurl'],PHP_URL_PATH);
  $path=(string)($parts['path']??'');$prefix=rtrim($base,'/').'/';
  if(!str_starts_with($path,$prefix))return 'local_unverified';
  $relative=rawurldecode(substr($path,strlen($prefix)));
  if($relative===''||str_contains($relative,'..')||str_contains($relative,'\\')||preg_match('/[\x00-\x1f]/',$relative))return 'invalid_url';
  $root=realpath($uploads['basedir']);$file=$root?realpath($root.'/'.$relative):false;
  if(!$root||!$file||!str_starts_with($file,$root.DIRECTORY_SEPARATOR))return 'missing';
  return is_file($file)&&is_readable($file)?'ready':'missing';
 }
 public static function object(string $kind,string $type,int $id):array|WP_Error {
  $kind=sanitize_key($kind);$type=sanitize_key($type);
  if(!in_array($kind,['post','term'],true)||!in_array($type,self::public_types($kind),true)||$id<1)return new WP_Error('universal_media_object_not_public');
  $policy=self::policy($kind,$type);
  if($kind==='post'){
   $item=get_post($id);
   if(!$item||$item->post_type!==$type)return new WP_Error('universal_media_object_not_found');
   $published=$item->post_status==='publish';$name=$item->post_title;
  }else{
   $item=get_term($id,$type);
   if(!$item instanceof WP_Term)return new WP_Error('universal_media_term_not_found');
   $published=true;$name=$item->name;
  }
  $meta=self::meta_values($kind,$id,(string)$policy['field']);
  if($kind==='post' && $policy['field']==='_thumbnail_id')$meta['_thumbnail_id']=get_post_thumbnail_id($id);
  $states=[];
  foreach($meta as $key=>$v)$states[$key]=self::value_state($v);
  $ready=in_array('ready',$states,true);
  $required=(bool)$policy['required'];$has_reference=(bool)$meta;
  $eligible=false;$eligibility='none';
  if($published){
   if($kind==='post'){$eligible=true;$eligibility='published';}
   elseif($type==='destinations' && function_exists('native_destinations_sale_term_index')){
    $index=native_destinations_sale_term_index('en');$eligible=!empty($index[$id]);$eligibility='commercial_policy';
   }else{
    // A public taxonomy term is not proof of a sale. Only count published
    // associated posts for editorial relevance, never claim checkout-ready.
    $query=new WP_Query(['post_type'=>get_post_types(['public'=>true],'names'),'post_status'=>'publish',
      'posts_per_page'=>1,'fields'=>'ids','no_found_rows'=>true,'suppress_filters'=>true,
      'tax_query'=>[['taxonomy'=>$type,'field'=>'term_id','terms'=>[$id],'include_children'=>false]],
      'ignore_sticky_posts'=>true]);
    $eligible=!empty($query->posts);$eligibility='published_association_not_sale';
   }
  }
  $eligible=(bool)apply_filters('mad4b/universal_media/eligible',$eligible,$kind,$type,$id,$eligibility);
  $requires_attention=$published && !$ready && ($required || $has_reference);
  // Only genuinely required media earns P0. Unknown rights are never auto-approved.
  $priority=$requires_attention?($eligible?'P0':'P2'):'none';
  if(!$required && $requires_attention)$priority=$eligible?'P1':'P3';
  return ['contract'=>self::CONTRACT,'kind'=>$kind,'type'=>$type,'id'=>$id,
   'name'=>mb_substr(wp_strip_all_tags((string)$name),0,120),'published'=>$published,
   'media_required'=>$required,'meta_fields'=>$states,'media_ready'=>$ready,
   'needs_media'=>$requires_attention,'eligible'=>$eligible,'eligibility_basis'=>$eligibility,
   'priority'=>$priority,'recommended_action'=>$requires_attention?($eligible?'media_resolve_then_review':'deferred_media_intake'):'none',
   'rights_verified'=>false,'auto_apply_authorized'=>false];
 }
 public static function candidate_search(string $kind,string $type,int $id,int $limit=12):array|WP_Error {
  global $wpdb;
  $object=self::object($kind,$type,$id);
  if(is_wp_error($object))return $object;
  $limit=max(1,min(25,$limit));
  $title=$object['name'];
  $tokens=preg_split('/[^\p{L}\p{N}]+/u',mb_strtolower(remove_accents($title),'UTF-8'));
  $stop=['museum','factory','avenue','road','city','town','egypt','egyptian','tour','tours','the','and','with','from','state','government','governorate','temple','hotel','visit','day','days','cruise','best','luxury','private'];
  $tokens=array_values(array_unique(array_filter($tokens,static fn($x)=>
    mb_strlen($x)>=4 && mb_strlen($x)<=40 && !in_array($x,$stop,true))));
  $tokens=array_slice($tokens,0,5);
  // Use rare, entity-specific words as a required match, not a broad OR of
  // generic terms such as "museum" or "factory" that imports unrelated media.
  if($type==='destinations' && preg_match('/\bcrocodile museum\b/i',$title))$tokens=['crocodile'];
  $anchor=array_slice($tokens,0,min(2,count($tokens)));
  // Alias pairs are explicit, auditable entity matches and are not global
  // fuzzy matches. They remain review-only even when a file matches.
  $aliases=[];
  if($type==='destinations' && preg_match('/avenue of sphinxes|rams road/i',$title))
   $aliases=['sphinx avenue','rams alley','avenue of sphinx','sphinxes avenue'];
  if(!$tokens)return ['contract'=>self::CONTRACT,'object'=>$object,'candidates'=>[],'read_only'=>true];
  $like=[];$args=[];
  foreach($anchor as $token){
   // Each semantic anchor is mandatory across the same attachment; do not
   // match an unrelated tour simply because its description mentions Egypt.
   $like[]='(p.post_title LIKE %s OR a.meta_value LIKE %s)';
   $needle='%'.$wpdb->esc_like($token).'%';array_push($args,$needle,$needle);
  }
  $where='('.implode(' AND ',$like).')';
  if($aliases){
   $alternate=[];
   foreach($aliases as $alias){$alternate[]='(p.post_title LIKE %s OR a.meta_value LIKE %s)';$needle='%'.$wpdb->esc_like($alias).'%';array_push($args,$needle,$needle);}
   $where='('.$where.' OR '.implode(' OR ',$alternate).')';
  }
  $query="SELECT DISTINCT p.ID,p.post_title,p.post_mime_type FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} a ON a.post_id=p.ID AND a.meta_key='_wp_attachment_image_alt' WHERE p.post_type='attachment' AND p.post_status='inherit' AND p.post_mime_type LIKE 'image/%%' AND ".$where." ORDER BY p.ID DESC LIMIT %d";
  $args[]=min(100,$limit*4);
  $rows=$wpdb->get_results($wpdb->prepare($query,...$args),ARRAY_A);
  if(!is_array($rows)||$wpdb->last_error)return new WP_Error('universal_media_candidates_query_error');
  $candidates=[];
  foreach($rows as $row){$aid=(int)$row['ID'];if(!self::local_file($aid))continue;
   $attachment=get_post($aid);
   $original=(string)get_post_meta($aid,'_source_url',true);
   if($original!=='' && ($origin_host=(string)wp_parse_url($original,PHP_URL_HOST))!=='' && $origin_host!==wp_parse_url(home_url('/'),PHP_URL_HOST))continue;
   // Source names inside imported media do not prove transfer rights.
   if(preg_match('/(?:allroyalegypt|egypttourgates|memphistours)/i',(string)$attachment->post_title))continue;
   $meta=wp_get_attachment_metadata($aid);$w=(int)($meta['width']??0);$h=(int)($meta['height']??0);
   if($w<640||$h<360)continue;
   $candidates[]=['attachment_id'=>$aid,'title'=>mb_substr($row['post_title'],0,140),
    'dimensions'=>[$w,$h],'matched_via'=>'same_site_media_metadata','visual_match_verified'=>false,
    'image_rights_verified'=>false,'provenance_verified'=>false,'auto_apply_authorized'=>false];
   if(count($candidates)>=$limit)break;
  }
  return ['contract'=>self::CONTRACT,'object'=>$object,'tokens'=>$tokens,'mandatory_anchors'=>$anchor,'review_aliases'=>$aliases,'candidates'=>$candidates,
   'scan_complete'=>count($rows)<min(100,$limit*4),'read_only'=>true,
   'next_step'=>count($candidates)?'review_visual_identity_rights_and_wpml':'supplier_or_licensed_external_discovery',
   'external_scraping_performed'=>false,'auto_apply_authorized'=>false];
 }
 /** Internet API discovery is metadata-only. No image download, attachment
  * creation, copyright assumptions or publication can happen here.
  * Prefer documented licensing APIs to unlicensed page scraping.
  */
 public static function external_candidates(string $kind,string $type,int $id,int $limit=8):array|WP_Error {
  $object=self::object($kind,$type,$id);
  if(is_wp_error($object))return $object;
  $limit=max(1,min(12,$limit));
  $query=mb_substr($object['name'],0,100);
  if(mb_strlen($query)<3)return new WP_Error('universal_media_external_query_too_short');
  $endpoint='https://commons.wikimedia.org/w/api.php';
  $params=['action'=>'query','format'=>'json','formatversion'=>2,
   'generator'=>'search','gsrsearch'=>'filetype:bitmap '.$query,'gsrnamespace'=>6,
   'gsrlimit'=>$limit,'prop'=>'imageinfo','iiprop'=>'url|size|extmetadata','iilimit'=>1];
  $url=add_query_arg($params,$endpoint);
  $response=wp_remote_get($url,['timeout'=>12,'redirection'=>0,'sslverify'=>true,
   'headers'=>['Accept'=>'application/json','User-Agent'=>'MAD4B-MediaDiscovery/1.0 (read-only; no downloads)'],
   'limit_response_size'=>180000]);
  if(is_wp_error($response))return ['contract'=>self::CONTRACT,'object'=>$object,
    'provider'=>'Wikimedia Commons','status'=>'unavailable','reason'=>$response->get_error_code(),
    'candidates'=>[],'read_only'=>true,'auto_apply_authorized'=>false];
  if((int)wp_remote_retrieve_response_code($response)!==200)return ['contract'=>self::CONTRACT,
    'object'=>$object,'provider'=>'Wikimedia Commons','status'=>'upstream_http_error',
    'http_status'=>(int)wp_remote_retrieve_response_code($response),
    'candidates'=>[],'read_only'=>true,'auto_apply_authorized'=>false];
  $body=json_decode((string)wp_remote_retrieve_body($response),true);
  if(!is_array($body) || isset($body['error']))return new WP_Error('universal_media_external_invalid_api_response');
  $rows=$body['query']['pages']??[];$out=[];
  foreach((array)$rows as $item){
   $ii=$item['imageinfo'][0]??[];
   $info=$ii['extmetadata']??[];
   $text=static function($key)use($info){
    return mb_substr(wp_strip_all_tags((string)($info[$key]['value']??'')),0,180);
   };
   $page=(string)($ii['descriptionurl']??'');
   if((string)wp_parse_url($page,PHP_URL_HOST)!=='commons.wikimedia.org')continue;
   $title=mb_substr((string)($item['title']??''),0,140);
   $other_country_clue=(bool)preg_match('/(?:mõisa|allikajärv|\bsaun\b|\bestonia\b|eesti)/iu',$title);
   $egypt_clue=(bool)preg_match('/(?:\begypt\b|\bluxor\b|\bnile\b|\baswan\b|\bbeni[- ]suef\b)/iu',$title);
   $out[]=['title'=>$title,'source_page'=>$page,
    'geographic_evidence'=>$other_country_clue?'conflicting_other_country_clue':($egypt_clue?'egypt_title_clue_unverified':'location_not_verified'),
    'dimensions'=>[(int)($ii['width']??0),(int)($ii['height']??0)],
    'license_label'=>$text('LicenseShortName'),'license_reference'=>$text('LicenseUrl'),
    'attribution_required'=>$text('AttributionRequired'),
    'author'=>$text('Artist'),'rights_verified'=>false,
    'visual_match_verified'=>false,'source_metadata_only'=>true,
    'download_performed'=>false,'auto_apply_authorized'=>false];
  }
  return ['contract'=>self::CONTRACT,'object'=>$object,'provider'=>'Wikimedia Commons',
    'status'=>'discovered','candidates'=>$out,'read_only'=>true,
    'rights_verified'=>false,'geographic_match_verified'=>false,'external_scraping_performed'=>false,
    'next_step'=>'human_or_authorized_visual_license_review_before_import',
    'auto_apply_authorized'=>false];
 }
 public static function scan():array {
  $found=[];$counts=['objects'=>0,'missing'=>0,'critical'=>0,'incomplete'=>false];
  foreach(['term','post'] as $kind){
   foreach(self::public_types($kind) as $type){
    $policy=self::policy($kind,$type);if(empty($policy['scan']))continue;
    $max=max(1,min(self::MAX_SCAN,(int)$policy['scan_limit']));
    if($kind==='term'){
     $ids=get_terms(['taxonomy'=>$type,'hide_empty'=>false,'fields'=>'ids','number'=>$max+1,'orderby'=>'term_id','order'=>'ASC']);
    }else{
     $ids=get_posts(['post_type'=>$type,'post_status'=>'publish','posts_per_page'=>$max+1,'fields'=>'ids',
      'orderby'=>'ID','order'=>'ASC','suppress_filters'=>true]);
    }
    if(is_wp_error($ids)){ $counts['incomplete']=true;continue; }
    if(count($ids)>$max){$counts['incomplete']=true;$ids=array_slice($ids,0,$max);}
    foreach($ids as $id){
     if(!self::language_is_canonical($kind,$type,(int)$id))continue;
     $obj=self::object($kind,$type,(int)$id);
     if(is_wp_error($obj))continue;
     $counts['objects']++;
     if(!$obj['needs_media'])continue;
     $counts['missing']++;if($obj['priority']==='P0')$counts['critical']++;
     $found[]=$obj;
    }
   }
  }
  return ['contract'=>self::CONTRACT,'complete'=>!$counts['incomplete'],'counts'=>$counts,
    'items'=>$found,'read_only'=>true,'auto_apply_authorized'=>false];
 }
 public static function signals(array $rows):array {
  $scan=self::scan();
  if(!$scan['complete'])$GLOBALS['mad4b_configuration_discovery_incomplete']=true;
  foreach($scan['items'] as $item){
   $priority=$item['priority'];
   $rows[]=['provider'=>'universal-media','code'=>'media_'.$priority.'_requires_review',
    'scope'=>$item['kind'].'_'.$item['type'].'_'.$item['id'],'source'=>'probe',
    'severity'=>$priority==='P0'?'high':($priority==='P1'?'medium':'low'),
    'label'=>"[$priority] Image needed for ".$item['type'].': '.$item['name'],
    'guidance'=>'Review local library and approved supplier media first; then licensed external discovery if needed. Preserve rights, visual identity, and WPML. No automatic scraping-to-publish.'];
  }
  // Commercial eligibility belongs to the provider; only destinations
  // currently have an exact approved eligible-offer index. Do not call
  // a generic taxonomy's raw usage count a bookable product.
  foreach(self::public_types('term') as $taxonomy){
   if($taxonomy!=='destinations'||!function_exists('native_destinations_sale_term_index'))continue;
   $terms=get_terms(['taxonomy'=>$taxonomy,'hide_empty'=>false,'fields'=>'ids','number'=>1000]);
   if(is_wp_error($terms))continue;
   foreach($terms as $id){
    if(!self::language_is_canonical('term',$taxonomy,(int)$id))continue;
    $term=get_term($id,$taxonomy);
    if(!$term instanceof WP_Term)continue;
    if(class_exists('Shared_Product_Destination_Term_Presentation_v1')){
     $class='Shared_Product_Destination_Term_Presentation_v1';
     if(method_exists($class,'eligible_for_hierarchy') && !$class::eligible_for_hierarchy($term,'en'))continue;
    }
    $type=(string)get_term_meta($id,'destination_type',true);
    if($type!=='state_governorate')continue;
    $obj=self::object('term',$taxonomy,(int)$id);
    if(!is_wp_error($obj)&&!$obj['eligible']){
      $rows[]=['provider'=>'universal-media','code'=>'published_offer_missing',
       'scope'=>'term_destinations_'.$id,'source'=>'probe','severity'=>'low',
       'label'=>'[P3] Destination without eligible commercial offer: '.$obj['name'],
       'guidance'=>'Review published eligible tour relations and commercial listing filters. Retain the taxonomy for editorial use.'];
    }
   }
  }
  // One site-level transport gap, not a duplicate for every taxonomy term.
  // Resolves only after a separately authorized signed term-media operation
  // is registered, never when an ordinary host script merely exists.
  $pending_terms=array_filter($scan['items'],static fn($item)=>
    $item['kind']==='term' && $item['media_required'] && $item['needs_media']);
  $signed_ready=(bool)apply_filters('mad4b/term_media/signed_transport_registered',false);
  if($pending_terms && !$signed_ready) {
   $rows[]=['provider'=>'universal-media','code'=>'signed_term_media_transport_missing',
    'scope'=>'site','source'=>'probe','severity'=>'high',
    'label'=>'Signed term-media apply operation is not registered for eligible Media Intake',
    'guidance'=>'Use the licensed-term-media preflight and a separately authorized host-signed apply operation with reviewed provenance, exact site/term/hash/preimage, rollback and verified receipt. Do not fall back to arbitrary WP-CLI edits.'];
  }
  if(!$scan['complete'])$rows[]=['provider'=>'universal-media','code'=>'universal_media_scan_incomplete',
   'scope'=>'site','source'=>'probe','severity'=>'high','label'=>'Media catalog inspection was not complete',
   'guidance'=>'Inspect registered public types, pagination and scanner errors before auto-resolving old alerts.'];
  return $rows;
 }
 public static function cli($args,$assoc):void {
  $mode=(string)($args[0]??'scan');
  if($mode==='scan')$result=self::scan();
  elseif(in_array($mode,['object','candidates','external'],true)){
   $kind=(string)($args[1]??'');$type=(string)($args[2]??'');$id=(int)($args[3]??0);
   $result=$mode==='object'?self::object($kind,$type,$id):($mode==='external'?self::external_candidates($kind,$type,$id,(int)($assoc['limit']??8)):self::candidate_search($kind,$type,$id,(int)($assoc['limit']??12)));
  }else WP_CLI::error('Expected scan, object, candidates or external.');
  if(is_wp_error($result))WP_CLI::error($result->get_error_code());
  WP_CLI::line(wp_json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
 }
}
// Use one alert producer for destination media readiness after the universal
// provider is available, while retaining the legacy class for old readers.
add_action('init',static function():void {
 if(class_exists('MAD4B_Destination_Card_Readiness_V1',false))
  remove_filter('mad4b/configuration_jobs/discover',
    [MAD4B_Destination_Card_Readiness_V1::class,'signals'],30);
},90);
add_filter('mad4b/configuration_jobs/discover',[MAD4B_Universal_Media_Readiness_V1::class,'signals'],35,1);
if(defined('WP_CLI')&&WP_CLI)WP_CLI::add_command('mad4b universal-media',[MAD4B_Universal_Media_Readiness_V1::class,'cli']);
}
