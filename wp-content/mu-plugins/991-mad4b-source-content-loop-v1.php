<?php
/**
 * MAD4B Source Content Loop v1 — bounded source-to-content orchestration.
 * Runs through existing Content Experience plan/apply, never writes commerce prices.
 * WP-CLI is the sole mutating entrypoint; cron uses the same site administrator.
 */
defined('ABSPATH') || exit;
final class MAD4B_Source_Content_Loop_V1 {
 const OPT='mad4b_source_content_loop_v1';
 const HOSTS='mad4b_source_loop_allowed_hosts_v1';
 const BIND='_mad4b_source_content_loop_key_v1';
 const URL='_mad4b_source_content_loop_url_v1';
 const MAX=100;
 private static function gate() {
  return defined('WP_CLI') && WP_CLI && wp_get_environment_type()==='staging'
   && current_user_can('manage_options')
   && class_exists('MAD4B_SCP_Policy') && MAD4B_SCP_Policy::can_mutate();
 }
 private static function err($code,$message='') {return new WP_Error('mad4b_source_loop_'.$code,$message);}
 private static function neutral_demo_policy() {
  $path=WP_PLUGIN_DIR.'/mad4b-site-control-plane/data/central-neutral-demo-authoring-v1.json';
  $raw=is_readable($path)?file_get_contents($path):'';
  $r=is_string($raw)&&strlen($raw)<10000?json_decode($raw,true):null;
  $none=['mode'=>'site_brand_core','default'=>false,'selection'=>'normal_brand_policy'];
  if(!is_array($r)||($r['contract']??'')!=='mad4b.central-demo-authoring-brand.v1')
   return $none;
  if(!class_exists('MAD4B_SCP_Site_Profile') ||
      !MAD4B_SCP_Site_Profile::site_urls_match_enrollment())return $none;
  $p=MAD4B_SCP_Site_Profile::profile();
  foreach((array)($r['assignments']??[]) as $a) {
   if(($a['site_uuid']??'')!==($p['site_uuid']??'') ||
     rtrim((string)($a['canonical_origin']??''),'/')!==
       rtrim((string)($p['canonical_origin']??''),'/') ||
     ($a['environment']??'')!==($p['environment']??'') ||
     ($a['mode']??'')!=='central_neutral_demo' || empty($a['default']))
      continue;
   $sources=class_exists('MAD4B_SCP_Context_Authority')
     ?MAD4B_SCP_Context_Authority::sources():[];
   $src=$sources[(string)($a['governed_brand_source_id']??'')]??null;
   $ready=is_array($src) && ($src['mode']??'')==='governed' &&
     ($src['status']??'')==='ready' && (int)($src['asset_count']??0)>=3;
   return ['mode'=>'central_neutral_demo','default'=>true,
    'selection'=>'central_versioned_registry',
    'policy_version'=>(string)$r['version'],
    'source_id'=>(string)($a['governed_brand_source_id']??''),
    'source_scan_ready'=>$ready,'governed_review_required'=>true,
    'publication_authority_granted'=>false,
    'policy_sha256'=>hash('sha256',wp_json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))];
  }
  return $none;
 }
 private static function registry_inconsistencies($rows) {
  $bad=[];
  foreach((array)$rows as $key=>$entry)if(!is_array($entry)||
    !hash_equals((string)$key,(string)($entry['key']??'')))$bad[]=(string)$key;
  return $bad;
 }
 private static function rows() {$r=get_option(self::OPT,[]);return is_array($r)?$r:[];}
 private static function store($rows) {return update_option(self::OPT,$rows,false);}
 private static function key($url) {return substr(hash('sha256',rtrim($url,'/')),0,24);}
 public static function normalize_url($raw) {
  $url=esc_url_raw(trim((string)$raw));
  if(!wp_http_validate_url($url) || wp_parse_url($url,PHP_URL_SCHEME)!=='https'
    || strlen($url)>1500 || wp_parse_url($url,PHP_URL_USER) || wp_parse_url($url,PHP_URL_PASS)
    || wp_parse_url($url,PHP_URL_FRAGMENT)) return self::err('invalid_url');
  $host=strtolower((string)wp_parse_url($url,PHP_URL_HOST));
  if(!$host || filter_var($host,FILTER_VALIDATE_IP) || str_ends_with($host,'.local')
    || str_ends_with($host,'.internal'))return self::err('private_host');
  return rtrim($url,'/');
 }
 private static function resolve_taxonomies($type,$tax) {
  if(!is_array($tax))return self::err('taxonomy_shape');
  $out=[];
  foreach($tax as $name=>$slugs) {
   $name=sanitize_key($name);
   if(!taxonomy_exists($name) || !is_object_in_taxonomy($type,$name))
     return self::err('taxonomy_unknown',$name);
   if(!is_array($slugs) || count($slugs)>15)return self::err('taxonomy_bounds',$name);
   $ids=[];
   foreach($slugs as $slug) {
    $term=get_term_by('slug',sanitize_title($slug),$name);
    if(!$term || is_wp_error($term))return self::err('term_not_found',$name.':'.$slug);
    $ids[]=(int)$term->term_id;
   }
   $out[$name]=array_values(array_unique($ids));
  }
  return $out;
 }
 public static function enroll($url,$type,$title,$tax=[],$provider_id='firecrawl') {
  if(!self::gate())return self::err('administrator_required');
  $url=self::normalize_url($url); if(is_wp_error($url))return $url;
  $type=sanitize_key($type);$title=sanitize_text_field($title);
  $provider_id=sanitize_key($provider_id);
  if(!preg_match('/^[a-z][a-z0-9_-]{2,64}$/D',$provider_id))
   return self::err('provider_id_invalid');
  if(!post_type_exists($type)||!$title||strlen($title)>180)return self::err('invalid_type_or_title');
  $terms=self::resolve_taxonomies($type,$tax);if(is_wp_error($terms))return $terms;
  $key=self::key($url);$rows=self::rows();
  if(!isset($rows[$key]) && count($rows)>=self::MAX)return self::err('capacity_reached');
  if(isset($rows[$key])) {
   if($rows[$key]['post_type']!==$type)return self::err('source_type_conflict');
   if(($rows[$key]['provider_id']??'firecrawl')!==$provider_id)return self::err('source_provider_conflict');
   return ['key'=>$key,'state'=>$rows[$key]['state'],'already_enrolled'=>true,'post_id'=>(int)($rows[$key]['post_id']??0)];
  }
  $host=strtolower((string)wp_parse_url($url,PHP_URL_HOST));
  $hosts=get_option(self::HOSTS,[]);
  if(!is_array($hosts))$hosts=[];
  $hosts=array_values(array_unique(array_merge($hosts,[$host])));
  if(count($hosts)>60)return self::err('host_capacity');
  update_option(self::HOSTS,$hosts,false);
  $rows[$key]=[
   'key'=>$key,'url'=>$url,'post_type'=>$type,'title'=>$title,
   'provider_id'=>$provider_id,'taxonomies'=>$tax,
   'post_id'=>0,'state'=>'enrolled','source_sha256'=>'','last_checked'=>'',
   'last_success'=>'','last_error'=>'','failures'=>0,'retry_after'=>0,
   'revision'=>1,'created_at'=>gmdate('c'),'publish_requested'=>false
  ];
  self::store($rows);
  return ['key'=>$key,'state'=>'enrolled','already_enrolled'=>false,'post_id'=>0];
 }
 private static function extract_commercial_facts($markdown) {
  $lines=preg_split('/\r?\n/u',(string)$markdown);
  $active=false;$group=[];$groups=[];
  foreach($lines as $line) {
   $line=trim((string)$line);
   if(!$active) {
    if(preg_match('/^(included and excluded|inclusions(?: and exclusions)?|price includes|included(?: items)?|what.s included)$/iu',$line))
     $active=true;
    continue;
   }
   if(preg_match('/^itinerary\b|^day\s*1\s*:|^see all days/iu',$line))break;
   if($line==='' && $group){$groups[]=$group;$group=[];continue;}
   if(!preg_match('/^[*•\-]\s+(.{5,250})$/u',$line,$m))continue;
   $item=trim(wp_strip_all_tags((string)$m[1]));
   if($item!=='' && count($group)<25)$group[]=$item;
  }
  if($group)$groups[]=$group;
  // A combined heading must contain two clearly separated fact groups.
  if(count($groups)!==2 || count($groups[0])<2 || count($groups[1])<1)
   return ['includes'=>[],'excludes'=>[],'verified'=>false];
  $normalize=static function($value,$excluded=false) {
   $v=mb_strtolower($value,'UTF-8');
   if($excluded) {
    if(str_contains($v,'tipping') || str_contains($v,'gratuities'))return 'Crew and guide gratuities';
    if(str_contains($v,'extra') || str_contains($v,'not mentioned'))return 'Optional extras not specified in the selected itinerary';
    return '';
   }
   if(str_contains($v,'meet and assist'))return 'Meet-and-assist services for arrival and departure';
   if(str_contains($v,'full board') || str_contains($v,'accommodation'))return 'Full-board accommodation aboard the Dahabiya';
   if(str_contains($v,'assistance') && str_contains($v,'excursion'))return 'Local assistance during the program and excursions';
   if(str_contains($v,'excursions') && str_contains($v,'itinerary'))return 'Guided excursions described in the selected itinerary';
   if(str_contains($v,'transfer'))return 'Transfers in a private air-conditioned vehicle';
   if(str_contains($v,'english') && str_contains($v,'guide'))return 'English-speaking guide during listed sightseeing';
   if(str_contains($v,'entrance fees'))return 'Admission fees for the historical sites listed in the itinerary';
   if(str_contains($v,'tax') || str_contains($v,'service charge'))return 'Applicable service charges and taxes';
   return '';
  };
  $included=[];$excluded=[];
  foreach(array_slice($groups[0],0,20) as $item) {
   $n=$normalize($item,false);
   if($n && !in_array($n,$included,true))$included[]=$n;
  }
  foreach(array_slice($groups[1],0,12) as $item) {
   $n=$normalize($item,true);
   if($n && !in_array($n,$excluded,true))$excluded[]=$n;
  }
  return ['includes'=>$included,'excludes'=>$excluded,
   'verified'=>count($included)>=4&&count($excluded)>=1,
   'raw_include_count'=>count($groups[0]),'raw_exclude_count'=>count($groups[1])];
 }
 private static function extract($markdown,$entry) {
  $plain=preg_replace('/!\[[^\]]*\]\([^)]+\)/u',' ',$markdown);
  $plain=(string)preg_replace('/\[[^\]]+\]\([^)]+\)/u',' ',(string)$plain);
  $plain=wp_strip_all_tags($plain);
  $word_count=str_word_count(preg_replace('/[^\x20-\x7e]/',' ',(string)$plain));
  if($word_count<80)return self::err('source_insufficient','At least 80 readable source words required.');
  if(preg_match('/(just a moment|checking your browser|access denied.*cloudflare)/i',substr($plain,0,1200)))
   return self::err('source_access_challenge');
  preg_match_all('/\b([1-9][0-9]?)\s*(?:days?|day)\b/i',$markdown,$d);
  $durations=array_values(array_unique(array_map('intval',$d[1]??[])));
  sort($durations);
  preg_match_all('/^\s*#{1,5}\s+(.{4,115})$/mu',$markdown,$h);
  $headings=[];
  foreach($h[1]??[] as $heading) {
   $heading=sanitize_text_field(trim($heading));
   if($heading && mb_strlen($heading)<105 && count($headings)<40)$headings[]=$heading;
  }
  $headings=array_values(array_unique($headings));
  $day_headings=array_values(array_filter($headings,static function($h){return preg_match('/^(day|days|jour|dia|día)\s*\d+/iu',$h);}));
  // Detect day-level programs from the supplier's structured day headings.
  $programs=[];$current=[];
  foreach(preg_split('/\r?\n/u',$markdown) as $line) {
   if(!preg_match('/^\s*Day\s+([1-9][0-9]?)\s*:\s*(.{3,120})\s*$/iu',trim($line),$match))continue;
   $day=(int)$match[1];
   if($day===1 && $current) {$programs[]=$current;$current=[];}
   if($current && $day!==(int)end($current)['day']+1)continue;
   $current[]=['day'=>$day,'label'=>sanitize_text_field($match[2])];
  }
  if($current)$programs[]=$current;
  $programs=array_values(array_filter($programs,static function($group)use($durations){
   return count($group)>1 && in_array(count($group),$durations,true);
  }));
  $facts=self::extract_commercial_facts($markdown);
  $source=$entry['url'];
  // Original synopsis synthesized from fact signals; never paste supplier prose.
  $headline=$entry['title'];
  $route='';$has_luxor=(bool)preg_match('/\bLuxor\b/i',$plain);
  $has_aswan=(bool)preg_match('/\bAswan\b/i',$plain);
  if($has_luxor && $has_aswan)$route='between Luxor and Aswan';
  $cruise=(bool)preg_match('/dahabiya|nile cruise|nile sailing/i',$headline.' '.$plain);
  $intro=$headline.' is '.($cruise?'a Nile sailing experience':'a travel experience')
    .($route?' '.$route:'').'. ';
  if($durations)$intro.='The source lists itinerary durations of '.implode(', ',$durations).' days. ';
  $intro.='Sightseeing stops and onboard facilities depend on the selected program and departure. ';
  $intro.='Please confirm sailing dates, inclusions, cabin type, price and availability before booking.';
  $html='<p>'.esc_html($intro).'</p>';
  if($programs){
   $html.='<h2>Itinerary options</h2>';
   foreach(array_slice($programs,0,8) as $group){
    $html.='<h3>'.count($group).'-day sailing program</h3><ol>';
    foreach(array_slice($group,0,20) as $day){
     $html.='<li><strong>Day '.absint($day['day']).'</strong> — '
      .esc_html(mb_substr($day['label'],0,90)).'</li>';
    }
    $html.='</ol>';
   }
  }
  $html.='<p>Program descriptions are subject to supplier confirmation. '
    .'Source reference: <a href="'.esc_url($source).'" rel="nofollow noopener noreferrer">'
    .esc_html(wp_parse_url($source,PHP_URL_HOST)).'</a>.</p>';
  return ['title'=>$headline,'content'=>$html,'excerpt'=>mb_substr($intro,0,200),
   'durations'=>$durations,'headings_count'=>count($headings),
   'itinerary_headings_count'=>count($day_headings),'programs_detected'=>array_map('count',$programs),'words'=>$word_count,
   'route_confirmed'=>$has_luxor&&$has_aswan,
   'facts_ready'=>$word_count>=80 && ($has_luxor||$has_aswan),
   'commercial_facts'=>$facts];
 }
 private static function schema_snapshot($type) {
  $profile=MAD4B_SCP_Content_Experience_Profiles::profile($type);
  if(is_wp_error($profile))return ['ready'=>false,'code'=>$profile->get_error_code()];
  $taxonomies=get_object_taxonomies($type);
  $registered=get_registered_meta_keys('post',$type);
  return ['ready'=>true,'profile_slug'=>(string)$profile['slug'],
   'profile_revision'=>(int)$profile['revision'],
   'meta_mode'=>(string)$profile['meta_mode'],
   'allowed_meta_count'=>count((array)($profile['meta_keys']??[])),
   'registered_meta_keys'=>array_slice(array_keys((array)$registered),0,100),
   'import_contract_enabled'=>!empty($profile['import_contract']['enabled']),
   'taxonomy_names'=>$taxonomies,
   'commercial_rate_autowrite'=>false,'media_import_autowrite'=>false];
 }
 private static function find_existing($entry) {
  $id=(int)($entry['post_id']??0);
  $post=$id?get_post($id):null;
  if($post && $post->post_type===$entry['post_type'])return $post;
  $found=get_posts(['post_type'=>$entry['post_type'],'post_status'=>'any','numberposts'=>2,
    'meta_key'=>self::BIND,'meta_value'=>$entry['key']]);
  if(count($found)>1)return self::err('binding_collision');
  if($found)return $found[0];
  $slug=sanitize_title($entry['title']);
  $slugpost=get_page_by_path($slug,OBJECT,$entry['post_type']);
  if($slugpost)return self::err('slug_collision','Existing post uses the proposed slug without a verified source binding.');
  return null;
 }
 private static function fail($key,$entry,$code,$message='') {
  $entry['state']='retryable_error';$entry['failures']=min(8,(int)($entry['failures']??0)+1);
  $entry['last_error']=substr($code.($message?': '.$message:''),0,280);
  $delay=min(21600,600*pow(2,max(0,$entry['failures']-1)));
  $entry['retry_after']=time()+(int)$delay;
  $entry['last_checked']=gmdate('c');$rows=self::rows();$rows[$key]=$entry;self::store($rows);
  return ['key'=>$key,'state'=>$entry['state'],'error'=>$entry['last_error'],
   'retry_after'=>gmdate('c',$entry['retry_after']),'post_id'=>(int)$entry['post_id']];
 }
 private static function lock($key) {
  $name='mad4b_src_loop_lock_'.$key;
  if(get_transient($name))return false;
  set_transient($name,1,180);
  return true;
 }
 private static function resume_publish($key,$entry) {
  // Trust is not a replacement for exact Brand Core or Content Experience
  // approval. Publishing also needs an explicit source-level opt-in, and the
  // existing native plan/apply checks must independently accept the mutation.
  if(class_exists('MAD4B_Scraping_Source_Profiles_V1')) {
   $source_profile=MAD4B_Scraping_Source_Profiles_V1::resolve((string)($entry['url']??''));
   $decision=is_wp_error($source_profile)?null:
     MAD4B_Scraping_Source_Profiles_V1::content_decision($source_profile,[
       'fields_complete'=>!empty($entry['capture_summary']['words']),
       'brand_ready'=>false,
       'approved_editorial_authority'=>false
     ]);
   if(!is_array($decision)||empty($decision['eligible'])) {
    $entry['state']='publication_blocked';
    $entry['last_error']='source_profile_content_review_required';
    $rows=self::rows();$rows[$key]=$entry;self::store($rows);
    return ['key'=>$key,'state'=>'publication_blocked',
      'post_id'=>(int)($entry['post_id']??0),'auto_published'=>false,
      'source_profile_decision'=>$decision,
      'error'=>'source_profile_content_review_required'];
   }
  }
  $id=(int)($entry['post_id']??0);$post=get_post($id);
  if(!$post || $post->post_type!==$entry['post_type'])
   return self::fail($key,$entry,'publication_target_missing');
  if($post->post_status==='publish') {
   $entry['state']='published_verified';$entry['last_error']='';
  } elseif($post->post_status!=='draft') {
   $entry['state']='publication_blocked';$entry['last_error']='target_not_draft';
  } else {
   $p=MAD4B_SCP_Content_Experience_Runtime::operation_plan($entry['post_type'],'publish',[
    'post_id'=>$id,'expected_modified_gmt'=>(string)$post->post_modified_gmt,'post_status'=>'publish']);
   if(is_wp_error($p)) {
    $entry['state']='publication_blocked';$entry['last_error']=$p->get_error_code();
   } else {
    $a=MAD4B_SCP_Content_Experience_Runtime::operation_apply($entry['post_type'],'publish',[
     'post_id'=>$id,'expected_modified_gmt'=>(string)$post->post_modified_gmt,
     'post_status'=>'publish','plan_sha256'=>$p['plan_sha256']]);
    if(is_wp_error($a)) {
     $entry['state']='publication_blocked';$entry['last_error']=$a->get_error_code();
    } elseif(get_post_status($id)==='publish') {
     $entry['state']='published_verified';$entry['last_error']='';$entry['published_at']=gmdate('c');
    } else {
     $entry['state']='publication_blocked';$entry['last_error']='publication_readback_failed';
    }
   }
  }
  $entry['last_checked']=gmdate('c');
  $rows=self::rows();$rows[$key]=$entry;self::store($rows);
  return ['key'=>$key,'state'=>$entry['state'],'post_id'=>$id,'post_status'=>get_post_status($id),
   'permalink'=>get_permalink($id),'source_sha256'=>$entry['source_sha256'],
   'error'=>$entry['last_error']];
 }
 public static function run($key,$publish=false,$force=false) {
  if(!self::gate())return self::err('administrator_required');
  $rows=self::rows();
  if(!isset($rows[$key]))return self::err('source_not_enrolled');
  $entry=$rows[$key];
  // A manually corrected research draft must not be overwritten by a later
  // mismatched vendor capture without an explicit source admission review.
  $boundPost=(int)($entry['post_id']??0);
  if($boundPost>0) {
   $admission=get_post_meta($boundPost,'_mad4b_source_admission_review_v1',true);
   if(is_array($admission) &&
      ($admission['state']??'')==='source_capture_mismatch_corrected_for_draft' &&
      empty($admission['approved_for_publication']))
    return self::err('source_admission_review_required');
  }
  if(self::registry_inconsistencies($rows))return self::err('registry_reconciliation_required');
  $entry['authoring_brand_policy']=self::neutral_demo_policy();
  $source_profile=class_exists('MAD4B_Scraping_Source_Profiles_V1')
    ?MAD4B_Scraping_Source_Profiles_V1::resolve($entry['url']):null;
  if(is_wp_error($source_profile))return $source_profile;
  if(is_array($source_profile)) {
   $entry['scraping_source_profile_id']=$source_profile['source_profile_id'];
   $entry['scraping_source_profile_digest']=$source_profile['profile_digest'];
  }
  if($publish && empty($entry['publish_requested'])) {
   $entry['publish_requested']=true;$rows[$key]=$entry;self::store($rows);
  }
  if(!$force && (int)$entry['retry_after']>time())return ['key'=>$key,'state'=>'backoff','post_id'=>(int)$entry['post_id']];
  if(!self::lock($key))return self::err('locked');
  try {
   if(!$force && !empty($entry['source_sha256']) && !empty($entry['post_id'])
      && ($publish || !empty($entry['publish_requested']))
      && get_post_status((int)$entry['post_id'])==='draft')
      return self::resume_publish($key,$entry);
   if(!class_exists('MAD4B_SCP_Firecrawl_Provider') || !method_exists('MAD4B_SCP_Firecrawl_Provider','scrape_intake_url'))
    return self::fail($key,$entry,'provider_adapter_unavailable');
   // Trusted code can register a different provider without changing the queue/storage contract.
   $providers=apply_filters('mad4b_source_loop_provider_registry_v1',[
    'firecrawl'=>static function($url){return MAD4B_SCP_Firecrawl_Provider::scrape_intake_url($url);}
   ],$entry);
   $selected=(string)($entry['provider_id']??'firecrawl');
   if(!is_array($providers)||!isset($providers[$selected])||!is_callable($providers[$selected]))
    return self::fail($key,$entry,'provider_registry_invalid');
   $capture=call_user_func($providers[$selected],$entry['url']);
   if(is_wp_error($capture))return self::fail($key,$entry,$capture->get_error_code(),$capture->get_error_message());
   if(!is_array($capture)||empty($capture['source_sha256'])||empty($capture['markdown']))
    return self::fail($key,$entry,'capture_invalid');
   $entry['last_checked']=gmdate('c');
   $sha=(string)$capture['source_sha256'];
   if(!$force && $entry['source_sha256']===$sha && (int)$entry['post_id']>0) {
    $entry['state']=get_post_status((int)$entry['post_id'])==='publish'?'published_verified':'draft_verified';$entry['failures']=0;$entry['retry_after']=0;
    $rows=self::rows();$rows[$key]=$entry;self::store($rows);
    return ['key'=>$key,'state'=>'unchanged','source_sha256'=>$sha,'post_id'=>(int)$entry['post_id']];
   }
   $extracted=self::extract($capture['markdown'],$entry);
   if(is_wp_error($extracted))return self::fail($key,$entry,$extracted->get_error_code(),$extracted->get_error_message());
   // Source Profiles are applied at the field-mapping boundary, not only in
   // diagnostics. Required fields cannot be silently excluded on create.
   $may=static fn($name)=>!is_array($source_profile) ||
     MAD4B_Scraping_Source_Profiles_V1::field_allowed($source_profile,$name);
   if(is_array($source_profile) &&
     ($source_profile['profile_state']??'')!=='enrolled')
    return self::fail($key,$entry,'scraping_source_profile_unregistered');
   if(!$may('description') && empty($entry['post_id']))
    return self::fail($key,$entry,'source_profile_required_description_excluded');
   $entry['schema_snapshot']=self::schema_snapshot($entry['post_type']);
   $entry['capture_summary']=[
    'source_profile_id'=>$source_profile['source_profile_id']??'legacy',
    'source_profile_digest'=>$source_profile['profile_digest']??'',
    'source_excluded_fields'=>$source_profile['exclude_fields']??[],
    'captured_at'=>$capture['captured_at'],'provider'=>$capture['provider'],
    'words'=>$extracted['words'],'durations'=>$extracted['durations'],
    'headings_count'=>$extracted['headings_count'],
    'programs_detected'=>$extracted['programs_detected'],
    'itinerary_headings_count'=>$extracted['itinerary_headings_count'],
    'route_confirmed'=>$extracted['route_confirmed'],'source_sha256'=>$sha
   ];
   $existing=self::find_existing($entry);
   if(is_wp_error($existing))return self::fail($key,$entry,$existing->get_error_code());
   if($existing && $existing->post_status!=='draft') {
    $entry['state']='source_changed_review';
    $entry['pending_source_sha256']=$sha;$entry['retry_after']=0;
    $rows=self::rows();$rows[$key]=$entry;self::store($rows);
    return ['key'=>$key,'state'=>'source_changed_review','post_id'=>$existing->ID,
      'pending_source_sha256'=>$sha,'auto_updated'=>false];
   }
   if(!$extracted['facts_ready'])return self::fail($key,$entry,'source_facts_incomplete');
   $terms=$may('taxonomies')
     ?self::resolve_taxonomies($entry['post_type'],$entry['taxonomies']):[];
   if(is_wp_error($terms))return self::fail($key,$entry,$terms->get_error_code());
   // Runtime taxonomy discovery; only unambiguous matching duration terms.
   $duration_mapped=[];$duration_missing=[];
   foreach(($may('duration') && $may('taxonomies')
     ?get_object_taxonomies($entry['post_type']):[]) as $tax) {
    $o=get_taxonomy($tax);
    if(!preg_match('/duration/i',$tax.' '.($o->labels->name??'')))continue;
    $matches=[];
    $all=get_terms(['taxonomy'=>$tax,'hide_empty'=>false,'number'=>200]);
    if(is_wp_error($all))continue;
    foreach($extracted['durations'] as $days){
     $candidate=[];
     foreach($all as $term)
      if(preg_match('/\\b'.preg_quote((string)$days,'/').'\\s+days?\\b/i',$term->name))
       $candidate[]=(int)$term->term_id;
     if(count($candidate)===1)$matches[]=$candidate[0];
     else $duration_missing[]=$days;
    }
    if($matches){$terms[$tax]=array_values(array_unique($matches));$duration_mapped[$tax]=$terms[$tax];}
    break;
   }
   $family_preflight=['family'=>'unclassified','state'=>'registry_not_loaded'];
   if(class_exists('MAD4B_Tour_Archetype_Registry_V1') &&
     $entry['post_type']==='tours-and-activities') {
    $type_slugs=(array)($entry['taxonomies']['tour-types']??[]);
    if(!$type_slugs && $existing)
     $type_slugs=wp_get_post_terms($existing->ID,'tour-types',['fields'=>'slugs']);
    if(!is_wp_error($type_slugs))
     $family_preflight=MAD4B_Tour_Archetype_Registry_V1::classify_slugs(
       $type_slugs,'tours-and-activities');
   }
   $entry['capture_summary']['tour_archetype_family']=$family_preflight['family'];
   $entry['capture_summary']['tour_archetype_state']=$family_preflight['state'];
   $entry['capture_summary']['tour_archetype_profile_sha256']=
     $family_preflight['profile_sha256']??'';
   $entry['capture_summary']['duration_term_ids']=$duration_mapped;
   $entry['capture_summary']['unmapped_durations']=array_values(array_unique($duration_missing));
   $entry['capture_summary']['commercial_facts_verified']=!empty($extracted['commercial_facts']['verified']);
   $entry['capture_summary']['included_count']=count($extracted['commercial_facts']['includes']);
   $entry['capture_summary']['excluded_count']=count($extracted['commercial_facts']['excludes']);
   // Typed JetEngine repeaters are populated only when both provider evidence and
   // the current Content Experience profile explicitly permit each registered key.
   $profile_check=MAD4B_SCP_Content_Experience_Profiles::profile($entry['post_type']);
   $meta=[];$allowed=array_fill_keys((array)($profile_check['meta_keys']??[]),true);
   $registered=get_registered_meta_keys('post',$entry['post_type']);
   if($entry['post_type']==='tours-and-activities') {
    if($may('tour_mode') && isset($allowed['tour_mode'],$registered['tour_mode']))
     $meta['tour_mode']='tour';
    if($may('tour_sub_title') && $may('duration') &&
      isset($allowed['tour_sub_title'],$registered['tour_sub_title']) && $extracted['durations'])
     $meta['tour_sub_title']=($family_preflight['state']==='matched'
       ?$family_preflight['content_subtitle']:'Travel itinerary').
       ': '.implode(', ',$extracted['durations']).' days';
    if(!empty($extracted['commercial_facts']['verified'])) {
     foreach(['includes'=>'included_item','excludes'=>'excludes_item'] as $field_key=>$child) {
      if(!$may($field_key) || !isset($allowed[$field_key],$registered[$field_key]))continue;
      $items=[];
      foreach($extracted['commercial_facts'][$field_key] as $i=>$text)
       $items['item-'.$i]=[$child=>$text];
      if($items)$meta[$field_key]=$items;
     }
    }
   }
   $input=['post_title'=>$extracted['title'],
    'post_content'=>$may('description')?$extracted['content']:($existing?$existing->post_content:''),
    'post_excerpt'=>$may('excerpt')?$extracted['excerpt']:($existing?$existing->post_excerpt:''),
    'post_name'=>sanitize_title($entry['title']),
    'taxonomies'=>$terms,'meta'=>$meta];
   $op='create';
   if($existing) {
    $op='update';$input['post_id']=(int)$existing->ID;
    $input['expected_modified_gmt']=(string)$existing->post_modified_gmt;
   }
   $profile=$entry['post_type'];
   $profile_definition=MAD4B_SCP_Content_Experience_Profiles::profile($profile);
   if(is_wp_error($profile_definition) || ($profile_definition['post_type']??'')!==$entry['post_type'])
    return self::fail($key,$entry,'profile_not_mapped');
   $plan=MAD4B_SCP_Content_Experience_Runtime::operation_plan($profile,$op,$input);
   if(is_wp_error($plan))return self::fail($key,$entry,$plan->get_error_code(),$plan->get_error_message());
   $input['plan_sha256']=$plan['plan_sha256'];
   $applied=MAD4B_SCP_Content_Experience_Runtime::operation_apply($profile,$op,$input);
   if(is_wp_error($applied))return self::fail($key,$entry,$applied->get_error_code(),$applied->get_error_message());
   $id=(int)($applied['post_id']??0);
   if(!$id || !get_post($id))return self::fail($key,$entry,'missing_post_after_apply');
   update_post_meta($id,self::BIND,$key);
   update_post_meta($id,self::URL,$entry['url']);
   update_post_meta($id,'_mad4b_source_content_sha256_v1',$sha);
   if(class_exists('MAD4B_Tour_Archetype_Registry_V1') &&
     $entry['post_type']==='tours-and-activities') {
    $family_sync=MAD4B_Tour_Archetype_Registry_V1::sync($id);
    $entry['capture_summary']['type_profile_sync_state']=
      is_wp_error($family_sync)?$family_sync->get_error_code():
       ($family_sync['state']??'unknown');
   }
   $entry['post_id']=$id;$entry['source_sha256']=$sha;$entry['pending_source_sha256']='';
   $entry['last_success']=gmdate('c');$entry['state']='draft_verified';
   $entry['last_error']='';$entry['failures']=0;$entry['retry_after']=0;
   $entry['revision']=(int)$entry['revision']+1;
   $verification=MAD4B_SCP_Content_Experience_Runtime::verify($profile,['post_id'=>$id]);
   if(is_wp_error($verification))return self::fail($key,$entry,$verification->get_error_code());
   if($publish || !empty($entry['publish_requested']))return self::resume_publish($key,$entry);
   $rows=self::rows();$rows[$key]=$entry;self::store($rows);
   $post=get_post($id);
   return ['key'=>$key,'state'=>$entry['state'],'post_id'=>$id,
    'post_status'=>$post?$post->post_status:'',
    'permalink'=>get_permalink($id),'source_sha256'=>$sha,
    'capture_summary'=>$entry['capture_summary'],'error'=>$entry['last_error']];
  } finally {delete_transient('mad4b_src_loop_lock_'.$key);}
 }
 public static function binding_reconcile_plan($sourcekey) {
  if(!self::gate() || !class_exists('MAD4B_SCP_Site_Profile') ||
    !MAD4B_SCP_Site_Profile::site_urls_match_enrollment())
    return self::err('source_binding_site_not_authorized');
  $rows=self::rows();$canonical=$rows[$sourcekey]??null;
  if(!is_array($canonical)||($canonical['key']??'')!==$sourcekey ||
    self::key((string)($canonical['url']??''))!==$sourcekey)
    return self::err('source_binding_canonical_missing');
  $aliases=[];
  foreach($rows as $slot=>$entry) {
   if($slot===$sourcekey)continue;
   if(!is_array($entry)||($entry['key']??'')!==$sourcekey)continue;
   if(($entry['url']??'')!==$canonical['url'] ||
     (int)($entry['post_id']??0)!==(int)($canonical['post_id']??0) ||
     ($entry['source_sha256']??'')!==($canonical['source_sha256']??''))
    return self::err('source_binding_conflicting_alias');
   $aliases[$slot]=$entry;
  }
  if(count($aliases)!==1 || count(self::registry_inconsistencies($rows))!==1)
    return self::err('source_binding_multiple_or_unknown_drift');
  $slot=(string)array_key_first($aliases);$active=$aliases[$slot];
  if((int)$active['revision']<=(int)$canonical['revision'])return self::err('source_binding_stale_candidate');
  $post=get_post((int)$active['post_id']);
  if(!$post || $post->post_status!=='draft' || $post->post_type!==$active['post_type'] ||
    (string)get_post_meta($post->ID,self::URL,true)!==(string)$active['url'] ||
    (string)get_post_meta($post->ID,self::BIND,true)!==$slot)
    return self::err('source_binding_target_drift');
  $material=[
   'contract'=>'mad4b.source-content-binding-reconcile.plan.v1',
   'sourcekey'=>$sourcekey,'alias'=>$slot,'post_id'=>$post->ID,
   'canonical_revision'=>(int)$canonical['revision'],
   'candidate_revision'=>(int)$active['revision'],
   'source_sha256'=>(string)$active['source_sha256'],
   'registry_sha256'=>hash('sha256',wp_json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),
   'site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid(),
   'site_profile_digest'=>MAD4B_SCP_Site_Profile::profile_digest(),
   'post_modified_gmt'=>$post->post_modified_gmt
  ];
  $material['plan_sha256']=hash('sha256',wp_json_encode($material,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  return $material+['state'=>'RECONCILE_ELIGIBLE','mutation_performed'=>false];
 }
 public static function binding_reconcile_apply($sourcekey,$expected,$confirmation) {
  if($confirmation!=='RECONCILE SOURCE LOOP BINDING')return self::err('source_binding_confirmation_required');
  $plan=self::binding_reconcile_plan($sourcekey);
  if(is_wp_error($plan))return $plan;
  if(!preg_match('/^[a-f0-9]{64}$/D',(string)$expected) ||
    !hash_equals($plan['plan_sha256'],$expected))return self::err('source_binding_plan_stale');
  $rows=self::rows();$alias=$plan['alias'];$before=$rows;$post_id=(int)$plan['post_id'];
  $meta_before=get_post_meta($post_id,self::BIND,true);
  $backup_key='mad4b_source_loop_reconcile_backup_'.substr($plan['plan_sha256'],0,24);
  if(!add_option($backup_key,['registry'=>$before,'binding'=>$meta_before,'plan'=>$plan], '',false))
    return self::err('source_binding_backup_exists_reconcile_first');
  $rows[$sourcekey]=$rows[$alias];unset($rows[$alias]);
  if(!self::store($rows)) {
   if(get_option(self::OPT)!==$rows)return self::err('source_binding_registry_write_failed');
  }
  update_post_meta($post_id,self::BIND,$sourcekey);
  $after=self::rows();
  $verified=count($after)===count($before)-1 && isset($after[$sourcekey]) &&
    !isset($after[$alias]) && !self::registry_inconsistencies($after) &&
    get_post_meta($post_id,self::BIND,true)===$sourcekey &&
    get_post_status($post_id)==='draft';
  if(!$verified) {
   self::store($before);update_post_meta($post_id,self::BIND,$meta_before);
   return self::err('source_binding_readback_failed_rolled_back');
  }
  return ['contract'=>'mad4b.source-content-binding-reconcile.apply.v1',
    'state'=>'RECONCILED','site_uuid'=>$plan['site_uuid'],
    'post_id'=>$post_id,'sourcekey'=>$sourcekey,
    'plan_sha256'=>$plan['plan_sha256'],
    'backup_option'=>$backup_key,'verified'=>true,
    'registry_inconsistencies'=>[],'post_binding'=>$sourcekey];
 }
 public static function tick($max=1) {
  if(!self::gate())return self::err('administrator_required');
  $max=max(1,min(3,(int)$max));$out=[];
  if(self::registry_inconsistencies(self::rows()))return self::err('registry_reconciliation_required');
  $queue=self::rows();
  // Oldest observation first: no starvation as the enrolled fleet grows.
  uasort($queue,static function($a,$b){
   return (int)strtotime((string)($a['last_checked']??'')) <=>
     (int)strtotime((string)($b['last_checked']??''));
  });
  foreach($queue as $key=>$row) {
   if(count($out)>=$max)break;
   if((int)($row['retry_after']??0)>time())continue;
   if((int)($row['failures']??0)>=5)continue;
   $interval=($row['state']??'')==='source_changed_review'?21600:1800;
   if(!empty($row['last_checked']) && strtotime($row['last_checked'])>time()-$interval)continue;
   // Only pre-authorized first publication may resume; periodic loop never
   // changes a published post's content without a fresh review.
   $out[]=self::run($key,!empty($row['publish_requested']),false);
  }
  return ['checked'=>count($out),'runs'=>$out];
 }
 public static function status() {
  $r=self::rows();$out=[];
  foreach($r as $v) {
   unset($v['taxonomies']);
   $out[]=$v;
  }
  return ['contract'=>'mad4b.source-content-loop.v1','count'=>count($r),
   'default_authoring_brand_policy'=>self::neutral_demo_policy(),
   'registry_inconsistencies'=>self::registry_inconsistencies($r),
   'reconciliation_required'=>!empty(self::registry_inconsistencies($r)),
   'provider_configured'=>class_exists('MAD4B_SCP_Firecrawl_Provider')
     ? MAD4B_SCP_Firecrawl_Provider::probe()['ready']??false:false,
   'sources'=>$out];
 }
}
// Read-only control-plane discovery; no remote mutation is exposed by this module.
add_action('wp_abilities_api_init',static function(){
 if(!function_exists('wp_register_ability') || !function_exists('wp_has_ability')
   || wp_has_ability('mad4b/source-content-loop-status'))return;
 wp_register_ability('mad4b/source-content-loop-status',[
  'label'=>'MAD4B Source URL Loop Status',
  'description'=>'Read-only enrolled-source capture, retry and publication state.',
  'category'=>'mad4b-read',
  'execute_callback'=>static function($input=[]){return MAD4B_Source_Content_Loop_V1::status();},
  'permission_callback'=>static function(){return class_exists('MAD4B_SCP_Policy')
    && MAD4B_SCP_Policy::can_read();},
  'input_schema'=>['type'=>'object','additionalProperties'=>false],
  'output_schema'=>['type'=>'object','additionalProperties'=>true],
  'meta'=>['public'=>false,'show_in_rest'=>false,
   'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
   'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]]
 ]);
},90);
add_action('plugins_loaded',static function(){
 if(!defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI'))return;
 WP_CLI::add_command('mad4b-source-loop',new class {
  public function enroll($args,$opts) {
   $tax=[];
   foreach(explode(';',(string)($opts['taxonomy']??'')) as $pair) {
    if(!$pair)continue;
    $bits=explode(':',$pair,2);
    if(count($bits)!==2)WP_CLI::error('Taxonomies require taxonomy:slug,slug');
    $tax[$bits[0]]=array_filter(explode(',',$bits[1]));
   }
   $r=MAD4B_Source_Content_Loop_V1::enroll($args[0]??'',
    $opts['post-type']??'tours-and-activities',$opts['title']??'',$tax,
    $opts['provider']??'firecrawl');
   $this->show($r);
  }
  public function run($args,$opts) {
   $this->show(MAD4B_Source_Content_Loop_V1::run($args[0]??'',
     isset($opts['publish']),isset($opts['force'])));
  }
  public function tick($args,$opts) {$this->show(MAD4B_Source_Content_Loop_V1::tick((int)($opts['max']??1)));}
  public function reconcile_plan($args,$opts) {
   $this->show(MAD4B_Source_Content_Loop_V1::binding_reconcile_plan($args[0]??''));
  }
  public function reconcile_apply($args,$opts) {
   $this->show(MAD4B_Source_Content_Loop_V1::binding_reconcile_apply(
    $args[0]??'',(string)($opts['expected-plan']??''),(string)($opts['confirm']??'')));
  }
  public function status() {$this->show(MAD4B_Source_Content_Loop_V1::status());}
  private function show($r) {
   if(is_wp_error($r))WP_CLI::error($r->get_error_code().' '.$r->get_error_message());
   WP_CLI::line(wp_json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  }
 });
},150);

