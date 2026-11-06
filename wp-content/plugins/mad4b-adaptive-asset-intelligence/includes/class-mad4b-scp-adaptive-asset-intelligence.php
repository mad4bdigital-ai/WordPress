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
 const MANIFEST = WP_CONTENT_DIR . '/mu-plugins/mad4b-css-autonomous-policy.json';
 /**
  * Resolve the installed company's own identity. The WordPress home and site
  * origins must match, and this installation must own a valid Brand Context.
  * Do not read a central/Staging UUID or accept caller-supplied HTTP headers.
  */
 public static function identity(): array {
  $env=wp_get_environment_type();
  if (!in_array($env,['staging','production'],true)) return [];
  $host=wp_parse_url(home_url('/'),PHP_URL_HOST);
  $site_host=wp_parse_url(site_url('/'),PHP_URL_HOST);
  if (!is_string($host)||!is_string($site_host) || $host==='' ||
      strtolower($host)!==strtolower($site_host) ||
      !preg_match('/^[a-z0-9][a-z0-9.-]{1,251}$/D',strtolower($host))) return [];
  $profile=get_option('mad4b_scp_brand_context_profile_v1',[]);
  if (!is_array($profile))return [];
  $uuid=(string)($profile['site_uuid']??'');
  $brand=(string)($profile['brand_id']??'');
  if (!preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D',$uuid) ||
      !preg_match('/^[0-9a-f]{32}$/D',$brand)) return [];
  return ['uuid'=>$uuid,'host'=>strtolower($host),'environment'=>$env,'brand_id'=>$brand];
 }
 public static function scope(): bool {return self::identity()!==[];}
 public static function uuid(): string {return self::identity()['uuid']??'';}
 public static function host(): string {return self::identity()['host']??'';}
 public static function environment(): string {return self::identity()['environment']??'';}
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
  foreach (['added_post_meta','updated_post_meta','deleted_post_meta'] as $meta_hook) {
   add_action($meta_hook,static function($meta_id,$post_id,$key){
    if (in_array($key,['_elementor_data','_elementor_page_settings'],true)
        && class_exists('MAD4B_SCP_Adaptive_Presentation_Discovery',false))
     MAD4B_SCP_Adaptive_Presentation_Discovery::queue_refresh();
   },40,3);
  }

  add_action('admin_post_mad4b_adaptive_presentation_scan',[__CLASS__,'handle_presentation_scan']);
  add_action('mad4b_adaptive_presentation_scan',[__CLASS__,'run_presentation_scan']);

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
  $fingerprint=hash('sha256',self::uuid().'|'.$type.'|'.$path.'|'.(string)($context['digest']??''));
  foreach ($feed as $existing) {
   if (($existing['fingerprint']??'')===$fingerprint && time()-(int)($existing['timestamp']??0)<3600)
    return ['stored'=>false,'deduplicated'=>true];
  }
  $evt=['id'=>hash('sha256',$fingerprint.'|'.time()),'fingerprint'=>$fingerprint,'type'=>$type,
   'severity'=>$severity,'path'=>$path,'message'=>sanitize_text_field(substr($message,0,200)),
   'timestamp'=>time(),'ack'=>false,'site_uuid'=>self::uuid()];
  array_unshift($feed,$evt);update_option(self::FEED,array_slice($feed,0,100),false);
  // Canonical event contract for additional MAD4B notification consumers; no email/WhatsApp send here.
  do_action('mad4b/notifications/recorded',['contract'=>'mad4b.notification.event.v1',
   'source'=>'adaptive_asset_intelligence','site_uuid'=>self::uuid(),'environment'=>self::environment(),
   'event_id'=>$evt['id'],'type'=>$type,'severity'=>$severity,'path'=>$path,
   'summary'=>$evt['message'],'recorded_at'=>gmdate('c',$evt['timestamp']),'external_delivery'=>false]);
  return ['stored'=>true,'event_id'=>$evt['id']];
 }
 public static function mark_dirty($reason='content_change',$path='/'): array {
  if (!self::scope())return ['queued'=>false];
  $path=self::path($path);if ($path==='')$path='/';
  $now=time();$queue=self::queue();$id=hash('sha256',self::uuid().'|'.$reason.'|'.$path);
  $deduplicated=false;
  foreach($queue as $i=>$job){
   if (($job['id']??'')===$id){
    $queue[$i]['updated']=$now;
    $deduplicated=true;
    break;
   }
  }
  if (!$deduplicated)
   array_unshift($queue,['id'=>$id,'reason'=>sanitize_key($reason),'path'=>$path,'updated'=>$now,'state'=>'pending']);
  update_option(self::QUEUE,array_slice($queue,0,50),false);
  // Even a deduplicated pending Job represents NEW source drift. Always bump
  // the CSS epoch and queue discovery; previous early return lost this signal.
  // Fail closed for any structural change until the Worker recertifies the route(s).
  $epoch=max(1,(int)get_option(self::EPOCH,1))+1;
  update_option(self::EPOCH,$epoch,false);
  self::emit('queued','info',$path,'CSS verification queued after '.sanitize_key($reason),['digest'=>(string)$epoch]);
  if (class_exists('MAD4B_SCP_Adaptive_Presentation_Discovery',false))
   MAD4B_SCP_Adaptive_Presentation_Discovery::queue_refresh();

  return ['queued'=>true,'deduplicated'=>$deduplicated,'revision'=>$epoch];
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
  // Any public content type can own Elementor condition trees in any company.
  if ($post->post_type!=='elementor_library') {
   $object=get_post_type_object($post->post_type);
   if (!$object || empty($object->public))return;
  }
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
  if (!in_array($option,['elementor_css_print_method','litespeed.conf.optm-css_comb','litespeed.conf.optm-css_comb_ext_inl','litespeed.conf.guest','litespeed.conf.guest_optm','litespeed.conf.cache-mobile','litespeed.conf.cache-mobile_rules','litespeed.conf.optm-ucss','litespeed.conf.optm-css_async','litespeed.conf.optm-js_comb','litespeed.conf.optm-js_comb_ext_inl','litespeed.conf.optm-js_defer','litespeed.conf.optm-js_min','litespeed.conf.optm-js_exc','litespeed.conf.optm-js_defer_exc','litespeed.conf.media-vpi','litespeed.conf.img_optm-auto','litespeed.conf.cdn-quic','icl_sitepress_settings'],true) || $old===$new)return;
  self::mark_dirty('asset_settings_changed','/');
 }
 public static function menu_changed(): void {self::mark_dirty('navigation_updated','/');}

 public static function run_presentation_scan(): void {
  if (!self::scope() || !class_exists('MAD4B_SCP_Adaptive_Presentation_Discovery',false))return;
  $result=MAD4B_SCP_Adaptive_Presentation_Discovery::scan();
  if (($result['state']??'')==='partial')
   self::emit('hold','warning','/','Presentation discovery was partial; review local source inventory.',
      ['digest'=>(string)($result['scanned_at']??'')]);
 }
 public static function handle_presentation_scan(): void {
  if (!current_user_can('manage_options'))wp_die('Forbidden',403);
  check_admin_referer('mad4b_adaptive_presentation_scan');
  self::run_presentation_scan();
  wp_safe_redirect(admin_url('admin.php?page='.self::PAGE));exit;
 }
 public static function manifest(): array {
  if (!self::scope() || !is_readable(self::MANIFEST))return [];
  $content=@file_get_contents(self::MANIFEST);if(!is_string($content)||strlen($content)>110000)return [];
  $v=json_decode($content,true);if(!is_array($v)||($v['site_uuid']??'')!==self::uuid()||($v['site_host']??'')!==self::host())return [];
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
 /**
  * The local Worker reports only its own company-scoped heartbeat. No worker
  * execution is inferred from shell access, a plugin activation or an empty
  * route manifest. Stale and cross-tenant heartbeats do not count.
  */
 public static function worker_state(): string {
  $identity=self::identity();
  if(!$identity)return 'not_configured';
  $w=get_option('mad4b_adaptive_asset_worker_heartbeat_v1',[]);
  $at=is_array($w)?(int)($w['last_run']??0):0;
  if (is_array($w) && ($w['site_uuid']??'')===$identity['uuid']
   && ($w['site_host']??'')===$identity['host']
   && ($w['status']??'')==='active'
   && $at>=time()-3600 && $at<=time()+120) return 'vps_local_chromium';
  return self::manifest()?'vps_local_chromium':'not_configured';
 }
 public static function summary(): array {
  $feed=self::notification_feed();$routes=self::manifest();$statuses=[];
  foreach($routes as $r){$s=$r['state'];$statuses[$s]=($statuses[$s]??0)+1;}
  return ['contract'=>'mad4b.adaptive-asset-intelligence.v1','environment'=>self::environment(),
   'site_uuid'=>self::uuid(),'pending_jobs'=>count(self::queue()),'unread_notifications'=>count(array_filter($feed,static fn($e)=>empty($e['ack']))),
   'routes'=>$routes,'states'=>$statuses,'notifications'=>array_slice($feed,0,30),
   'presentation_discovery'=>class_exists('MAD4B_SCP_Adaptive_Presentation_Discovery',false)?MAD4B_SCP_Adaptive_Presentation_Discovery::status():['state'=>'provider_unavailable'],
   'predictive_css'=>class_exists('MAD4B_Predictive_CSS_Plan_V2',false)?MAD4B_Predictive_CSS_Plan_V2::summary():['present'=>false,'runtime'=>'off','routes'=>[]],
   'js_policies'=>class_exists('MAD4B_Device_JS_Combine_V1',false)?MAD4B_Device_JS_Combine_V1::overview():[],
   'js_cpu'=>class_exists('MAD4B_JS_CPU_Intelligence',false)?MAD4B_JS_CPU_Intelligence::summary():['rows'=>[], 'pending_routes'=>0],
   'worker'=>self::worker_state(),'litespeed_provider'=>class_exists('MAD4B_LSC_Local_Provider_V1')?MAD4B_LSC_Local_Provider_V1::capabilities():['scope'=>'missing'],'external_delivery'=>false];
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
  echo '<p>Company-scoped Staging/Production. The native WordPress source only queues and reports; browser analysis requires a local VPS Worker. No unsolicited external notification delivery.</p>';
  $provider=$s['litespeed_provider'];
  echo '<p><strong>LiteSpeed compatibility:</strong> '.esc_html($provider['mode']??'unavailable').
    ' | CSS combining: '.esc_html($provider['css_combine_owner']??'native').
    ' | Local CSS: '.esc_html($provider['runtime_css_overlay']??'disabled').'</p>';
  echo '<p><small>Cloud CDN, image optimization and VPI follow each company-specific cache/provider configuration; the module does not enable remote services.</small></p>';

  echo '<p><strong>Pending:</strong> '.(int)$s['pending_jobs'].' &nbsp; <strong>Unread alerts:</strong> '.(int)$s['unread_notifications'].' &nbsp; <strong>Policy routes:</strong> '.count($s['routes']).'</p>';

  $discovery=$s['presentation_discovery']??[];
  $counts=$discovery['counts']??[];
  echo '<h2>Predictive Rendering &amp; Preload</h2>';
  echo '<p>Local company-scoped discovery of Elementor widget trees, JetEngine visibility rules, data-query dependencies and declared CSS/JS handles. This is pre-calculation and a read-only load plan; visitor-specific pricing and native condition evaluation remain live.</p>';
  echo '<p><strong>Discovery state:</strong> '.esc_html($discovery['state']??'not_scanned').
       ' &nbsp; <strong>Snapshot UTC:</strong> '.esc_html(!empty($discovery['scanned_at'])?gmdate('Y-m-d H:i',(int)$discovery['scanned_at']):'not yet').
       ' &nbsp; <strong>Company:</strong> '.esc_html(self::host()).'</p>';
  echo '<table class="widefat striped"><thead><tr><th>Documents</th><th>Elements</th><th>Widgets</th><th>Conditional elements</th><th>Rules</th><th>Dynamic bindings</th><th>Query dependencies</th></tr></thead><tbody><tr>';
  foreach(['documents','nodes','widgets','conditional_elements','rules','dynamic_bindings','query_dependencies'] as $key)
   echo '<td>'.(int)($counts[$key]??0).'</td>';
  echo '</tr></tbody></table>';
  echo '<p><strong>JetEngine condition types discovered:</strong> '.(int)($discovery['registered_condition_types']??0).
       ' &nbsp; <strong>Unrecognized condition types:</strong> '.count($discovery['unknown_condition_types']??[]).
       ' &nbsp; <strong>Widgets needing runtime asset context:</strong> '.count($discovery['unknown_widget_types']??[]).
       ' &nbsp; <strong>Widget asset profiles:</strong> '.count($discovery['asset_dependencies_by_widget']??[]).'</p>';
  echo '<p>Pre-calculation: document graph indexed. Pre-page-load: asset dependency candidates inventoried. Render optimization: native visibility conditions respected; no unsafe early skipping or personalized-price caching.</p>';
  $cssPlan=$s['predictive_css']??[];
  echo '<h3>Predictive Critical CSS — Responsive Device Plans</h3>';
  echo '<p><strong>Runtime:</strong> '.esc_html((string)($cssPlan['runtime']??'off')).
    ' &nbsp; <strong>Release:</strong> '.esc_html((string)($cssPlan['release']??'none')).
    ' &nbsp; <strong>Verified CSS bundle variants:</strong> '.(int)($cssPlan['bundle_variants']??0).'</p>';
  echo '<p>Per-route device profiles use browser-measured first-screen rules and the native LiteSpeed async library. Activation requires matching company, WPML language, CSS content SHA256, Elementor rendered document set, discovery revision and device. Unknown conditional widgets, stale plans or invalid source files retain the full native CSS.</p>';
  echo '<table class="widefat striped"><thead><tr><th>Route</th><th>Locale</th><th>Viewport</th><th>State</th><th>Strategy</th><th>Critical CSS gzip</th><th>Elementor documents</th><th>LCP gate</th><th>Native LCP</th><th>Async LCP</th><th>Source SHA</th></tr></thead><tbody>';
  foreach((array)($cssPlan['routes']??[])as$r){
    echo '<tr><td>'.esc_html((string)($r['path']??'')).'</td><td>'.esc_html((string)($r['locale']??'')).
      '</td><td>'.esc_html((string)($r['device']??'')).'</td><td>'.esc_html((string)($r['status']??'')).
      '</td><td>'.esc_html((string)($r['strategy']??'')).'</td><td>'.(int)($r['critical_gzip_bytes']??0).
      ' B</td><td>'.(int)($r['template_count']??0).'</td><td>'.esc_html((string)($r['performance_gate']??'unverified')).
      '</td><td>'.(int)($r['baseline_lcp_ms']??0).' ms</td><td>'.(int)($r['async_lcp_ms']??0).
      ' ms</td><td><code>'.esc_html((string)($r['bundle_sha']??'')).'</code></td></tr>';
  }
  echo '</tbody></table>';
  if(empty($cssPlan['routes'])) echo '<p>No certified or test-only responsive CSS plans are available for this company yet.</p>';

  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
  echo '<input type="hidden" name="action" value="mad4b_adaptive_presentation_scan" />';
  wp_nonce_field('mad4b_adaptive_presentation_scan');
  submit_button('Refresh presentation discovery','secondary','submit',false);
  echo '</form>';

  $priority=$discovery['theme_builder_priority']??[];
  echo '<h3>Theme Builder: template priority and top-to-bottom order</h3>';
  echo '<p>Header P0, applicable Single/Archive P1, internal elements P2 in Elementor tree order, Footer P3, Popups P4. Page-specific template selection still follows Elementor Pro and WPML. Responsive visual Y must be measured in the browser.</p>';
  echo '<p><strong>Theme templates:</strong> '.(int)($priority['published_theme_builder_templates']??0).
       ' &nbsp; <strong>Assigned:</strong> '.(int)($priority['assigned_templates']??0).
       ' &nbsp; <strong>WPML variants:</strong> '.(int)($priority['translation_variants']??0).
       ' &nbsp; <strong>Unassigned:</strong> '.(int)($priority['unassigned_templates']??0).'</p>';
  $providers=(array)($priority['provider_discovery']??[]);
  echo '<p><strong>JetEngine visibility enabled:</strong> '.(int)($providers['jet_visibility_active']??0).
       ' &nbsp; <strong>JetSmartFilters widgets:</strong> '.(int)($providers['smart_filter_widgets']??0).
       ' &nbsp; <strong>JetEngine listings:</strong> '.(int)($providers['listing_widgets']??0).
       ' &nbsp; <strong>Query Builder references:</strong> '.(int)($providers['query_builder_bindings']??0).
       ' &nbsp; <strong>Inactive visibility configurations:</strong> '.(int)($providers['jet_visibility_inactive_config']??0).'</p>';
  $full=(array)get_option('mad4b_adaptive_theme_builder_priority_v1',[]);
  $all=(array)($full['templates']??[]);
  $chosen=absint($_GET['theme_document']??0);
  if($chosen)foreach($all as $item) {
   if((int)($item['document_id']??0)!==$chosen)continue;
   echo '<h4>Element hierarchy: '.esc_html($item['name']).' ('.esc_html($item['priority']).')</h4>';
   echo '<p>The dotted numbering follows the stored Elementor container tree, not the actual on-screen position of a responsive widget.</p>';
   echo '<table class="widefat striped"><thead><tr><th>Tree position</th><th>Element</th><th>Provider and conditions</th><th>Query Builder ID</th><th>Nested listing/template</th></tr></thead><tbody>';
   foreach((array)$item['top_to_bottom'] as $entry) {
    $signals=implode(', ',(array)($entry['provider_signals']??[]));
    if(!empty($entry['filter_provider']))$signals.=' | '.(string)$entry['filter_provider'].' / '.(string)$entry['filter_target_query_key'];
    echo '<tr><td>'.esc_html($entry['order']??'').'</td><td>'.esc_html($entry['type']??'').
     '</td><td>'.esc_html($signals).'</td><td>'.(int)($entry['query_dependency']??0).
     '</td><td>'.(int)($entry['template_dependency']??0).'</td></tr>';
   }
   echo '</tbody></table>';
   if(!empty($item['preview_truncated']))echo '<p>Additional nodes require more focused inspection.</p>';
   break;
  }
  echo '<table class="widefat striped"><thead><tr><th>Priority</th><th>Theme Builder document</th><th>Language</th><th>Assignment</th><th>Nodes</th><th>Tree</th></tr></thead><tbody>';
  $displayed=0;
  foreach($all as $item) {
   if(($item['assignment']??'unassigned')==='unassigned')continue;
   if(++$displayed>20)break;
   $url=add_query_arg(['page'=>self::PAGE,'theme_document'=>(int)$item['document_id']],admin_url('admin.php'));
   echo '<tr><td>'.esc_html($item['priority']).'</td><td>'.esc_html($item['name']).
      '</td><td>'.esc_html($item['language']).'</td><td>'.esc_html($item['assignment']).
      '</td><td>'.(int)$item['node_total'].'</td><td><a href="'.esc_url($url).'">Inspect</a></td></tr>';
  }
  echo '</tbody></table>';
  $network=(array)get_option('mad4b_network_dependency_graph_v1',[]);
  echo '<h2>Network Dependency Tree: native asset handles</h2>';
  echo '<p>This dynamic source-local graph records WordPress handles and their declared dependencies by rendered route, WPML language and published document. It does not infer network latency from PHP or bypass Elementor, JetEngine, WooCommerce, or native visibility rules.</p>';
  echo '<p><strong>Unused JetWoo gallery Swiper policy:</strong> '.esc_html((string)get_option('mad4b_network_dependency_policy_v1','off')).
    ' &nbsp; <strong>Observed route variants:</strong> '.count($network).
    ' &nbsp; <strong>Visitor preference batch:</strong> '.esc_html((string)get_option('mad4b_visitor_preference_batch_v1','off')).'</p>';
  echo '<table class="widefat striped"><thead><tr><th>Route</th><th>Language</th><th>JS Handles</th><th>CSS Handles</th><th>Max dependency depth</th><th>Gallery proof</th><th>Asset decision</th><th>Longest script handles</th><th>UTC</th></tr></thead><tbody>';
  foreach(array_slice($network,0,15)as$r){
    echo '<tr><td>'.esc_html(($r['route']??'').(isset($r['document_id'])?' #'.(int)$r['document_id']:'')) .
      '</td><td>'.esc_html($r['language']??'').'</td><td>'.(int)($r['scripts_queued']??0).
      '</td><td>'.(int)($r['styles_queued']??0).'</td><td>'.(int)($r['max_registered_dependency_depth']??0).
      '</td><td>'.esc_html($r['gallery_inventory']??'').'</td><td>'.esc_html($r['policy']??'').
      '</td><td>'.esc_html(implode(', ',array_map(static fn($h)=>($h['handle']??'').':'.(int)($h['depth']??0),array_slice((array)($r['long_chains']??[]),0,6)))).
      '</td><td>'.esc_html(!empty($r['observed_at'])?gmdate('Y-m-d H:i',(int)$r['observed_at']):'').'</td></tr>';
  }
  echo '</tbody></table>';
  if(!$network) echo '<p>No dynamic graph observed yet. Visit a public uncached page to allow company-local discovery.</p>';
  echo '<h2>JavaScript device policies</h2><p>LiteSpeed JS Combine only. Global defer/delay remains off; full inline combination requires independent acceptance.</p>';
  echo '<table class="widefat striped"><thead><tr><th>Route</th><th>Locale</th><th>Device</th><th>State</th><th>JS mode</th><th>Past trials</th></tr></thead><tbody>';
  foreach($s['js_policies'] as $j)echo '<tr><td>'.esc_html($j['path']).'</td><td>'.esc_html($j['locale']).'</td><td>'.esc_html($j['device']).'</td><td>'.esc_html($j['status']).'</td><td>'.esc_html($j['mode']).'</td><td>'.(int)$j['trials'].'</td></tr>';
  echo '</tbody></table>';
  echo '<h2>JavaScript CPU Intelligence</h2><p>Per-device CPU attribution and read-only experiment suggestions. Native LiteSpeed remains active until the route/device five-pair and interaction gates pass.</p>';
  echo '<p><strong>Queued CPU rechecks:</strong> '.(int)($s['js_cpu']['pending_routes']??0).'</p>';
  echo '<table class="widefat striped"><thead><tr><th>Route</th><th>Device</th><th>CPU samples</th><th>State</th><th>Next experiment</th><th>Measured UTC</th></tr></thead><tbody>';
  foreach(($s['js_cpu']['rows']??[]) as $j){
   echo '<tr><td>'.esc_html($j['path']).'</td><td>'.esc_html($j['device']).'</td><td>'.(int)$j['samples'].'</td><td>'.esc_html($j['state']).'</td><td>'.esc_html($j['recommendation']).'</td><td>'.esc_html($j['measured_at']?gmdate('Y-m-d H:i',(int)$j['measured_at']):'-').'</td></tr>';
  }
  echo '</tbody></table>';
  echo '<h2>Page policy states</h2><table class="widefat striped"><thead><tr><th>Route</th><th>Language</th><th>Device</th><th>Status</th><th>CSS assets</th><th>Expiry UTC</th></tr></thead><tbody>';
  foreach($s['routes'] as $r)echo '<tr><td>'.esc_html($r['path']).'</td><td>'.esc_html($r['locale']).'</td><td>'.esc_html($r['device']).'</td><td>'.esc_html($r['state']).'</td><td>'.(int)$r['count'].'</td><td>'.esc_html($r['expires']?gmdate('Y-m-d H:i',(int)$r['expires']):'-').'</td></tr>';
  echo '</tbody></table><h2>Notifications</h2><table class="widefat striped"><thead><tr><th>Date (UTC)</th><th>Severity</th><th>Route</th><th>Event</th><th>Action</th></tr></thead><tbody>';
  foreach($s['notifications'] as $e){echo '<tr><td>'.esc_html(gmdate('Y-m-d H:i',(int)$e['timestamp'])).'</td><td>'.esc_html($e['severity']).'</td><td>'.esc_html($e['path']).'</td><td>'.esc_html($e['message']).'</td><td>';
   if(empty($e['ack'])){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mad4b_adaptive_assets_ack" /><input type="hidden" name="event_id" value="'.esc_attr($e['id']).'" />';wp_nonce_field('mad4b_adaptive_css_ack');submit_button('Mark read','small','submit',false);echo '</form>';}else echo 'Read';echo '</td></tr>';
  }
  echo '</tbody></table></div>';
 }
}
