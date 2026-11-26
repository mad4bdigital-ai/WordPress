<?php
defined('ABSPATH')||exit;
final class MAD4B_Cache_Lifecycle_Control_V1 {
 const HOOK='mad4b_cache_lifecycle_verify_pending_v1';
 public static function boot(){
  add_filter('cron_schedules',static function($s){$s['mad4b_cache_five_minutes']=['interval'=>300,'display'=>'MAD4B cache verification'];return $s;});
  add_action(self::HOOK,[__CLASS__,'drain']);
  add_action('init',[__CLASS__,'native_web_cdn_refresh'],30);
  add_action('init',static function(){if(!wp_next_scheduled(self::HOOK))wp_schedule_event(time()+120,'mad4b_cache_five_minutes',self::HOOK);});
  add_filter('mad4b_scp_dynamic_provider_manifests',[__CLASS__,'manifest'],25);
  add_action('admin_menu',[__CLASS__,'menu'],45);
  add_action('wp_abilities_api_categories_init',static function(){if(function_exists('wp_register_ability_category'))wp_register_ability_category('mad4b-cache',['label'=>'MAD4B Cache','description'=>'Cache lifecycle status and verification receipts']);});
  add_action('wp_abilities_api_init',[__CLASS__,'abilities'],30);
  add_action('rest_api_init',static function(){register_rest_route('mad4b/v1','/cache-lifecycle/status',['methods'=>'GET','permission_callback'=>static fn()=>current_user_can('manage_options'),'callback'=>static function(){$r=new WP_REST_Response(self::status());$r->header('Cache-Control','private, no-store');return $r;}]);});
  if(defined('WP_CLI')&&WP_CLI){
   WP_CLI::add_command('mad4b cache-lifecycle pricing-acceptance',static function(){if(wp_get_environment_type()!=='staging')WP_CLI::error('STAGING_REQUIRED');require dirname(__DIR__).'/pricing-cache-acceptance.php';});
   WP_CLI::add_command('mad4b cache-lifecycle status',static function(){WP_CLI::line(wp_json_encode(self::status()));});
   WP_CLI::add_command('mad4b cache-lifecycle drain',static function(){WP_CLI::line(wp_json_encode(self::drain()));});
   WP_CLI::add_command('mad4b cache-lifecycle configure',static function(){WP_CLI::line(wp_json_encode(self::activate()));});
  }
 }


 public static function request_native_cdn_refresh($reason){
  if($reason!=='policy_migration')return ['state'=>'INVALID_REASON'];
  $last=get_option('mad4b_cache_lifecycle_cdn_refresh_v1',[]);
  if(($last['created']??0)>time()-HOUR_IN_SECONDS)return ['state'=>'COOLDOWN_OR_PENDING'];
  $job=['id'=>wp_generate_uuid4(),'state'=>'PENDING_WEB_DELIVERY','created'=>time(),'reason'=>'policy_migration','attempts'=>0];
  update_option('mad4b_cache_lifecycle_cdn_refresh_v1',$job,false);return ['state'=>'QUEUED_NATIVE_HOSTINGER_CDN_REFRESH'];
 }
 public static function native_web_cdn_refresh(){
  // Hostinger's platform eligibility is evaluated by its own native code in a
  // real web request. CLI never fabricates H_PLATFORM or any provider identity.
  if(empty($_SERVER['H_PLATFORM'])||(defined('WP_CLI')&&WP_CLI))return;
  $key='mad4b_cache_lifecycle_cdn_refresh_v1';$job=get_option($key,[]);
  if(($job['state']??'')!=='PENDING_WEB_DELIVERY'||($job['attempts']??0)!==0)return;
  global $wp_filter;$hook=$wp_filter['litespeed_purged_all']??null;$callback=null;
  if(is_object($hook))foreach($hook->callbacks as $callbacks)foreach($callbacks as $item){$cb=$item['function']??null;
   if(is_array($cb)&&isset($cb[0],$cb[1])&&is_object($cb[0])&&is_a($cb[0],'Hostinger\\Cdn\\LiteSpeedHooks')&&$cb[1]==='purge_cdn_cache')$callback=$cb;
  }
  if(!$callback)return;
  if(!add_option('mad4b_cache_lifecycle_cdn_refresh_lock_v1',time(),'','no'))return;
  try{$job['attempts']=1;$job['state']='NATIVE_CALL_STARTED';update_option($key,$job,false);
   call_user_func($callback);$job['state']='NATIVE_CALL_DELIVERED_VERIFICATION_REQUIRED';$job['delivered_utc']=gmdate('c');update_option($key,$job,false);
  }catch(Throwable $e){$job['state']='NATIVE_CALL_FAILED';update_option($key,$job,false);}
  finally{delete_option('mad4b_cache_lifecycle_cdn_refresh_lock_v1');}
 }
 public static function abilities(){
  if(!function_exists('wp_register_ability'))return;
  foreach(['mad4b/cache-lifecycle-status'=>[__CLASS__,'status'],'mad4b/cache-lifecycle-fleet'=>[__CLASS__,'fleet']] as $name=>$callback){
   if(function_exists('wp_has_ability')&&wp_has_ability($name))continue;
   wp_register_ability($name,['label'=>str_replace('-',' ',substr($name,6)),'description'=>'Read-only cache lifecycle status. A receipt reports exactly the observed origin and environment.',
    'category'=>'mad4b-cache','permission_callback'=>static fn()=>current_user_can('manage_options'),'execute_callback'=>static fn($input=[])=>call_user_func($callback),
    'input_schema'=>['type'=>'object','properties'=>[],'additionalProperties'=>false],
    'output_schema'=>['type'=>'object','additionalProperties'=>true],
    'meta'=>['show_in_rest'=>false,'mcp'=>['public'=>false,'surface'=>'read','type'=>'tool'],'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]]]);
  }
 }
 public static function fleet(){
  $rows=get_option('mad4b_cache_lifecycle_fleet_v1',[]);$now=time();
  foreach($rows as &$row){$when=strtotime($row['observed_utc']??'');$row['stale']=!$when||$now-$when>5400;if($row['stale'])$row['state']='STALE';}unset($row);
  return ['contract'=>'mad4b.cache-lifecycle-fleet.v1','companies'=>array_values($rows)];
 }
 public static function ingest_fleet($rows){
  if(!class_exists('MAD4B_Company_Environment_Registry_V2',false)||!is_array($rows)||count($rows)>500)return new WP_Error('CACHE_FLEET_INVALID');
  $registry=MAD4B_Company_Environment_Registry_V2::discover();$validated=[];
  foreach($rows as $row){
   if(!is_array($row))return new WP_Error('CACHE_FLEET_ROW_INVALID');$company=$row['company_id']??'';$environment=$row['environment']??'';
   $target=$registry[$company]['environments'][$environment]??null;
   if(!is_array($target)||($row['origin']??'')!==($target['canonical_origin']??'')||!in_array($environment,['staging','production'],true)
    ||!in_array($row['state']??'', ['PASS','FAILED','UNKNOWN'],true)||!is_string($row['observed_utc']??null)||!strtotime($row['observed_utc']))return new WP_Error('CACHE_FLEET_SCOPE_MISMATCH');
   foreach(['freshness','isolation'] as $field)if(!in_array($row[$field]??'', ['PASS','FAILED','UNKNOWN'],true))return new WP_Error('CACHE_FLEET_PROOF_INVALID');
   $validated[$company.':'.$environment]=['company_id'=>$company,'environment'=>$environment,'origin'=>$row['origin'],'state'=>$row['state'],'freshness'=>$row['freshness'],'isolation'=>$row['isolation'],
    'observed_utc'=>$row['observed_utc'],'evidence_kind'=>'INDEPENDENT_SSH_HTTP_READBACK','registered_site_uuid'=>$target['site_uuid']??null,'financial_calculation_tested'=>false,'native_execution_receipt'=>false];
  }
  update_option('mad4b_cache_lifecycle_fleet_v1',$validated,false);return ['state'=>'INGESTED','count'=>count($validated)];
 }
 public static function manifest($items){
  if(!is_array($items))$items=[];$items[]=['provider_id'=>'cache_lifecycle','existing_registry_identity'=>'cache_lifecycle','implementation_version'=>MAD4B_Cache_Lifecycle_V1::VERSION,
   'supports'=>['cache_lifecycle_status','cache_targeted_invalidation','cache_freshness_verification','cache_private_context_policy'],
   'side_effects'=>[['effect_scope'=>'company_site','ownership'=>'provider','reversibility'=>'reversible','compensation_support'=>'manual','externality'=>'local']],
   'soft_elapsed_budget_ms'=>120000,'enforceable_deadline_supported'=>false];return $items;
 }
 public static function private_rule_lines(): ?array {
  $path=dirname(__DIR__).'/config/private-cache.htaccess';
  if(!is_file($path)||is_link($path)||!is_readable($path))return null;
  $block=file_get_contents($path);
  if(!is_string($block)||$block==='')return null;
  $lines=explode("\n",trim($block));
  if(array_shift($lines)!=='# BEGIN MAD4B PRIVATE CACHE CONTEXT'||array_pop($lines)!=='# END MAD4B PRIVATE CACHE CONTEXT'||!$lines)return null;
  return $lines;
 }
 public static function activate(){
  $before=get_option('mad4b_cache_lifecycle_configuration_v1',[]);
  $path=ABSPATH.'.htaccess';$result=['version'=>'1.1.0','enabled'=>true,'provider_changed'=>false,'private_rules'=>'SERVER_ADAPTER_REQUIRED'];
  if(is_file($path)&&!is_link($path)&&is_writable($path)){
   require_once ABSPATH.'wp-admin/includes/misc.php';
   $lines=self::private_rule_lines();
   $result['private_rules']=$lines===null?'CONFIG_MISSING_OR_INVALID':(insert_with_markers($path,'MAD4B PRIVATE CACHE CONTEXT',$lines)?'INSTALLED':'INSTALL_FAILED');
  }
  update_option('mad4b_cache_lifecycle_configuration_v1',$result,false);
  if(($before['version']??'')!=='1.1.0'&&class_exists('Hostinger\\Cdn\\LiteSpeedHooks'))self::request_native_cdn_refresh('policy_migration');
  return $result;
 }
 /** Independent HTTP evidence is scoped to one response, not an entire release. */
 public static function classify_http(int $code,string $expected,string $observed,
   string $control,bool $expect_absent=false): string {
  if($expect_absent&&in_array($code,[404,410],true))return 'VERIFIED';
  if($code===200&&$observed!==''&&hash_equals($expected,$observed))
    return 'VERIFIED';
  if($code===200&&$observed==='' &&
    preg_match('/(?:^|[, ])private(?:[, ]|$)/i',$control) &&
    preg_match('/(?:^|[, ])no-store(?:[, ]|$)/i',$control))
    return 'OBSERVED_NOT_CACHEABLE';
  return 'VERIFY_FAILED';
 }

 /** Fair, read-only selection. Repeated failures back off instead of starving new revisions. */
 public static function select_due(array $rows,int $now,int $limit=12): array {
  $eligible=[];
  foreach($rows as $key=>$row){
   if(!is_array($row)||!in_array(($row['state']??''),['PENDING_INVALIDATION','VERIFY_FAILED','OBSERVED_NOT_CACHEABLE'],true))continue;
   if(in_array(($row['state']??''),['VERIFY_FAILED','OBSERVED_NOT_CACHEABLE'],true)
      && (int)($row['retry_after']??0)>$now)continue;
   $eligible[]=['key'=>$key,'row'=>$row,
      'checked'=>(int)($row['checked']??0),'updated'=>(int)($row['updated']??0)];
  }
  usort($eligible,static fn($a,$b)=>[$a['checked'],$a['updated'],(string)$a['key']]
      <=>[$b['checked'],$b['updated'],(string)$b['key']]);
  $out=[];
  foreach(array_slice($eligible,0,max(1,min(12,$limit))) as $item)
   $out[$item['key']]=$item['row'];
  return $out;
 }
 public static function status(){
  $rows=get_option(MAD4B_Cache_Lifecycle_V1::OPTION,[]);$pending=0;$failed=0;$verified=0;$not_cacheable=0;$oldest=0;$now=time();
  foreach($rows as $r){
   $state=$r['state']??'';
   if($state==='VERIFIED')++$verified;
   elseif($state==='VERIFY_FAILED')++$failed;
   elseif($state==='OBSERVED_NOT_CACHEABLE')++$not_cacheable;
   else ++$pending;
   if($state!=='VERIFIED')$oldest=max($oldest,$now-(int)($r['updated']??$now));
  }
  return ['contract'=>'mad4b.cache-lifecycle-status.v1','version'=>MAD4B_Cache_Lifecycle_V1::VERSION,'enabled'=>true,'home'=>get_option('home'),'environment'=>wp_get_environment_type(),
   'edge_html_policy'=>get_option('mad4b_cache_lifecycle_edge_isolation_verified_v1',false)===true?'verified_public':'private_until_isolation_verified','object_cache_active'=>wp_using_ext_object_cache(),'native_purge_available'=>(bool)has_action('litespeed_purge_url'),
   'configuration'=>get_option('mad4b_cache_lifecycle_configuration_v1',[]),'pending'=>$pending,'verified'=>$verified,'failed'=>$failed,
   'observed_not_cacheable'=>$not_cacheable,'total_routes'=>count($rows),'oldest_unverified_seconds'=>$oldest,'verification_scope'=>'http_route_revision_only','full_lifecycle_accepted'=>false,
   'native_cdn_refresh'=>get_option('mad4b_cache_lifecycle_cdn_refresh_v1',null),'party_price_acceptance'=>get_option('mad4b_cache_lifecycle_party_acceptance_v1',null),'last_run'=>get_option('mad4b_cache_lifecycle_last_run_v1',null)];
 }
 private static function ack($key,$revision,$state,$proof){
  global $wpdb;$name=MAD4B_Cache_Lifecycle_V1::OPTION;$wpdb->query('START TRANSACTION');$matched=false;
  try{$raw=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE",$name));$rows=maybe_unserialize($raw);
   if(is_array($rows)&&($rows[$key]['revision']??'')===$revision){
    $matched=true;$now=time();$failures=$state==='VERIFY_FAILED'?min(10,1+(int)($rows[$key]['failure_count']??0)):0;
    $rows[$key]['state']=$state;$rows[$key]['proof']=$proof;$rows[$key]['checked']=$now;
    $rows[$key]['failure_count']=$failures;
    $rows[$key]['retry_after']=$state==='VERIFY_FAILED'
       ?$now+min(3600,300*(2**min(3,$failures-1))):
        ($state==='OBSERVED_NOT_CACHEABLE'?$now+6*HOUR_IN_SECONDS:0);
    if(false===$wpdb->update($wpdb->options,['option_value'=>maybe_serialize($rows)],['option_name'=>$name]))throw new RuntimeException('ACK_FAILED');
   }
   $wpdb->query('COMMIT');if($matched)wp_cache_delete($name,'options');
  }catch(Throwable $e){$wpdb->query('ROLLBACK');return false;}return $matched;
 }
 public static function drain(){
  // DB advisory lock is site-scoped and is released even on exceptions.
  global $wpdb;$lock='mad4b_cache_'.substr(hash('sha256',ABSPATH),0,24);
  if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!=='1')return ['state'=>'BUSY'];
  $out=['contract'=>'mad4b.cache-lifecycle-drain.v1','run_id'=>wp_generate_uuid4(),'checked'=>0,'verified'=>0,'failed'=>0,'observed_not_cacheable'=>0,'stale_revision_skipped'=>0,'observed_utc'=>gmdate('c'),'verification_scope'=>'http_route_revision_only','purge_reissued'=>false];
  try{
   $rows=get_option(MAD4B_Cache_Lifecycle_V1::OPTION,[]);
   // Verification is only advisory; Cron may need to run WooCommerce,
   // Action Scheduler, Fluent Forms and other business hooks afterward.
   // Keep the total worst-case remote wait bounded to 3 x 3 seconds.
   $started=microtime(true);
   $max_routes=3;
   $per_request_timeout_s=3;
   $max_wall_s=12;
   $out['verification_budget']=['max_routes'=>$max_routes,
     'per_request_timeout_seconds'=>$per_request_timeout_s,
     'max_wall_seconds'=>$max_wall_s];
   $pending=self::select_due(is_array($rows)?$rows:[],time(),$max_routes);
   // Initial invalidation happens ONCE in MAD4B_Cache_Lifecycle_V1::queue_urls().
   // The verification timer must not issue another purge: failed or old
   // revisions can remain in the ledger for days. Repeating that action
   // would advance Nginx FastCGI generations on EVERY five-minute retry,
   // produce cold MISSes for mobile/desktop and starve new revisions.
   // Read-only verification with fair selection, CAS acknowledgement and
   // bounded failure backoff remain active below.
   foreach($pending as $key=>$row){
    // If transport timeout exceeds its configured deadline, defer the rest
    // without altering original cache invalidation or unverified receipts.
    if(microtime(true)-$started>=$max_wall_s)break;
    $url=$row['url']??'';if(!MAD4B_Cache_Lifecycle_V1::safe_url($url))continue;++$out['checked'];
    $response=wp_remote_get($url,['timeout'=>$per_request_timeout_s,
      'redirection'=>0,'limit_response_size'=>1024,
      'user-agent'=>'MAD4B-Cache-Lifecycle/1.0']);
    $code=is_wp_error($response)?0:wp_remote_retrieve_response_code($response);
    $revision=is_wp_error($response)?'':(string)wp_remote_retrieve_header($response,'x-mad4b-content-revision');
    $control=is_wp_error($response)?'':(string)wp_remote_retrieve_header($response,'cache-control');
    // A confirmed private/no-store response with no revision is NOT proof
    // that a public route was invalidated. Keep it separate and recheck later.
    $state=self::classify_http($code,(string)$row['revision'],$revision,
      $control,!empty($row['expect_absent']));
    $ok=$state==='VERIFIED';
    $not_cacheable=$state==='OBSERVED_NOT_CACHEABLE';
    $proof=['http'=>$code,'revision_match'=>$ok,'observed_revision'=>$revision,
      'cache_control'=>$control,'noncacheable_observation'=>(bool)$not_cacheable,
      'verification_scope'=>$not_cacheable?'single_private_http_response':'http_revision_matching',
      'cdn'=>is_wp_error($response)?null:wp_remote_retrieve_header($response,'x-hcdn-cache-status'),'observed_utc'=>gmdate('c')];
    if(self::ack($key,$row['revision'],$state,$proof))
      ++$out[$ok?'verified':($not_cacheable?'observed_not_cacheable':'failed')];
    else ++$out['stale_revision_skipped'];
   }
   $out['duration_ms']=round((microtime(true)-$started)*1000,1);
   $out['budget_deferred']=max(0,count($pending)-$out['checked']);
   $remaining=get_option(MAD4B_Cache_Lifecycle_V1::OPTION,[]);
   $out['remaining_unverified']=is_array($remaining)?count(array_filter($remaining,
     static fn($r)=>($r['state']??'')!=='VERIFIED')):null;
   $out['state']=$out['failed']?'RETRY_PENDING':
      ($out['stale_revision_skipped']?'RECONCILE_REVISION_CHANGED':
      ($out['remaining_unverified']?'PARTIAL_BACKLOG':'PASS'));
   update_option('mad4b_cache_lifecycle_last_run_v1',$out,false);
  }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
  return $out;
 }
 public static function menu(){
  $parent=class_exists('MAD4B_Company_Portfolio_Manager_V1')?'mad4b-company-brand-readiness':'tools.php';
  add_submenu_page($parent,'Cache Lifecycle','Cache Lifecycle','manage_options','mad4b-cache-lifecycle',[__CLASS__,'render']);
 }
 public static function render(){
  if(!current_user_can('manage_options'))return;echo '<div class="wrap"><h1>Cache Lifecycle</h1><p>Content changes → targeted invalidation → public revision verification. The existing cache provider is preserved.</p><h2>This company</h2><pre>'.esc_html(wp_json_encode(self::status(),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)).'</pre>';
  $fleet=get_option('mad4b_cache_lifecycle_fleet_v1',[]);
  if($fleet){echo '<h2>Company results</h2><table class="widefat striped"><thead><tr><th>Company</th><th>Environment</th><th>State</th><th>Freshness</th><th>Context isolation</th><th>Observed</th></tr></thead><tbody>';
   foreach($fleet as $row){echo '<tr>';foreach(['company_id','environment','state','freshness','isolation','observed_utc'] as $key)echo '<td>'.esc_html((string)($row[$key]??'UNKNOWN')).'</td>';echo '</tr>';}echo '</tbody></table>';
  }echo '</div>';
 }
}
