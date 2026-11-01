<?php
/** Country-aware holiday calendar ingestion under MAD4B Google OAuth; no automatic campaigns. */
if(!defined('ABSPATH'))exit;
final class MAD4B_SCP_Season_Calendar {
 const CONTRACT='mad4b.season-calendar.v1';
 const OPTION='mad4b_scp_season_calendar_proposals_v1';
 const BINDINGS='mad4b_scp_season_calendar_bindings_v1';
 const LAST_RUN='mad4b_scp_season_calendar_last_run_v1';
 private static $booted=false;
 public static function status(){
  $google=class_exists('MAD4B_SCP_Google_Drive_Context')?MAD4B_SCP_Google_Drive_Context::connection_status():[];
  $profile=class_exists('MAD4B_SCP_Market_Profiles')?MAD4B_SCP_Market_Profiles::status():[];
  return ['contract'=>self::CONTRACT,'google_provider'=>'mad4b_brand_context_existing_google_oauth',
   'google_connection_state'=>(string)($google['health_state']??'unavailable'),
   'calendar_scope_required'=>'https://www.googleapis.com/auth/calendar.readonly',
   'site_bound'=>(bool)($profile['site_bound']??false),
   'approved_market_profiles'=>(int)($profile['approved_count']??0),
   'pending_proposals'=>count(self::proposals()),'public_holiday_provider'=>class_exists('MAD4B_SCP_Public_Holidays')?'mad4b.public-holidays.v1':'unavailable',
   'public_network_policy'=>defined('WP_HTTP_BLOCK_EXTERNAL')&&WP_HTTP_BLOCK_EXTERNAL?'restricted_external_http':'wordpress_http_configured',
   'auto_approvals'=>false,'auto_publication'=>false,
   'next_action'=>(($google['health_state']??'')==='token_unreadable'?'reconnect_existing_google_authorization':'discover_subscribed_holiday_calendars')];
 }
 public static function proposals(){ $r=get_option(self::OPTION,[]);return is_array($r)?$r:[]; }
 /** Detect only Google-owned country holiday feeds in a subscribed account. */
 public static function normalize_subscribed_calendars($items){
  $out=[];$country_aliases=['usa'=>'US','uk'=>'GB','australian'=>'AU','canadian'=>'CA','brazilian'=>'BR'];
  foreach((array)$items as $calendar){
   if(!is_array($calendar))continue;
   $id=(string)($calendar['id']??'');
   if(!preg_match('/^([a-z]{2}(?:-[a-z]{2})?)\\.([a-z]{2}|usa|uk|australian|canadian|brazilian)#holiday@group\\.v\\.calendar\\.google\\.com$/D',$id,$m))continue;
   $country=$country_aliases[$m[2]]??strtoupper($m[2]);
   if(!preg_match('/^[A-Z]{2}$/D',$country))continue;
   $out[$id]=['calendar_id'=>$id,'country'=>$country,'provider_language'=>$m[1],
    'label'=>sanitize_text_field((string)($calendar['summary']??$id)),
    'provider'=>'google_calendar','kind'=>'holiday_calendar','read_only'=>true];
  }
  return $out;
 }
 public static function subscribed_country_calendars(){
  $result=MAD4B_SCP_Google_Drive_Context::calendar_read_request('calendar_list');
  if(is_wp_error($result))return $result;
  if(empty($result['complete']))return new WP_Error('mad4b_calendar_list_incomplete','Calendar list incomplete; no import.');
  return self::normalize_subscribed_calendars($result['items']);
 }
 public static function normalize($events,$calendar_id,$country,$language,$market_profile_id){
  if(!is_array($events)||count($events)>500)return [];
  $out=[];
  foreach($events as $event){
   if(!is_array($event)||($event['status']??'confirmed')!=='confirmed')continue;
   $start=$event['start']['date']??null;$end=$event['end']['date']??null;
   if(!is_string($start)||!is_string($end)||!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D',$start)||!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D',$end))continue;
   $start_time=strtotime($start.' UTC');$end_time=strtotime($end.' UTC');
   if(!$start_time||!$end_time||$end_time<=$start_time||$end_time-$start_time>32*DAY_IN_SECONDS)continue;
   $id=(string)($event['id']??'');$title=sanitize_text_field((string)($event['summary']??''));
   if(!$id||strlen($id)>200||!$title)continue;
   $tentative=(bool)preg_match('/مبدئي|tentative|preliminary|may change|قد يتغيّر|قد يتغير/ui',$title.' '.(string)($event['description']??''));
   $key=hash('sha256',$market_profile_id.'|'.$country.'|'.$language.'|'.$calendar_id.'|'.$id.'|'.(string)($event['originalStartTime']['date']??''));
   $out[$key]=['contract'=>self::CONTRACT,'source'=>'google_calendar','source_calendar_id'=>$calendar_id,
    'event_id'=>$id,'source_identity_sha256'=>$key,'country'=>$country,'language'=>$language,'market_profile_id'=>$market_profile_id,
    'title'=>$title,'date_start'=>$start,'date_end'=>gmdate('Y-m-d',$end_time-DAY_IN_SECONDS),'google_end_exclusive'=>$end,
    'tentative'=>$tentative,'state'=>'pending_review','approval'=>false,'season_term_id'=>0,'revision'=>1];
  }
  return $out;
 }
 /** Pure prepare for a supplied, validated provider payload. Read-only. */
 public static function plan($country,$language,$calendar_id,$events,$subscribed,$window_from='',$window_to=''){
  $country=strtoupper((string)$country);$language=sanitize_key((string)$language);
  if(!is_array($events)||count($events)>500)return new WP_Error('mad4b_calendar_event_limit','Bounded Calendar import permits at most 500 events.');
  $market=MAD4B_SCP_Market_Profiles::by_country($country,$language);
  if(!$market)return new WP_Error('mad4b_calendar_market_not_approved','Select an approved, visible country-language Market Profile first.');
  if(!isset($subscribed[$calendar_id])||($subscribed[$calendar_id]['country']??'')!==$country)
   return new WP_Error('mad4b_calendar_country_source_mismatch','Calendar must be subscribed and country-matched.');
  $normalized=self::normalize($events,$calendar_id,$country,$language,$market['profile_id']);
  return ['contract'=>self::CONTRACT,'country'=>$country,'language'=>$language,'market_profile_id'=>$market['profile_id'],
   'source_calendar_id'=>$calendar_id,'window_from'=>$window_from,'window_to'=>$window_to,'records'=>$normalized,'count'=>count($normalized),'automatic_approval'=>false,
   'term_created'=>0,'season_published'=>0,'offers_enabled'=>false];
 }
 /** Fetch from Brand Context Google authorization; no local credential or alternate OAuth. */
 public static function fetch_plan($country,$language,$calendar_id,$from,$to){
  $market=MAD4B_SCP_Market_Profiles::by_country($country,$language);
  if(!$market)return new WP_Error('mad4b_calendar_market_not_approved','Market not approved.');
  $subscribed=self::subscribed_country_calendars();if(is_wp_error($subscribed))return $subscribed;
  if(!isset($subscribed[$calendar_id])||$subscribed[$calendar_id]['country']!==strtoupper($country))return new WP_Error('mad4b_calendar_source_not_subscribed','Approved country holiday calendar not subscribed.');
  $args=['calendar_id'=>$calendar_id,'time_min'=>$from.'T00:00:00+00:00','time_max'=>$to.'T23:59:59+00:00'];
  $response=MAD4B_SCP_Google_Drive_Context::calendar_read_request('events',$args);
  if(is_wp_error($response))return $response;
  if(empty($response['complete']))return new WP_Error('mad4b_calendar_events_incomplete','Calendar event pagination not exhausted; no partial import.');
  return self::plan($country,$language,$calendar_id,$response['items'],$subscribed,$from,$to);
 }
 /** Local admin-only ingestion, records remain pending review, never alters Seasons taxonomy. */
 public static function scheduled_bindings(){
  $all=get_option(self::BINDINGS,[]);return is_array($all)?$all:[];
 }
 private static function binding_key($profile_id,$calendar_id){return hash('sha256',(string)$profile_id.'|'.(string)$calendar_id);}
 private static function binding_valid($plan){
  $market=MAD4B_SCP_Market_Profiles::approved($plan['market_profile_id']??'');
  if(!$market||$market['country']!==($plan['country']??'')||$market['language']!==($plan['language']??''))return false;
  $key=self::binding_key($market['profile_id'],$plan['source_calendar_id']??'');
  $b=self::scheduled_bindings()[$key]??null;
  return is_array($b)&&!empty($b['enabled'])&&($b['site_uuid']??'')===MAD4B_SCP_Market_Profiles::site_uuid()
   &&($b['country']??'')===$market['country']&&($b['language']??'')===$market['language']
   &&($b['calendar_id']??'')===($plan['source_calendar_id']??'');
 }
 public static function enable_scheduled_binding($plan){
  if(wp_get_environment_type()!=='local'||!current_user_can('manage_options')||!is_array($plan))return new WP_Error('mad4b_calendar_schedule_denied','Local admin required');
  $market=MAD4B_SCP_Market_Profiles::approved($plan['market_profile_id']??'');
  if(!$market||$market['country']!==($plan['country']??'')||$market['language']!==($plan['language']??''))return new WP_Error('mad4b_calendar_schedule_market_invalid','Unapproved market');
  $calendar=(string)($plan['source_calendar_id']??'');
  if(!isset(self::normalize_subscribed_calendars([['id'=>$calendar]])[$calendar]))return new WP_Error('mad4b_calendar_schedule_source_invalid','Only an explicitly reviewed Google holiday calendar is eligible');
  $key=self::binding_key($market['profile_id'],$calendar);$bindings=self::scheduled_bindings();
  if(count($bindings)>=10&&!isset($bindings[$key]))return new WP_Error('mad4b_calendar_schedule_limit','Maximum ten approved calendar bindings');
  $bindings[$key]=['calendar_id'=>$calendar,'market_profile_id'=>$market['profile_id'],'country'=>$market['country'],
   'language'=>$market['language'],'site_uuid'=>MAD4B_SCP_Market_Profiles::site_uuid(),'enabled'=>true,
   'approved_by'=>get_current_user_id(),'approved_at'=>current_time('mysql',true)];
  update_option(self::BINDINGS,$bindings,false);
  if(!wp_next_scheduled('mad4b_scp_season_calendar_sync'))wp_schedule_event(time()+HOUR_IN_SECONDS,'daily','mad4b_scp_season_calendar_sync');
  return ['scheduled'=>true,'bound_country'=>$market['country'],'bound_language'=>$market['language'],'calendar_id'=>$calendar,'next_run'=>wp_next_scheduled('mad4b_scp_season_calendar_sync')];
 }
 public static function run_scheduled_sync(){
  $result=['contract'=>self::CONTRACT,'mode'=>'approved_country_calendar_daily_proposals','checked'=>0,'fetched'=>0,'errors'=>[],'season_terms_published'=>0,'financial_offers_changed'=>0];
  if(wp_get_environment_type()!=='local'||!MAD4B_SCP_Market_Profiles::site_uuid())return;
  $today=gmdate('Y-m-d');$until=gmdate('Y-m-d',strtotime('+330 days'));
  foreach(array_slice(self::scheduled_bindings(),0,10) as $binding){
   if(!is_array($binding)||empty($binding['enabled']))continue;
   $planStub=['country'=>$binding['country']??'','language'=>$binding['language']??'',
    'market_profile_id'=>$binding['market_profile_id']??'','source_calendar_id'=>$binding['calendar_id']??''];
   if(!self::binding_valid($planStub))continue;
   $result['checked']++;
   $plan=self::fetch_plan($planStub['country'],$planStub['language'],$planStub['source_calendar_id'],$today,$until);
   if(is_wp_error($plan)){$result['errors'][]=$plan->get_error_code();continue;}
   $store=self::store_plan($plan,true);
   if(is_wp_error($store)){$result['errors'][]=$store->get_error_code();continue;}
   $result['fetched']++;
  }
  $result['ran_at']=current_time('mysql',true);update_option(self::LAST_RUN,$result,false);
 }
 public static function store_plan($plan,$scheduled=false){
  if(wp_get_environment_type()!=='local'||!MAD4B_SCP_Market_Profiles::site_uuid()||!(current_user_can('manage_options')||($scheduled&&doing_action('mad4b_scp_season_calendar_sync')&&self::binding_valid($plan))))return new WP_Error('mad4b_calendar_write_not_allowed','Site admin or approved scheduled binding required.');
  if(!is_array($plan)||($plan['contract']??'')!==self::CONTRACT||!is_array($plan['records']??null))return new WP_Error('mad4b_calendar_plan_invalid','Invalid plan.');
  $market=MAD4B_SCP_Market_Profiles::by_country($plan['country']??'',$plan['language']??'');
  if(!$market||$market['profile_id']!==($plan['market_profile_id']??''))return new WP_Error('mad4b_calendar_plan_market_stale','Market profile changed.');
  $existing=self::proposals();
  foreach($plan['records'] as $id=>$row){
   if(count($existing)>=500&&!isset($existing[$id]))return new WP_Error('mad4b_calendar_proposals_full','Proposal limit reached.');
   if(!is_array($row)||($row['source_identity_sha256']??'')!==$id||($row['country']??'')!==$plan['country']||($row['language']??'')!==$plan['language']||($row['state']??'')!=='pending_review')return new WP_Error('mad4b_calendar_plan_row_invalid','Proposal does not match its source.');
   if(isset($existing[$id])&&$existing[$id]['date_start']===$row['date_start']&&$existing[$id]['date_end']===$row['date_end']&&($existing[$id]['title']??'')===$row['title']&&($existing[$id]['tentative']??null)===$row['tentative'])continue;
   if(isset($existing[$id])&&!empty($existing[$id]['season_term_id'])){
    $term_id=absint($existing[$id]['season_term_id']);
    if(get_term_meta($term_id,'mad4b_calendar_source_identity',true)===$id){
     update_term_meta($term_id,'season_enabled','0');update_term_meta($term_id,'mad4b_calendar_review_status','source_changed_review_required');
    }
   }
   $row['revision']=(int)($existing[$id]['revision']??0)+1;
   $existing[$id]=$row;
  }
  // Only a complete, bounded Google Calendar fetch can mark absent events as stale.
  $from=(string)($plan['window_from']??'');$to=(string)($plan['window_to']??'');
  if(preg_match('/^\d{4}-\d{2}-\d{2}$/D',$from)&&preg_match('/^\d{4}-\d{2}-\d{2}$/D',$to)&&$from<=$to){
   foreach($existing as $oldkey=>$old){
    if(isset($plan['records'][$oldkey])||!is_array($old)||($old['source_calendar_id']??'')!==($plan['source_calendar_id']??'')||($old['market_profile_id']??'')!==$market['profile_id']||($old['country']??'')!==$market['country']||($old['language']??'')!==$market['language'])continue;
    if(($old['date_start']??'')<$from||($old['date_start']??'')>$to||($old['state']??'')==='source_missing_review')continue;
    $old['state']='source_missing_review';$old['approval']=false;$old['revision']=(int)($old['revision']??0)+1;
    if(!empty($old['season_term_id'])){
     $id=absint($old['season_term_id']);if(get_term_meta($id,'mad4b_calendar_source_identity',true)===$oldkey){
      update_term_meta($id,'season_enabled','0');update_term_meta($id,'mad4b_calendar_review_status','source_missing_review');
     }
    }
    $existing[$oldkey]=$old;
   }
  }
  update_option(self::OPTION,$existing,false);
  return ['stored'=>count($plan['records']),'current_pending'=>count(self::proposals()),'terms_modified'=>0,'offers_modified'=>0];
 }
 /** Explicit site-admin decision: write reviewed non-tentative dates to an inactive Season term. */
 public static function approve_schedule($key,$expected_revision){
  if(wp_get_environment_type()!=='local'||!current_user_can('manage_options')||!MAD4B_SCP_Market_Profiles::site_uuid())return new WP_Error('mad4b_season_approval_denied','Site-local administrator required');
  $key=(string)$key;$stored=self::proposals();$r=$stored[$key]??null;
  if(!preg_match('/^[a-f0-9]{64}$/D',$key)||!is_array($r)||($r['state']??'')!=='pending_review'||!empty($r['tentative'])||(int)($r['revision']??0)!==(int)$expected_revision)return new WP_Error('mad4b_season_review_required','A current confirmed event and exact revision are required');
  $market=MAD4B_SCP_Market_Profiles::by_country($r['country'],$r['language']);
  if(!$market||$market['profile_id']!==$r['market_profile_id'])return new WP_Error('mad4b_season_market_stale','Market profile is no longer approved');
  if(!taxonomy_exists('season'))return new WP_Error('mad4b_season_taxonomy_unavailable','Season taxonomy not available');
  $slug='mad4b-cal-'.substr($key,0,16);
  $existing=get_term_by('slug',$slug,'season');
  if($existing && get_term_meta($existing->term_id,'mad4b_calendar_source_identity',true)!==$key)return new WP_Error('mad4b_season_term_collision','Another season owns the slug');
  if(!$existing){
   $label=trim($r['title'].' '.$r['date_start'].' ['.$r['country'].' / '.$r['language'].']');
   $new=wp_insert_term($label,'season',['slug'=>$slug]);
   if(is_wp_error($new))return $new;$id=(int)$new['term_id'];
  }else $id=(int)$existing->term_id;
  update_term_meta($id,'season_enabled','0');
  update_term_meta($id,'season_mode','domestic');
  update_term_meta($id,'season_date_windows',[['start'=>$r['date_start'],'end'=>$r['date_end']]]);
  update_term_meta($id,'mad4b_calendar_source_identity',$key);
  update_term_meta($id,'mad4b_market_profile_id',$r['market_profile_id']);
  update_term_meta($id,'mad4b_market_country',$r['country']);
  update_term_meta($id,'mad4b_market_language',$r['language']);
  update_term_meta($id,'mad4b_calendar_review_status','reviewed_schedule_inactive');
  $r['state']='reviewed_schedule_inactive';$r['approval']=true;$r['season_term_id']=$id;$r['reviewed_by']=get_current_user_id();$r['reviewed_at']=current_time('mysql',true);
  $stored[$key]=$r;update_option(self::OPTION,$stored,false);
  return ['season_term_id'=>$id,'season_enabled'=>false,'seasonal_templates_enabled'=>false,'financial_offer_enabled'=>false,'reviewed'=>true];
 }
 public static function fetch_action(){
  if(!current_user_can('manage_options'))wp_die('Access denied');check_admin_referer('mad4b_season_calendar_fetch');
  $market=sanitize_key(wp_unslash($_POST['market_profile_id']??''));$profile=MAD4B_SCP_Market_Profiles::approved($market);
  if(!$profile)wp_die('Market must be approved');
  $calendar=sanitize_text_field(wp_unslash($_POST['calendar_id']??''));
  $from=sanitize_text_field(wp_unslash($_POST['date_from']??''));$to=sanitize_text_field(wp_unslash($_POST['date_to']??''));
  if(!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D',$from)||!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D',$to))wp_die('Invalid date window');
  $plan=self::fetch_plan($profile['country'],$profile['language'],$calendar,$from,$to);
  if(is_wp_error($plan))wp_die(esc_html($plan->get_error_code()));
  $write=self::store_plan($plan);if(is_wp_error($write))wp_die(esc_html($write->get_error_code()));
  if(!empty($_POST['enable_daily_sync'])){ $scheduled=self::enable_scheduled_binding($plan);if(is_wp_error($scheduled))wp_die(esc_html($scheduled->get_error_code()));}
  wp_safe_redirect(admin_url('admin.php?page=mad4b-season-calendar&fetched=1'));exit;
 }
 public static function approve_action(){
  if(!current_user_can('manage_options'))wp_die('Access denied');check_admin_referer('mad4b_season_calendar_approve');
  $result=self::approve_schedule(sanitize_text_field(wp_unslash($_POST['proposal_id']??'')),absint($_POST['revision']??0));
  if(is_wp_error($result))wp_die(esc_html($result->get_error_code()));
  wp_safe_redirect(admin_url('admin.php?page=mad4b-season-calendar&reviewed=1'));exit;
 }
 public static function boot(){
  if(self::$booted)return;self::$booted=true;
  MAD4B_SCP_Admin_Route_Registry::schedule_submenu([__CLASS__,'admin_menu'],93);
  MAD4B_SCP_Admin_Route_Registry::register('mad4b-season-calendar','manage_options');
  add_action('mad4b_scp_season_calendar_sync',[__CLASS__,'run_scheduled_sync']);
  add_action('admin_post_mad4b_season_calendar_fetch',[__CLASS__,'fetch_action']);
  add_action('admin_post_mad4b_season_calendar_approve',[__CLASS__,'approve_action']);
 }
 public static function admin_menu(){add_submenu_page('mad4b-control-plane','Country Seasons (Calendar)','Country Seasons (Calendar)','manage_options','mad4b-season-calendar',[__CLASS__,'admin_page']);}
 public static function admin_page(){
  if(!current_user_can('manage_options'))wp_die('Denied');
  $status=self::status();
  echo '<div class="wrap"><h1>MAD4B — Country Season Calendar</h1><p>Existing Brand Context Google authorization is the only Google connection. Events are proposals until reviewed; no offer or campaign is created.</p>';
  echo '<p>Google connection: <code>'.esc_html($status['google_connection_state']).'</code> | Approved market profiles: '.(int)$status['approved_market_profiles'].' | Proposals: '.(int)$status['pending_proposals'].'</p>';
  if($status['google_connection_state']!=='healthy')echo '<p><strong>Connection action required:</strong> <a href="'.esc_url(admin_url('admin.php?page=mad4b-control-plane-context')).'">Open MAD4B Context Authority</a> and reconnect the existing Google authorization with calendar read scope. No extra OAuth app is created.</p>';
  $calendars=[];
  if($status['google_connection_state']==='healthy'){
   $discovery=self::subscribed_country_calendars();
   if(!is_wp_error($discovery))$calendars=$discovery;
   else echo '<p>Calendar discovery unavailable: '.esc_html($discovery->get_error_code()).'</p>';
  }
  echo '<p>Public holiday alternative: Google public iCalendar and Nager.Date require no user OAuth, but the current Local Lab blocks WordPress external HTTP. A governed MAD4B public feed gateway or explicitly approved narrow egress is required for live retrieval; neither replaces private-calendar authorization.</p>';
  echo '<h2>Fetch country holidays into pending proposals</h2>';
  if(!$calendars||!$status['approved_market_profiles'])echo '<p>No eligible country calendars and approved markets are simultaneously available. Approve Market Profiles and subscribe to Google holiday calendars in the connected Google account first.</p>';
  else {
   echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mad4b_season_calendar_fetch">';wp_nonce_field('mad4b_season_calendar_fetch');
   echo '<p>Approved market: <select name="market_profile_id">';
   foreach(MAD4B_SCP_Market_Profiles::approved_list() as $market)echo '<option value="'.esc_attr($market['profile_id']).'">'.esc_html($market['country'].' + '.$market['language'].' — '.$market['label']).'</option>';
   echo '</select> Subscribed holiday calendar: <select name="calendar_id">';
   foreach($calendars as $calendar)echo '<option value="'.esc_attr($calendar['calendar_id']).'">'.esc_html($calendar['country'].' — '.$calendar['label']).'</option>';
   echo '</select></p><p>From <input type="date" name="date_from" value="'.esc_attr(gmdate('Y-m-d')).'"> To <input type="date" name="date_to" value="'.esc_attr(gmdate('Y-m-d',strtotime('+11 months'))).'">';
   echo '</p><p><label><input type="checkbox" name="enable_daily_sync" value="1" checked> Refresh this approved market and calendar daily (pending proposals only)</label></p>';
   submit_button('Fetch as pending proposals');echo '</form>';
  }
  echo '<h2>Review dates before creating inactive local seasons</h2><table class="widefat striped"><thead><tr><th>Country / Language</th><th>Event</th><th>Dates</th><th>Source state</th><th>Action</th></tr></thead><tbody>';
  foreach(array_slice(self::proposals(),-100,100,true) as $key=>$p){
   echo '<tr><td>'.esc_html($p['country'].' / '.$p['language']).'</td><td>'.esc_html($p['title']).'</td><td>'.esc_html($p['date_start'].' – '.$p['date_end']).'</td><td>'.esc_html($p['state'].(!empty($p['tentative'])?' (tentative)':'')).'</td><td>';
   if(($p['state']??'')==='pending_review'&&empty($p['tentative'])){
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mad4b_season_calendar_approve"><input type="hidden" name="proposal_id" value="'.esc_attr($key).'"><input type="hidden" name="revision" value="'.(int)$p['revision'].'">';wp_nonce_field('mad4b_season_calendar_approve');submit_button('Confirm inactive season','secondary','submit',false);echo '</form>';
   }elseif(!empty($p['tentative']))echo 'Verify final date in an authoritative source first';else echo 'Inactive season #'.(int)($p['season_term_id']??0);
   echo '</td></tr>';
  }
  echo '</tbody></table></div>';
 }
}
