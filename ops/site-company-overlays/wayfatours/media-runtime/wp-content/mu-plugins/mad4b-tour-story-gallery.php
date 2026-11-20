<?php
/**
 * Plugin Name: MAD4B Tour Story Gallery
 * Description: Itinerary and Highlights-aware photo sections backed by exact local tour/destination media, no copying or mutations.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
// Reuse the shared Tour Highlights resolver; no second MU plugin or module map.
$hl_file = WP_PLUGIN_DIR . '/mad4b-site-control-plane/includes/class-mad4b-scp-tour-highlights-v1.php';
if (is_file($hl_file)) require_once $hl_file;
unset($hl_file);
final class MAD4B_Tour_Story_Gallery_V1 {
 const CONTRACT='mad4b.tour-story-gallery.v1';
 private static function norm(string $v):string {
  $v=html_entity_decode(wp_strip_all_tags($v),ENT_QUOTES|ENT_HTML5,'UTF-8');
  $v=remove_accents(mb_strtolower($v,'UTF-8'));
  return trim(preg_replace('/[^\p{L}\p{N}]+/u',' ',$v));
 }
 private static function contains(string $haystack,string $needle):bool {
  $n=self::norm($needle);$h=' '.self::norm($haystack).' ';
  return mb_strlen($n)>2&&mb_strpos($h,' '.$n.' ')!==false;
 }
 private static function id_for_url(string $url,int $post):int {
  if(!$url||!class_exists('MAD4B_Gallery_Resolution_Engine_V1',false))return 0;
  $r=MAD4B_Gallery_Resolution_Engine_V1::inspect($url,$post);
  return !empty($r['valid_source'])&&(int)($r['verified_local']??0)===1 ? (int)($r['render_value']??0):0;
 }
 private static function local_photo(int $id):?array {
  if($id<=0||get_post_type($id)!=='attachment')return null;
  if(class_exists('MAD4B_Tour_Watermark_Guard_V1',false)
     &&!MAD4B_Tour_Watermark_Guard_V1::approved_for_public($id))return null;
  if(!class_exists('MAD4B_Media_Visual_Lineage_V1',false)||!class_exists('MAD4B_Tour_Photo_Quality_V1',false))return null;
  $o=MAD4B_Media_Visual_Lineage_V1::origin($id);
  if(empty($o['verified'])||!preg_match('/^[a-f0-9]{64}$/D',(string)($o['origin_sha256']??'')))return null;
  if(MAD4B_Tour_Photo_Quality_V1::provenance($id)['status']==='synthetic_confirmed')return null;
  $file=get_attached_file($id);if(!$file||!is_file($file))return null;
  $url=wp_get_attachment_url($id);if(!$url)return null;
  $thumb=wp_get_attachment_image_url($id,'medium_large') ?: $url;
  return ['id'=>$id,'origin'=>$o['origin_sha256'],'url'=>$url,'thumbnail'=>$thumb,
   'alt'=>trim((string)get_post_meta($id,'_wp_attachment_image_alt',true))?:wp_strip_all_tags(get_the_title($id)),
   'provenance'=>MAD4B_Tour_Photo_Quality_V1::provenance($id)['status']];
 }
 private static function from_gallery(string $value,int $post):array {
  $pieces=array_values(array_filter(array_map('trim',explode(',',$value))));
  if(!$pieces||count($pieces)>500)return [];
  $r=MAD4B_Gallery_Resolution_Engine_V1::inspect(implode(',',$pieces),$post);
  if(empty($r['valid_source']))return [];
  return array_values(array_filter(array_map('intval',explode(',',(string)($r['render_value']??'')))));
 }
 private static function itinerary(int $post,bool $program_preferred=true,int $verified_program=0):array {
  $policy=$program_preferred&&class_exists('MAD4B_Gallery_Itinerary_Policy_V1',false)
    ?MAD4B_Gallery_Itinerary_Policy_V1::resolve($post,$post,$verified_program):null;
  if(is_array($policy)&&($policy['authority']??'')==='taxonomy:programs:program_itinerary'){
   $rows=[];foreach((array)($policy['days']??[])as $day){
     $text=(string)($day['title']??'');
     $detail=(string)($day['description']??'');
     $rows[]=[
       'day'=>(int)($day['day']??0),'title'=>$text,'detail'=>$detail,
       'optional_title'=>(bool)preg_match('/\\boptional\\b|\\bopcional\\b|\\bfacultatif\\b|\\boptionnel\\b/iu',$text),
       'logistics_only'=>(bool)preg_match('/\\b(?:arrival|departure|return|transfer|fly|flight|check.?in|check.?out)\\b/iu',$text)
         && !preg_match('/\\b(?:visit|explore|discover|sightseeing|temple|museum|pyramids|leisure)\\b/iu',$text.' '.$detail)
     ];
   }
   return $rows;
  }
  if(is_array($policy)&&!empty($policy['selection_required']))
    return []; // unselected multi-Program Cruise: no invented Day labels.
  if(is_array($policy)&&($policy['authority']??'')==='taxonomy:programs')
    return []; // Program content absent: never fall back to stale Tour days.
  $raw=get_post_meta($post,'itinerary_item',true);$out=[];
  // Hourly stops within a single-day activity are NOT separate travel days.
  // The JetEngine repeater 'number' field is the sequential STOP number.
  // Detect the presentation contract from its times, not specific post IDs.
  $stops=0;$withTimes=0;
  foreach((array)$raw as $item){
   if(!is_array($item))continue;
   $stops++;
   $heading=html_entity_decode(wp_strip_all_tags((string)($item['title']??'')),ENT_QUOTES|ENT_HTML5,'UTF-8');
   if(preg_match('/^\d{1,2}:\d{2}(?:\s*[–-]\s*\d{1,2}:\d{2})?\s*[—–-]/u',$heading))$withTimes++;
  }
  $mode=(string)get_post_meta($post,'tour_mode',true);
  $declaredDays=(int)get_post_meta($post,'itinerary_item_number',true);
  $single_day_stop_sequence=$stops>=2&&$withTimes===$stops
    &&($mode==='activity'||$declaredDays===1);
  foreach((array)$raw as $i=>$row){
   if(!is_array($row))continue;
   $title=html_entity_decode(wp_strip_all_tags((string)($row['title']??'')),ENT_QUOTES|ENT_HTML5,'UTF-8');
   $detail=html_entity_decode(wp_strip_all_tags((string)($row['description']??'')),ENT_QUOTES|ENT_HTML5,'UTF-8');
   $number=$single_day_stop_sequence?1:(int)($row['number']??0);
   if(!$number&&preg_match('/\b(?:day|dia|jour|tag|giorno)\s*(\d+)/iu',$title,$m))$number=(int)$m[1];
   if(!$number)$number=count($out)+1;
   $optional=(bool)preg_match('/\boptional\b|\bopcional\b|\bfacultatif\b|\boptionnel\b/iu',$title);
   $logistics=(bool)preg_match('/\b(?:arrival|departure|return|transfer|fly|flight|check.?in|check.?out)\b/iu',$title)
     && !preg_match('/\b(?:visit|explore|discover|sightseeing|temple|museum|pyramids|leisure)\b/iu',$title.' '.$detail);
   $out[]=['day'=>$number,'title'=>$title,'detail'=>$detail,'optional_title'=>$optional,'logistics_only'=>$logistics];
  }
  return $out;
 }
 private static function country_root(int $post):int {
  $terms=wp_get_object_terms($post,'destinations',['fields'=>'all']);if(is_wp_error($terms))return 0;
  foreach($terms as $t){
   $chain=array_reverse(get_ancestors($t->term_id,'destinations','taxonomy'));
   $chain[]=$t->term_id;
   foreach($chain as $tid){$v=get_term((int)$tid,'destinations');if(!$v||is_wp_error($v))continue;
    // Use the actual taxonomic country below the top-level continent;
    // do not hardcode site-specific term IDs.
    $parent=(int)$v->parent;$parent_term=$parent?get_term($parent,'destinations'):null;
    if($parent_term&&!is_wp_error($parent_term)&&(int)$parent_term->parent===0)return (int)$v->term_id;
   }
  }
  return 0;
 }
 private static function destination_terms(int $post,array $days,string $highlights):array {
  $root=self::country_root($post);if(!$root)return [];
  $main=get_terms(['taxonomy'=>'destinations','parent'=>$root,'hide_empty'=>false]);
  if(is_wp_error($main)||!is_array($main))return [];
  $all=implode(' ',array_map(static fn($d)=>$d['title'].' '.$d['detail'],$days)).' '.$highlights;
  $sections=[];
  foreach($main as $term){
   if(!self::contains($all,$term->name))continue;
   $day_ids=[];
   foreach($days as $day){
    if(!self::contains($day['title'].' '.$day['detail'],$term->name))continue;
    if(!empty($day['logistics_only']))continue;
    // A place mentioned only as the departure point of a flight or
    // transfer does not count as a day exploring that destination.
    if(!self::contains($day['title'],$term->name)){
        $detail=self::norm($day['detail']);$named=preg_quote(self::norm($term->name),'/');
        $pass_through=(bool)preg_match('/\b(?:flight|fly|transfer|return|departure|arrival|airport|overnight|depart|pickup|pick.?up|drop.?off|drive|begin your journey)\b.{0,90}'.$named.'/u',$detail);
        $actual_visit=(bool)preg_match('/\b(?:visit|explore|tour|sightseeing|discover)\b.{0,45}'.$named.'/u',$detail);
        if($pass_through&&!$actual_visit)continue;
    }
    // A day mixing an optional excursion and guaranteed transfer is not
    // entirely optional. Exclude only the named optional destination itself.
    if($day['optional_title']&&preg_match('/optional.{0,18}'.preg_quote(self::norm($term->name),'/').'/iu',self::norm($day['title'])))continue;
    $day_ids[]=(int)$day['day'];
   }
   // Highlights-only region may be relevant but cannot imply a precise day.
   $day_ids=array_values(array_unique($day_ids));sort($day_ids);
   $sections[$term->term_id]=['id'=>(int)$term->term_id,'title'=>$term->name,
     'days'=>$day_ids,'children'=>[],'images'=>[],'minimum'=>$day_ids?max(2,min(6,(int)ceil(count($day_ids)*1.25))):0,
     'evidence'=>'itinerary_highlights_destination_taxonomy'];
  }
  // Avoid arbitrary taxonomic depth: descendants are used only if named in
  // actual itinerary/highlights and contain owned local photos.
  $all_terms=get_terms(['taxonomy'=>'destinations','hide_empty'=>false]);
  if(!is_wp_error($all_terms)&&is_array($all_terms))foreach($all_terms as $term){
   if(isset($sections[$term->term_id]))continue;
   $ancestors=get_ancestors($term->term_id,'destinations','taxonomy');
   foreach($sections as $parent=>$section){
    if(!in_array($parent,$ancestors,true))continue;
    $match=self::contains($all,$term->name);
    $name=self::norm($term->name);
    if(!$match&&preg_match('/\b(?:east|west) bank\b/',$name,$m))$match=self::contains($all,$m[0].' of '.$section['title'])||self::contains($all,$m[0].' of luxor');
    if($match){
       $optional_only=false;$nonoptional=false;
       foreach($days as $day){
        $text=self::norm($day['title'].' '.$day['detail']);
        if(!self::contains($text,$term->name))continue;
        if(preg_match('/optional.{0,35}'.preg_quote(self::norm($term->name),'/').'/iu',$text))$optional_only=true;
        else $nonoptional=true;
       }
       if(!$optional_only||$nonoptional)$sections[$parent]['children'][]=(int)$term->term_id;
    }
    break;
   }
  }
  $sorted=array_values($sections);
  usort($sorted,static function($a,$b){
   $da=$a['days'][0]??9999;$db=$b['days'][0]??9999;
   return $da<=>$db ?: strcmp($a['title'],$b['title']);
  });
  return $sorted;
 }
 private static function term_candidates(int $id,int $post,int $max):array {
  $ids=[];
  $raw=get_term_meta($id,'locations_gallery_attachment_ids',true);
  foreach((array)$raw as $aid){$aid=(int)$aid;if($aid)$ids[]=$aid;}
  $g=get_term_meta($id,'image_gellery',true);
  if(is_string($g)&&$g!=='')$ids=array_merge($ids,self::from_gallery($g,$post));
  $featured=get_term_meta($id,'featured_image',true);
  if(is_numeric($featured))$ids[]=(int)$featured;
  elseif(is_string($featured)&&$featured!==''){$fid=self::id_for_url($featured,$post);if($fid)$ids[]=$fid;}
  return array_slice(array_values(array_unique($ids)),0,$max);
 }
 public static function geography_review(int $post):array {
  $lang=(string)apply_filters('wpml_default_language','en');
  $canonical=(int)apply_filters('wpml_object_id',$post,'tours-and-activities',false,$lang);
  if(!$canonical)$canonical=$post;
  $days=self::itinerary($canonical);$sharm=[];$giftun=[];$hurghada=false;
  foreach($days as $row){
    $content=self::norm($row['title'].' '.$row['detail']);
    if(preg_match('/\bsharm\s+(?:el\s+)?sh[ie]+[ikh]{1,4}\b/u',$content)
       ||self::contains($content,'sharm el sheikh')||self::contains($content,'sharm el shikh'))$sharm[]=(int)$row['day'];
    if(self::contains($content,'giftun')||self::contains($content,'orange bay'))$giftun[]=(int)$row['day'];
    if(self::contains($content,'hurghada'))$hurghada=true;
  }
  if($sharm&&$giftun&&!$hurghada)return [[
     'code'=>'sharm_giftun_destination_ambiguity',
     'severity'=>'high','primary_destination'=>'Sharm el-Sheikh',
     'conflicting_optional_excursion'=>'Giftun Island / Orange Bay (Hurghada area)',
     'sharm_days'=>array_values(array_unique($sharm)),
     'giftun_days'=>array_values(array_unique($giftun)),
     'requires_supplier_confirmation'=>true,
     'not_auto_corrected'=>true]];
  return [];
 }
 private static function curated_candidates(int $canonical,int $destination):array {
  // Editorial choices are recorded per canonical tour and taxonomic region.
  // No cross-company media cloning, automatic library-wide import or missing
  // source spoofing. Every image is separately lineage/provenance verified.
  $map=get_post_meta($canonical,'_mad4b_story_section_curated_ids_v1',true);
  if(!is_array($map))return [];
  $items=$map[(string)$destination]??$map[$destination]??[];
  if(!is_array($items)||count($items)>12)return [];
  $out=[];$seen=[];
  foreach($items as $item){
    $id=(int)($item['attachment_id']??0);
    $kind=(string)($item['context_evidence']??'');
    if(!$id||!in_array($kind,['named_destination_photo','exact_tour_photo'],true))continue;
    if(isset($seen[$id]))continue;
    $p=self::local_photo($id);if(!$p)continue;
    if($kind==='named_destination_photo'){
      $name=self::norm((string)get_the_title($id).' '.basename((string)get_attached_file($id)));
      if(!self::contains($name,'sharm')&&!self::contains($name,'sharks bay'))continue;
    }else {
      $slug=self::norm((string)get_post_field('post_name',$canonical));
      $file=self::norm(basename((string)get_attached_file($id)));
      $title=self::norm((string)get_the_title($canonical));
      if(!self::contains($file,'best of egypt')&&!self::contains($file,mb_substr($slug,0,25)))continue;
    }
    $seen[$id]=true;
    $p['source']='editorially_curated_photo';
    $p['source_term_id']=$destination;
    $p['context_evidence']=$kind;
    $p['context_label']=sanitize_text_field((string)($item['caption']??''));
    $p['visit_guaranteed']=false; // Destination scenery, not an included optional excursion.
    $out[]=$p;
  }
  return $out;
 }
 private static function allowed_context(int $aid,string $group,array $regions,array $day_terms):bool {
  $text=(string)get_post_field('post_title',$aid).' '.basename((string)get_attached_file($aid));
  // A named photo explicitly located in a DIFFERENT region must not be
  // silently presented as the current destination (even if both are visited).
  foreach($regions as $other){if(self::norm($group)===self::norm($other))continue;
   if(self::contains($text,$other)&&!self::contains($text,$group))return false;
  }
  // Destination gallery may contain other regions' temples. Evidence from
  // exact named itinerary landmark elsewhere beats a broad parent tag.
  foreach($day_terms as $other=>$names){if(self::norm($other)===self::norm($group))continue;
   foreach($names as $name){
    if(self::contains($text,$name))return false;
    // Child place may be called 'Karnak Temple' while filenames say 'Karnak'.
    $tokens=explode(' ',self::norm($name));
    $generic=array_merge(array_map([self::class,'norm'],$regions),['egyptian','grand','great','temple','valley','museum','city','east','west','north','south','river','nile','saint','old']);
    if(count($tokens)>=2&&mb_strlen($tokens[0])>=5&&!in_array($tokens[0],$generic,true)
       &&self::contains($text,$tokens[0]))return false;
   }
  }
  return true;
 }
 public static function build(int $post,bool $legacy_tour_itinerary=false,int $verified_program=0):array {
  if(get_post_type($post)!=='tours-and-activities'||get_post_status($post)!=='publish')return ['contract'=>self::CONTRACT,'sections'=>[]];
  $details=apply_filters('wpml_post_language_details',null,$post);
  $lang=(string)(is_array($details)?($details['language_code']??''):'');
  if($lang==='')$lang=(string)apply_filters('wpml_current_language','en');
  $default=(string)apply_filters('wpml_default_language','en');
  $source=(int)apply_filters('wpml_object_id',$post,'tours-and-activities',false,$default);
  if(!$source||get_post_status($source)!=='publish'){
    // Missing default-language master: choose one stable published family
    // member for MEDIA context, without publishing an invented translation.
    $trid=apply_filters('wpml_element_trid',null,$post,'post_tours-and-activities');
    $translations=$trid?apply_filters('wpml_get_element_translations',[],$trid,'post_tours-and-activities'):[];
    $eligible=[];
    foreach((array)$translations as $translation){
      $candidate=(int)($translation->element_id??0);
      if($candidate&&get_post_status($candidate)==='publish')$eligible[]=$candidate;
    }
    $source=$eligible?min($eligible):$post;
  }
  $g=(string)get_post_meta($post,'gallery',true);
  $itinerary_policy=!$legacy_tour_itinerary&&class_exists('MAD4B_Gallery_Itinerary_Policy_V1',false)
    ?MAD4B_Gallery_Itinerary_Policy_V1::resolve($source,$source,$verified_program):[];
  $it=self::itinerary($source,!$legacy_tour_itinerary,$verified_program);$highlights=(string)get_post_meta($source,'tour_highlights_content',true);
  $revision=(int)get_option('mad4b_story_gallery_media_revision_v1',1);
  // Term Program content changes independently of the Tour post. Include
  // exact authoritative day data in cache identity; no stale Program route.
  $program_authority=($itinerary_policy['authority']??'')==='taxonomy:programs:program_itinerary';
  // Preserve existing cache identity for normal Tours/Activities and
  // Cruises without a confirmed Program. Version only Program galleries.
  $cache_key='mad4b_story_'.($program_authority?'r10_':'r8_').md5(
    $post.'|'.$source.'|'.$lang.'|'.$revision.'|'.
    (string)get_post_field('post_modified_gmt',$post).'|'.hash('sha256',
      $g.maybe_serialize($it).($program_authority?maybe_serialize($itinerary_policy):'').$highlights));
  $cached=get_transient($cache_key);
  if(is_array($cached)&&($cached['contract']??'')===self::CONTRACT)return $cached;
  // Itinerary/highlights are read from the canonical source tour. Resolve
  // the destination hierarchy in THAT language, then project headings into
  // the current visitor locale. Arabic names cannot be matched against
  // English canonical itinerary text, nor can AR term IDs supply EN media.
  // A single canonical-language scope must wrap all taxonomy/gallery reads.
  // Never shadow the switch flag: doing so leaked WPML EN into ES/IT views.
  $runtime_lang=(string)apply_filters('wpml_current_language',$default);
  $switched_to_source=($runtime_lang!=='' && $runtime_lang!==$default);
  if($switched_to_source)do_action('wpml_switch_language',$default);
  try {
  $section_list=self::destination_terms($source,$it,wp_strip_all_tags($highlights));
  $associated_fallback=false;
  if(!$section_list && !$it && trim(wp_strip_all_tags($highlights))===''){
    // A published cruise with no itinerary may still have genuinely linked
    // destinations. Show ONLY these as associative scenery; never invent days,
    // inclusions, or copy a foreign/stale tour gallery into the story.
    $types=wp_get_object_terms($source,'tour-types_jet',['fields'=>'slugs']);
    $title_context=self::norm((string)get_the_title($source));
    $is_cruise=(!is_wp_error($types)&&count(array_intersect((array)$types,
      ['nile-cruises','deluxe-nile-cruises','ultra-deluxe-nile-cruises','standard-nile-cruises']))>0)
      ||(string)get_post_meta($source,'tour_mode',true)==='cruise_tour'
      ||(bool)preg_match('/\b(?:nile cruise|croisiere|kreuzfahrt|crucero|crociera)\b/u',$title_context);
    if($is_cruise){
      $root=self::country_root($source);
      $assigned=wp_get_object_terms($source,'destinations',['fields'=>'ids']);
      if($root&&!is_wp_error($assigned)){
        $regions=get_terms(['taxonomy'=>'destinations','parent'=>$root,'hide_empty'=>false]);
        if(!is_wp_error($regions))foreach((array)$regions as $region){
          if(!in_array((int)$region->term_id,array_map('intval',$assigned),true))continue;
          $section_list[]=['id'=>(int)$region->term_id,'title'=>$region->name,
           'days'=>[],'children'=>[],'images'=>[],'minimum'=>0,'count'=>0,
           'coverage_gap'=>0,'visit_guaranteed'=>false,
           'evidence'=>'associated_destination_only_itinerary_unavailable'];
        }
        $associated_fallback=count($section_list)>0;
      }
    }
  }
  $all_regions=array_column($section_list,'title');
  $landmark_claims=[];
  foreach($section_list as $entry){
    $landmark_claims[$entry['title']]=[];
    foreach($entry['children'] as $tid){$term=get_term($tid,'destinations');
     if($term&&!is_wp_error($term))$landmark_claims[$entry['title']][]=$term->name;
    }
  }
  $claimed=[];$candidate_count=0;$ignored_synthetic=0;$unverified=0;$foreign_tour_gallery=0;
  $sections=[];$labels=[];
  foreach($section_list as $section){
   $photos=[];$local=[];
   $sources=array_merge([$section['id']],$section['children']);
   $day_terms=$landmark_claims;
   foreach($sources as $index=>$tid){
    foreach(self::term_candidates($tid,$source,72) as $aid){
     $candidate_count++;
     $p=self::local_photo($aid);
     if(!$p){$unverified++;continue;}
     if(isset($claimed[$p['origin']])||isset($local[$p['origin']]))continue;
     if(!self::allowed_context($aid,$section['title'],$all_regions,$day_terms))continue;
     // Strong source association: directly attached to a contextual place;
     // broad country assets are not imported into individual sections.
     $p['source_term_id']=$tid;
     $p['source']='destination_gallery';
     $photos[]=$p;$local[$p['origin']]=true;
     if(count($photos)>=max(5,min(12,2*$section['minimum'])))break 2;
    }
   }
   // Editorially selected Sharm/Red Sea local photos may supplement sparse
   // destination galleries. Do not overwrite gallery postmeta or fabricate
   // that an optional activity was included in the actual itinerary.
   foreach(self::curated_candidates($source,(int)$section['id']) as $candidate){
      $candidate_count++;
      if(isset($claimed[$candidate['origin']])||isset($local[$candidate['origin']]))continue;
      $photos[]=$candidate;
      $local[$candidate['origin']]=true;
      if(count($photos)>=max(5,min(12,2*$section['minimum'])))break;
   }
   foreach($photos as $p)$claimed[$p['origin']]=true;
   // Canonical context and original local media are shared across WPML;
   // only human-facing chapter names are projected into the active language.
   if($source!==$post){
      $translated=(int)apply_filters('wpml_object_id',(int)$section['id'],'destinations',false,$lang);
      if($translated){
       // get_term() inside canonical-language WPML scope silently remaps
       // translated term IDs to English, corrupting visitor-facing labels.
       // Read the exact translated term's name without changing WPML state.
       global $wpdb;
       $label=$wpdb->get_var($wpdb->prepare(
        "SELECT t.name FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt
          ON tt.term_id=t.term_id WHERE t.term_id=%d AND tt.taxonomy=%s LIMIT 1",
        $translated,'destinations'));
       if(is_string($label)&&trim($label)!=='')$section['title']=$label;
      }
   }
   $section['images']=$photos;
   $section['count']=count($photos);
   $section['coverage_gap']=max(0,$section['minimum']-$section['count']);
   $section['days']=array_values($section['days']);
   $sections[]=$section;
  }
  // Complete tour gallery remains intact. We surface unclassified photo
  // records separately instead of mislabeling them as a specific location.
  $own=[];
  $tour_gallery_urls=array_values(array_filter(array_map('trim',explode(',',$g))));
  $gallery_ids=self::from_gallery($g,$post);
  foreach($gallery_ids as $index=>$aid){
   // A previously copied foreign gallery must remain available for recovery,
   // but should not automatically be promoted as this tour's photo story.
   $source_url=$tour_gallery_urls[$index]??'';
   $site_host=strtolower((string)wp_parse_url(home_url(),PHP_URL_HOST));
   $origin_host=strtolower((string)wp_parse_url($source_url,PHP_URL_HOST));
   if($origin_host!==$site_host){$foreign_tour_gallery++;continue;}
   $candidate_count++;$p=self::local_photo($aid);
   if(!$p){$unverified++;continue;}
   if(isset($claimed[$p['origin']]))continue;
   $claimed[$p['origin']]=true;$p['source']='tour_gallery';$own[]=$p;
  }
  if($own)array_unshift($sections,['id'=>'tour','title'=>__('Tour Photos','mad4b'),'days'=>[],
    'children'=>[],'images'=>$own,'minimum'=>0,'count'=>count($own),'coverage_gap'=>0,'evidence'=>'existing_tour_gallery']);
  } finally {
     if($switched_to_source)do_action('wpml_switch_language',$runtime_lang);
  }
  // Changing a Cruise from embedded Tour days to its authoritative Program
  // MUST NOT silently remove previously verified local images. Keep legacy
  // eligible destination media as an UNASSIGNED reviewable chapter, never
  // imply that any of them belongs to a Program day or is included.
  $unassigned_count=0;
  if($program_authority&&!$legacy_tour_itinerary){
    $prior=self::build($post,true);
    $unassigned=[];
    foreach((array)($prior['sections']??[]) as $section){
      foreach((array)($section['images']??[]) as $photo){
        $sha=(string)($photo['origin']??'');
        if($sha===''||isset($claimed[$sha]))continue;
        // Provenance, exact local source and watermark guard were checked
        // already by the legacy story builder.
        $claimed[$sha]=true;
        $photo['program_day_verified']=false;
        $photo['photo_role']='contextual_destination_unassigned';
        $unassigned[]=$photo;
      }
    }
    if($unassigned){
      $unassigned_count=count($unassigned);
      $title=match($lang){
        'ar'=>'صور وجهات إضافية','es'=>'Otras fotos de destinos',
        'fr'=>'Autres photos des destinations','de'=>'Weitere Reisefotos',
        'it'=>'Altre foto delle destinazioni',
        default=>'Additional Destination Photos'
      };
      $sections[]=['id'=>'program-unassigned','title'=>$title,
        'days'=>[],'children'=>[],'images'=>$unassigned,
        'minimum'=>0,'count'=>$unassigned_count,'coverage_gap'=>0,
        'visit_guaranteed'=>false,'program_day_authority'=>false,
        'evidence'=>'previously_verified_tour_destination_media_without_program_day'];
    }
  }
  $gaps=[];foreach($sections as $section)if($section['coverage_gap']>0)$gaps[]=['section'=>$section['title'],'missing'=>$section['coverage_gap'],'days'=>$section['days']];
  $output=['contract'=>self::CONTRACT,'tour_id'=>$post,'canonical_post_id'=>$source,'language'=>$lang,
    'sections'=>$sections,'image_count'=>count($claimed),'section_count'=>count($sections),
    'coverage_gaps'=>$gaps,'itinerary_days'=>count(array_unique(array_filter(array_map(static fn($d)=>absint($d['day']??0),$it)))),'itinerary_stops'=>count($it),'associated_destination_ids'=>array_map('intval',wp_list_pluck($section_list,'id')),
    'candidate_scanned'=>$candidate_count,'unverified_candidates'=>$unverified,
    'foreign_tour_gallery_records_held_for_review'=>$foreign_tour_gallery,
    'geography_review'=>self::geography_review($source),
    'media_scope'=>$associated_fallback?'associated_destinations_only':'itinerary_and_highlights',
    'itinerary_missing'=>$associated_fallback,
    'itinerary_source'=>(string)($itinerary_policy['authority']??'tour_post_meta:itinerary_item'),
    'cruise_program_id'=>(int)($itinerary_policy['program_id']??0),
    'program_selection_required'=>(bool)($itinerary_policy['selection_required']??false),
    'program_data_warnings'=>(array)($itinerary_policy['warnings']??[]),
    'program_unassigned_legacy_photo_count'=>$unassigned_count,
    'mutated_gallery'=>false,'source_media_copied'=>false,'cross_tour_use_allowed'=>true];
  set_transient($cache_key,$output,10*MINUTE_IN_SECONDS);
  return $output;
 }
 /**
  * Keep Elementor's native popup and close/trigger behavior, but avoid
  * materializing the old UAEL gallery when our itinerary-aware viewer has
  * enough reviewed, locally hosted destination photographs. Fail open to
  * the original widget if any required context is missing.
  */
 public static function deferred_popup_content($content,$widget,$args=[]){
  // UAEL Addon keeps UAEL's own renderer and Fancybox, with an inert DOM
  // template until popup open. Do not replace it with our legacy story grid.
  if(class_exists('MAD4B_UAEL_Tour_Gallery_Addon_V1',false)
     &&MAD4B_UAEL_Tour_Gallery_Addon_V1::can_render((int)get_queried_object_id()))return $content;
  if(!is_object($widget)||!method_exists($widget,'get_name')
      ||$widget->get_name()!=='uael-image-gallery'
      ||!method_exists($widget,'get_id')
      ||$widget->get_id()!=='1e96a81')return $content;
  if(is_admin()||is_user_logged_in()||is_preview()||wp_doing_ajax()
      ||(defined('REST_REQUEST')&&REST_REQUEST)
      ||!function_exists('mad4b_is_main_tour_singular')
      ||!mad4b_is_main_tour_singular())return $content;
  $mode=(string)get_option('mad4b_story_popup_lazy_mode','off');
  if($mode!=='active'&&($mode!=='canary'||!isset($_GET['mad4b_story_popup_canary'])))
      return $content;
  $tour=(int)get_queried_object_id();
  if($tour<1||get_post_status($tour)!=='publish')return $content;
  // Do not supersede an authentic, company-specific authored gallery.
  // This deferred story treatment is reserved for verified mass-reused
  // foreign bulk imports; unresolved catalog queries fail open to UAEL.
  if(!class_exists('MAD4B_Tour_Photo_Quality_V1',false))return $content;
  $bulk=MAD4B_Tour_Photo_Quality_V1::bulk_reuse_evidence(
      $tour,(string)get_post_meta($tour,'gallery',true));
  if(empty($bulk['untrusted'])||$bulk['reason']!=='exact_bulk_foreign_gallery_reuse')
      return $content;
  $story=self::build($tour);
  if((int)($story['image_count']??0)<1 ||empty($story['sections']))
      return $content;
  return '<div data-mad4b-story-gallery-deferred="1" aria-hidden="true"></div>';
 }
 public static function admin_box($post):void {
  $id=(int)$post->ID;
  if(!current_user_can('edit_post',$id))return;
  $data=self::build($id);
  if(empty($data['sections'])){
    echo '<p>No itinerary-linked destinations or eligible local gallery photos were found. Review Highlights, Itinerary and the tour Destinations taxonomy.</p>';
    return;
  }
  $source=(string)($data['itinerary_source']??'tour_post_meta:itinerary_item');
  $programId=(int)($data['cruise_program_id']??0);
  if($programId>0){
    echo '<p><strong>Gallery itinerary authority:</strong> '.esc_html($source).
      ' · Program term #'.(int)$programId.'. Photo selection remains read-only.</p>';
  }elseif((string)get_post_meta($id,'tour_mode',true)==='cruise_tour'){
    echo '<p><strong>Cruise Program review:</strong> No authoritative linked Program is available for this tour. Gallery uses the local Tour itinerary where present. Do not infer a different Program or copy another company’s relationship.</p>';
  }
  foreach((array)($data['program_data_warnings']??[]) as $warning){
    $code=is_array($warning)?(string)($warning['code']??'program_data_review'):(string)$warning;
    $detail='';
    if($code==='stored_day_number_disagrees_with_title'&&is_array($warning))
      $detail=': title Day '.(int)($warning['title_day']??0).
       ' differs from stored number '.(int)($warning['stored_day']??0);
    echo '<p><strong>Program itinerary review required:</strong> '.
      esc_html($code.$detail).'. The gallery does not rewrite term metadata.</p>';
  }
  if(class_exists('MAD4B_Cruise_Gallery_Coverage_V1',false)){
    $coverage=MAD4B_Cruise_Gallery_Coverage_V1::plan($id);
    if(($coverage['state']??'')==='review_proposals_ready'
        &&(int)($coverage['candidate_count']??0)>0){
      echo '<p><strong>Cruise gallery Content Job review proposals:</strong> '.
        (int)$coverage['candidate_count'].
        ' day-specific photo gap(s). No Content Job has been created automatically.</p><ul>';
      foreach((array)$coverage['candidates']as $task){
        $day=(int)($task['day']??0);
        $title=(string)($task['day_title']??'');
        $digest=(string)($task['key']??'');
        echo '<li><strong>Day '.(int)$day.': '.esc_html($title).
          '</strong> — exact destination photographs require provenance, watermark and rights review. '.
          '<code>'.esc_html(substr($digest,0,16)).'</code> '.
          '<em>PENDING_AUTHORIZED_CONTENT_JOB</em></li>';
      }
      echo '</ul><p><small>Only the native Content Jobs workflow can create a review job with an authorized actor. Existing media are not overwritten and optional excursions are never inferred as inclusions.</small></p>';
    }
  }
  echo '<p>Image sections are derived from the published itinerary, highlights and destination-owned photo libraries. They do not overwrite the tour gallery.</p>';
  echo '<table class="widefat striped"><thead><tr><th>Section</th><th>Itinerary days</th><th>Photos</th><th>Coverage</th><th>Manage source</th></tr></thead><tbody>';
  foreach($data['sections'] as $section){
    $name=(string)$section['title'];$count=(int)$section['count'];$minimum=(int)$section['minimum'];$days=(array)$section['days'];
    $status=$minimum===0?'Tour originals':($count>=$minimum?'Covered':'Needs '.($minimum-$count).' more');
    $url=is_numeric($section['id'])?add_query_arg(['taxonomy'=>'destinations','tag_ID'=>(int)$section['id'],'post_type'=>'tours-and-activities'],admin_url('term.php')):'';
    echo '<tr><td><strong>'.esc_html($name).'</strong></td><td>'.esc_html(implode(', ',array_map('intval',$days))).'</td>';
    echo '<td>'.esc_html((string)$count).'</td><td>'.esc_html($status).'</td>';
    echo '<td>'.($url?'<a href="'.esc_url($url).'">Edit destination media</a>':'Existing tour gallery').'</td></tr>';
  }
  echo '</tbody></table>';
  $gaps=(array)($data['coverage_gaps']??[]);
  if($gaps){echo '<p style="color:#9b3c00"><strong>Coverage review required:</strong> ';
    echo esc_html(implode('; ',array_map(static fn($x)=>$x['section'].' ('.(int)$x['missing'].' missing)', $gaps))).'</p>';
  }else echo '<p><strong>All itinerary destinations meet the current photo coverage target.</strong></p>';
  foreach((array)($data['geography_review']??[]) as $issue){
   echo '<p style="color:#9b3c00"><strong>Itinerary location review:</strong> '
      .esc_html($issue['primary_destination'].' (day '.implode(', ',$issue['sharm_days']).') conflicts with '
       .$issue['conflicting_optional_excursion'].' (day '.implode(', ',$issue['giftun_days']).').')
      .' Confirm the actual resort and available excursions with the tour operator before changing this itinerary.</p>';
  }
  echo '<p><small>Shared authentic destination photos are permitted across tours. Known generative media is excluded. Unknown provenance still requires editorial verification. Optional excursions are not treated as guaranteed inclusions.</small></p>';
 }
 public static function enqueue():void {
  if(class_exists('MAD4B_UAEL_Tour_Gallery_Addon_V1',false)
    &&MAD4B_UAEL_Tour_Gallery_Addon_V1::can_render((int)get_queried_object_id()))return;
  if(!is_singular('tours-and-activities'))return;
  $post=get_queried_object_id();$data=self::build($post);
  if(empty($data['sections']))return;
  $url=content_url('/mu-plugins/assets/');
  wp_enqueue_style('mad4b-tour-story-gallery',$url.'mad4b-tour-story-gallery.css',[],'1.0.0');
  wp_enqueue_script('mad4b-tour-story-gallery',$url.'mad4b-tour-story-gallery.1.2.0-20261111.js',[],'1.2.0',true);
  wp_add_inline_script('mad4b-tour-story-gallery','window.MAD4B_TOUR_STORY_GALLERY='.wp_json_encode($data,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES).';','before');
 }
 public static function cli($args,$assoc):void {
  $id=absint($args[0]??0);
  WP_CLI::line(wp_json_encode(self::build($id),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
 }
}
// Invalidate generated presentation when a destination photo list, image
// provenance, or verified local lineage changes. No extra images are copied.
$mad4b_story_gallery_touch=static function($meta_id,$object_id,$meta_key){
 if(in_array((string)$meta_key,['image_gellery','featured_image','locations_gallery_attachment_ids',
   '_mad4b_ai_generated','_ai_generated','_generated_by_ai','_mad4b_synthetic_image',
   '_mad4b_media_origin_type','_mad4b_photo_authenticity_verified',
   '_mad4b_story_section_curated_ids_v1'],true)){
  update_option('mad4b_story_gallery_media_revision_v1',max(1,(int)get_option('mad4b_story_gallery_media_revision_v1',1))+1,false);
 }
};
foreach(['added_term_meta','updated_term_meta','deleted_term_meta','added_post_meta','updated_post_meta','deleted_post_meta'] as $hook)
 add_action($hook,$mad4b_story_gallery_touch,20,3);
add_action('delete_attachment',static function(){
 update_option('mad4b_story_gallery_media_revision_v1',max(1,(int)get_option('mad4b_story_gallery_media_revision_v1',1))+1,false);
},20);
add_filter('elementor/widget/render_content',[MAD4B_Tour_Story_Gallery_V1::class,'deferred_popup_content'],35,3);
add_action('add_meta_boxes_tours-and-activities',static function(){
 add_meta_box('mad4b-tour-story-coverage','MAD4B · Itinerary Photo Coverage',
  [MAD4B_Tour_Story_Gallery_V1::class,'admin_box'],'tours-and-activities','normal','low');
});
add_action('wp_enqueue_scripts',[MAD4B_Tour_Story_Gallery_V1::class,'enqueue'],60);
if(defined('WP_CLI')&&WP_CLI)WP_CLI::add_command('mad4b story-gallery',[MAD4B_Tour_Story_Gallery_V1::class,'cli']);
