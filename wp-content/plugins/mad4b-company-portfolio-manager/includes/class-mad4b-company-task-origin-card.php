<?php
/**
 * Plugin Name: MAD4B Central Company Task Origins Card (Staging)
 * Description: Read-only company-scoped task provenance and central source coverage.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Company_Task_Origins_Card {
 const CONTRACT='mad4b.company-task-origins.v1';
 const REGISTRY='mad4b_tourism_company_site_registry_v1';
 const OPEN=['QUEUED','RETRY','RUNNING','CANCEL_REQUESTED','WAITING_JOB','WAITING_EXECUTOR','WAITING_EXTERNAL','NEEDS_RECONCILIATION','DEAD_LETTER','LEASED'];
 private static function central(){
  return class_exists('MAD4B_Company_Portfolio_Manager_V1',false)
   && MAD4B_Company_Portfolio_Manager_V1::staging();
 }
 private static function err($code,$status=403){
  return new WP_Error('mad4b_company_task_origins_'.$code,$code,['status'=>$status]);
 }
 private static function identity($code){
  $code=sanitize_key((string)$code);
  if(!self::central()||!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$code))
   return self::err('site_or_code',403);
  $registry=get_option(self::REGISTRY,[]);
  $r=is_array($registry)?($registry[$code]??null):null;
  if(!is_array($r)||($r['company_code']??'')!==$code||
   empty($r['host_binding_verified']) ||
   !wp_is_uuid((string)($r['company_site_uuid']??'')) ||
   !wp_is_uuid((string)($r['native_site_profile_uuid']??'')) ||
   !preg_match('/^[a-f0-9]{64}$/D',(string)($r['native_profile_digest']??'')))
   return self::err('company_binding_unverified');
  $unique=MAD4B_Company_Portfolio_Manager_V1::unique_company_site_identity($code,(string)$r['company_site_uuid'],(string)$r['native_site_profile_uuid'],$registry);
  if(is_wp_error($unique))return $unique;
  if(class_exists('MAD4B_Company_Brand_Readiness_Hub')){
   $req=new WP_REST_Request('GET','/mad4b/v1/company-brand-readiness/companies/'.$code);
   $req->set_param('company',$code);
   $allow=MAD4B_Company_Brand_Readiness_Hub::permission($req);
   if(is_wp_error($allow))return $allow;
  }elseif(!current_user_can('manage_options'))return self::err('no_company_read_capability');
  return ['code'=>$code,'site_uuid'=>(string)$r['company_site_uuid'],
   'native_site_uuid'=>(string)$r['native_site_profile_uuid']];
 }
 private static function origin($key){
  if(str_starts_with($key,'gaps.'))return 'Canonical Gaps';
  if(str_starts_with($key,'event.'))return 'Workflow Events';
  if(str_starts_with($key,'ai.'))return 'AI Pipeline';
  if(str_starts_with($key,'qa.')||str_starts_with($key,'qa31-')||
     str_starts_with($key,'vps.')||str_starts_with($key,'rt-'))return 'QA & Diagnostics';
  return 'Other / Manual';
 }
 public static function summary($company){
  $identity=self::identity($company);
  if(is_wp_error($identity))return $identity;
  global $wpdb;
  $batch=$wpdb->prefix.'mad4b_hub_bulk_batches';
  $tasks=$wpdb->prefix.'mad4b_hub_bulk_tasks';
  $jobs=$wpdb->prefix.'mad4b_content_jobs';
  $gap=$wpdb->prefix.'mad4b_hub_canonical_gaps';
  $entity=$wpdb->prefix.'mad4b_hub_canonical_entities';
  foreach([$batch,$tasks,$jobs,$gap,$entity] as $t){
   if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($t)))!==$t)
    return self::err('source_table_unavailable',503);
  }
  // Read only; bounded query. The canonical link is verified using both
  // exact gap ID and canonical ID; source company is NOT assumed to be
  // the currently managed destination company.
  $sql="SELECT b.batch_key,b.created_at,t.state,t.stage,t.operation,
     t.target_ref,t.content_job_id,t.payload_json,
     j.site_uuid AS job_site_uuid,j.brand_id AS job_brand_id,
     g.company_id AS gap_company,g.gap_code,g.source_type AS gap_source_type,
     e.company_id AS entity_company,
     JSON_UNQUOTE(JSON_EXTRACT(e.snapshot_json,'$.provider')) AS canonical_provider,
     JSON_UNQUOTE(JSON_EXTRACT(e.snapshot_json,'$.dataset')) AS canonical_dataset
     FROM $tasks t INNER JOIN $batch b ON b.batch_id=t.batch_id
     LEFT JOIN $jobs j ON j.job_id=t.content_job_id
     LEFT JOIN $gap g ON b.batch_key LIKE 'gaps.%'
      AND g.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(t.payload_json,'$.gap_task_id')) AS UNSIGNED)
     LEFT JOIN $entity e ON e.id=g.canonical_id
      AND e.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(t.payload_json,'$.canonical_id')) AS UNSIGNED)
     WHERE t.state IN ('QUEUED','RETRY','RUNNING','CANCEL_REQUESTED','WAITING_JOB',
        'WAITING_EXECUTOR','WAITING_EXTERNAL','NEEDS_RECONCILIATION','DEAD_LETTER','LEASED')
     ORDER BY t.id DESC LIMIT 1001";
  $rows=$wpdb->get_results($sql,ARRAY_A);
  if(!is_array($rows)||$wpdb->last_error)return self::err('queue_read_failed',503);
  $truncated=count($rows)>1000;
  $rows=array_slice($rows,0,1000);
  $all=['total'=>0,'origins'=>[],'states'=>[],'sources'=>[],'company_total'=>0,
        'authoritative_origin'=>0,'legacy_origin'=>0,
        'company_states'=>[],'company_origins'=>[],'company_recent'=>[]];
  foreach($rows as $row){
   $payload=json_decode((string)$row['payload_json'],true);
   if(!is_array($payload))$payload=[];
   $origin=self::origin((string)$row['batch_key']);
   $state=(string)$row['state'];
   $bind=$payload['workflow_origin']??null;
   $authoritative=false;
   if(is_array($bind)&&($bind['contract']??'')==='mad4b.hub.workflow-origin.v1'
      &&preg_match('/^[a-f0-9]{64}$/D',(string)($bind['origin_sha256']??''))){
     $hash=$bind['origin_sha256'];unset($bind['origin_sha256']);
     $authoritative=hash_equals($hash,hash('sha256',wp_json_encode($bind,JSON_UNESCAPED_SLASHES)));
   }
   $all[$authoritative?'authoritative_origin':'legacy_origin']++;
   if($authoritative){
    $origin=([
      'product_data'=>'Product Data',
      'content_production'=>'Content Production',
      'integration_operation'=>'Integration Handoff',
      'qa_diagnostic'=>'QA & Diagnostics'
    ][$bind['workflow_type']??'']??'Unknown Workflow');
   }
   $source='Unattributed / platform';
   if($origin==='Canonical Gaps' && $row['entity_company']!==null &&
      (string)$row['gap_company']===(string)$row['entity_company'] &&
      (string)($payload['gap_code']??'')===(string)$row['gap_code']){
    $source=(string)$row['entity_company'];
   }elseif(($origin==='Workflow Events'||$origin==='AI Pipeline') &&
      !empty($row['job_site_uuid']))$source='Site UUID: '.$row['job_site_uuid'];
   $entrypoint=$authoritative
      ?((string)($bind['source_provider']??'')).' / '.(string)($bind['trigger_event_type']??'')
      :($origin==='Canonical Gaps'
       ?(string)($row['canonical_provider']??'unknown').' / '.(string)($row['canonical_dataset']??'unknown')
       :($origin==='Workflow Events'?(string)($payload['trigger_source']??'unknown')
         :($origin==='AI Pipeline'?'AI stage / external handoff':'QA or manual trigger')));
   if($authoritative && $origin==='Product Data' && $row['entity_company']!==null
      && $row['gap_company']===$row['entity_company']
      && $row['entity_company']===($bind['source_company']??''))$source=$row['entity_company'];
   if($authoritative && $origin==='Content Production' && !empty($row['job_site_uuid'])
      && $row['job_site_uuid']===($bind['source_site_uuid']??''))$source='Site UUID: '.$row['job_site_uuid'];
   $all['total']++;
   $all['origins'][$origin]=($all['origins'][$origin]??0)+1;
   $all['states'][$state]=($all['states'][$state]??0)+1;
   $skey=$origin.'|'.$source.'|'.$entrypoint;
   if(!isset($all['sources'][$skey]))$all['sources'][$skey]=[
    'origin'=>$origin,'source'=>$source,'entrypoint'=>$entrypoint,'tasks'=>0,'states'=>[]];
   $all['sources'][$skey]['tasks']++;
   $all['sources'][$skey]['states'][$state]=($all['sources'][$skey]['states'][$state]??0)+1;
   $belongs=(
      in_array($origin,['Canonical Gaps','Product Data'],true) && $source===$identity['code']
    )||(
      in_array($origin,['Workflow Events','AI Pipeline','Content Production'],true)
      && in_array((string)$row['job_site_uuid'],
          [$identity['site_uuid'],$identity['native_site_uuid']],true)
    );
   if(!$belongs)continue;
   $all['company_total']++;
   $all['company_states'][$state]=($all['company_states'][$state]??0)+1;
   $all['company_origins'][$origin]=($all['company_origins'][$origin]??0)+1;
   if(count($all['company_recent'])<8)$all['company_recent'][]=[
     'origin'=>$origin,'state'=>$state,'stage'=>(string)$row['stage'],
     'operation'=>(string)$row['operation'],'target_ref'=>(string)$row['target_ref'],
     'content_job_id'=>(string)$row['content_job_id']
   ];
  }
  $sources=array_values($all['sources']);
  usort($sources,static fn($x,$y)=>$y['tasks']<=>$x['tasks']);
  // Company operators must never see unrelated company source counts.
  // Only central platform admins can see cross-company coverage.
  $central_admin=current_user_can('manage_options')&&current_user_can('activate_plugins');
  if(!$central_admin){
   $sources=array_values(array_filter($sources,static fn($group)=>
      $group['source']===$identity['code'] ||
      $group['source']==='Site UUID: '.$identity['site_uuid'] ||
      $group['source']==='Site UUID: '.$identity['native_site_uuid']));
  }
  return [
   'contract'=>self::CONTRACT,'company_code'=>$identity['code'],
   'company_site_uuid'=>$identity['site_uuid'],
   'company'=>[
     'open_tasks'=>$all['company_total'],'states'=>$all['company_states'],
     'origins'=>$all['company_origins'],'recent'=>$all['company_recent']
   ],
   'central'=>[
     'total_open_tasks'=>$central_admin?$all['total']:$all['company_total'],
     'origins'=>$central_admin?$all['origins']:$all['company_origins'],
     'states'=>$central_admin?$all['states']:$all['company_states'],
     'source_groups'=>$sources,
     'authoritative_origin'=>$central_admin?$all['authoritative_origin']:null,
     'legacy_origin'=>$central_admin?$all['legacy_origin']:null,
     'includes_other_companies'=>$central_admin,
   ],
   'scope_rule'=>'exact_verified_company_binding_only',
   'sample_truncated'=>$truncated,'read_only'=>true,
   'as_of_utc'=>gmdate('c'),'execution_performed'=>false
  ];
 }
 public static function render($code){
  $result=self::summary($code);
  if(is_wp_error($result)){
   echo '<section class="mad4b-scp-card mad4b-task-origin-card"><h2>Task origins</h2><p role="status">'.
      esc_html($result->get_error_message()).'</p></section>';
   return;
  }
  $c=$result['company'];$total=$result['central']['total_open_tasks'];
  $other=max(0,$total-$c['open_tasks']);
  echo '<section id="mad4b-task-origins" class="mad4b-scp-card mad4b-task-origin-card" aria-labelledby="mad4b-task-origin-title">';
  echo '<div class="mad4b-task-origin-heading"><div><h2 id="mad4b-task-origin-title">Task origins &amp; lifecycle</h2>';
  echo '<p>Verified company: <strong>'.esc_html($code).'</strong>. Exact source and site binding — no inferred ownership.</p></div>';
  echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=mad4b-hub-bulk-queue')).'">Open Bulk Queue</a></div>';
  echo '<div class="mad4b-task-origin-metrics">';
  foreach([
    ['Company tasks',$c['open_tasks']],
    ['Central open tasks',$total],
    ['Other or unassigned',$other],
    ['Waiting executor',$result['central']['states']['WAITING_EXECUTOR']??0],
  ] as $metric){
   echo '<div class="mad4b-task-origin-metric"><span>'.esc_html($metric[0]).'</span>'.
     '<strong>'.(int)$metric[1].'</strong></div>';
  }
  echo '</div>';
  echo '<p class="description">Company tasks are counted only when the verified company code matches the canonical source, or the job belongs to its verified site UUID. '.
    ($result['central']['includes_other_companies']?'Central totals also include other sources and QA diagnostics.':'Only authorized company data is displayed.').
    '</p>';
  if($result['central']['includes_other_companies'])
   echo '<p class="description">Workflow Origin evidence: <strong>'.
     (int)$result['central']['authoritative_origin'].'</strong> source-bound at creation; <strong>'.
     (int)$result['central']['legacy_origin'].'</strong> legacy tasks (origin not recorded at enqueue). Legacy labels are observational, not certified original triggers.</p>';
  if($result['sample_truncated'])echo '<p role="status">Showing a bounded sample of 1,000 open tasks. Counts may be incomplete.</p>';
  echo '<details><summary>Source of tasks and current status</summary><div class="mad4b-task-origin-table">';
  echo '<table class="widefat striped"><thead><tr><th scope="col">Start / trigger</th><th scope="col">Recorded source</th><th scope="col">Open</th><th scope="col">States</th></tr></thead><tbody>';
  foreach($result['central']['source_groups'] as $group){
   $states=[];foreach($group['states'] as $state=>$n)$states[]=$state.': '.$n;
   echo '<tr><td>'.esc_html($group['origin']).'<br><small>'.esc_html($group['entrypoint']).'</small></td><td><code>'.esc_html($group['source']).'</code></td><td>'.(int)$group['tasks'].'</td><td>'.esc_html(implode(' · ',$states)).'</td></tr>';
  }
  echo '</tbody></table></div></details>';
  if($c['recent']){
   echo '<details><summary>Tasks directly linked to this company</summary>';
   echo '<div class="mad4b-task-origin-table"><table class="widefat striped"><thead><tr><th>Origin</th><th>Stage / operation</th><th>Target</th><th>State</th></tr></thead><tbody>';
   foreach($c['recent'] as $task){
    echo '<tr><td>'.esc_html($task['origin']).'</td><td>'.esc_html($task['stage'].' / '.$task['operation']).'</td>'.
    '<td><code>'.esc_html($task['target_ref']?:$task['content_job_id']).'</code></td><td>'.esc_html($task['state']).'</td></tr>';
   }
   echo '</tbody></table></div></details>';
  }else{
   echo '<p role="status"><strong>No directly bound tasks for '.esc_html($code).'.</strong>'.
     ($result['central']['includes_other_companies']?' Other source tasks are shown for platform oversight and are not assigned to this company.':'').
     '</p>';
  }
  echo '<p class="mad4b-task-origin-footnote">Read-only · Updated '.esc_html($result['as_of_utc']).' · No changes to task states or permissions</p>';
  echo '</section>';
 }
 public static function rest(){
  if(!self::central())return;
  register_rest_route('mad4b/v1','/company-task-origins/(?P<company>[a-z][a-z0-9-]{2,47})',[
   'methods'=>'GET','callback'=>static function($r){
    $value=self::summary((string)$r['company']);
    $resp=rest_ensure_response($value);
    if($resp instanceof WP_REST_Response)$resp->header('Cache-Control','private, no-store');
    return $resp;
   },
   'permission_callback'=>static function($r){
    $result=self::identity((string)$r['company']);
    return is_wp_error($result)?$result:true;
   }
  ]);
 }
 public static function enqueue_assets($hook=''){
  if(!self::central()||!is_admin()||!current_user_can('mad4b_manage_company_operations')||
     sanitize_key((string)($_GET['page']??''))!=='mad4b-company-brand-readiness')return;
  $base=content_url('mu-plugins/mad4b-company-task-origin-assets/task-origin.css');
  $path=WPMU_PLUGIN_DIR.'/mad4b-company-task-origin-assets/task-origin.css';
  if(is_readable($path))wp_enqueue_style('mad4b-company-task-origin-card',
    $base,[],'0.1.'.filemtime($path));
 }
 public static function boot(){
  add_action('rest_api_init',[__CLASS__,'rest'],75);
  add_action('admin_enqueue_scripts',[__CLASS__,'enqueue_assets'],90);
 }
}
MAD4B_Company_Task_Origins_Card::boot();

