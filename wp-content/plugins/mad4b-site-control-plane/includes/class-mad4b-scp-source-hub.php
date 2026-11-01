<?php
/** MAD4B Source Hub: site-scoped source registry and zero-publication inbox. */
if(!defined('ABSPATH'))exit;
final class MAD4B_SCP_Source_Hub {
 const CONTRACT='mad4b.source-hub.v1';
 const SOURCES='mad4b_scp_source_hub_sources_v1';
 const ITEMS='mad4b_scp_source_hub_items_v1';
 const AUDIT='mad4b_scp_source_hub_audit_v1';
 const RUNTIME='/run/mad4b-local-vault/source-hub';
 private static $booted=false;
 public static function content_kinds(){return ['tourism_news'=>'Tourism news','travel_reference_update'=>'Open travel knowledge update','open_source_release'=>'Open-source project release','local_provider_update'=>'Local provider update'];}
 public static function adapters(){return ['public_ical'=>'Public iCalendar','rss_atom'=>'RSS / Atom','json_feed'=>'JSON Feed','provider_json'=>'Governed Provider JSON','nager_holidays'=>'Nager.Date Holidays'];}
 public static function sources(){ $r=get_option(self::SOURCES,[]);return is_array($r)?$r:[]; }
 public static function items(){ $r=get_option(self::ITEMS,[]);return is_array($r)?$r:[]; }
 public static function site_uuid(){return class_exists('MAD4B_SCP_Market_Profiles')?MAD4B_SCP_Market_Profiles::site_uuid():'';}
 public static function status(){
  $r=self::sources();$active=array_filter($r,static fn($v)=>($v['state']??'')==='approved');
  $brand=class_exists('MAD4B_SCP_Context_Authority')?MAD4B_SCP_Context_Authority::status():[];
  return ['contract'=>self::CONTRACT,'site_bound'=>(bool)self::site_uuid(),'sources'=>count($r),'approved_sources'=>count($active),
   'pending_items'=>count(self::items()),'hourly_import_enabled'=>(bool)wp_next_scheduled('mad4b_scp_source_hub_import'),'adapters'=>array_keys(self::adapters()),'brand_context_state'=>$brand['state']??'unavailable',
   'worker_transport'=>wp_get_environment_type()==='local'?'site_bound_private_host_spool':'wordpress_http_when_authorized',
   'auto_publish'=>false,'automatic_approval'=>false];
 }
 private static function log_event($event,$source_id,$details=[]){
  $log=get_option(self::AUDIT,[]);if(!is_array($log))$log=[];
  $log[]=['event'=>$event,'source_id'=>$source_id,'site_uuid'=>self::site_uuid(),'actor'=>get_current_user_id(),
    'occurred_at'=>gmdate('c'),'details'=>$details];
  update_option(self::AUDIT,array_slice($log,-250),false);
 }
 public static function poll_ingest(){
  if(wp_get_environment_type()!=='local'||!self::site_uuid())return;
  foreach(array_keys(self::sources()) as $id){if(self::approved($id))self::ingest_snapshot($id);}
 }
 public static function enable_hourly_import(){
  if(!self::admin_allowed())return self::error('admin_required');
  if(!wp_next_scheduled('mad4b_scp_source_hub_import'))wp_schedule_event(time()+HOUR_IN_SECONDS,'hourly','mad4b_scp_source_hub_import');
  return ['enabled'=>(bool)wp_next_scheduled('mad4b_scp_source_hub_import'),'publishes_content'=>false];
 }
 private static function error($reason){return new WP_Error('mad4b_source_hub_'.$reason,$reason);}
 private static function admin_allowed(){return wp_get_environment_type()==='local'&&current_user_can('manage_options')&&self::site_uuid()!=='';}
 private static function validate_url($url,$adapter){
  $p=wp_parse_url($url);
  if(!is_array($p)||strtolower($p['scheme']??'')!=='https'||!empty($p['user'])||!empty($p['pass'])||!empty($p['fragment'])||isset($p['port']))return false;
  $host=strtolower((string)($p['host']??''));if(!$host||strlen($host)>253||!preg_match('/^[a-z0-9.-]+$/D',$host)||filter_var($host,FILTER_VALIDATE_IP))return false;
  // Initial certified domains; additional provider hosts require governed allowlist revision.
  $allowed=['calendar.google.com'=>['public_ical'],'nagerholidays.com'=>['nager_holidays'],'www.unwto.org'=>['rss_atom'],'news.un.org'=>['rss_atom'],'en.unwto-ap.org'=>['rss_atom']];
  $extra=get_option('mad4b_scp_source_hub_trusted_hosts_v1',[]);
  foreach((array)$extra as $approved_host=>$kinds){
   if(!is_string($approved_host)||!preg_match('/^[a-z0-9.-]+$/D',$approved_host)||!is_array($kinds))continue;
   $allowed[$approved_host]=array_values(array_intersect(array_keys(self::adapters()),$kinds));
  }
  return isset($allowed[$host])&&in_array($adapter,$allowed[$host],true);
 }
 private static function sync_worker_manifest(){
  if(wp_get_environment_type()!=='local'||!self::site_uuid())return;
  if(!is_dir(self::RUNTIME)&&!wp_mkdir_p(self::RUNTIME))return;
  $approved=[];
  foreach(self::sources() as $id=>$record){$a=self::approved($id);if($a)$approved[$id]=[
    'id'=>$id,'url'=>$a['url'],'url_sha256'=>hash('sha256',$a['url']),'adapter'=>$a['adapter'],
    'revision'=>$a['revision'],'country'=>$a['country'],'language'=>$a['language']];}
  $data=['contract'=>'mad4b.source-hub-worker-manifest.v1','site_uuid'=>self::site_uuid(),'generated_at'=>time(),'sources'=>$approved];
  $temp=self::RUNTIME.'/manifest.tmp.'.getmypid();$dest=self::RUNTIME.'/manifest.json';
  if(file_put_contents($temp,wp_json_encode($data),LOCK_EX)===false)return;
  @chmod($temp,0600);rename($temp,$dest);
 }
 public static function trust_host($hostname,$kinds){
  if(!self::admin_allowed())return self::error('admin_required');
  $hostname=strtolower(trim((string)$hostname));
  if(!preg_match('/^[a-z0-9][a-z0-9.-]{3,250}[a-z0-9]$/D',$hostname)||filter_var($hostname,FILTER_VALIDATE_IP)||!is_array($kinds))return self::error('host_invalid');
  $permitted=array_values(array_intersect(array_keys(self::adapters()),$kinds));
  if(!$permitted)return self::error('adapter_invalid');
  $hosts=get_option('mad4b_scp_source_hub_trusted_hosts_v1',[]);if(!is_array($hosts))$hosts=[];
  if(count($hosts)>=20&&!isset($hosts[$hostname]))return self::error('host_capacity');
  $hosts[$hostname]=$permitted;update_option('mad4b_scp_source_hub_trusted_hosts_v1',$hosts,false);self::log_event('provider_host_reviewed',$hostname,['adapters'=>$permitted]);
  return ['hostname'=>$hostname,'adapters'=>$permitted,'status'=>'operator_approved_host'];
 }
 public static function save_draft($id,$adapter,$url,$country,$language,$title,$expected=0,$content_kind='tourism_news'){
  if(!self::admin_allowed())return self::error('admin_required');
  $id=sanitize_key($id);$adapter=sanitize_key($adapter);$country=strtoupper(sanitize_text_field($country));$language=sanitize_key($language);$title=sanitize_text_field($title);$url=esc_url_raw($url);$content_kind=sanitize_key((string)$content_kind);
  if(!$id||strlen($id)>60||!isset(self::adapters()[$adapter])||!self::validate_url($url,$adapter)||!$title||strlen($title)>150||!isset(self::content_kinds()[$content_kind]))return self::error('invalid_fields');
  if(!preg_match('/^[A-Z]{2}$/D',$country)||!isset(MAD4B_SCP_Market_Profiles::enabled_languages()[$language]))return self::error('country_language_invalid');
  $list=self::sources();$old=$list[$id]??[];$rev=(int)($old['revision']??0);
  if($rev!==(int)$expected)return self::error('stale_revision');
  if(!$old&&count($list)>=60)return self::error('source_capacity');
  $list[$id]=['contract'=>self::CONTRACT,'id'=>$id,'adapter'=>$adapter,'url'=>$url,'country'=>$country,'language'=>$language,
    'title'=>$title,'content_kind'=>$content_kind,'state'=>'draft','revision'=>$rev+1,'site_uuid'=>self::site_uuid(),'environment'=>wp_get_environment_type(),
    'updated_at'=>gmdate('c'),'updated_by'=>get_current_user_id()];
  update_option(self::SOURCES,$list,false);self::sync_worker_manifest();self::log_event('draft_saved',$id,['revision'=>$rev+1]);return $list[$id];
 }
 public static function approved($id){
  $p=self::sources()[sanitize_key((string)$id)]??null;
  if(!is_array($p)||($p['state']??'')!=='approved'||($p['contract']??'')!==self::CONTRACT||($p['site_uuid']??'')!==self::site_uuid()||($p['environment']??'')!==wp_get_environment_type()||!self::validate_url($p['url'],$p['adapter']))return [];
  if(!isset(MAD4B_SCP_Market_Profiles::enabled_languages()[$p['language']]))return [];
  return $p;
 }
 public static function approve($id,$revision){
  if(!self::admin_allowed())return self::error('admin_required');
  $list=self::sources();$id=sanitize_key($id);$p=$list[$id]??null;
  if(!is_array($p)||($p['state']??'')!=='draft'||(int)$p['revision']!==(int)$revision||($p['site_uuid']??'')!==self::site_uuid())return self::error('approval_invalid');
  $list[$id]['state']='approved';$list[$id]['approved_at']=gmdate('c');$list[$id]['approved_by']=get_current_user_id();
  update_option(self::SOURCES,$list,false);self::sync_worker_manifest();self::log_event('source_approved',$id,['revision'=>$revision]);return self::approved($id)?:self::error('approval_readback');
 }
 public static function normalize($source,$body){
  $type=$source['adapter'];if(strlen($body)>1500000)return self::error('body_too_large');
  if($type==='public_ical'){
   if(!class_exists('MAD4B_SCP_Public_Holidays'))return self::error('calendar_adapter_missing');
   $out=[];
   for($year=(int)gmdate('Y');$year<=(int)gmdate('Y')+2;$year++){
    $events=MAD4B_SCP_Public_Holidays::parse_google_ics($body,$year);
    if(is_wp_error($events))return $events;
    foreach($events as $event)$out[]=['external_id'=>$event['id'],'title'=>$event['summary'],'description'=>$event['description']??'',
      'date'=>$event['start']['date'],'end_exclusive'=>$event['end']['date'],'category'=>'calendar_event'];
   }
   return $out;
  }
  if($type==='nager_holidays'){
   $input=json_decode($body,true);if(!is_array($input))return self::error('json_invalid');$out=[];
   foreach(array_slice($input,0,400) as $event){if(!is_array($event)||strtoupper($event['countryCode']??'')!==$source['country']||empty($event['nationalHoliday']))continue;
    $date=(string)($event['date']??'');if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date))continue;
    $name=sanitize_text_field((string)($event['name']??''));if(!$name)continue;
    $out[]=['external_id'=>hash('sha256',$date.'|'.$name),'title'=>$name,'description'=>'','date'=>$date,'category'=>'national_holiday'];}
   return $out;
  }
  if($type==='provider_json'){
   $payload=json_decode($body,true);
   if(!is_array($payload)||($payload['contract']??'')!=='mad4b.source-provider.v1'||!is_array($payload['items']??null))return self::error('provider_contract_invalid');
   $out=[];foreach(array_slice($payload['items'],0,200) as $item){
    if(!is_array($item)||empty($item['id'])||empty($item['title']))continue;
    $out[]=['external_id'=>sanitize_text_field((string)$item['id']),'title'=>sanitize_text_field((string)$item['title']),
      'description'=>wp_strip_all_tags((string)($item['summary']??'')),'date'=>sanitize_text_field((string)($item['published_at']??'')),
      'category'=>'local_provider_update','article_url'=>isset($item['url'])&&str_starts_with((string)$item['url'],'https://')?esc_url_raw($item['url']):''];
   }return $out;
  }
  if($type==='rss_atom'){
   $items=self::parse_rss($body);if(is_wp_error($items))return $items;
   $kind=sanitize_key((string)($source['content_kind']??'tourism_news'));
   if(!isset(self::content_kinds()[$kind]))return self::error('content_kind_invalid');
   foreach($items as &$entry)$entry['category']=$kind;
   unset($entry);return $items;
  }
  if($type==='json_feed'){
   $json=json_decode($body,true);if(!is_array($json)||!is_array($json['items']??null)||!str_starts_with((string)($json['version']??''),'https://jsonfeed.org/version/'))return self::error('json_feed_invalid');
   $out=[];foreach(array_slice($json['items'],0,200) as $item){if(!is_array($item)||empty($item['id'])||empty($item['title']))continue;
    $out[]=['external_id'=>(string)$item['id'],'title'=>sanitize_text_field($item['title']),
      'description'=>wp_strip_all_tags((string)($item['summary']??$item['content_text']??'')),'date'=>(string)($item['date_published']??''),'category'=>'news'];}
   return $out;
  }
  return self::error('adapter_unknown');
 }
 public static function parse_rss($body){
  if(strlen($body)>1500000||stripos($body,'<!DOCTYPE')!==false||stripos($body,'<!ENTITY')!==false)return self::error('xml_rejected');
  $dom=new DOMDocument();$before=libxml_use_internal_errors(true);
  try{$ok=$dom->loadXML($body,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}finally{libxml_clear_errors();libxml_use_internal_errors($before);}
  if(!$ok)return self::error('xml_invalid');
  $root=$dom->documentElement->localName??'';
  if(!in_array($root,['rss','feed','RDF'],true))return self::error('not_rss_atom');
  $xpath=new DOMXPath($dom);$nodes=$xpath->query('//*[local-name()="item" or local-name()="entry"]');$out=[];
  foreach($nodes as $node){if(count($out)>=200)break;$get=static function($key)use($node){foreach($node->childNodes as $child)if($child->nodeType===XML_ELEMENT_NODE&&$child->localName===$key)return trim($child->textContent);return '';};
   $title=sanitize_text_field($get('title'));$guid=$get('guid')?:$get('id');$link=$get('link');
   if(!$link)foreach($node->childNodes as $child)if($child->nodeType===XML_ELEMENT_NODE&&$child->localName==='link'&&$child->hasAttribute('href')){$link=(string)$child->getAttribute('href');break;}
   if(!$guid){$guid=$link?:hash('sha256',$title.'|'.$get('pubDate'));}if(!$title)continue;
   $date=$get('pubDate')?:$get('published')?:$get('updated');
   $out[]=['external_id'=>substr($guid,0,250),'title'=>$title,'description'=>wp_strip_all_tags($get('description')?:$get('summary')),
     'date'=>$date,'category'=>'tourism_news','article_url'=>$link&&str_starts_with($link,'https://')?esc_url_raw($link):''];}
  return $out;
 }
 /** Native WordPress HTTPS transport for environments where site policy permits it. */
 public static function fetch_wordpress($id){
  if(!current_user_can('manage_options')||!self::site_uuid())return self::error('admin_required');
  $src=self::approved($id);if(!$src)return self::error('source_not_approved');
  if(defined('WP_HTTP_BLOCK_EXTERNAL')&&WP_HTTP_BLOCK_EXTERNAL)return self::error('wordpress_external_network_blocked');
  $response=wp_safe_remote_get($src['url'],['timeout'=>18,'redirection'=>0,'limit_response_size'=>1500000,
    'headers'=>['Accept'=>'application/rss+xml,application/atom+xml,text/calendar,application/json,application/feed+json']]);
  if(is_wp_error($response))return $response;
  if((int)wp_remote_retrieve_response_code($response)!==200)return self::error('provider_http_failed');
  $body=(string)wp_remote_retrieve_body($response);
  return self::store_body($src,$body,hash('sha256',$body));
 }
 private static function store_body($s,$body,$sha){
  $items=self::normalize($s,$body);if(is_wp_error($items))return $items;
  $existing=self::items();$count=0;$id=$s['id'];
  foreach($items as $item){$key=hash('sha256',$id.'|'.$item['external_id']);$record=[
    'source_id'=>$id,'source_revision'=>$s['revision'],'country'=>$s['country'],'language'=>$s['language'],
    'category'=>$item['category'],'title'=>$item['title'],'description'=>mb_substr($item['description']??'',0,3000),
    'event_date'=>$item['date']??'','article_url'=>$item['article_url']??'','source_sha256'=>$sha,'item_sha256'=>hash('sha256',wp_json_encode($item)),'state'=>'pending_review','auto_published'=>false];
   if(isset($existing[$key])&&($existing[$key]['item_sha256']??'')===$record['item_sha256']&&($existing[$key]['source_revision']??0)===$record['source_revision']&&($existing[$key]['category']??'')===$record['category'])continue;
   if(count($existing)>=1000&&!isset($existing[$key]))return self::error('item_capacity');
   $existing[$key]=$record;$count++;
  }
  update_option(self::ITEMS,$existing,false);
  $sources=self::sources();
  if(isset($sources[$id])&&($sources[$id]['revision']??0)===$s['revision']){
   $sources[$id]['last_success_at']=gmdate('c');$sources[$id]['last_body_sha256']=$sha;
   $sources[$id]['last_item_count']=count($items);update_option(self::SOURCES,$sources,false);
  }
  if($count)self::log_event('source_items_staged',$id,['new_or_changed'=>$count]);
  return ['contract'=>self::CONTRACT,'source_id'=>$id,'new_or_changed'=>$count,'pending_total'=>count($existing),'publication_changes'=>0];
 }
 public static function ingest_snapshot($id){
  if(wp_get_environment_type()!=='local'||!self::site_uuid())return self::error('local_only');
  $s=self::approved($id);if(!$s)return self::error('source_not_approved');
  $path=self::RUNTIME.'/inbox/'.sanitize_key($id).'.json';
  if(!is_file($path)||is_link($path)||filesize($path)>2300000)return self::error('snapshot_missing');
  $json=json_decode((string)file_get_contents($path),true);
  if(!is_array($json)||($json['contract']??'')!=='mad4b.source-fetch-receipt.v1'||($json['source_id']??'')!==$id||($json['site_uuid']??'')!==self::site_uuid()
    ||($json['url_sha256']??'')!==hash('sha256',$s['url'])||($json['source_revision']??0)!==(int)$s['revision']||abs(time()-(int)($json['fetched_at']??0))>36*HOUR_IN_SECONDS)return self::error('receipt_binding_invalid');
  $body=base64_decode((string)($json['body_b64']??''),true);
  if(!is_string($body)||strlen($body)>1500000||!hash_equals((string)($json['body_sha256']??''),hash('sha256',$body)))return self::error('receipt_integrity');
  return self::store_body($s,$body,$json['body_sha256']);
 }
 public static function poll_action(){
  if(!current_user_can('manage_options'))wp_die('Unauthorized');check_admin_referer('mad4b_source_hub_poll');
  $action=sanitize_key(wp_unslash($_POST['poll_action']??''));
  if($action==='enable'){$r=self::enable_hourly_import();if(is_wp_error($r))wp_die(esc_html($r->get_error_code()));}
  elseif($action==='disable'){wp_clear_scheduled_hook('mad4b_scp_source_hub_import');}
  else wp_die('Unsupported scheduler action');
  wp_safe_redirect(admin_url('admin.php?page=mad4b-source-hub&schedule=1'));exit;
 }
 public static function save_action(){
  if(!current_user_can('manage_options'))wp_die('Unauthorized');check_admin_referer('mad4b_source_hub_save');
  $r=self::save_draft(wp_unslash($_POST['source_id']??''),wp_unslash($_POST['adapter']??''),
    wp_unslash($_POST['url']??''),wp_unslash($_POST['country']??''),wp_unslash($_POST['language']??''),
    wp_unslash($_POST['title']??''),absint($_POST['revision']??0),wp_unslash($_POST['content_kind']??'tourism_news'));
  if(is_wp_error($r))wp_die(esc_html($r->get_error_code()));
  wp_safe_redirect(admin_url('admin.php?page=mad4b-source-hub&saved=1'));exit;
 }
 public static function approve_action(){
  if(!current_user_can('manage_options'))wp_die('Unauthorized');check_admin_referer('mad4b_source_hub_approve');
  $r=self::approve(wp_unslash($_POST['source_id']??''),absint($_POST['revision']??0));
  if(is_wp_error($r))wp_die(esc_html($r->get_error_code()));
  wp_safe_redirect(admin_url('admin.php?page=mad4b-source-hub&approved=1'));exit;
 }
 public static function ingest_action(){
  if(!current_user_can('manage_options'))wp_die('Unauthorized');check_admin_referer('mad4b_source_hub_ingest');
  $r=self::ingest_snapshot(wp_unslash($_POST['source_id']??''));
  if(is_wp_error($r))wp_die(esc_html($r->get_error_code()));
  wp_safe_redirect(admin_url('admin.php?page=mad4b-source-hub&imported=1'));exit;
 }
 public static function trust_action(){
  if(!current_user_can('manage_options'))wp_die('Unauthorized');check_admin_referer('mad4b_source_hub_trust');
  $adapter=sanitize_key(wp_unslash($_POST['adapter']??''));
  $r=self::trust_host(wp_unslash($_POST['hostname']??''),[$adapter]);
  if(is_wp_error($r))wp_die(esc_html($r->get_error_code()));
  wp_safe_redirect(admin_url('admin.php?page=mad4b-source-hub&host=1'));exit;
 }
 public static function boot(){
  if(self::$booted)return;self::$booted=true;
  MAD4B_SCP_Admin_Route_Registry::schedule_submenu([__CLASS__,'menu'],75);
  MAD4B_SCP_Admin_Route_Registry::register('mad4b-source-hub','manage_options');
  add_action('wp_abilities_api_init',[__CLASS__,'register_ability'],9);
  add_action('mad4b_scp_source_hub_import',[__CLASS__,'poll_ingest']);
  add_action('admin_post_mad4b_source_hub_save',[__CLASS__,'save_action']);
  add_action('admin_post_mad4b_source_hub_approve',[__CLASS__,'approve_action']);
  add_action('admin_post_mad4b_source_hub_ingest',[__CLASS__,'ingest_action']);
  add_action('admin_post_mad4b_source_hub_trust',[__CLASS__,'trust_action']);
  add_action('admin_post_mad4b_source_hub_poll',[__CLASS__,'poll_action']);
 }
 public static function register_ability(){if(!function_exists('wp_register_ability')||!class_exists('MAD4B_SCP_Policy'))return;
  if(function_exists('wp_has_ability')&&wp_has_ability('mad4b/source-hub-status'))return;
  wp_register_ability('mad4b/source-hub-status',['label'=>'MAD4B Source Hub Status','description'=>'Read source registry and pending counts without publishing',
   'category'=>'mad4b-read','execute_callback'=>[__CLASS__,'status'],'permission_callback'=>['MAD4B_SCP_Policy','can_read'],
   'output_schema'=>['type'=>'object','additionalProperties'=>true],
   'meta'=>['public'=>false,'show_in_rest'=>false,'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]]]);
 }
 public static function menu(){add_submenu_page('mad4b-control-plane','MAD4B Source Hub','Source Hub','manage_options','mad4b-source-hub',[__CLASS__,'admin_page']);}
 public static function admin_page(){
  if(!current_user_can('manage_options'))wp_die('Denied');
  $status=self::status();$langs=MAD4B_SCP_Market_Profiles::enabled_languages();
  echo '<div class="wrap"><h1>MAD4B Source Hub</h1><p>Source authority for public calendars, tourism RSS/Atom, JSON Feed and local-provider updates. Source approval allows retrieval only, not publication.</p>';
  echo '<p>Approved sources: '.(int)$status['approved_sources'].' / '.(int)$status['sources'].' | Pending items: '.(int)$status['pending_items'].' | Brand Context: '.esc_html($status['brand_context_state']).'</p>';
  echo '<h2>Registered sources</h2><table class="widefat striped"><thead><tr><th>Source</th><th>Adapter</th><th>Market</th><th>State / Revision</th><th>Import</th></tr></thead><tbody>';
  foreach(self::sources() as $r){
   echo '<tr><td>'.esc_html($r['title']).'</td><td>'.esc_html($r['adapter']).'</td><td>'.esc_html($r['country'].' / '.$r['language']).'</td><td>'.esc_html($r['state'].' #'.$r['revision']).'</td><td>';
   if(self::approved($r['id'])){
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mad4b_source_hub_ingest"><input type="hidden" name="source_id" value="'.esc_attr($r['id']).'">';
    wp_nonce_field('mad4b_source_hub_ingest');submit_button('Import downloaded receipt','secondary','submit',false);echo '</form>';
   } else echo 'Not approved';
   echo '</td></tr>';
  }
  echo '</tbody></table><h2>Register source as draft</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mad4b_source_hub_save">';wp_nonce_field('mad4b_source_hub_save');
  echo '<p>Source ID <input name="source_id" required pattern="[a-z0-9_-]+"> Name <input name="title" required> URL <input type="url" name="url" required size="65"></p><p>Adapter <select name="adapter">';
  foreach(self::adapters() as $id=>$label)echo '<option value="'.esc_attr($id).'">'.esc_html($label).'</option>';
  echo '</select> Content kind <select name="content_kind">';
  foreach(self::content_kinds() as $id=>$label)echo '<option value="'.esc_attr($id).'">'.esc_html($label).'</option>';
  echo '</select> Country ISO2 <input name="country" maxlength="2" required size="4"> Site language <select name="language">';
  foreach($langs as $id=>$label)echo '<option value="'.esc_attr($id).'">'.esc_html($label).'</option>';
  echo '</select> Existing revision <input type="number" name="revision" min="0" value="0"></p>';
  submit_button('Save source draft');echo '</form>';
  echo '<h2>Approve reviewed draft</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mad4b_source_hub_approve">';wp_nonce_field('mad4b_source_hub_approve');
  echo '<select name="source_id">';foreach(self::sources() as $r)if($r['state']==='draft')echo '<option value="'.esc_attr($r['id']).'">'.esc_html($r['title'].' #'.$r['revision']).'</option>';
  echo '</select> Exact revision <input name="revision" type="number" min="1" required>';submit_button('Approve source, not content');echo '</form>';
  echo '<h2>Trusted provider host</h2><p>Add only an operator-reviewed public HTTPS feed host. Local host worker enforces a public IP and does not follow redirects.</p>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mad4b_source_hub_trust">';wp_nonce_field('mad4b_source_hub_trust');
  echo '<input name="hostname" placeholder="news.example.org" required> Allowed adapter <select name="adapter">';
  foreach(self::adapters() as $id=>$label)echo '<option value="'.esc_attr($id).'">'.esc_html($label).'</option>';
  echo '</select>';submit_button('Trust this host','secondary');echo '</form>';
  echo '<h2>Inbox import schedule</h2><p>Approved host worker downloads public sources independently; this optional WordPress hourly action imports only already-fetched local receipts for review.</p>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mad4b_source_hub_poll">';wp_nonce_field('mad4b_source_hub_poll');
  echo '<select name="poll_action"><option value="enable">Enable hourly inbox import</option><option value="disable">Disable hourly inbox import</option></select>';
  submit_button('Apply import schedule','secondary');echo '</form>';
  echo '<h2>Pending review</h2><p>Imported items are isolated from live website content and Brand Authority approvals.</p><table class="widefat striped"><thead><tr><th>Title</th><th>Type</th><th>Country / Language</th><th>State</th></tr></thead><tbody>';
  foreach(array_slice(self::items(),-25,25,true) as $item)echo '<tr><td>'.esc_html($item['title']).'</td><td>'.esc_html($item['category']).'</td><td>'.esc_html($item['country'].' / '.$item['language']).'</td><td>'.esc_html($item['state']).'</td></tr>';
  echo '</tbody></table><p>Network-isolated Lab: use the MAD4B bundled Source Hub worker on the authorized VPS. No external gateway or Google OAuth is required for public feeds.</p></div>';
 }
}
