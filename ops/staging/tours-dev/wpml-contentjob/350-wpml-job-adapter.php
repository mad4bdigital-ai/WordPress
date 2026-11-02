<?php
/**
 * Plugin Name: MAD4B WPML ContentJob Adapter
 * Description: Governed, idempotent intake of WPML and localization outbox tasks.
 * Version: 0.1.0-staging
 */
defined('ABSPATH') || exit;
final class MAD4B_WPML_ContentJob_Adapter {
 const MAP='mad4b_wpml_content_job_map_v1';
 const EVENT='mad4b_wpml_content_job_sync_v1';
 const AUDIT='mad4b_wpml_content_job_sync_audit_v1';
 public static function ready(){
  return wp_get_environment_type()==='staging'
   && rtrim(home_url(),'/')==='https://tours.dev.mad4b.com'
   && defined('ICL_SITEPRESS_VERSION')
   && class_exists('MAD4B_SCP_Content_Jobs')
   && class_exists('MAD4B_SCP_Operational_Integrity')
   && function_exists('mad4b_wpml_auto_sync_allowed_v1');
 }
 private static function scope(){
  $r=MAD4B_SCP_Operational_Integrity::capture();
  return is_wp_error($r)?$r:($r['scope']??new WP_Error('bridge_missing_scope'));
 }
 private static function key($scope,$r){return hash('sha256',$scope['site_uuid'].'|'.$r['type'].'|'.$r['src'].'|'.$r['lang']);}
 private static function push(&$group,$src,$dst,$type,$lang,$from,$extra){
  $src=(int)$src;$dst=(int)$dst;$lang=sanitize_key($lang);
  if(!$src||!get_post($src)||get_post_type($src)!==$type||!preg_match('/^[a-z]{2}$/',$lang))return;
  $k=$type.'|'.$src.'|'.$lang;
  if(!isset($group[$k]))$group[$k]=['src'=>$src,'dst'=>$dst,'type'=>$type,'lang'=>$lang,'gaps'=>[],'native_jobs'=>[]];
  if(!$group[$k]['dst'])$group[$k]['dst']=$dst;
  if($from==='outbox')$group[$k]['gaps'][]=$extra;
  else $group[$k]['native_jobs'][]=$extra;
 }
 public static function discover(){
  global $wpdb;$group=[];$stats=['outbox_pending'=>0,'wpml_untranslated'=>0,'wpml_joined'=>0];
  foreach((array)get_option('mad4b_elementor_localization_outbox_v1',[]) as $r){
   if(!in_array(($r['status']??''),['queued','leased','needs_review'],true))continue;
   $src=(int)($r['source_post_id']??0);$p=get_post($src);
   if(!$p)continue;
   self::push($group,$src,$r['target_post_id']??0,$p->post_type,$r['target_language']??'','outbox',$r['gap_code']??'localization');
   $stats['outbox_pending']++;
  }
  $t=$wpdb->prefix;
  $sql="SELECT j.job_id,j.translated,tgt.element_id dst,tgt.element_type,tgt.language_code,
    src.element_id src
    FROM {$t}icl_translate_job j
    JOIN {$t}icl_translation_status st ON st.rid=j.rid
    JOIN {$t}icl_translations tgt ON tgt.translation_id=st.translation_id
    LEFT JOIN {$t}icl_translations src ON src.trid=tgt.trid
      AND src.element_type=tgt.element_type AND src.language_code=tgt.source_language_code
    WHERE j.translated=0 ORDER BY j.job_id DESC LIMIT 250";
  foreach((array)$wpdb->get_results($sql,ARRAY_A) as $r){
   $stats['wpml_untranslated']++;
   $type=preg_replace('/^post_/','',(string)$r['element_type']);
   $before=count($group);
   self::push($group,$r['src'],$r['dst'],$type,$r['language_code'],'wpml',(int)$r['job_id']);
   $stats['wpml_joined']+=(count($group)>$before||isset($group[$type.'|'.(int)$r['src'].'|'.sanitize_key($r['language_code'])]))?1:0;
  }
  $origin=(string)apply_filters('wpml_default_language',null);
  foreach($group as &$r){
   $r['gaps']=array_values(array_unique($r['gaps']));
   $r['native_jobs']=array_values(array_unique($r['native_jobs']));
   $r['eligible']=get_post_status($r['src'])==='publish'&&$r['lang']!==$origin
     &&mad4b_wpml_auto_sync_allowed_v1($r['src'],$r['dst']);
  }unset($r);
  ksort($group,SORT_STRING);
  return ['items'=>array_values($group),'counts'=>$stats];
 }
 public static function preview(){
  if(!self::ready())return new WP_Error('bridge_wrong_site');
  $scope=self::scope();if(is_wp_error($scope))return $scope;
  $scan=self::discover();$map=(array)get_option(self::MAP,[]);
  $summary=['eligible'=>0,'excluded'=>0,'mapped'=>0,'total'=>count($scan['items'])];$examples=[];
  foreach($scan['items'] as $r){
   $summary[$r['eligible']?'eligible':'excluded']++;
   if(!empty($map[self::key($scope,$r)]['job_id']))$summary['mapped']++;
   if(count($examples)<12)$examples[]=['source'=>$r['src'],'lang'=>$r['lang'],'gaps'=>$r['gaps'],
    'native_wpml_jobs'=>$r['native_jobs'],'eligible'=>$r['eligible']];
  }
  return ['contract'=>'mad4b.wpml.content-job-intake.v1','summary'=>$summary,'sources'=>$scan['counts'],
    'samples'=>$examples,'no_publish'=>true,'mutation'=>false];
 }
 private static function native_lookup($scope,$key) {
  global $wpdb;
  $tables=MAD4B_SCP_Schema::tables();
  $needle='wpml-ref:'.$key;
  $sql=$wpdb->prepare("SELECT job_id,state,job_revision,subject FROM {$tables['content_jobs']}
   WHERE site_uuid=%s AND tenant_id=%s AND brand_id=%s AND content_type=%s AND subject LIKE %s LIMIT 3",
   $scope['site_uuid'],$scope['tenant_ref'],$scope['brand_ref'],'translation','%'.$wpdb->esc_like($needle).'%');
  $rows=(array)$wpdb->get_results($sql,ARRAY_A);$hits=[];
  foreach($rows as $r)if(strpos($r['subject'],$needle)!==false)$hits[]=$r;
  if(count($hits)>1)return new WP_Error('bridge_duplicate_native_content_jobs');
  return $hits[0]??null;
 }
 private static function commit_map($map){
  update_option(self::MAP,$map,false);
  return get_option(self::MAP,[])===$map;
 }
 public static function run($limit=5,$only_source=0){
  if(!self::ready())return new WP_Error('bridge_wrong_site');
  // WP-Cron without a service actor is discovery-only. Do not open a
  // transaction or mark an intake uncertain on authorization rejection.
  if(!class_exists('MAD4B_SCP_Policy')||true!==MAD4B_SCP_Policy::can_mutate())
   return new WP_Error('wpml_content_job_executor_not_authorized',
    'The intake needs a verified WordPress operator or governed NHI.',
    ['mutation_performed'=>false,'requires_authorized_principal'=>true]);
  global $wpdb;
  if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,2)','mad4b_wpml_jobs_native_intake'))!=='1')
   return new WP_Error('bridge_lock_busy');
  try{
   $scope=self::scope();if(is_wp_error($scope))return $scope;
   $list=self::discover()['items'];$map=(array)get_option(self::MAP,[]);
   // Unmapped and safe-to-create translations come first. A fixed ID ordering
   // would otherwise reprocess the same 5 linked jobs forever.
   usort($list,static function($a,$b)use($scope,$map){
    $ka=self::key($scope,$a);$kb=self::key($scope,$b);
    $pa=!empty($map[$ka]['job_id'])||!empty($map[$ka]['uncertain'])?1:0;
    $pb=!empty($map[$kb]['job_id'])||!empty($map[$kb]['uncertain'])?1:0;
    return ($pa<=>$pb)?:strcmp($ka,$kb);
   });
   $limit=max(1,min(10,(int)$limit));$done=0;$created=0;$reused=0;$skipped=0;$errors=[];$jobs=[];$remaining=0;
   foreach($list as $r){
    if($only_source&&$r['src']!==(int)$only_source)continue;
    if(!$r['eligible']){$skipped++;continue;}
    $priority_key=self::key($scope,$r);
    // Regular background intake only processes new source/language identities.
    // Explicit --source requests can still perform idempotency/readback checks.
    if(!$only_source && (!empty($map[$priority_key]['job_id']) || !empty($map[$priority_key]['uncertain'])))continue;
    if($done>=$limit){$remaining++;continue;}
    $done++;$key=self::key($scope,$r);$before=$map[$key]??[];
    $native=self::native_lookup($scope,$key);
    if(is_wp_error($native)){$errors[]=$native->get_error_code();continue;}
    if(!$native&&!empty($before['job_id'])){
     $read=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$before['job_id']]);
     if(is_wp_error($read)){$errors[]='mapping_native_readback_failed';continue;}
     $native=$read['job'];
    }
    if(!$native){
     if(!empty($before['uncertain'])){$errors[]='unconfirmed_create_needs_manual_reconcile';continue;}
     $title=mb_substr(sanitize_text_field(get_the_title($r['src'])),0,140);
     $input=[
      'brand_id'=>$scope['brand_ref'],
      'subject'=>'WPML translation — '.$title.' ['.$r['lang'].'] post #'.$r['src'].' wpml-ref:'.$key,
      'language'=>$r['lang'],'country'=>'global','content_type'=>'translation',
      'target_post_type'=>$r['type'],'automation_level'=>'assisted',
      'reason'=>'WPML translation intake; exact source #'.$r['src'].'; no auto-publication.',
     ];
     $write=MAD4B_SCP_Content_Jobs::create_job($input);
     if(is_wp_error($write)){
      $map[$key]=array_merge($before,['uncertain'=>true,'error'=>$write->get_error_code(),'at'=>gmdate('c')]);
      self::commit_map($map);$errors[]=$write->get_error_code();continue;
     }
     $native=$write['job']??null;
     if(!$native||empty($native['job_id'])){$errors[]='native_create_readback_missing';continue;}
     $created++;
    }else $reused++;
    $map[$key]=[
     'job_id'=>$native['job_id'],'source_post_id'=>$r['src'],'target_post_id'=>$r['dst'],
     'type'=>$r['type'],'lang'=>$r['lang'],'native_wpml_job_ids'=>$r['native_jobs'],
     'gap_codes'=>$r['gaps'],'state'=>$native['state'],'updated_at'=>gmdate('c'),
    ];
    if(!self::commit_map($map)){$errors[]='mapping_readback_failed';continue;}
    if($native['state']==='NEW'){
     $next=MAD4B_SCP_Content_Jobs::transition_job([
      'job_id'=>$native['job_id'],'expected_revision'=>(int)$native['job_revision'],
      'state'=>'QUEUED','stage'=>'INTAKE',
      'reason'=>'WPML translation requested; waiting for authorized translation executor.',
     ]);
     if(is_wp_error($next))$errors[]='native_transition_'.$next->get_error_code();
     else{$map[$key]['state']='QUEUED';self::commit_map($map);}
    }
    $jobs[]=['source'=>$r['src'],'lang'=>$r['lang'],'job_id'=>$native['job_id']];
   }
   $audit=(array)get_option(self::AUDIT,[]);
   $audit[]=['at'=>gmdate('c'),'created'=>$created,'reused'=>$reused,'errors'=>$errors,'source'=>$only_source];
   update_option(self::AUDIT,array_slice($audit,-40),false);
   if($remaining&&!$only_source&&!wp_next_scheduled(self::EVENT))
    wp_schedule_single_event(time()+90,self::EVENT);
   return ['contract'=>'mad4b.wpml.content-job-intake.v1','created'=>$created,'reused'=>$reused,
    'excluded'=>$skipped,'remaining'=>$remaining,'errors'=>$errors,'jobs'=>$jobs,
    'translation_executed'=>false,'published'=>false];
  }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)','mad4b_wpml_jobs_native_intake'));}
 }
 public static function cli($args,$opts){
  $cmd=$args[0]??'status';
  if($cmd==='preview'||$cmd==='status')$r=self::preview();
  elseif($cmd==='run')$r=self::run($opts['limit']??5,$opts['source']??0);
  else WP_CLI::error('Usage: wp mad4b-wpml-jobs preview|status|run --limit=5 --source=0');
  if(is_wp_error($r))WP_CLI::error($r->get_error_code());
  WP_CLI::line(wp_json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
 }
 public static function boot(){
  if(!self::ready())return;
  add_action(self::EVENT,static function(){
   // The site cron has no WordPress principal and must not invent one.
   // Discovery keeps the translation outbox visible; host-scoped native
   // executor invokes the actual write command with an enrolled operator.
   if(class_exists('MAD4B_SCP_Policy')&&MAD4B_SCP_Policy::can_mutate())self::run(5);
  });
  add_action('mad4b/localization/jobs_reconciled',static function(){
   if(!wp_next_scheduled(self::EVENT))wp_schedule_single_event(time()+75,self::EVENT);
  });
  add_action('init',static function(){
   if(!wp_next_scheduled(self::EVENT))wp_schedule_single_event(time()+300,self::EVENT);
  },110);
  if(defined('WP_CLI')&&WP_CLI)WP_CLI::add_command('mad4b-wpml-jobs',[__CLASS__,'cli']);
 }
}
add_action('plugins_loaded',['MAD4B_WPML_ContentJob_Adapter','boot'],115);
