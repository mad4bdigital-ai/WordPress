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
   $target=(int)($r['dst']??0);
   $status=$target?(string)get_post_status($target):'missing';
   $r['target_post_status']=$status;
   if($status==='draft' && $target>0){
    $details=apply_filters('wpml_post_language_details',null,$target);
    if(is_array($details)&&($details['language_code']??'')===$r['lang']){
     $r['draft_pending_review']=true;
     $r['review_blocker']='WPML-linked target is unpublished. Editorial, Elementor reference and public render checks required';
    }
   }
   // Skip language-specific translation jobs ONLY when runtime evidence
   // confirms this is a reusable text-free technical structure, and no
   // separate native WPML job exists. Never suppress user-facing content.
   $r['work_kind']='editorial_translation';
   if(in_array($r['type'],['elementor_library','jet-engine'],true)
      &&class_exists('MAD4B_WPML_Translation_Need_V1')){
    $analysis=MAD4B_WPML_Translation_Need_V1::source($r['src']);
    $r['classification']=is_wp_error($analysis)?'needs_render_review':$analysis['classification'];
    if($r['classification']==='technical_only' && empty($r['native_jobs'])){
     $r['work_kind']='technical_design_only';
     $r['eligible']=false;
    }elseif($r['classification']==='needs_render_review')$r['work_kind']='render_review_required';
   }
   if(!empty($r['draft_pending_review']))$r['work_kind']='draft_pending_review';
  }unset($r);
  ksort($group,SORT_STRING);
  return ['items'=>array_values($group),'counts'=>$stats];
 }
 public static function preview(){
  if(!self::ready())return new WP_Error('bridge_wrong_site');
  $scope=self::scope();if(is_wp_error($scope))return $scope;
  $scan=self::discover();$map=(array)get_option(self::MAP,[]);
  $summary=['eligible'=>0,'excluded'=>0,'mapped'=>0,'total'=>count($scan['items']),
    'technical_design_only'=>0,'render_review_required'=>0,'draft_pending_review'=>0];$examples=[];
  foreach($scan['items'] as $r){
   $summary[$r['eligible']?'eligible':'excluded']++;
   if(($r['work_kind']??'')==='technical_design_only')$summary['technical_design_only']++;
   if(($r['work_kind']??'')==='render_review_required')$summary['render_review_required']++;
   if(($r['work_kind']??'')==='draft_pending_review')$summary['draft_pending_review']++;
   if(!empty($map[self::key($scope,$r)]['job_id']))$summary['mapped']++;
   if(count($examples)<12)$examples[]=['source'=>$r['src'],'lang'=>$r['lang'],'gaps'=>$r['gaps'],
    'native_wpml_jobs'=>$r['native_jobs'],'eligible'=>$r['eligible'],
    'work_kind'=>$r['work_kind']??'editorial_translation',
    'target_post_id'=>$r['dst']??0,'target_post_status'=>$r['target_post_status']??'missing',
    'review_blocker'=>$r['review_blocker']??null,
    'classification'=>$r['classification']??'content_post'];
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
  if(count($hits)>1){
   // A group followup does not erase the terminal original job. Only
   // disambiguate by the authoritative site-scoped current WPML map, and
   // only when every *other* matching native job is truly terminal.
   $map=(array)get_option(self::MAP,[]);
   $current=(string)($map[$key]['job_id']??'');
   $selected=array_values(array_filter($hits,
      static fn($one)=>$one['job_id']===$current));
   $conflicts=array_values(array_filter($hits,
      static fn($one)=>$one['job_id']!==$current &&
         !in_array($one['state'],['COMPLETED','CANCELLED'],true)));
   if(count($selected)===1 && !$conflicts)
      return $selected[0];
   return new WP_Error('bridge_duplicate_native_content_jobs');
  }
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
    if($pa!==$pb)return $pa<=>$pb;
    if(class_exists('MAD4B_WPML_Frontend_Priority_V1')){
     $ra=MAD4B_WPML_Frontend_Priority_V1::rank($a['src'],$a['type'],$a['lang'],$a['dst']);
     $rb=MAD4B_WPML_Frontend_Priority_V1::rank($b['src'],$b['type'],$b['lang'],$b['dst']);
     if($ra['priority']!==$rb['priority'])return $rb['priority']<=>$ra['priority'];
    }
    return strcmp($ka,$kb);
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
    $priority=class_exists('MAD4B_WPML_Frontend_Priority_V1')
       ?MAD4B_WPML_Frontend_Priority_V1::rank($r['src'],$r['type'],$r['lang'],$r['dst']):null;
    $jobs[]=['source'=>$r['src'],'lang'=>$r['lang'],'job_id'=>$native['job_id'],
       'frontend_priority'=>$priority['priority']??null,
       'priority_evidence'=>$priority['evidence']??'unavailable',
       'priority_path'=>$priority['evidence_path']??null];
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
 /** Resolve a ContentJob to authoritative WPML objects and translation QA without mutating them. */
 public static function binding($input){
  if(!self::ready())return new WP_Error('wpml_bridge_wrong_site');
  $id=strtolower(trim((string)($input['job_id']??'')));
  if(!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',$id))
   return new WP_Error('wpml_bridge_invalid_job_id');
  $native=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$id]);
  if(is_wp_error($native))return $native;
  if(($native['job']['content_type']??'')!=='translation')
   return new WP_Error('wpml_bridge_not_translation_job');
  $scope=self::scope();if(is_wp_error($scope))return $scope;
  $match=null;$map=(array)get_option(self::MAP,[]);
  foreach($map as $key=>$row){
   if(($row['job_id']??'')!==$id)continue;
   $expected=self::key($scope,[
    'src'=>(int)$row['source_post_id'],'type'=>(string)$row['type'],
    'lang'=>(string)$row['lang']]);
   if(!hash_equals((string)$key,$expected))return new WP_Error('wpml_bridge_binding_key_mismatch');
   $match=$row;break;
  }
  if(!$match)return new WP_Error('wpml_bridge_binding_not_found');
  $source=(int)$match['source_post_id'];$target=(int)$match['target_post_id'];
  $lang=(string)$match['lang'];
  // The persisted binding is historical evidence. Re-read current WPML and
  // localization sources to detect new tasks for the same post and locale.
  $current=null;
  foreach(self::discover()['items'] as $now){
   if((int)$now['src']===$source && (string)$now['type']===(string)$match['type']
       && (string)$now['lang']===$lang){$current=$now;break;}
  }
  if($current&&!empty($current['dst']))$target=(int)$current['dst'];
  // A historical intake mapping may have captured target_post_id=0 while
  // WPML drafts were excluded from its original translation collection.
  // After publication the discovery gap disappears; resolve the authoritative
  // WPML ID directly and verify the source TRID, target locale and post type.
  $live_target=(int)apply_filters('wpml_object_id',$source,(string)$match['type'],false,$lang);
  if($live_target && get_post_type($live_target)===(string)$match['type']){
   $source_trid=(int)apply_filters('wpml_element_trid',null,$source,'post_'.$match['type']);
   $target_trid=(int)apply_filters('wpml_element_trid',null,$live_target,'post_'.$match['type']);
   $locale_data=(array)apply_filters('wpml_post_language_details',null,$live_target);
   if($source_trid>0 && $source_trid===$target_trid
     &&($locale_data['language_code']??'')===$lang)
    $target=$live_target;
  }
  $gaps=array_values(array_unique(array_merge((array)($match['gap_codes']??[]),
      (array)($current['gaps']??[]))));
  $wpml_ids=array_values(array_unique(array_merge((array)($match['native_wpml_job_ids']??[]),
      (array)($current['native_jobs']??[]))));
  $issues=[];
  foreach((array)get_option('mad4b_elementor_localization_outbox_v1',[]) as $outbox_key=>$o){
   if((int)($o['source_post_id']??0)!==$source
    ||($o['target_language']??'')!==$lang)continue;
   if(!in_array(($o['gap_code']??''),$gaps,true))continue;
   $issues[]=['code'=>$o['gap_code'],'status'=>$o['status']??'unknown',
    'key'=>(string)$outbox_key,
    'expected_source_sha256'=>$o['source_sha256']??null,
    'operation'=>$o['operation']??null,'lease_until'=>$o['lease_until']??null,
    'receipt_id'=>$o['receipt_id']??null,
    'readback_passed'=>$o['readback_passed']??null];
  }
  global $wpdb;$native_wpml=[];
  foreach(array_slice($wpml_ids,0,25) as $wid){
   $wid=(int)$wid;if($wid<=0)continue;
   $q=$wpdb->get_row($wpdb->prepare(
    "SELECT j.job_id,j.translated,s.status,s.needs_update FROM {$wpdb->prefix}icl_translate_job j
      JOIN {$wpdb->prefix}icl_translation_status s ON s.rid=j.rid WHERE j.job_id=%d LIMIT 1",
    $wid),ARRAY_A);
   if($q)$native_wpml[]=['job_id'=>(int)$q['job_id'],'translated'=>(int)$q['translated'],
    'status'=>(int)$q['status'],'needs_update'=>(int)$q['needs_update']];
  }
  $gaps_verified=$gaps ? !!$issues : true;
  if($gaps){
   foreach($gaps as $gap){
    $matched=array_values(array_filter($issues,static fn($v)=>$v['code']===$gap));
    if(!$matched){$gaps_verified=false;continue;}
    foreach($matched as $v)if($v['status']!=='verified'||$v['readback_passed']!==true)
     $gaps_verified=false;
   }
  }
  $native_verified=!empty($wpml_ids);
  if($native_verified){
   if(count($native_wpml)!==count($wpml_ids))$native_verified=false;
   foreach($native_wpml as $v)if(!$v['translated']||$v['needs_update'])$native_verified=false;
  }
  $requires_native=!empty($wpml_ids);
  $requires_gap=!empty($gaps);
  $target_status=$target?(string)get_post_status($target):'missing';
  $draft_pending_review=$target>0&&$target_status==='draft';
  $ready=($requires_gap||$requires_native)&&$gaps_verified&&(!$requires_native||$native_verified)
    &&$target_status==='publish';
  $review_blockers=[];
  if($draft_pending_review)$review_blockers[]='unpublished_wpml_draft';
  if(!$gaps_verified)$review_blockers[]='outbox_receipt_or_localization_gap_unverified';
  if($requires_native&&!$native_verified)$review_blockers[]='native_wpml_job_unverified';
  if(!$draft_pending_review&&$target_status!=='publish')$review_blockers[]='published_target_missing';
  $analysis=class_exists('MAD4B_WPML_Translation_Need_V1')
    ?MAD4B_WPML_Translation_Need_V1::source($source):null;
  return ['contract'=>'mad4b.wpml.contentjob-binding.v1','job_id'=>$id,
   'translation_classification'=>is_array($analysis)?$analysis['classification']:'needs_render_review',
   'static_string_examples'=>is_array($analysis)?array_slice($analysis['examples'],0,7):[],
   'source_post_id'=>$source,'target_post_id'=>$target,'target_language'=>$lang,
   'target_post_status'=>$target_status,'draft_pending_review'=>$draft_pending_review,
   'review_blockers'=>$review_blockers,
   'review_visibility'=>'control_plane_and_outbox',
   'post_type'=>$match['type'],'native_job_state'=>$native['job']['state'],
   'master_or_post_excluded'=>!mad4b_wpml_auto_sync_allowed_v1($source,$target),
   'current_gap_codes'=>$gaps,'current_native_wpml_job_ids'=>$wpml_ids,
   'outbox_issues'=>$issues,'native_wpml_jobs'=>$native_wpml,
   'readback_verified'=>$ready,'review_required'=>true,'auto_publish'=>false,
   'mutation_performed'=>false];
 }
 /** Advance verified WPML receipts to governed editorial review, never to completion without review. */
 public static function reconcile_verified($limit=5,$only_id=''){
  if(!self::ready()||true!==MAD4B_SCP_Policy::can_mutate())
   return new WP_Error('wpml_reconcile_executor_not_authorized');
  $limit=max(1,min(15,(int)$limit));$map=(array)get_option(self::MAP,[]);
  $summary=['checked'=>0,'ready'=>0,'waiting_review'=>0,'already_waiting'=>0,
    'unverified'=>0,'excluded'=>0,'errors'=>[]];
  $all=array_values(array_filter($map,static function($m)use($only_id){
   return !empty($m['job_id'])
     && !in_array(($m['state']??''),['COMPLETED','CANCELLED'],true)
     && (!$only_id||$m['job_id']===$only_id);
  }));
  $total=count($all);
  $cursor_key='mad4b_wpml_content_job_qa_cursor_v1';
  $cursor=(int)get_option($cursor_key,0);
  if(!$only_id&&class_exists('MAD4B_WPML_Frontend_Priority_V1')){
   usort($all,static function($a,$b){
    $ra=MAD4B_WPML_Frontend_Priority_V1::rank($a['source_post_id'],$a['type'],$a['lang'],$a['target_post_id']);
    $rb=MAD4B_WPML_Frontend_Priority_V1::rank($b['source_post_id'],$b['type'],$b['lang'],$b['target_post_id']);
    return ($rb['priority']<=>$ra['priority'])?:strcmp($a['job_id'],$b['job_id']);
   });
  }
  // Split review capacity between visible high-priority work and older
  // unresolved work, preventing starvation when the top jobs lack receipts.
  $hot_count=$only_id?min($limit,$total):min((int)floor($limit/2),$total);
  $rows=array_slice($all,0,$hot_count);
  $rest=array_slice($all,$hot_count);
  $rest_count=count($rest);
  if($rest_count>0){
   $offset=$cursor%$rest_count;
   $take=min($limit-count($rows),$rest_count);
   for($i=0;$i<$take;$i++)$rows[]=$rest[($offset+$i)%$rest_count];
   $next_cursor=($offset+$take)%$rest_count;
  }else $next_cursor=0;
  $attempts=count($rows);
  for($i=0;$i<$attempts;$i++){
   $m=$rows[$i];
   $summary['checked']++;
   $v=self::binding(['job_id'=>$m['job_id']]);
   if(is_wp_error($v)){$summary['errors'][]=$v->get_error_code();continue;}
   if($v['master_or_post_excluded']){$summary['excluded']++;continue;}
   if(!$v['readback_verified']){$summary['unverified']++;continue;}
   $summary['ready']++;
   $job=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$m['job_id']]);
   if(is_wp_error($job)){$summary['errors'][]=$job->get_error_code();continue;}
   $row=$job['job'];
   if($row['state']==='WAITING_REVIEW'){$summary['already_waiting']++;continue;}
   if(in_array($row['state'],['COMPLETED','CANCELLED'],true))continue;
   if($row['state']==='QUEUED'){
    $first=MAD4B_SCP_Content_Jobs::transition_job([
     'job_id'=>$m['job_id'],'expected_revision'=>(int)$row['job_revision'],
     'state'=>'RUNNING','stage'=>'FINAL_QA',
     'reason'=>'Verified WPML translation and localization receipts passed native readback; editor review pending.']);
    if(is_wp_error($first)){$summary['errors'][]=$first->get_error_code();continue;}
    $row=$first['job'];
   }
   if($row['state']==='RUNNING'){
    $next=MAD4B_SCP_Content_Jobs::transition_job([
     'job_id'=>$m['job_id'],'expected_revision'=>(int)$row['job_revision'],
     'state'=>'WAITING_REVIEW','stage'=>'FINAL_QA',
     'reason'=>'Localization verified against WPML and Elementor source; awaiting editorial approval.']);
    if(is_wp_error($next)){$summary['errors'][]=$next->get_error_code();continue;}
    $summary['waiting_review']++;
   }
  }
  if(!$only_id&&$total>0)update_option($cursor_key,$next_cursor,false);
  return ['contract'=>'mad4b.wpml.contentjob-qa-reconcile.v1','summary'=>$summary,
   'source_count'=>$total,'frontend_first_review_slots'=>$hot_count,
   'next_review_cursor'=>$only_id?null:$next_cursor,
   'content_published'=>false,'translation_generated'=>false];
 }
 public static function approve_verified($id,$review_reference){
  if(!self::ready()||!MAD4B_SCP_Policy::can_mutate()||!current_user_can('manage_options'))
   return new WP_Error('wpml_review_authority_denied');
  $review_reference=trim(sanitize_text_field((string)$review_reference));
  if(!preg_match('/^[a-zA-Z0-9:._-]{8,110}$/D',$review_reference))
   return new WP_Error('wpml_review_reference_required');
  $binding=self::binding(['job_id'=>$id]);
  if(is_wp_error($binding))return $binding;
  if(!$binding['readback_verified']||$binding['master_or_post_excluded'])
   return new WP_Error('wpml_review_missing_verified_translation_receipts');
  $job=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$id]);
  if(is_wp_error($job))return $job;
  $row=$job['job'];
  if($row['state']==='COMPLETED')return ['job'=>$row,'already_completed'=>true];
  if($row['state']!=='WAITING_REVIEW')
   return new WP_Error('wpml_review_requires_waiting_review_state');
  $r=MAD4B_SCP_Content_Jobs::transition_job([
   'job_id'=>$id,'expected_revision'=>(int)$row['job_revision'],
   'state'=>'COMPLETED','stage'=>'FINAL_QA',
   'reason'=>'WPML localization reviewed and approved under reference '.$review_reference.
     '; native translation and source readback verified.']);
  if(is_wp_error($r))return $r;
  return ['contract'=>'mad4b.wpml.contentjob-reviewed-completion.v1',
   'state'=>$r['job']['state'],'review_reference'=>$review_reference,
   'job_id'=>$id,'content_published'=>false];
 }
 /** Validate external Elementor/JetEngine links in one draft, without mutation. */
 private static function draft_reference_qa($source,$target,$lang){
  $raw=(string)get_post_meta($source,'_elementor_data',true);
  $translated=(string)get_post_meta($target,'_elementor_data',true);
  $base=json_decode($raw,true);$local=json_decode($translated,true);
  if(!is_array($base)||!is_array($local))
   return ['verified'=>false,'checked'=>0,'issues'=>[['code'=>'elementor_tree_unavailable']]];
  $flatten=static function($nodes)use(&$flatten){
   $out=[];
   foreach((array)$nodes as $node){
    if(!is_array($node))continue;
    if(isset($node['id']))$out[(string)$node['id']]=(array)($node['settings']??[]);
    foreach($flatten($node['elements']??[]) as $key=>$value)$out[$key]=$value;
   }
   return $out;
  };
  $src=$flatten($base);$dst=$flatten($local);$checked=0;$issues=[];
  $ref_types=['lisitng_id'=>'jet-engine','listing_id'=>'jet-engine',
   'listing_item_id'=>'jet-engine','template_id'=>'elementor_library',
   'filter_id'=>'jet-smart-filters','popup_id'=>'elementor_library'];
  foreach($src as $node=>$settings){
   if(!array_key_exists($node,$dst)){
    $issues[]=['code'=>'missing_widget','widget'=>$node];continue;
   }
   foreach($ref_types as $field=>$type){
    if(!array_key_exists($field,$settings))continue;
    $raw=$settings[$field];$is_list=is_array($raw);
    $sources=[];
    if($is_list){
     if(!$raw||count($raw)>24||array_keys($raw)!==range(0,count($raw)-1)){
      $issues[]=['code'=>'invalid_reference_array','widget'=>$node,'field'=>$field];continue;
     }
     foreach($raw as $v){
      if(!is_scalar($v)||!ctype_digit((string)$v)||(int)$v<=0){
       $issues[]=['code'=>'invalid_reference_array_item','widget'=>$node,'field'=>$field];
       continue 2;
      }
      $sources[]=(int)$v;
     }
    }elseif(is_scalar($raw)&&ctype_digit((string)$raw)&&(int)$raw>0){
     $sources[]=(int)$raw;
    }else continue;
    $current=$dst[$node][$field]??null;
    $targets=is_array($current)?array_values($current):[$current];
    if($is_list!==is_array($current)||count($targets)!==count($sources)){
     $issues[]=['code'=>'reference_shape_mismatch','widget'=>$node,'field'=>$field];
     continue;
    }
    foreach($sources as $position=>$source_id){
     $checked++;
     $found=is_scalar($targets[$position]??null)?(int)$targets[$position]:0;
     if(get_post_type($source_id)!==$type){
      $issues[]=['code'=>'invalid_source_reference','widget'=>$node,
       'field'=>$field,'source_id'=>$source_id,'expected_type'=>$type];
      continue;
     }
     $expected=(int)apply_filters('wpml_object_id',$source_id,$type,false,$lang);
     if(!$expected&&$type==='elementor_library'
       &&class_exists('MAD4B_WPML_Translation_Need_V1')){
       $need=MAD4B_WPML_Translation_Need_V1::source($source_id);
       if(is_array($need)&&($need['classification']??'')==='shared_dynamic_provider'
         &&$found===$source_id)continue;
     }
     if(!$expected){
      $issues[]=['code'=>'localized_reference_missing','widget'=>$node,
       'field'=>$field,'source_id'=>$source_id,'expected_type'=>$type];
     }elseif($expected!==$found){
      $issues[]=['code'=>'localized_reference_mismatch','widget'=>$node,
       'field'=>$field,'source_id'=>$source_id,'expected_target_id'=>$expected,
       'actual_target_id'=>$found];
     }elseif(get_post_status($expected)!=='publish'){
      // A correctly WPML-linked draft is NOT a usable published provider.
      $issues[]=['code'=>'localized_reference_unpublished','widget'=>$node,
       'field'=>$field,'source_id'=>$source_id,'target_id'=>$expected,
       'target_status'=>get_post_status($expected)];
     }
     if($type==='jet-engine'){
      // Data-source readiness is independent of correct listing IDs.
      $listing=(array)get_post_meta($source_id,'_listing_data',true);
      $entity=(string)($listing['post_type']??'');
      if(($listing['source']??'')==='posts'&&$entity==='custom-reviews'){
       $counts=wp_count_posts($entity);
       $public_count=is_object($counts)?(int)($counts->publish??0):0;
       if($public_count<=0){
        $issues[]=['code'=>'verified_reviews_data_missing','widget'=>$node,
         'field'=>$field,'source_id'=>$source_id,'data_post_type'=>$entity,
         'verified_published_reviews'=>$public_count];
       }
      }
     }
    }
   }
  }
  return ['verified'=>!$issues,'checked'=>$checked,'issues'=>array_slice($issues,0,15)];
 }
 /** A visible review ledger, separate from the translation execution queue. */
 public static function review_dashboard($limit=80){
  if(!self::ready()||!MAD4B_SCP_Policy::can_read())
   return new WP_Error('wpml_review_dashboard_read_denied');
  $limit=max(1,min(100,(int)$limit));$map=(array)get_option(self::MAP,[]);
  $outbox=(array)get_option('mad4b_elementor_localization_outbox_v1',[]);
  $rows=[];$totals=['draft_pending_review'=>0,'review_with_published_target'=>0,
    'draft_without_native_review'=>0,'needs_translation_or_other'=>0,
    'draft_references_unresolved'=>0,'draft_references_verified'=>0];
  foreach($map as $m){
   $source=(int)($m['source_post_id']??0);$lang=(string)($m['lang']??'');
   $type=(string)($m['type']??'');
   if(!$source||!$lang||!$type||empty($m['job_id']))continue;
   $target=(int)apply_filters('wpml_object_id',$source,$type,false,$lang);
   $post_status=$target?(string)get_post_status($target):'missing';
   $job_state=(string)($m['state']??'UNKNOWN');
   $native=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$m['job_id']]);
   if(!is_wp_error($native))$job_state=(string)($native['job']['state']??$job_state);
   if($job_state==='CANCELLED'||$job_state==='COMPLETED')continue;
   $outbox_states=[];
   foreach($outbox as $issue)if((int)($issue['source_post_id']??0)===$source
       &&($issue['target_language']??'')===$lang)
    $outbox_states[(string)($issue['status']??'unknown')]=true;
   $state_list=array_keys($outbox_states);
   $is_draft=$post_status==='draft';
   $reference_qa=$is_draft&&$type==='elementor_library'
      ?self::draft_reference_qa($source,$target,$lang)
      :['verified'=>null,'checked'=>0,'issues'=>[]];
   $review_blockers=$is_draft
      ?['editorial_review','public_render_readback','unpublished_translation']
      :['editorial_review_or_receipts'];
   if($is_draft&&$reference_qa['verified']===false)
     $review_blockers[]='wpml_reference_mapping_unresolved';
   elseif($is_draft)$review_blockers[]='wpml_reference_mapping_verified';
   $is_review=$job_state==='WAITING_REVIEW';
   if($is_draft){
    if($is_review)$totals['draft_pending_review']++;
    else $totals['draft_without_native_review']++;
    if($reference_qa['verified']===false)$totals['draft_references_unresolved']++;
    elseif($reference_qa['verified']===true)$totals['draft_references_verified']++;
   }elseif($is_review)$totals['review_with_published_target']++;
   else $totals['needs_translation_or_other']++;
   if(!$is_draft&&!$is_review)continue;
   if(count($rows)>=$limit)continue;
   $rows[]=['job_id'=>$m['job_id'],'native_state'=>$job_state,
     'source_post_id'=>$source,'source_title'=>get_the_title($source),
     'target_post_id'=>$target,'target_post_status'=>$post_status,
     'target_language'=>$lang,'post_type'=>$type,
     'outbox_statuses'=>$state_list,
     'review_kind'=>$is_draft?'unpublished_wpml_draft':'published_target_pending_review',
     'publicly_visible_translation'=>$post_status==='publish',
     'review_blockers'=>$review_blockers,
     'reference_qa'=>$reference_qa,
     'auto_completed'=>false];
  }
  return ['contract'=>'mad4b.wpml.review-dashboard.v1',
   'site'=>home_url(),'totals'=>$totals,'rows'=>$rows,
   'record_count'=>count($rows),'publication_changed'=>false,'job_state_changed'=>false];
 }
 public static function register_abilities(){
  if(!function_exists('wp_register_ability')||!self::ready())return;
  $name='mad4b/wpml-contentjob-binding';
  if(function_exists('wp_has_ability')&&wp_has_ability($name))return;
  wp_register_ability($name,[
   'label'=>'Resolve Native ContentJob to WPML Task and Translation Evidence',
   'description'=>'Scoped read-only mapping from ContentJob ID to source/target and WPML translation status.',
   'category'=>'mad4b-read',
   'execute_callback'=>static fn($input)=>self::binding((array)$input),
   'permission_callback'=>static fn($input)=>MAD4B_SCP_Policy::can_read(),
   'input_schema'=>['type'=>'object','properties'=>[
    'job_id'=>['type'=>'string','minLength'=>36,'maxLength'=>36]],
    'required'=>['job_id'],'additionalProperties'=>false],
   'output_schema'=>['type'=>'object','additionalProperties'=>true],
   'meta'=>['mcp'=>['public'=>false,'type'=>'tool','surface'=>'read']]
  ]);
  $dashboard='mad4b/wpml-review-dashboard';
  if(!function_exists('wp_has_ability')||!wp_has_ability($dashboard))
   wp_register_ability($dashboard,[
    'label'=>'WPML Pending Draft and ContentJob Review Dashboard',
    'description'=>'Read-only visibility of WPML draft translation status, ContentJob review state and pending outbox items.',
    'category'=>'mad4b-read',
    'execute_callback'=>static fn($input)=>self::review_dashboard((int)($input['limit']??80)),
    'permission_callback'=>static fn($input)=>MAD4B_SCP_Policy::can_read(),
    'input_schema'=>['type'=>'object','properties'=>[
      'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>100]],
     'additionalProperties'=>false],
    'output_schema'=>['type'=>'object','additionalProperties'=>true],
    'meta'=>['mcp'=>['public'=>false,'type'=>'tool','surface'=>'read']]
   ]);
 }
 /** Refresh cached ContentJob states from the native authority; no job changes. */
 public static function refresh_native_states($limit=40){
  if(!self::ready()||!class_exists('MAD4B_SCP_Policy')
     ||true!==MAD4B_SCP_Policy::can_mutate())return new WP_Error('wpml_map_sync_not_authorized');
  global $wpdb;
  if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,2)','mad4b_wpml_map_state_sync'))!=='1')
   return new WP_Error('wpml_map_sync_busy');
  try{
   $map=(array)get_option(self::MAP,[]);
   $keys=array_keys($map);$n=count($keys);
   $limit=max(1,min(80,(int)$limit));
   if(!$n)return ['contract'=>'mad4b.wpml.map-native-state-sync.v1','scanned'=>0,'updated'=>0];
   $cursor_key='mad4b_wpml_map_native_status_cursor_v1';
   $cursor=(int)get_option($cursor_key,0)%$n;
   $scan=min($limit,$n);$changed=[];$errors=[];
   for($i=0;$i<$scan;$i++){
    $key=$keys[($cursor+$i)%$n];
    $id=(string)($map[$key]['job_id']??'');
    if(!$id)continue;
    $actual=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$id]);
    if(is_wp_error($actual)){$errors[]=$actual->get_error_code();continue;}
    $state=(string)($actual['job']['state']??'');
    if(!in_array($state,['QUEUED','RUNNING','WAITING_REVIEW','COMPLETED','CANCELLED','FAILED'],true))continue;
    if(($map[$key]['state']??'')!==$state){
     $map[$key]['state']=$state;$map[$key]['observed_native_state_at']=gmdate('c');
     $changed[]=$id;
    }
   }
   if($changed){
    update_option(self::MAP,$map,false);
    if(get_option(self::MAP,[])!==$map)return new WP_Error('wpml_map_sync_readback_failed');
   }
   update_option($cursor_key,($cursor+$scan)%$n,false);
   return ['contract'=>'mad4b.wpml.map-native-state-sync.v1',
     'scanned'=>$scan,'updated'=>count($changed),'ids'=>array_slice($changed,0,15),
     'errors'=>array_slice($errors,0,8),
     'next_cursor'=>($cursor+$scan)%$n,'content_mutated'=>false,'job_state_mutated'=>false];
  }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)','mad4b_wpml_map_state_sync'));}
 }
 public static function cli($args,$opts){
  $cmd=$args[0]??'status';
  if($cmd==='preview'||$cmd==='status')$r=self::preview();
  elseif($cmd==='run')$r=self::run($opts['limit']??5,$opts['source']??0);
  elseif($cmd==='reconcile')$r=self::reconcile_verified($opts['limit']??5,$opts['job-id']??'');
  elseif($cmd==='sync-state')$r=self::refresh_native_states($opts['limit']??40);
  elseif($cmd==='review-dashboard')$r=self::review_dashboard($opts['limit']??80);
  elseif($cmd==='approve')$r=self::approve_verified($args[1]??'',$opts['review-ref']??'');
  else WP_CLI::error('Usage: wp mad4b-wpml-jobs preview|status|run|reconcile|approve <job-id> --review-ref=...');
  if(is_wp_error($r))WP_CLI::error($r->get_error_code());
  WP_CLI::line(wp_json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
 }
 public static function boot(){
  if(!self::ready())return;
  add_action('wp_abilities_api_init',[__CLASS__,'register_abilities'],45);
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
