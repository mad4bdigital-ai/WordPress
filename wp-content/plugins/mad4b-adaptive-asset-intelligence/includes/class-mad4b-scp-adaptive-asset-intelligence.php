<?php
/**
 * MAD4B Adaptive Asset Intelligence: Staging WordPress control-plane adapter.
 * Browser measurements remain in the already isolated VPS Worker.
 * No external notifications or browser processes on visitor requests.
 */
defined('ABSPATH') || exit;
final class MAD4B_SCP_Adaptive_Asset_Intelligence {
 const PAGE = 'mad4b-adaptive-assets';
 const FEED = 'mad4b_adaptive_asset_notification_feed_v1';
 const QUEUE = 'mad4b_adaptive_asset_job_queue_v1';
 const EPOCH = 'mad4b_css_ai_epoch_v1';
 const UUID = 'e5e64bca-ed62-48d2-b2b3-dff693cd702a';
 const HOST = 'tours.dev.mad4b.com';
 const MANIFEST = WP_CONTENT_DIR . '/mu-plugins/mad4b-css-autonomous-policy.json';
 public static function scope(): bool {
  if (wp_get_environment_type() !== 'staging' || (string)wp_parse_url(home_url(),PHP_URL_HOST) !== self::HOST) return false;
  $profile=get_option('mad4b_scp_brand_context_profile_v1',[]);
  return is_array($profile) && ($profile['site_uuid']??'')===self::UUID;
 }
 public static function boot(): void {
  if (!self::scope()) return;
  MAD4B_SCP_Admin_Route_Registry::schedule_submenu([__CLASS__,'menu']);
  add_action('admin_bar_menu',[__CLASS__,'admin_bar'],96);
  add_action('admin_notices',[__CLASS__,'dashboard_notice']);
  add_action('admin_post_mad4b_adaptive_assets_ack',[__CLASS__,'handle_ack']);
  add_action('rest_api_init',[__CLASS__,'rest']);
  // Only authoring or source changes invalidate. No front-end polling or scanning.
  add_action('elementor/document/after_save',[__CLASS__,'elementor_saved'],10,2);
  add_action('save_post',[__CLASS__,'post_saved'],20,3);
  add_action('upgrader_process_complete',[__CLASS__,'upgraded'],10,2);
  add_action('updated_option',[__CLASS__,'option_changed'],10,3);
  add_action('wp_update_nav_menu',[__CLASS__,'menu_changed']);
 }
 private static function path($raw): string {
  if (!is_string($raw) || strlen($raw)>240 || !preg_match('#^/[A-Za-z0-9/_~.\-]*$#D',$raw)
   || strpos($raw,'..')!==false || strpos($raw,'//')!==false) return '';
  if (preg_match('~/(?:cart|checkout|booking|account|wp-admin|wp-json)(?:/|$)~i',$raw)) return '';
  return $raw;
 }
 public static function queue(): array {
  $q=get_option(self::QUEUE,[]);
  return is_array($q)?array_slice(array_values($q),0,50):[];
 }
 public static function notification_feed(): array {
  $v=get_option(self::FEED,[]);
  return is_array($v)?array_slice(array_values($v),0,100):[];
 }
 public static function emit(string $type,string $severity,string $path,string $message,array $context=[]): array {
  if (!self::scope() || !in_array($type,['queued','certified','hold','error','invalidate','recovered'],true)
   || !in_array($severity,['info','success','warning','critical'],true)) return ['stored'=>false];
  $path=self::path($path);if($path==='')return ['stored'=>false];
  $feed=self::notification_feed();
  // One event per identical state, route and source digest for a bounded hour, not per WP request.
  $fingerprint=hash('sha256',self::UUID.'|'.$type.'|'.$path.'|'.(string)($context['digest']??''));
  foreach ($feed as $existing) {
   if (($existing['fingerprint']??'')===$fingerprint && time()-(int)($existing['timestamp']??0)<3600)
    return ['stored'=>false,'deduplicated'=>true];
  }
  $evt=['id'=>hash('sha256',$fingerprint.'|'.time()),'fingerprint'=>$fingerprint,'type'=>$type,
   'severity'=>$severity,'path'=>$path,'message'=>sanitize_text_field(substr($message,0,200)),
   'timestamp'=>time(),'ack'=>false,'site_uuid'=>self::UUID];
  array_unshift($feed,$evt);update_option(self::FEED,array_slice($feed,0,100),false);
  // Canonical event contract for additional MAD4B notification consumers; no email/WhatsApp send here.
  do_action('mad4b/notifications/recorded',['contract'=>'mad4b.notification.event.v1',
   'source'=>'adaptive_asset_intelligence','site_uuid'=>self::UUID,'environment'=>'staging',
   'event_id'=>$evt['id'],'type'=>$type,'severity'=>$severity,'path'=>$path,
   'summary'=>$evt['message'],'recorded_at'=>gmdate('c',$evt['timestamp']),'external_delivery'=>false]);
  return ['stored'=>true,'event_id'=>$evt['id']];
 }
 public static function mark_dirty($reason='content_change',$path='/'): array {
  if (!self::scope())return ['queued'=>false];
  $path=self::path($path);if ($path==='')$path='/';
  $now=time();$queue=self::queue();$id=hash('sha256',self::UUID.'|'.$reason.'|'.$path);
  foreach($queue as $i=>$job){
   if (($job['id']??'')===$id){$queue[$i]['updated']=$now;update_option(self::QUEUE,$queue,false);return ['queued'=>true,'deduplicated'=>true];}
  }
  array_unshift($queue,['id'=>$id,'reason'=>sanitize_key($reason),'path'=>$path,'updated'=>$now,'state'=>'pending']);
  update_option(self::QUEUE,array_slice($queue,0,50),false);
  // Fail closed for any structural change until the Worker recertifies the route(s).
  $epoch=max(1,(int)get_option(self::EPOCH,1))+1;
  update_option(self::EPOCH,$epoch,false);
  self::emit('queued','info',$path,'CSS verification queued after '.sanitize_key($reason),['digest'=>(string)$epoch]);
  return ['queued'=>true,'revision'=>$epoch];
 }
 public static function acknowledge_worker($path,$status,$detail=''): array {
  if (!self::scope() || !defined('WP_CLI') || !WP_CLI)return ['ok'=>false,'error'=>'local_cli_required'];
  $path=self::path($path);$status=sanitize_key((string)$status);
  if($path==='' || !in_array($status,['certified','hold','native','failed'],true))return ['ok'=>false,'error'=>'bad_event'];
  $queue=array_values(array_filter(self::queue(),static fn($j)=>($j['path']??'')!==$path));
  update_option(self::QUEUE,$queue,false);
  $type=$status==='certified'?'certified':($status==='failed'?'error':'hold');
  $sev=$status==='certified'?'success':($status==='failed'?'critical':'warning');
  $result=self::emit($type,$sev,$path,'Adaptive CSS '.$status.': '.substr(sanitize_text_field((string)$detail),0,120),['digest'=>$status]);
  return ['ok'=>true,'status'=>$status,'event'=>$result];
 }
 public static function post_saved($id,$post,$update): void {
  if (!$update || wp_is_post_revision($id) || !($post instanceof WP_Post) || $post->post_status!=='publish')return;
  if (!in_array($post->post_type,['page','elementor_library','tours-and-activities','tours_and_activities'],true))return;
  $path=$post->post_type==='elementor_library'?'/':(string)wp_parse_url(get_permalink($id),PHP_URL_PATH);
  self::mark_dirty('wp_content_saved',$path);
 }
 public static function elementor_saved($document,$data): void {
  $id=method_exists($document,'get_main_id')?(int)$document->get_main_id():0;
  if (!$id) return;
  $post=get_post($id);$path=$post && $post->post_type!=='elementor_library'?(string)wp_parse_url(get_permalink($id),PHP_URL_PATH):'/';
  self::mark_dirty('elementor_document_saved',$path);
 }
 public static function upgraded($upgrader,$hook_extra): void {
  if (!is_array($hook_extra) || !in_array($hook_extra['type']??'',['plugin','theme','core'],true)) return;
  self::mark_dirty('source_package_updated','/');
 }
 public static function option_changed($option,$old,$new): void {
  if (!in_array($option,['elementor_css_print_method','litespeed.conf.optm-css_comb','litespeed.conf.optm-css_comb_ext_inl','litespeed.conf.guest','litespeed.conf.guest_optm','litespeed.conf.cache-mobile','litespeed.conf.cache-mobile_rules','icl_sitepress_settings'],true) || $old===$new)return;
  self::mark_dirty('asset_settings_changed','/');
 }
 public static function menu_changed(): void {self::mark_dirty('navigation_updated','/');}
 public static function manifest(): array {
  if (!self::scope() || !is_readable(self::MANIFEST))return [];
  $content=@file_get_contents(self::MANIFEST);if(!is_string($content)||strlen($content)>110000)return [];
  $v=json_decode($content,true);if(!is_array($v)||($v['site_uuid']??'')!==self::UUID||($v['site_host']??'')!==self::HOST)return [];
  $rows=[];
  foreach ($v['routes']??[] as $route) {
   if (!is_array($route))continue;
   foreach (['desktop','mobile'] as $device) {
    $policy=$route['devices'][$device]??null;
    if (!is_array($policy))continue;
    $rows[]=['path'=>$route['path']??'','locale'=>$route['locale']??'',
     'device'=>$device,'state'=>$policy['status']??'native','expires'=>$policy['expires']??0,
     'count'=>is_array($policy['assets']??null)?count($policy['assets']):0,
     'reason'=>implode(', ',array_slice(is_array($policy['issues']??null)?$policy['issues']:[],0,3))];
   }
  }
  return $rows;
 }
 public static function summary(): array {
  $feed=self::notification_feed();$routes=self::manifest();$statuses=[];
  foreach($routes as $r){$s=$r['state'];$statuses[$s]=($statuses[$s]??0)+1;}
  return ['contract'=>'mad4b.adaptive-asset-intelligence.v1','environment'=>'staging',
   'site_uuid'=>self::UUID,'pending_jobs'=>count(self::queue()),'unread_notifications'=>count(array_filter($feed,static fn($e)=>empty($e['ack']))),
   'routes'=>$routes,'states'=>$statuses,'notifications'=>array_slice($feed,0,30),
   'worker'=>'vps_local_chromium','litespeed_provider'=>class_exists('MAD4B_LSC_Local_Provider_V1')?MAD4B_LSC_Local_Provider_V1::capabilities():['scope'=>'missing'],'external_delivery'=>false];
 }
 public static function rest(): void {
  register_rest_route('mad4b/v1','/adaptive-assets',['methods'=>'GET',
    'permission_callback'=>static fn()=>current_user_can('manage_options')||current_user_can('mad4b_manage_company_operations'),
    'callback'=>static fn()=>rest_ensure_response(self::summary())]);
 }
 public static function menu(): void {
  MAD4B_SCP_Admin_Route_Registry::register(self::PAGE,'manage_options');
  add_submenu_page('mad4b-control-plane','Adaptive Asset Intelligence','Adaptive Assets',
   'manage_options',self::PAGE,[__CLASS__,'render']);
 }
 public static function admin_bar($bar): void {
  if (!is_admin_bar_showing()||!current_user_can('manage_options'))return;
  $s=self::summary();$c=$s['unread_notifications'];
  if($c)$bar->add_node(['id'=>'mad4b-adaptive-css-alerts','title'=>'Adaptive CSS Alerts: '.$c,
    'href'=>admin_url('admin.php?page='.self::PAGE)]);
 }
 public static function dashboard_notice(): void {
  if (!current_user_can('manage_options')||!function_exists('get_current_screen'))return;
  $screen=get_current_screen();if(!$screen || $screen->id!=='dashboard')return;
  $s=self::summary();if(!$s['unread_notifications'])return;
  echo '<div class="notice notice-info"><p><strong>MAD4B Adaptive Assets:</strong> '.(int)$s['unread_notifications'].
   ' notifications. <a href="'.esc_url(admin_url('admin.php?page='.self::PAGE)).'">View performance alerts</a></p></div>';
 }
 public static function handle_ack(): void {
  if (!current_user_can('manage_options'))wp_die('Forbidden',403);
  check_admin_referer('mad4b_adaptive_css_ack');
  $id=isset($_POST['event_id']) && is_string($_POST['event_id'])?sanitize_text_field(wp_unslash($_POST['event_id'])):'';
  if(!preg_match('/^[a-f0-9]{64}$/D',$id))wp_die('Invalid event',400);
  $feed=self::notification_feed();foreach($feed as &$e)if(($e['id']??'')===$id)$e['ack']=true;
  unset($e);update_option(self::FEED,$feed,false);
  wp_safe_redirect(admin_url('admin.php?page='.self::PAGE));exit;
 }
 public static function render(): void {
  if(!current_user_can('manage_options'))wp_die('Forbidden',403);
  $s=self::summary();
  echo '<div class="wrap"><h1>MAD4B Adaptive Asset Intelligence</h1>';
  echo '<p>Staging only. Browser analysis and release decisions run on the local VPS Worker. No email or WhatsApp dispatch.</p>';
  $provider=$s['litespeed_provider'];
  echo '<p><strong>LiteSpeed compatibility:</strong> '.esc_html($provider['mode']??'unavailable').
    ' | CSS combining: '.esc_html($provider['css_combine_owner']??'native').
    ' | Local CSS: '.esc_html($provider['runtime_css_overlay']??'disabled').'</p>';
  echo '<p><small>QUIC.cloud CDN, image optimization, and VPI remain managed by LiteSpeed until verified local replacements exist.</small></p>';

  echo '<p><strong>Pending:</strong> '.(int)$s['pending_jobs'].' &nbsp; <strong>Unread alerts:</strong> '.(int)$s['unread_notifications'].' &nbsp; <strong>Policy routes:</strong> '.count($s['routes']).'</p>';
  echo '<h2>Page policy states</h2><table class="widefat striped"><thead><tr><th>Route</th><th>Language</th><th>Device</th><th>Status</th><th>CSS assets</th><th>Expiry UTC</th></tr></thead><tbody>';
  foreach($s['routes'] as $r)echo '<tr><td>'.esc_html($r['path']).'</td><td>'.esc_html($r['locale']).'</td><td>'.esc_html($r['device']).'</td><td>'.esc_html($r['state']).'</td><td>'.(int)$r['count'].'</td><td>'.esc_html($r['expires']?gmdate('Y-m-d H:i',(int)$r['expires']):'-').'</td></tr>';
  echo '</tbody></table><h2>Notifications</h2><table class="widefat striped"><thead><tr><th>Date (UTC)</th><th>Severity</th><th>Route</th><th>Event</th><th>Action</th></tr></thead><tbody>';
  foreach($s['notifications'] as $e){echo '<tr><td>'.esc_html(gmdate('Y-m-d H:i',(int)$e['timestamp'])).'</td><td>'.esc_html($e['severity']).'</td><td>'.esc_html($e['path']).'</td><td>'.esc_html($e['message']).'</td><td>';
   if(empty($e['ack'])){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mad4b_adaptive_assets_ack" /><input type="hidden" name="event_id" value="'.esc_attr($e['id']).'" />';wp_nonce_field('mad4b_adaptive_css_ack');submit_button('Mark read','small','submit',false);echo '</form>';}else echo 'Read';echo '</td></tr>';
  }
  echo '</tbody></table></div>';
 }
}
