<?php
defined('ABSPATH') || exit;
/** Review-only crawl polling and ContentHub page inventory for enrolled staging. */
final class MAD4B_SCP_Firecrawl_Crawl_Integration_V1 {
 private static function enrolled_crawl_site():bool {
  if(!class_exists('MAD4B_SCP_Site_Profile',false))return false;
  $s=MAD4B_SCP_Site_Profile::status();
  return is_array($s) && ($s['configured']??false)===true
   && ($s['origin_match']??false)===true
   && ($s['deployment_binding_match']??false)===true
   && ($s['environment_match']??false)===true
   && ($s['write_enabled']??false)===true;
 }
 
 const HOOK='mad4b_firecrawl_crawl_poll_v1';
 const SCHEDULE='mad4b_firecrawl_five_minutes_v1';
 const MENU='mad4b-firecrawl-crawl-pages';
 private static $booted=false;
 public static function boot(){
  if(self::$booted)return;
  self::$booted=true;
  add_filter('cron_schedules',[__CLASS__,'schedules']);
  add_action('init',[__CLASS__,'ensure_schedule'],99);
  add_action(self::HOOK,[__CLASS__,'poll']);
  add_action('admin_menu',[__CLASS__,'menu'],99);
 }
 public static function schedules($schedules){
  $schedules[self::SCHEDULE]=['interval'=>300,'display'=>'MAD4B Firecrawl crawl status (5m)'];
  return $schedules;
 }
 public static function ensure_schedule(){
  if(!self::enrolled_crawl_site())return;
  if(!wp_next_scheduled(self::HOOK))
   wp_schedule_event(time()+90,self::SCHEDULE,self::HOOK);
 }
 public static function poll(){
  if(!self::enrolled_crawl_site())return;
  $runs=get_option('mad4b_firecrawl_owned_crawl_runs_v1',[]);
  if(!is_array($runs))return;
  // Only prior owner-started exact crawls. Never start new crawls or publish.
  $count=0;
  foreach($runs as $id=>$run){
   if($count>=3)break;
   if(!is_array($run)||!empty($run['detached_test'])||
    ($run['review_only']??false)!==true||
    !preg_match('/^[a-zA-Z0-9-]{16,72}$/D',(string)$id))continue;
   if(in_array($run['state']??'',['completed','failed','cancelled'],true)
      && empty($run['next_url']))continue;
   if(empty($run['source_url'])||
    wp_parse_url($run['source_url'],PHP_URL_HOST)!==
    wp_parse_url(home_url(),PHP_URL_HOST))continue;
   $count++;
   $result=MAD4B_SCP_Firecrawl_Provider::owned_crawl_status($id,true);
   if(is_wp_error($result)){
    $logs=get_option('mad4b_firecrawl_crawl_poll_errors_v1',[]);
    if(!is_array($logs))$logs=[];
    $logs[$id]=['error'=>$result->get_error_code(),'at'=>gmdate('c')];
    if(count($logs)>30)$logs=array_slice($logs,-30,null,true);
    update_option('mad4b_firecrawl_crawl_poll_errors_v1',$logs,false);
   }
  }
 }
 public static function menu(){
  add_submenu_page('mad4b-control-plane','Crawl Pages','Crawl Pages',
    'manage_options',self::MENU,[__CLASS__,'render']);
 }
 public static function render(){
  if(!current_user_can('manage_options'))wp_die('Administrator required.');
  echo '<div class="wrap"><h1>Firecrawl — Content Hub Crawl Pages</h1>';
  echo '<p>Review-only capture inventory. No automatic publishing. Multi-page capture respects source robots rules.</p>';
  $runs=get_option('mad4b_firecrawl_owned_crawl_runs_v1',[]);
  if(!is_array($runs))$runs=[];
  echo '<h2>Owned crawl runs</h2><table class="widefat striped"><thead><tr><th>Crawl ID</th><th>Mode</th><th>Status</th><th>Content Job</th><th>Page versions</th></tr></thead><tbody>';
  foreach(array_reverse($runs,true) as $id=>$run){
   if(!is_array($run))continue;
   $summary=MAD4B_SCP_Firecrawl_Page_Store::summary($id);
   $count=is_wp_error($summary)?'Unavailable':(string)($summary['stored_page_versions']??0);
   echo '<tr><td><code>'.esc_html($id).'</code></td><td>'.esc_html($run['mode']??'').'</td><td>'.
    esc_html($run['state']??'unknown').'</td><td><code>'.esc_html($run['job_id']??'').'</code></td><td>'.
    esc_html($count).'</td></tr>';
  }
  echo '</tbody></table>';
  $job=isset($_GET['crawl_job_id'])?sanitize_text_field(wp_unslash($_GET['crawl_job_id'])):'';
  echo '<h2>Content Hub job inventory</h2><form method="get" action="'.esc_url(admin_url('admin.php')).'">';
  echo '<input type="hidden" name="page" value="'.esc_attr(self::MENU).'">';
  echo '<label for="crawl_job_id">Content Job UUID</label> <input id="crawl_job_id" name="crawl_job_id" class="regular-text" value="'.esc_attr($job).'">';
  echo ' <button class="button" type="submit">Inspect pages</button></form>';
  if($job!==''){
   $data=MAD4B_SCP_Firecrawl_Page_Store::list_by_job($job);
   if(is_wp_error($data))echo '<div class="notice notice-warning"><p>'.esc_html($data->get_error_code()).'</p></div>';
   else {
    echo '<p>Page versions: '.esc_html((string)$data['count']).' — review only</p>';
    echo '<table class="widefat striped"><thead><tr><th>Source</th><th>Crawl</th><th>SHA-256</th><th>Captured</th></tr></thead><tbody>';
    foreach($data['pages'] as $page){
     $view=add_query_arg(['page'=>self::MENU,
       'crawl_job_id'=>$job,'crawl_page_id'=>(int)$page['id']],admin_url('admin.php'));
     echo '<tr><td><a href="'.esc_url($view).'">'.esc_html($page['source_url']).'</a></td><td><code>'.esc_html($page['crawl_id']).'</code></td><td><code>'.esc_html($page['content_sha256']).'</code></td><td>'.esc_html($page['updated_at']).'</td></tr>';
    }
    echo '</tbody></table>';
    $page_id=isset($_GET['crawl_page_id'])?absint($_GET['crawl_page_id']):0;
    if($page_id>0){
     $entry=MAD4B_SCP_Firecrawl_Page_Store::read_by_job($job,$page_id);
     if(is_wp_error($entry))echo '<p>'.esc_html($entry->get_error_code()).'</p>';
     else {
      echo '<h3>Stored Markdown — '.esc_html($entry['page']['source_url']).'</h3>';
      echo '<p>SHA-256 verified. Evidence only; no publish action.</p>';
      echo '<pre style="max-height:600px;overflow:auto;white-space:pre-wrap;background:#fff;padding:12px;border:1px solid #ddd">'.esc_html($entry['page']['markdown']).'</pre>';
     }
    }
   }
  }
  echo '</div>';
 }
}
