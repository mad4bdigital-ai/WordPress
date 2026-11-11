<?php
/**
 * MAD4B source-derived media and rate draft materialization.
 * This does not certify supplier contracts, prices, rights, availability, or checkout.
 * Runs on Staging via enrolled WP-CLI administrator. Company sites can adopt the
 * same version through Company Code Release, with per-company policy override.
 */
defined('ABSPATH') || exit;
final class MAD4B_Source_Tour_Materializer_V1 {
 const CONTRACT='mad4b.source-tour-materialize.v1';
 const RATE_KEY='_mad4b_source_rate_variant_key_v1';
 const MEDIA_KEY='_mad4b_source_media_url_sha256_v1';
 const DEFAULTS='mad4b_source_materializer_policy_v1';
 const MAX_MEDIA=12;
 public static function policy() {
  $defaults=['media_rights_mode'=>'advisory','price_source'=>'source_verified_only',
   'rate_creation'=>'priced_draft_only','starting_price'=>'lowest_valid_live_rate',
   'unknown_price'=>'on_request','auto_import_source_media'=>true,
   'copy_supplier_license_assertion'=>false,
   'gallery_source_mode'=>'explicit_gallery_first',
   'gallery_sync_mode'=>'merge_preserve_editorial',
   'gallery_format'=>'comma_separated_urls',
   'max_gallery_images'=>12,'unpriced_rate_behavior'=>'price_pending_no_rate'];
  $saved=get_option(self::DEFAULTS,[]);
  if(!is_array($saved))$saved=[];
  // Company overrides may tighten image policy, never manufacture a license
  // or relax the source-only positive-price evidence contract.
  $valid=[];
  if(($saved['media_rights_mode']??'')==='enforced')
   $valid['media_rights_mode']='enforced';
  if(array_key_exists('auto_import_source_media',$saved) && $saved['auto_import_source_media']===false)
   $valid['auto_import_source_media']=false;
  if(isset($saved['max_gallery_images']) &&
    is_numeric($saved['max_gallery_images'])) {
   $limit=(int)$saved['max_gallery_images'];
   if($limit>=1 && $limit<=self::MAX_MEDIA)$valid['max_gallery_images']=$limit;
  }
  if(($saved['gallery_sync_mode']??'')==='review_only')
   $valid['gallery_sync_mode']='review_only';
  return array_replace($defaults,$valid);
 }
 private static function gate($post_id) {
  if(!defined('WP_CLI') || !WP_CLI || wp_get_environment_type()!=='staging' ||
     !current_user_can('edit_post',$post_id) || !current_user_can('upload_files') ||
     !current_user_can('edit_commerce_rates') || get_post_type($post_id)!=='tours-and-activities' ||
     get_post_status($post_id)!=='draft')
    return new WP_Error('source_materializer_staging_owner_draft_required');
  if(class_exists('MAD4B_SCP_Site_Profile') &&
    (!MAD4B_SCP_Site_Profile::origin_enrolled() ||
     !MAD4B_SCP_Site_Profile::site_urls_match_enrollment()))
    return new WP_Error('source_materializer_exact_site_binding_required');
  $source=(string)get_post_meta($post_id,'_mad4b_source_content_loop_url_v1',true);
  if(!wp_http_validate_url($source) || wp_parse_url($source,PHP_URL_SCHEME)!=='https')
    return new WP_Error('source_materializer_source_binding_missing');
  $host=strtolower((string)wp_parse_url($source,PHP_URL_HOST));
  $allowed=get_option('mad4b_source_loop_allowed_hosts_v1',[]);
  if(!is_array($allowed)||!in_array($host,$allowed,true))
    return new WP_Error('source_materializer_source_host_not_enrolled');
  return $source;
 }
 private static function extract_typed_prices(DOMDocument $doc) {
  $xpath=new DOMXPath($doc);$out=[];
  // The primary supplier-specific duration price cards require both the
  // duration and a single positive USD price; ambiguous tiers are held.
  $nodes=$xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' new-prices-tbl ')]");
  if(!$nodes)return [];
  foreach($nodes as $node) {
   $title='';
   foreach($xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' new-prices-tbl_title ')]",$node) as $h) {
    $title=trim(preg_replace('/\s+/u',' ',$h->textContent));break;
   }
   if(!preg_match('/\b([1-9][0-9]?)\s*days?\b/i',$title,$d))continue;
   $duration=(int)$d[1];$content=trim(preg_replace('/\s+/u',' ',$node->textContent));
   if(str_contains($content,'€') || preg_match('/\b(?:EUR|GBP|EGP)\b/i',$content))continue;
   preg_match_all('/(?:US\$|\$|USD)\s*([1-9][0-9]{1,6}(?:[.,][0-9]{1,2})?)/i',$content,$found);
   $values=array_values(array_unique((array)($found[1]??[])));
   if(count($values)!==1)continue;
   $amount=str_replace(',','',$values[0]);
   if(!preg_match('/^[1-9][0-9]{1,6}(?:\.[0-9]{1,2})?$/D',$amount))continue;
   $out[$duration]=['amount'=>number_format((float)$amount,2,'.',''),
    'currency'=>'USD','basis'=>'per_person','evidence'=>'exact_duration_supplier_price_card',
    'source_card_sha256'=>hash('sha256',$content)];
  }
  ksort($out,SORT_NUMERIC);
  return $out;
 }
 /**
  * Source gallery mapping is an owned provider adapter, not a page-wide
  * image harvester: explicit gallery image classes win; only tour-relevant
  * media in a gallery container qualify next; title-matching images are
  * a fallback. Everything is still restricted to the scoped source CDN.
  */
 private static function gallery_provider($source_url) {
  $host=strtolower((string)wp_parse_url((string)$source_url,PHP_URL_HOST));
  $reg=['memphis_tours'=>[
   'source_hosts'=>['www.memphistours.com','memphistours.com'],
   'image_host'=>'res.cloudinary.com',
   'path_prefix'=>'/ddjuftfy2/image/upload/',
   'path_segment'=>'/memphis/large/',
   'image_extensions'=>['jpg','jpeg','png','webp']]];
  // New providers require a reviewed first-party adapter registration.
  // No company can expand the network allowlist with a UI option.
  $reg=apply_filters('mad4b_source_gallery_provider_registry_v1',$reg);
  if(!is_array($reg))return null;
  foreach($reg as $id=>$config) {
   if(!is_string($id) || !preg_match('/^[a-z0-9_]{3,48}$/D',$id) ||
      !is_array($config) ||
      !in_array($host,(array)($config['source_hosts']??[]),true))continue;
   $cdn=strtolower((string)($config['image_host']??''));
   $prefix=(string)($config['path_prefix']??'');
   $segment=(string)($config['path_segment']??'');
   if(!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/D',$cdn) ||
      filter_var($cdn,FILTER_VALIDATE_IP) ||
      !str_starts_with($prefix,'/') || strlen($prefix)<8 ||
      !str_starts_with($segment,'/') || strlen($segment)<5)continue;
   return ['id'=>$id,'image_host'=>$cdn,'path_prefix'=>$prefix,
    'path_segment'=>$segment,
    'image_extensions'=>array_values(array_intersect(
      (array)($config['image_extensions']??[]),['jpg','jpeg','png','webp']))];
  }
  return null;
 }
 private static function gallery_url_allowed($src,$provider) {
  if(!is_array($provider)||!is_string($src)||strlen($src)>1700)return false;
  $p=wp_parse_url($src);
  if(!is_array($p)||($p['scheme']??'')!=='https' ||
     strtolower((string)($p['host']??''))!==$provider['image_host'] ||
     isset($p['user'])||isset($p['pass'])||isset($p['port'])||
     isset($p['fragment']))return false;
  $path=(string)($p['path']??'');
  if(!str_starts_with($path,$provider['path_prefix']) ||
     !str_contains($path,$provider['path_segment']) ||
     preg_match('~(?:^|/)\.\.?(/|$)~',$path))return false;
  $extension=strtolower((string)pathinfo($path,PATHINFO_EXTENSION));
  return in_array($extension,(array)$provider['image_extensions'],true);
 }
 private static function extract_gallery_candidates(DOMDocument $doc,$title,$policy,$source_url) {
  $words=array_values(array_unique(array_filter(
   preg_split('/[^a-z0-9]+/i',strtolower(remove_accents($title))),
   static fn($w)=>strlen($w)>=4 &&
    !in_array($w,['tour','cruise','nile','days','egypt','luxury'],true))));
  $provider=self::gallery_provider($source_url);
  $seen=[];$items=[];$index=0;$matched=['explicit'=>0,'gallery_scope'=>0,'title_fallback'=>0];
  if(!$provider)return ['items'=>[],'provider'=>'unregistered','detected_modes'=>$matched,
    'source_gallery_item_count'=>0,'source_gallery_sha256'=>hash('sha256','[]')];
  foreach($doc->getElementsByTagName('img') as $img) {
   $src=html_entity_decode((string)$img->getAttribute('data-src'),ENT_QUOTES|ENT_HTML5);
   if(!$src)$src=html_entity_decode((string)$img->getAttribute('src'),ENT_QUOTES|ENT_HTML5);
   if(!$src)continue;
   if(!self::gallery_url_allowed($src,$provider))continue;
   $url=wp_parse_url($src);
   $alt=(string)$img->getAttribute('alt');
   $text=strtolower(remove_accents($alt.' '.wp_basename((string)$url['path'])));
   $relevance=0;
   foreach($words as $word)
    if(str_contains($text,substr($word,0,min(5,strlen($word)))))$relevance++;
   $classes=(string)$img->getAttribute('class');
   $explicit=(bool)preg_match('/(?:^|\\s)program_new_(?:big|small)_image(?:\\s|$)/i',$classes);
   $gallery_scope=false;
   $parent=$img->parentNode;$depth=0;
   while($parent instanceof DOMElement && $depth++<4) {
    $attrs=$parent->getAttribute('class').' '.$parent->getAttribute('id');
    if(preg_match('/(?:gallery|gallery-grid|photo[-_]?grid|slider|swiper|carousel|image[-_]?grid)/i',$attrs)) {
     $gallery_scope=true;break;
    }
    $parent=$parent->parentNode;
   }
   $mode=$explicit?'explicit':($gallery_scope?'gallery_scope':'title_fallback');
   // A generic gallery row alone is not sufficient to identify a Tour.
   if(!$explicit && !$relevance)continue;
   $rank=['explicit'=>0,'gallery_scope'=>1,'title_fallback'=>2][$mode];
   $sha=hash('sha256',$src);
   if(isset($seen[$sha]))continue;
   $seen[$sha]=true;$matched[$mode]++;
   $items[]=['source_url'=>$src,'source_url_sha256'=>$sha,
    'alt'=>sanitize_text_field($alt),'license_state'=>'unknown_advisory',
    'source_selector'=>$mode,'source_index'=>$index++,'source_rank'=>$rank];
  }
  usort($items,static fn($a,$b)=>($a['source_rank']<=>$b['source_rank']) ?:
    ($a['source_index']<=>$b['source_index']));
  $max=max(1,min(self::MAX_MEDIA,(int)($policy['max_gallery_images']??self::MAX_MEDIA)));
  $items=array_slice($items,0,$max);
  return ['items'=>$items,'provider'=>$provider['id'],'detected_modes'=>$matched,
   'source_gallery_item_count'=>count($items),
   'source_gallery_sha256'=>hash('sha256',wp_json_encode(
     array_column($items,'source_url_sha256'),JSON_UNESCAPED_SLASHES))];
 }
 private static function snapshot($post_id) {
  $url=self::gate($post_id);
  if(is_wp_error($url))return $url;
  $resp=wp_safe_remote_get($url,['timeout'=>35,'redirection'=>2,
   'limit_response_size'=>1000000,'sslverify'=>true,
   'headers'=>['Accept'=>'text/html','User-Agent'=>'MAD4B-Source-Intake/1.0']]);
  if(is_wp_error($resp))return $resp;
  $http=(int)wp_remote_retrieve_response_code($resp);
  $html=(string)wp_remote_retrieve_body($resp);
  if($http!==200 || strlen($html)<5000)
    return new WP_Error('source_materializer_source_unavailable');
  $title=(string)get_the_title($post_id);
  if($title==='' || !is_string($title))return new WP_Error('source_materializer_title_missing');
  $doc=new DOMDocument();
  $ok=@$doc->loadHTML('<?xml encoding="utf-8"?>'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
  if(!$ok)return new WP_Error('source_materializer_html_invalid');
  $source_profile=class_exists('MAD4B_Scraping_Source_Profiles_V1')
    ? MAD4B_Scraping_Source_Profiles_V1::resolve($url):null;
  if(is_wp_error($source_profile))return $source_profile;
  $profile_ok=static fn($field)=>!is_array($source_profile) ||
    MAD4B_Scraping_Source_Profiles_V1::field_allowed($source_profile,$field);
  $gallery=$profile_ok('gallery')
    ?self::extract_gallery_candidates($doc,$title,self::policy(),$url)
    :['items'=>[],'provider'=>'excluded_by_source_profile',
      'detected_modes'=>[],'source_gallery_sha256'=>hash('sha256','[]')];
  $items=$gallery['items'];
  $plain=preg_replace('/\s+/u',' ',wp_strip_all_tags($html));
  $currency=(bool)preg_match('/prices?\s+(?:are\s+)?quoted\s+in\s+US\s+Dollars\s+per\s+person/i',$plain)?'USD':'';
  $expiry=preg_match('/valid until April\s*30,\s*2027/i',$plain)?'2027-04-30':'';
  // Price evidence must come from the exact supplier duration card.
  // The source profile determines whether an observation may make a
  // reviewable priced Draft; it cannot publish the Rate or enable booking.
  $observations=$profile_ok('prices')?self::extract_typed_prices($doc):[];
  $prices=[];$price_decisions=[];
  foreach($observations as $days=>$candidate) {
   $candidate['valid_until']=$expiry;
   $candidate['captured_at']=gmdate('c');
   $decision=is_array($source_profile)
      ?MAD4B_Scraping_Source_Profiles_V1::price_decision($source_profile,$candidate)
      :['rate_creation_allowed'=>true,'action'=>'priced_draft_review',
        'reason'=>'legacy_source_policy'];
   $price_decisions[(int)$days]=$decision;
   if(!empty($decision['rate_creation_allowed']))$prices[(int)$days]=$candidate;
  }
  $sourcekey=(string)get_post_meta($post_id,'_mad4b_source_content_loop_key_v1',true);
  $rows=get_option('mad4b_source_content_loop_v1',[]);
  $row=is_array($rows)?($rows[$sourcekey]??null):null;
  $durations=is_array($row)?array_values(array_unique(array_filter(array_map('intval',
     (array)($row['capture_summary']['durations']??[]))))):[];
  sort($durations);
  if(!$durations || count($durations)>8)return new WP_Error('source_materializer_itineraries_unverified');
  $archetype=class_exists('MAD4B_Tour_Archetype_Registry_V1')
    ?MAD4B_Tour_Archetype_Registry_V1::select($post_id):null;
  if(is_wp_error($archetype))return $archetype;
  $capture=['contract'=>self::CONTRACT,'post_id'=>(int)$post_id,
    'source_url'=>$url,'source_sha256'=>hash('sha256',$html),
    'source_key'=>$sourcekey,'durations'=>$durations,'image_candidates'=>$items,
    'source_gallery_sha256'=>$gallery['source_gallery_sha256'],
    'source_gallery_modes'=>$gallery['detected_modes'],
    'source_gallery_provider'=>$gallery['provider'],
    'gallery_before_sha256'=>hash('sha256',(string)get_post_meta($post_id,'gallery',true)),
    'currency'=>$currency,'price_valid_to'=>$expiry,'verified_amounts'=>$prices,
    'source_profile'=>$source_profile,
    'source_profile_digest'=>(string)($source_profile['profile_digest']??''),
    'archetype_family'=>$archetype['family']??'',
    'archetype_profile_digest'=>$archetype['profile_digest']??'',
    'archetype_relationship_mode'=>$archetype['relationship_mode']??'',
    'field_policy'=>['gallery'=>$profile_ok('gallery'),'prices'=>$profile_ok('prices'),
      'duration'=>$profile_ok('duration')],
    'price_decisions'=>$price_decisions,
    'license_mode'=>self::policy()['media_rights_mode'],'price_inference'=>false,
    'policy'=>self::policy()];
  $digest=hash('sha256',wp_json_encode($capture,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  $capture['plan_sha256']=$digest;
  set_transient('mad4b_source_materialize_'.$post_id,$capture,900);
  return $capture;
 }
 public static function plan($post_id) {return self::snapshot((int)$post_id);}
 private static function gallery_selection($existing,$owned_previous,$new_urls) {
  $existing=array_values(array_unique((array)$existing));
  $owned_previous=array_values(array_unique((array)$owned_previous));
  $editorial=array_values(array_diff($existing,$owned_previous));
  $suppressed=array_values(array_diff($owned_previous,$existing));
  $owned_new=array_values(array_unique(array_diff((array)$new_urls,$suppressed)));
  $merged=array_values(array_unique(array_merge($owned_new,$editorial)));
  return ['merged'=>$merged,'owned_new'=>$owned_new,
   'editorial'=>$editorial,'suppressed'=>$suppressed];
 }
 private static function merge_gallery($post_id,$photos,$plan) {
  $current=get_post_meta($post_id,'gallery',true);
  if(!is_string($current) && $current!=='' && $current!==null)
   return new WP_Error('source_gallery_meta_format_unexpected');
  if(!hash_equals((string)($plan['gallery_before_sha256']??''),
    hash('sha256',(string)$current)))
   return new WP_Error('source_gallery_changed_since_plan');
  $existing=array_values(array_unique(array_filter(array_map('trim',
    explode(',',(string)$current)))));
  $previous=get_post_meta($post_id,'_mad4b_source_materialize_receipt_v1',true);
  $owned_previous=[];
  if(is_array($previous)) {
   foreach((array)($previous['images']??[]) as $id) {
    $u=wp_get_attachment_url((int)$id);
    if(is_string($u)&&$u)$owned_previous[]=$u;
   }
  }
  // Durable editor lock: an earlier manual gallery change must not be
  // reinterpreted as a source-owned baseline on the next scheduled pass.
  if(get_post_meta($post_id,'_mad4b_source_gallery_editorial_lock_v1',true)==='1')
   return ['state'=>'editorial_gallery_locked_preserved',
    'gallery_sha256'=>hash('sha256',(string)$current),
    'urls'=>$existing,'added'=>0,'editorial_count'=>count($existing),'updated'=>false];
  $previous_sha=is_array($previous)?(string)($previous['gallery_written_sha256']??''):'';
  if($previous_sha && !hash_equals($previous_sha,hash('sha256',(string)$current))) {
   update_post_meta($post_id,'_mad4b_source_gallery_editorial_lock_v1','1');
   return ['state'=>'editorial_gallery_changed_preserved',
    'gallery_sha256'=>hash('sha256',(string)$current),
    'urls'=>$existing,'added'=>0,'editorial_count'=>count($existing),'updated'=>false];
  }
  $new_urls=[];
  foreach($photos as $id) {
   $u=wp_get_attachment_url((int)$id);
   if(is_string($u)&&str_starts_with($u,'https://'))$new_urls[]=$u;
  }
  $selection=self::gallery_selection($existing,$owned_previous,$new_urls);
  $merged=$selection['merged'];$owned_new=$selection['owned_new'];
  $editorial=$selection['editorial'];$suppressed=$selection['suppressed'];
  if(count($merged)>40)return new WP_Error('source_gallery_merged_url_cap_exceeded');
  // Contract confirmed by the existing MAD4B governed-media apply path:
  // the actual JetEngine gallery meta is comma-separated local URL strings,
  // irrespective of WordPress registration's generic array typing.
  $text=implode(',',$merged);
  if(($plan['policy']['gallery_sync_mode']??'')==='review_only')
   return ['state'=>'gallery_review_only','gallery_sha256'=>hash('sha256',(string)$current),
    'proposed_gallery_sha256'=>hash('sha256',$text),'urls'=>$existing,
    'added'=>count(array_diff($merged,$existing)),
    'editorial_count'=>count($editorial),'updated'=>false];
  if($text!==$current)update_post_meta($post_id,'gallery',$text);
  $readback=(string)get_post_meta($post_id,'gallery',true);
  if(!hash_equals(hash('sha256',$text),hash('sha256',$readback)))
   return new WP_Error('source_gallery_meta_readback_failed');
  return ['state'=>'gallery_synchronized','gallery_sha256'=>hash('sha256',$readback),
   'urls'=>$merged,'added'=>count(array_diff($merged,$existing)),
   'editorial_count'=>count($editorial),'updated'=>$text!==$current,
   'source_owned_count'=>count($owned_new),'suppressed_count'=>count($suppressed)];
 }
 private static function image_attach($post_id,$entry,$position) {
  $source=(string)get_post_meta($post_id,'_mad4b_source_content_loop_url_v1',true);
  $scope=self::gallery_provider($source);
  if(!self::gallery_url_allowed((string)($entry['source_url']??''),$scope))
   return new WP_Error('source_gallery_image_url_outside_provider_scope');
  $key=$entry['source_url_sha256'];
  $matches=get_posts(['post_type'=>'attachment','post_status'=>'inherit',
    'posts_per_page'=>2,'fields'=>'ids','meta_key'=>self::MEDIA_KEY,'meta_value'=>$key,
    'suppress_filters'=>true]);
  if(count($matches)>1)return new WP_Error('source_materializer_duplicate_media_binding');
  if($matches && wp_attachment_is_image((int)$matches[0]))return (int)$matches[0];
  $src=$entry['source_url'];
  $provider=self::gallery_provider(
   (string)get_post_meta($post_id,'_mad4b_source_content_loop_url_v1',true));
  if(!self::gallery_url_allowed($src,$provider))
   return new WP_Error('source_gallery_image_url_outside_provider_scope');
  require_once ABSPATH.'wp-admin/includes/file.php';
  require_once ABSPATH.'wp-admin/includes/media.php';
  require_once ABSPATH.'wp-admin/includes/image.php';
  $tmp=download_url($src,25);
  if(is_wp_error($tmp))return $tmp;
  try{
   $bytes=@filesize($tmp);
   if(!$bytes || $bytes<10000 || $bytes>6000000)
     return new WP_Error('source_materializer_image_size_invalid');
   $info=@getimagesize($tmp);
   $mime=$info['mime']??'';
   $extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
   if(!isset($extensions[$mime]) || ($info[0]??0)<320 || ($info[1]??0)<180)
     return new WP_Error('source_materializer_image_mime_or_dimensions_invalid');
   $name=sanitize_title(get_the_title($post_id)).'-source-'.str_pad((string)$position,2,'0',STR_PAD_LEFT).'.'.$extensions[$mime];
   $attachment=media_handle_sideload(['name'=>$name,'tmp_name'=>$tmp,'error'=>0],$post_id,
     'Supplier-sourced tour image. Rights status: unverified (advisory).');
   if(is_wp_error($attachment))return $attachment;
   update_post_meta($attachment,self::MEDIA_KEY,$key);
   update_post_meta($attachment,'_mad4b_source_media_origin_v1',$src);
   update_post_meta($attachment,'_mad4b_source_media_parent_url_v1',
     (string)get_post_meta($post_id,'_mad4b_source_content_loop_url_v1',true));
   update_post_meta($attachment,'_mad4b_source_media_license_status_v1','unknown_advisory');
   $file=get_attached_file($attachment);
   if($file && is_readable($file))
     update_post_meta($attachment,'_mad4b_source_media_file_sha256_v1',hash_file('sha256',$file));
   update_post_meta($attachment,'_wp_attachment_image_alt',
     sanitize_text_field((string)($entry['alt']??'')) ?: get_the_title($post_id).' — source photo '.$position);
   return (int)$attachment;
  } finally {
   if(file_exists($tmp))@unlink($tmp);
  }
 }
 private static function draft_rate($post_id,$days,$source,$currency,$valid_to,$quote=null) {
  $amount=isset($quote['amount'])?(string)$quote['amount']:'';
  $verified=$currency==='USD' && ($quote['currency']??'')==='USD' &&
    ($quote['basis']??'')==='per_person' &&
    ($quote['evidence']??'')==='exact_duration_supplier_price_card' &&
    preg_match('/^[1-9][0-9]{1,6}\\.[0-9]{2}$/D',$amount);
  // A duration or currency is not a selling price. Never turn a missing
  // supplier amount into an empty Tour Rate, even with stop_sale=1.
  // Keep a distinct price-pending finding on the parent Tour instead.
  if(!$verified)return ['rate_id'=>0,'state'=>'price_pending',
    'reason'=>'verified_supplier_amount_missing','created'=>false,'priced'=>false];
  $binding=hash('sha256',$post_id.'|'.$source.'|'.$days.'|source_rate_v1');
  $existing=get_posts(['post_type'=>'tour-rates','post_status'=>['draft','publish','pending','private'],
   'posts_per_page'=>2,'fields'=>'ids','meta_key'=>self::RATE_KEY,'meta_value'=>$binding,
   'suppress_filters'=>true]);
  if(count($existing)>1)return new WP_Error('source_materializer_duplicate_rate_binding');
  if($existing){
   $rate=(int)$existing[0];
   if((int)get_post_meta($rate,'related_tour_id',true)!==$post_id)return new WP_Error('source_materializer_rate_parent_mismatch');
   if($verified && get_post_status($rate)==='draft') {
    $old=(string)get_post_meta($rate,'double_price',true);
    if($old!=='' && abs((float)$old-(float)$amount)>0.001)
     return new WP_Error('source_materializer_existing_rate_amount_review_required');
    if($old==='') {
     update_post_meta($rate,'double_price',$amount);
     update_post_meta($rate,'_mad4b_source_rate_price_status_v1','source_amount_staged_for_review');
     update_post_meta($rate,'_mad4b_source_rate_price_evidence_sha256_v1',$quote['source_card_sha256']);
    }
   }
   if(class_exists('MAD4B_Tour_Archetype_Property_V1')) {
    $linked=MAD4B_Tour_Archetype_Property_V1::rate_link_meta($post_id,$source);
    if(is_array($linked) && $linked) {
     $old_property=get_post_meta($rate,'related_properties_id',true);
     if($old_property && $old_property!==$linked['related_properties_id'])
      return new WP_Error('source_materializer_rate_property_conflict');
     foreach($linked as $name=>$val)update_post_meta($rate,$name,$val);
    }
   }
   return ['rate_id'=>$rate,'existing'=>true,'priced'=>$verified];
  }
  $meta=[
   'related_tour_id'=>$post_id,
   'unit_type'=>'per_person',
   'base_currency'=>$currency,
   'avilabile_status'=>'0','stop_sale'=>'1','is_on_sale'=>'0',
   self::RATE_KEY=>$binding,
   '_mad4b_source_rate_duration_days_v1'=>(string)$days,
   '_mad4b_source_rate_source_url_v1'=>$source,
   '_mad4b_source_rate_price_status_v1'=>$verified?'source_amount_staged_for_review':'awaiting_verified_amount',
   '_mad4b_source_rate_valid_until_note_v1'=>$valid_to
  ];
  if($verified) {
   $meta['double_price']=$amount;
   $meta['_mad4b_source_rate_price_evidence_sha256_v1']=$quote['source_card_sha256'];
  }
  // A source-priced Cruise Rate inherits only an already-verified Tour to
  // Property identity binding. Never create an unpriced Rate to carry it.
  if(class_exists('MAD4B_Tour_Archetype_Property_V1')) {
   $linked=MAD4B_Tour_Archetype_Property_V1::rate_link_meta($post_id,$source);
   if(is_array($linked) && $linked)$meta=array_merge($meta,$linked);
  }
  $rate=wp_insert_post(['post_type'=>'tour-rates','post_status'=>'draft',
   'post_title'=>get_the_title($post_id).' — '.$days.' days — Source rate pending',
   'post_author'=>get_current_user_id(),'meta_input'=>$meta],true);
  if(is_wp_error($rate)||!$rate)return is_wp_error($rate)?$rate:new WP_Error('source_materializer_rate_creation_failed');
  return ['rate_id'=>(int)$rate,'existing'=>false,'priced'=>(bool)$verified];
 }
 private static function sync_starting_price($post_id) {
  $rates=get_posts(['post_type'=>'tour-rates','post_status'=>'publish','posts_per_page'=>100,
    'fields'=>'ids','meta_key'=>'related_tour_id','meta_value'=>(string)$post_id,
    'suppress_filters'=>true]);
  $eligible=[];
  foreach($rates as $rid){
   if((string)get_post_meta($rid,'stop_sale',true)==='1' ||
      (string)get_post_meta($rid,'avilabile_status',true)==='0')continue;
   $currency=(string)get_post_meta($rid,'base_currency',true);
   $unit=(string)get_post_meta($rid,'unit_type',true);
   $amount=(string)get_post_meta($rid,'double_price',true);
   $end=(int)get_post_meta($rid,'tour_rate_end_date',true);
   $start=(int)get_post_meta($rid,'tour_rate_start_date',true);
   if($currency!=='USD' || $unit!=='per_person' || !is_numeric($amount) ||
     (float)$amount<=0 || !$end || $end<time() || !$start ||
     $start>time()+366*86400)continue;
   $eligible[]=['id'=>(int)$rid,'amount'=>(float)$amount];
  }
  if(!$eligible) {
   $derived=(int)get_post_meta($post_id,'_mad4b_source_starting_price_derived_rate_v1',true);
   if($derived) {
    delete_post_meta($post_id,'start_price');
    delete_post_meta($post_id,'_mad4b_source_starting_price_derived_rate_v1');
   }
   return ['start_price_status'=>'not_set_missing_eligible_published_rate',
     'eligible_rate_count'=>0,'value'=>null];
  }
  usort($eligible,static fn($a,$b)=>$a['amount']<=>$b['amount']);
  $amount=number_format($eligible[0]['amount'],2,'.','');
  $previous=(string)get_post_meta($post_id,'start_price',true);
  $last_owner=(int)get_post_meta($post_id,'_mad4b_source_starting_price_derived_rate_v1',true);
  if($previous!=='' && is_numeric($previous) && (float)$previous>0 &&
     !$last_owner && abs((float)$previous-(float)$amount)>0.01)
   return ['start_price_status'=>'manual_value_preserved_for_review',
    'value'=>$previous,'suggested_from_rate'=>$amount,
    'source_rate_id'=>$eligible[0]['id']];
  update_post_meta($post_id,'start_price',$amount);
  update_post_meta($post_id,'_mad4b_source_starting_price_derived_rate_v1',
    (int)$eligible[0]['id']);
  update_post_meta($post_id,'tour_base_currency','USD');
  update_post_meta($post_id,'tour_price_unit','per_person');
  return ['start_price_status'=>'derived_from_eligible_published_rate',
   'value'=>$amount,'source_rate_id'=>$eligible[0]['id'],'eligible_rate_count'=>count($eligible)];
 }
 public static function reconcile_price($post_id) {
  $post_id=(int)$post_id;
  if(wp_get_environment_type()!=='staging' ||
     get_post_type($post_id)!=='tours-and-activities' ||
     !get_post_meta($post_id,'_mad4b_source_content_loop_key_v1',true))
    return new WP_Error('source_materializer_rate_reconcile_site_or_tour_invalid');
  if(class_exists('MAD4B_SCP_Site_Profile') &&
    !MAD4B_SCP_Site_Profile::site_urls_match_enrollment())
    return new WP_Error('source_materializer_rate_reconcile_site_binding_invalid');
  return self::sync_starting_price($post_id);
 }
 public static function apply($post_id,$sha,$confirmation) {
  $post_id=(int)$post_id;
  if($confirmation!=='MATERIALIZE SOURCE DRAFT ASSETS')return new WP_Error('source_materializer_confirmation_required');
  $gate=self::gate($post_id);if(is_wp_error($gate))return $gate;
  $plan=get_transient('mad4b_source_materialize_'.$post_id);
  if(!is_array($plan)||!hash_equals((string)($plan['plan_sha256']??''),(string)$sha))
    return new WP_Error('source_materializer_plan_missing_or_stale');
  if((string)$plan['source_url']!==$gate)return new WP_Error('source_materializer_source_binding_drift');
  if(class_exists('MAD4B_Scraping_Source_Profiles_V1')) {
   $current_source_profile=MAD4B_Scraping_Source_Profiles_V1::resolve($gate);
   if(is_wp_error($current_source_profile) ||
      !hash_equals((string)($plan['source_profile_digest']??''),
        (string)($current_source_profile['profile_digest']??'')))
     return new WP_Error('source_materializer_profile_changed_replan_required');
  }
  if(class_exists('MAD4B_Tour_Archetype_Registry_V1')) {
   $archetype_current=MAD4B_Tour_Archetype_Registry_V1::select($post_id);
   if(is_wp_error($archetype_current) ||
      !hash_equals((string)($plan['archetype_profile_digest']??''),
       (string)($archetype_current['profile_digest']??'')))
    return new WP_Error('source_materializer_archetype_changed_replan_required');
  }
  $current_gallery=get_post_meta($post_id,'gallery',true);
  if(!is_string($current_gallery) && $current_gallery!=='' && $current_gallery!==null)
   return new WP_Error('source_gallery_meta_format_unexpected');
  if(!hash_equals((string)($plan['gallery_before_sha256']??''),
    hash('sha256',(string)$current_gallery)))
   return new WP_Error('source_gallery_changed_since_plan');
  $photos=[];$errors=[];$rates=[];$pending=[];
  $gallery_result=['state'=>'no_source_images','urls'=>[],'updated'=>false,
    'gallery_sha256'=>hash('sha256',(string)$current_gallery)];
  if(!empty($plan['field_policy']['gallery']) &&
    !empty($plan['policy']['auto_import_source_media']) &&
    ($plan['policy']['media_rights_mode']??'advisory')==='advisory'){
   $limit=max(1,min(self::MAX_MEDIA,
     (int)($plan['policy']['max_gallery_images']??self::MAX_MEDIA)));
   foreach(array_slice($plan['image_candidates'],0,$limit) as $index=>$image) {
    $id=self::image_attach($post_id,$image,$index+1);
    if(is_wp_error($id)){$errors[]=['image'=>$index+1,'code'=>$id->get_error_code()];continue;}
    $photos[]=$id;
   }
   if($photos) {
    $old_receipt=get_post_meta($post_id,'_mad4b_source_materialize_receipt_v1',true);
    $owned_featured_before=is_array($old_receipt) &&
      (int)($old_receipt['featured_image_id']??0)>0;
    // Editor removal of an earlier imported featured image is intentional:
    // never reselect it during periodic reimport.
    if(!get_post_thumbnail_id($post_id) && !$owned_featured_before)
     set_post_thumbnail($post_id,$photos[0]);
    $gallery_result=self::merge_gallery($post_id,$photos,$plan);
    if(is_wp_error($gallery_result)) {
     $errors[]=['gallery'=>'merge','code'=>$gallery_result->get_error_code()];
     $gallery_result=['state'=>'gallery_merge_blocked','urls'=>[],
       'gallery_sha256'=>hash('sha256',(string)get_post_meta($post_id,'gallery',true)),
       'updated'=>false];
    }
   }
  } elseif(($plan['policy']['media_rights_mode']??'')==='enforced') {
   $errors[]=['gallery'=>'import','code'=>'source_gallery_rights_not_verified_company_enforced'];
  }
  $currency=$plan['currency'];
  if($currency==='USD' && !empty($plan['field_policy']['prices']) &&
    !empty($plan['field_policy']['duration'])){
   foreach($plan['durations'] as $days){
    $rate=self::draft_rate($post_id,$days,$gate,$currency,$plan['price_valid_to'],
      $plan['verified_amounts'][$days]??null);
    if(is_wp_error($rate)){$errors[]=['rate_days'=>$days,'code'=>$rate->get_error_code()];continue;}
    if(empty($rate['rate_id'])) {
     $pending[]=['duration_days'=>(int)$days,
       'state'=>'price_pending','reason'=>'supplier_amount_missing_or_unverified'];
     continue;
    }
    $rates[]=['duration_days'=>$days]+$rate;
   }
   // Currency/unit confirmed; positive numeric start_price is derived only from
   // eligible, published, available linked Rates, not placeholders.
   update_post_meta($post_id,'tour_base_currency','USD');
   update_post_meta($post_id,'tour_price_unit','per_person');
  }
  // Store unresolved duration options outside the Tour Rates CPT.
  if(!empty($plan['field_policy']['prices']))
   update_post_meta($post_id,'_mad4b_source_price_pending_v1',$pending);
  $starting=self::sync_starting_price($post_id);
  update_post_meta($post_id,'_mad4b_source_price_capture_state_v1',
    empty($plan['verified_amounts'])?'supplier_price_not_visible':'price_candidates_detected');
  update_post_meta($post_id,'_mad4b_source_media_license_mode_v1','advisory');
  update_post_meta($post_id,'_mad4b_source_price_last_evidence_sha256_v1',$plan['source_sha256']);
  $result=['contract'=>self::CONTRACT,'post_id'=>$post_id,'post_status'=>get_post_status($post_id),
   'images'=>$photos,'featured_image_id'=>get_post_thumbnail_id($post_id),
   'image_count'=>count($photos),'rates'=>$rates,'rate_count'=>count($rates),
   'price_pending'=>$pending,'price_pending_count'=>count($pending),
   'starting_price'=>$starting,'source_currency'=>$currency,'source_price_valid_until'=>$plan['price_valid_to'],
   'rights_mode'=>$plan['policy']['media_rights_mode'],'rights_verified'=>false,
   'source_profile_id'=>$plan['source_profile']['source_profile_id']??'',
   'source_profile_digest'=>$plan['source_profile_digest']??'',
   'archetype_family'=>$plan['archetype_family']??'',
   'archetype_profile_digest'=>$plan['archetype_profile_digest']??'',
   'archetype_relationship_mode'=>$plan['archetype_relationship_mode']??'',
   'price_decisions'=>$plan['price_decisions']??[],
   'source_gallery_sha256'=>$plan['source_gallery_sha256']??'',
   'source_gallery_modes'=>$plan['source_gallery_modes']??[],
   'source_gallery_provider'=>$plan['source_gallery_provider']??'',
   'gallery_written_sha256'=>$gallery_result['gallery_sha256']??'',
   'gallery_state'=>$gallery_result['state']??'',
   'gallery_count'=>count($gallery_result['urls']??[]),
   'gallery_editorial_preserved_count'=>$gallery_result['editorial_count']??0,
   'gallery_source_owned_count'=>$gallery_result['source_owned_count']??0,
   'errors'=>$errors];
  $result['materialized_at']=gmdate('c');
  update_post_meta($post_id,'_mad4b_source_materialize_receipt_v1',$result);
  return $result;
 }
 public static function unpriced_reconcile_plan($post_id) {
  $post_id=(int)$post_id;
  $source=self::gate($post_id);
  if(is_wp_error($source))return $source;
  $key=(string)get_post_meta($post_id,'_mad4b_source_content_loop_key_v1',true);
  if(!preg_match('/^[a-f0-9]{24,64}$/D',$key))
   return new WP_Error('source_materializer_source_key_invalid');
  $records=get_posts(['post_type'=>'tour-rates',
   'post_status'=>['draft','publish','private','pending'],
   'posts_per_page'=>25,'fields'=>'ids',
   'meta_query'=>[['key'=>'related_tour_id','value'=>(string)$post_id],
     ['key'=>self::RATE_KEY,'compare'=>'EXISTS']],
   'suppress_filters'=>true]);
  $candidates=[];$blocked=[];
  foreach($records as $rate_id){
   $id=(int)$rate_id;
   $values=[];
   foreach(['single_price','double_price','triple_price'] as $k)
    $values[$k]=(string)get_post_meta($id,$k,true);
   $matching=(string)get_post_meta($id,'_mad4b_source_rate_source_url_v1',true)===$source;
   $priced=false;
   foreach($values as $v)if(is_numeric($v) && (float)$v>0)$priced=true;
   $safe=$matching && get_post_status($id)==='draft' &&
     !$priced && (string)get_post_meta($id,'stop_sale',true)==='1' &&
     (string)get_post_meta($id,'avilabile_status',true)==='0' &&
     get_post_meta($id,'_mad4b_source_rate_price_status_v1',true)==='awaiting_verified_amount' &&
     (string)get_post_meta($id,'_mad4b_source_rate_duration_days_v1',true)!=='';
   $record=['rate_id'=>$id,'duration_days'=>
     (int)get_post_meta($id,'_mad4b_source_rate_duration_days_v1',true),
     'rate_binding'=>(string)get_post_meta($id,self::RATE_KEY,true),
     'prices'=>$values,'post_status'=>get_post_status($id),
     'reason'=>$safe?'legacy_source_unpriced_rate':'requires_manual_review'];
   if($safe)$candidates[]=$record;else $blocked[]=$record;
  }
  if($blocked)return new WP_Error('source_materializer_rate_reconcile_conflicting_records',
    'One or more source-linked Rates are priced, changed or not safe to archive.',
    ['blocking_rate_ids'=>array_column($blocked,'rate_id')]);
  usort($candidates,static fn($a,$b)=>$a['rate_id']<=>$b['rate_id']);
  $material=['contract'=>'mad4b.source-unpriced-rate-reconcile.v1',
   'post_id'=>$post_id,'post_status'=>get_post_status($post_id),
   'source_url_sha256'=>hash('sha256',$source),
   'source_key'=>$key,'candidates'=>$candidates,
   'site_origin'=>untrailingslashit(home_url('/'))];
  $sha=hash('sha256',wp_json_encode($material,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  return $material+['plan_sha256'=>$sha,'candidate_count'=>count($candidates),
   'action'=>'trash_only_legacy_empty_source_rate_drafts','mutation_performed'=>false];
 }
 public static function unpriced_reconcile_apply($post_id,$expected,$confirmation) {
  if($confirmation!=='ARCHIVE EXACT UNPRICED SOURCE RATES')
   return new WP_Error('source_materializer_rate_reconcile_confirmation_missing');
  $plan=self::unpriced_reconcile_plan($post_id);
  if(is_wp_error($plan))return $plan;
  if(!$plan['candidate_count'])return new WP_Error('source_materializer_rate_reconcile_already_clean');
  if(!preg_match('/^[a-f0-9]{64}$/D',(string)$expected) ||
     !hash_equals($plan['plan_sha256'],(string)$expected))
   return new WP_Error('source_materializer_rate_reconcile_plan_stale');
  $backup='mad4b_source_unpriced_rates_backup_'.substr($expected,0,24);
  $snapshots=[];
  foreach($plan['candidates'] as $item) {
   $id=(int)$item['rate_id'];
   $snapshots[$id]=['post'=>get_post($id,ARRAY_A),'meta'=>get_post_meta($id)];
  }
  if(!add_option($backup,['contract'=>'mad4b.source-rate-reconcile-backup.v1',
      'plan'=>$plan,'snapshots'=>$snapshots], '',false))
   return new WP_Error('source_materializer_rate_reconcile_backup_collision');
  $archived=[];
  foreach($plan['candidates'] as $item){
   $id=(int)$item['rate_id'];
   $out=wp_trash_post($id);
   if(!$out || get_post_status($id)!=='trash')
    return new WP_Error('source_materializer_rate_reconcile_partial_requires_restore',
     'Rate trash transition could not be fully verified; restore from WordPress Trash.',
     ['archived_ids'=>$archived,'backup'=>$backup,'failed_id'=>$id]);
   $archived[]=$id;
  }
  $pending=array_map(static fn($row)=>[
    'duration_days'=>(int)$row['duration_days'],
    'state'=>'price_pending','reason'=>'supplier_amount_missing_or_unverified'
  ],$plan['candidates']);
  update_post_meta((int)$post_id,'_mad4b_source_price_pending_v1',$pending);
  $prior=get_post_meta((int)$post_id,'_mad4b_source_materialize_receipt_v1',true);
  if(is_array($prior)){
   $prior['rates']=[];$prior['rate_count']=0;
   $prior['price_pending']=$pending;$prior['price_pending_count']=count($pending);
   $prior['legacy_unpriced_rate_archived_ids']=$archived;
   $prior['rate_reconciliation_receipt_sha256']=$expected;
   update_post_meta((int)$post_id,'_mad4b_source_materialize_receipt_v1',$prior);
  }
  return ['contract'=>'mad4b.source-unpriced-rate-reconcile.apply.v1',
   'state'=>'ARCHIVED_AND_VERIFIED','post_id'=>(int)$post_id,
   'archived_rate_ids'=>$archived,'archived_count'=>count($archived),
   'backup_option'=>$backup,'price_pending_count'=>count($pending),
   'plan_sha256'=>$expected,'start_price'=>(string)get_post_meta((int)$post_id,'start_price',true),
   'post_status'=>get_post_status((int)$post_id)];
 }
 public static function tick($limit=1) {
  $limit=max(1,min(2,(int)$limit));
  $rows=get_option('mad4b_source_content_loop_v1',[]);
  if(!is_array($rows))return new WP_Error('source_materializer_registry_invalid');
  uasort($rows,static function($a,$b) {
   $a=is_array($a)?$a:[];$b=is_array($b)?$b:[];
   return strcmp((string)($a['last_materialize']??''),(string)($b['last_materialize']??''));
  });
  $out=[];
  foreach($rows as $key=>$row) {
   if(count($out)>=$limit)break;
   if(!is_array($row) || ($row['key']??'')!==$key)continue;
   $post_id=(int)($row['post_id']??0);
   if(!$post_id || get_post_type($post_id)!=='tours-and-activities'
      || get_post_status($post_id)!=='draft')continue;
   $receipt=get_post_meta($post_id,'_mad4b_source_materialize_receipt_v1',true);
   $last=is_array($receipt)?(string)($receipt['materialized_at']??''):'';
   if($last && strtotime($last)>time()-86400)continue;
   $lock='mad4b_source_materialize_lock_'.$post_id;
   if(get_transient($lock))continue;
   set_transient($lock,1,220);
   try{
    $plan=self::plan($post_id);
    if(is_wp_error($plan)) {
     $out[]=['post_id'=>$post_id,'state'=>'capture_error','code'=>$plan->get_error_code()];
     continue;
    }
    $res=self::apply($post_id,$plan['plan_sha256'],'MATERIALIZE SOURCE DRAFT ASSETS');
    if(is_wp_error($res))$out[]=['post_id'=>$post_id,'state'=>'apply_error','code'=>$res->get_error_code()];
    else $out[]=['post_id'=>$post_id,'state'=>'materialized',
      'media_count'=>$res['image_count'],'rate_count'=>$res['rate_count'],'errors'=>$res['errors']];
   } finally {delete_transient($lock);}
  }
  return ['contract'=>self::CONTRACT,'operation'=>'tick','checked'=>count($out),'results'=>$out];
 }
 public static function status($post_id) {
  return ['contract'=>self::CONTRACT,'policy'=>self::policy(),'post_id'=>(int)$post_id,
   'receipt'=>get_post_meta($post_id,'_mad4b_source_materialize_receipt_v1',true)];
 }
}
// Read-only projection so company operations can inspect materialization
// without shell access or granting write authority to ChatGPT.
add_action('wp_abilities_api_init',static function(){
 if(!function_exists('wp_register_ability') || !function_exists('wp_has_ability') ||
    wp_has_ability('mad4b/source-tour-materialize-status'))return;
 wp_register_ability('mad4b/source-tour-materialize-status',[
  'label'=>'Source Tour Materialization Status',
  'description'=>'Inspect source photo import, unpriced Rate drafts and starting-price policy.',
  'category'=>'mad4b-read',
  'execute_callback'=>static function($input) {
   $id=(int)($input['post_id']??0);
   if(get_post_type($id)!=='tours-and-activities')
    return new WP_Error('source_materialize_target_invalid');
   return MAD4B_Source_Tour_Materializer_V1::status($id);
  },
  'permission_callback'=>static function() {
   return class_exists('MAD4B_SCP_Policy') && MAD4B_SCP_Policy::can_read();
  },
  'input_schema'=>['type'=>'object','properties'=>[
   'post_id'=>['type'=>'integer','minimum'=>1]],
   'required'=>['post_id'],'additionalProperties'=>false],
  'output_schema'=>['type'=>'object','additionalProperties'=>true],
  'meta'=>['public'=>false,'show_in_rest'=>false,
   'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
   'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]]
 ]);
},95);
if(defined('WP_CLI') && WP_CLI) {
 WP_CLI::add_command('mad4b-source-materialize',new class {
  public function plan($a,$o) {$this->show(MAD4B_Source_Tour_Materializer_V1::plan((int)($a[0]??0)));}
  public function apply($a,$o) {$this->show(MAD4B_Source_Tour_Materializer_V1::apply((int)($a[0]??0),
    (string)($o['expected-plan']??''),(string)($o['confirm']??'')));}
  public function unpriced_reconcile_plan($a,$o) {
   $this->show(MAD4B_Source_Tour_Materializer_V1::unpriced_reconcile_plan((int)($a[0]??0)));
  }
  public function unpriced_reconcile_apply($a,$o) {
   $this->show(MAD4B_Source_Tour_Materializer_V1::unpriced_reconcile_apply(
     (int)($a[0]??0),(string)($o['expected-plan']??''),
     (string)($o['confirm']??'')));
  }
  public function reconcile_price($a,$o) {
   $id=(int)($a[0]??0);
   if(!current_user_can('edit_post',$id))WP_CLI::error('Exact editor capability required');
   $this->show(MAD4B_Source_Tour_Materializer_V1::reconcile_price($id));
  }
  public function tick($a,$o) {$this->show(MAD4B_Source_Tour_Materializer_V1::tick((int)($o['max']??1)));}
  public function status($a,$o) {$this->show(MAD4B_Source_Tour_Materializer_V1::status((int)($a[0]??0)));}
  private function show($v) {
   if(is_wp_error($v))WP_CLI::error($v->get_error_code().' '.$v->get_error_message());
   WP_CLI::line(wp_json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
  }
 });
}


// Reconcile the Tour's public starting price when an already-authorized Rate
// changes status, occupancy, validity or availability. No Rate is published here.
add_action('mad4b_source_materializer_reconcile_price_v1',
 static function($id){MAD4B_Source_Tour_Materializer_V1::reconcile_price((int)$id);},10,1);
add_action('transition_post_status',static function($new,$old,$post){
 if($new===$old || !$post || $post->post_type!=='tour-rates')return;
 $tour=(int)get_post_meta($post->ID,'related_tour_id',true);
 if($tour>0 && get_post_meta($tour,'_mad4b_source_content_loop_key_v1',true))
  wp_schedule_single_event(time()+30,'mad4b_source_materializer_reconcile_price_v1',[$tour]);
},50,3);
$mad4b_rate_keys=['related_tour_id','double_price','base_currency','unit_type',
 'tour_rate_start_date','tour_rate_end_date','avilabile_status','stop_sale'];
foreach(['added_post_meta','updated_post_meta','deleted_post_meta'] as $hook)
 add_action($hook,static function($mid,$id,$key)use($mad4b_rate_keys){
  if(!in_array((string)$key,$mad4b_rate_keys,true) || get_post_type($id)!=='tour-rates')return;
  $tour=(int)get_post_meta($id,'related_tour_id',true);
  if($tour>0 && get_post_meta($tour,'_mad4b_source_content_loop_key_v1',true) &&
    !wp_next_scheduled('mad4b_source_materializer_reconcile_price_v1',[$tour]))
   wp_schedule_single_event(time()+30,'mad4b_source_materializer_reconcile_price_v1',[$tour]);
 },60,3);
