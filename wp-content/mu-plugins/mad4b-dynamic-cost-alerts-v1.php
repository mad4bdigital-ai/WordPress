<?php
/**
 * Plugin Name: MAD4B Dynamic Cost Readiness Alerts
 * Version: 1.0.0
 * Description: Source-scoped cost findings in the native readiness ledger. No new cron, rates, receipts or payment authority.
 */
defined('ABSPATH')||exit;
final class MAD4B_Dynamic_Cost_Alerts_V1 {
 const SOURCE='mad4b-dynamic-cost-readiness';
 const CONTRACT='mad4b.dynamic-cost-readiness.v1';
 const CURSOR='mad4b_dynamic_cost_alert_cursor_v1';
 static bool $busy=false;
 static bool $wake=false;
 static function allowed(int $id):bool {
  return class_exists('MAD4B_Product_Cost_Review_Ledger_V1',false)&&
   MAD4B_Product_Cost_Review_Ledger_V1::allowed($id)&&
   get_current_user_id()>0&&!get_user_meta(get_current_user_id(),'mad4b_company_suspension_v1',true)&&
   in_array(get_post_status($id),['publish','draft','pending','private'],true);
 }
 static function scope(array $context):string {
  if(!$context)return 'structural';
  $c=MAD4B_Generic_Cost_Composition_V1::context($context);
  return substr(hash('sha256',wp_json_encode($c)),0,24);
 }
 static function finding(int $id,string $scope,string $group,string $reason,string $label,array $details=[],array $context=[]):array {
  $type=get_post_type($id);$code='dynamic_cost_'.substr(hash('sha256',$scope.'|'.$group.'|'.$reason),0,24);
  $key=hash('sha256',get_current_blog_id().'|'.$type.'|'.$id.'|'.$code);
  return ['id'=>$key,'post_type'=>$type,'object_id'=>$id,'code'=>$code,'severity'=>'high',
   'label'=>mb_strcut('Cost · '.$label,0,239,'UTF-8'),'source'=>self::SOURCE,
   'source_evidence'=>['contract'=>self::CONTRACT,'scope'=>$scope,'group'=>$group,'reason'=>$reason,
    'owner_group'=>'pricing','context'=>$context,'details'=>$details,
    'fix_url'=>self::url($id,$context),'source_is_commercial_approval'=>false,'checkout_authorized'=>false]];
 }
 static function costLink(array $row):bool {
  $source=$row['source']??'';
  return in_array($source,[self::SOURCE,'mad4b-package-cost-readiness'],true)||
   ($source==='mad4b-rate-planner'&&preg_match('/^rate_plan_(?:package_stage_source_incomplete|package_cost_composition_missing|combined_tour_supplier_cost_missing|profit_unverified|price_missing|source_policy_review)_/',(string)($row['code']??'')));
 }
 static function url(int $id,array $context=[]):string {
  $url=admin_url('admin.php?page=mad4b-product-cost-review&product_id='.$id);
  foreach(MAD4B_Generic_Cost_Composition_V1::context($context) as $key=>$v)
   if($context&&$v!==null&&$v!=='')$url=add_query_arg($key,(string)$v,$url);
  return $url;
 }
 /** Each check explicitly reports its coverage; an omitted check never closes an alert. */
 static function scan(int $id,array $context=[]):array|WP_Error {
  if(!self::allowed($id))return new WP_Error('dynamic_cost_alert_scope_denied');
  $type=get_post_type($id);$scope=self::scope($context);
  $rows=MAD4B_Cost_Source_Registry_V1::rows($id,$type);
  $issues=[];$covered=[];$groups=[];$members=[];
  $add=static function($group,$reason,$label,$details=[])use(&$issues,$id,$scope,$context){
   $r=self::finding($id,$scope,$group,$reason,$label,$details,$context);$issues[$r['id']]=$r;
  };
  foreach($rows as $row){
   $v=$row['value']??[];$m=$v['mapping']??[];
   $key=(string)($row['source']??'');
   if(!str_starts_with($key,'registry:')||!is_array($m))continue;
   $group=$m['component_key']??'';
   if($group==='')$group=$key;
   $groups[$group][]=$row;
  }
  $partial=false;
  foreach($rows as $row)if(($row['component']??'')==='Registry candidate limit'||($row['value']['issue']??'')==='partial_candidate_set')$partial=true;
  if(!$context&&$partial)$add('registry','partial_candidate_set','Source candidate limit reached; inspect registry coverage');
  elseif(!$context)$covered['registry']=true;
  foreach($groups as $group=>$rr){
   $group=(string)$group;$check='source:'.$group;$invalid=[];$mappingKeys=[];$policies=[];$units=[];$identities=[];
   foreach($rr as $row){
    $v=$row['value']??[];$m=$v['mapping']??[];
    $mappingKeys[]=(string)($row['source']??'');
    $identities[]=hash('sha256',(string)($row['source']??''));
    if(!empty($v['entity_id']))$identities[]=hash('sha256',wp_json_encode([$row['source']??'', $v['entity_id'],$v['row_key']??null]));
    $policies[]=wp_json_encode(array_intersect_key($m,array_flip(['component_role','selection_mode','inclusion','quantity_source','fixed_quantity'])));
    if(($row['status']??'')==='missing')$invalid[]=[
     'source_id'=>$v['entity_id']??null,'source_type'=>$v['entity_type']??null,
     'row_key'=>$v['row_key']??null,'amount_field'=>$m['amount_field']??null,
     'currency_field'=>$m['currency_field']??null,'basis_field'=>$m['basis_field']??null,
     'relation_id'=>$m['relation_id']??0,'issue'=>$v['issue']??'cost_currency_or_basis_missing'];
    elseif(($row['status']??'')==='reverify')$units[]=(string)($v['basis']??'');
   }
   if(!$context&&$invalid)$add($check,'missing_source','Missing cost source / amount / basis: '.$group,array_slice($invalid,0,30));
   if(!$context&&count(array_unique($policies))>1)$add($check,'conflicting_policy','Conflicting component selection, quantity or inclusion: '.$group);
   $mode=$rr[0]['value']['mapping']['selection_mode']??'manual';
   if(!$context&&in_array($mode,['cheapest_eligible','priority'],true)&&count(array_unique($units))>1)
    $add($check,'incomparable_basis','Incompatible billing units: '.$group);
   sort($identities);$members[$check]=$identities;$members['math:'.$group]=$identities;
   if(!$context&&!$partial)$covered[$check]=true;
  }
  // Scope-specific arithmetic is evaluated only with real saved operator context.
  // Do not fabricate a party size, currency or departure for an unattended scan.
  if($context){
   $c=MAD4B_Generic_Cost_Composition_V1::context($context);
   $g=MAD4B_Generic_Cost_Composition_V1::generic($rows,$c,MAD4B_Generic_Cost_Composition_V1::fxSnapshot());
   foreach((array)$g['blockers'] as $block){
    $group='registry';$reason=(string)$block;
    foreach(array_keys($groups) as $candidate)if(str_starts_with((string)$block,$candidate.':')&&strlen($candidate)>strlen($group==='registry'?'':$group)){
     $group=$candidate;$reason=substr((string)$block,strlen($candidate)+1);
    }
    if(in_array($reason,['missing_cost_or_quantity','fx_reference_missing','source_already_counted','duplicate_source_instance','manual_selection_required','condition_unresolved','incomparable_basis','conflicting_component_policies','amount_limit_exceeded','no_calculable_reference','invalid_selection_mode'],true))
     $add('math:'.$group,$reason,'Cost calculation: '.$group.' — '.str_replace('_',' ',$reason),['blocker'=>$block]);
   }
   if(!$partial)foreach(array_keys($groups) as $group)$covered['math:'.$group]=true;
   // For exact dated native context, re-use the native ledger's real preflight.
   if($type==='tours-and-activities'&&!empty($c['date'])&&!empty($c['tier_id'])){
    $v=MAD4B_Product_Cost_Review_Ledger_V1::inspect($id,$context);
    if(!is_wp_error($v)){
     $n=$v['original_readbacks']['generic_cost_composition']['native_composition']??null;
     if(is_array($n)){
      foreach((array)$n['lines'] as $line)if(($line['target_total_minor']??null)===null)
       $add('native:fx','fx_reference_missing','Native cost conversion missing; review source currency and FX');
      foreach((array)$n['blockers'] as $b)if(in_array($b,['guide_cost_reference_missing','guide_language_selection_required','guide_count_required'],true))
       $add('native:guide-context',$b,'Guide calculation — '.str_replace('_',' ',$b));
      $nativeLineKeys=array_map(static fn($l)=>hash('sha256',(string)($l['line_key']??'')),(array)$n['lines']);
      $members['native:fx']=$nativeLineKeys;
      if($nativeLineKeys&&!empty($v['original_readbacks']['dated_indicative_stage_composition']['stage_count']))$covered['native:fx']=true;
      $covered['native:guide-context']=true;
     }
    }
   }
  }elseif($type==='tours-and-activities'&&class_exists('MAD4B_Package_Components_Editor_Context_V1',false)&&MAD4B_Package_Components_Editor_Context_V1::isPackage($id)){
   // Native guide/transport classification is independent of a party quote.
   if(class_exists('MAD4B_Guide_Service_Composition_V1',false)){
    $s=MAD4B_Guide_Service_Composition_V1::source($id);
    if(!is_wp_error($s)){
     $saved=MAD4B_Guide_Service_Composition_V1::saved($s);
     if(is_wp_error($saved))$add('native:guide','guide_coverage','Guide coverage needs review: '.$saved->get_error_code());
     else{
      $unknown=array_keys(array_filter($saved['statuses'],static fn($v)=>$v==='unknown'));
      if($unknown)$add('native:guide','guide_coverage','Guide service days remain unreviewed',['days'=>$unknown]);
      if(in_array('half_day',$saved['statuses'],true)&&empty($saved['half_day_factor']))
       $add('native:guide','guide_half_day_factor','Half-day guide cost factor is missing');
     }
     $members['native:guide']=array_map(static fn($k)=>hash('sha256',(string)$k),array_keys((array)($s['days']??[])));
     if($members['native:guide'])$covered['native:guide']=true;
     $it=$s['source_kind']==='term'?get_term_meta((int)$s['source_id'],'program_itinerary',true):get_post_meta((int)$s['source_id'],'itinerary_item',true);
     if(is_array($it)&&$it){
      $unknown=[];$endpoints=[];$required=[];$valid=true;$dayIds=[];
      foreach($it as $k=>$r){
       if(!is_array($r)){$valid=false;continue;}
       $dayIds[]=hash('sha256',(string)$k);$need=$r['mad4b_transport_need_v1']??'review';if($need==='required')$required[]=(string)$k;
       if(!in_array($need,['required','optional','none'],true))$unknown[]=(string)$k;
       elseif($need==='required'&&(!(int)($r['mad4b_transport_from_term_v1']??0)||!(int)($r['mad4b_transport_to_term_v1']??0)))$endpoints[]=(string)$k;
      }
      if($unknown)$add('native:transport','transport_classification','Transport need is unreviewed on '.count($unknown).' itinerary rows',['days'=>$unknown,'field'=>'mad4b_transport_need_v1']);
      if($endpoints)$add('native:transport','transport_endpoints','Required transport endpoints missing',['days'=>$endpoints,'fields'=>['mad4b_transport_from_term_v1','mad4b_transport_to_term_v1']]);
      if($required)$add('native:transport-cost','transport_cost_admission','Required transport cost evidence needs native admission',['days'=>$required]);
      $members['native:transport']=$dayIds;$members['native:transport-cost']=$dayIds;
      if($valid)$covered['native:transport']=true;
      // Explicitly all nonrequired, with no unknown days, is a valid no-required-transport decision.
      if($valid&&!$unknown&&!$required)$covered['native:transport-cost']=true;
     }
    }
   }
   if(!has_filter('mad4b/package-cost-receipt/verify-authority')||!has_filter('mad4b/package_rate_generator/approved_price'))
    $add('native:authority','composition_authority','Commercial composition / signed receipt provider is not connected');
   else {
    // Mere presence of a callback is not verification; leave old authority alert open.
   }
   // CP4 stage receipts and native planner issues remain owned by original providers.
  }
  foreach($issues as &$row){$group=$row['source_evidence']['group'];if(isset($members[$group]))$row['source_evidence']['coverage_members']=$members[$group];}
  unset($row);
  return ['contract'=>self::CONTRACT,'product_id'=>$id,'scope'=>$scope,'issues'=>array_values($issues),
   'covered_groups'=>array_keys($covered),'coverage_members'=>$members,'complete_registry_candidate_set'=>!$partial];
 }
 /** Central ledger changes only from exact same covered scope. Preserve other providers and missing groups. */
 static function merge(array $old,array $archive,array $scan,string $now):array {
  $new=$old;$seen=[];$id=(int)$scan['product_id'];$scope=$scan['scope'];
  foreach($scan['issues'] as $row){
   $key=$row['id'];$seen[$key]=true;$before=$old[$key]??$archive[$key]??[];
   $new[$key]=array_merge($before,$row,['status'=>'open','first_seen'=>$before['first_seen']??$now,'last_seen'=>$now,
    'resolved_at'=>null,'reopens'=>(int)($before['reopens']??0)+(($before['status']??'')==='resolved'?1:0)]);
  }
  foreach($new as $key=>$row){
   if(($row['source']??'')!==self::SOURCE||(int)($row['object_id']??0)!==$id||
      ($row['source_evidence']['scope']??'')!==$scope||isset($seen[$key])||($row['status']??'')==='resolved')continue;
   $group=$row['source_evidence']['group']??'';
   if(!in_array($group,(array)$scan['covered_groups'],true))continue;
   $previousMembers=(array)($row['source_evidence']['coverage_members']??[]);
   if($previousMembers&&array_diff($previousMembers,(array)($scan['coverage_members'][$group]??[])))continue;
   $new[$key]['status']='resolved';$new[$key]['resolved_at']=$now;
  }
  return $new;
 }
 static function reconcile(int $id,array $context=[]):array|WP_Error {
  if(!self::allowed($id)||!class_exists('MAD4B_SCP_Content_Readiness_Alerts',false))return new WP_Error('dynamic_cost_alert_permission_denied');
  $owner='MAD4B_SCP_Content_Readiness_Alerts';
  global $wpdb;$lock='mad4b_wpml_url_jobs_'.get_current_blog_id();
  if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)return new WP_Error('dynamic_cost_alert_ledger_busy');
  try{
   $scan=self::scan($id,$context);if(is_wp_error($scan))return $scan;
   $old=(array)get_option($owner::STORE,[]);$archive=defined($owner.'::RESOLVED_ARCHIVE')?(array)get_option($owner::RESOLVED_ARCHIVE,[]):[];
   $new=self::merge($old,$archive,$scan,gmdate('c'));
   if(count($new)>$owner::MAX_ISSUES)return new WP_Error('dynamic_cost_alert_capacity');
   if($new!==$old){
    update_option($owner::STORE,$new,false);if(get_option($owner::STORE,[])!==$new)return new WP_Error('dynamic_cost_alert_readback_failed');
    if(class_exists('MAD4B_SCP_Readiness_Integrations',false))MAD4B_SCP_Readiness_Integrations::record_changes($old,$new);
   }
   if(class_exists('MAD4B_Lifecycle_Access',false)&&MAD4B_Lifecycle_Access::can('commerce_manage_request_tasks')&&
      class_exists('MAD4B_SCP_Policy',false)&&MAD4B_SCP_Policy::can_mutate()&&class_exists(\FluentBoards\App\Models\Task::class)){
    foreach($new as $key=>$row){
     if(($row['source']??'')!==self::SOURCE||(int)$row['object_id']!==$id||($row['source_evidence']['scope']??'')!==$scan['scope']||empty($row['board_task_id']))continue;
     if(($old[$key]['status']??'')===($row['status']??''))continue;
     $task=\FluentBoards\App\Models\Task::find((int)$row['board_task_id']);
     if($task&&$task->source==='mad4b-readiness'&&(string)$task->source_id===$key){
      if($row['status']==='resolved')$task->close();elseif($task->status==='closed')$task->reopen();
     }
    }
   }
   return ['product_id'=>$id,'scope'=>$scan['scope'],'open'=>count($scan['issues']),'covered'=>count($scan['covered_groups']),'store'=>$owner::STORE];
  }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
 }
 static function refresh(int $id):array {
  $results=[];if(!self::allowed($id))return $results;
  $results[]=self::reconcile($id);
  $contexts=[];$history=MAD4B_Product_Cost_Review_Ledger_V1::history($id);
  $latest=$history?end($history):[];
  if(!empty($latest['review_context']))$contexts[self::scope($latest['review_context'])]=$latest['review_context'];
  // Revisit existing unresolved exact contexts as well; do not close a different occupancy or date.
  foreach((array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,[]) as $row){
   $e=$row['source_evidence']??[];
   if(($row['source']??'')===self::SOURCE&&(int)$row['object_id']===$id&&($row['status']??'')!=='resolved'&&!empty($e['context']))
    $contexts[self::scope($e['context'])]=$e['context'];
  }
  foreach(array_slice($contexts,0,8,true) as $c)$results[]=self::reconcile($id,$c);
  return $results;
 }
 static function mappedTypes():array {
  $types=[];
  foreach(MAD4B_Cost_Source_Registry_V1::mappings() as $m){
   if($m['kind']==='taxonomy'){
    $t=get_taxonomy($m['slug']);foreach((array)($t->object_type??[]) as $p)$types[$p]=true;
   }else{
    $types[$m['slug']]=true;
    if(!empty($m['relation_id'])){
     $ends=MAD4B_Cost_Component_Rules_V1::relationEndpoints(MAD4B_Cost_Component_Rules_V1::relation((int)$m['relation_id']));
     if($ends)$types[$ends[$m['relation_direction']==='children'?'parent':'child']]=true;
    }elseif(!empty($m['relation_field'])){
     foreach(MAD4B_Product_Cost_Review_Ledger_V1::types() as $p=>$label)$types[$p]=true;
    }
   }
  }
  return array_values(array_filter(array_keys($types),static fn($p)=>MAD4B_Cost_Source_Registry_V1::eligible('post_type',$p)));
 }
 static function batch():array {
  if(self::$busy||!current_user_can('commerce_manage_pricing')||!get_current_user_id())return ['status'=>'held'];
  self::$busy=true;
  try{
   $types=self::mappedTypes();
   $packages=get_posts(['post_type'=>'tours-and-activities','post_status'=>'publish','fields'=>'ids','posts_per_page'=>161,
    'meta_key'=>'_mad4b_package_accommodation_source_plan_v1','suppress_filters'=>true,'orderby'=>'ID','order'=>'ASC']);
   $ids=count($packages)<=160?$packages:[];
   $reviewed=get_posts(['post_type'=>array_keys(MAD4B_Product_Cost_Review_Ledger_V1::types()),'post_status'=>['publish','draft','pending','private'],'fields'=>'ids','posts_per_page'=>81,'meta_key'=>MAD4B_Product_Cost_Review_Ledger_V1::HISTORY,'suppress_filters'=>true,'orderby'=>'ID','order'=>'ASC']);
   if(count($reviewed)<=80)$ids=array_merge($ids,$reviewed);
   $cursor=max(0,(int)get_option(self::CURSOR,0));
   if($types){
    $page=get_posts(['post_type'=>$types,'post_status'=>['publish','draft','pending'],'fields'=>'ids','posts_per_page'=>20,
     'offset'=>$cursor,'orderby'=>'ID','order'=>'ASC','suppress_filters'=>true]);
    $ids=array_merge($ids,$page);update_option(self::CURSOR,count($page)<20?0:$cursor+20,false);
   }
   // Published native packages and bounded dynamic product census; no absent object closes alerts.
   $out=[];foreach(array_unique(array_map('intval',$ids)) as $id){
    if(!self::allowed($id))continue;
    $type=get_post_type($id);$lang=apply_filters('wpml_default_language',null);
    $canonical=$lang?apply_filters('wpml_object_id',$id,$type,false,$lang):$id;
    if($canonical&&(int)$canonical!==$id)continue;
    $out[$id]=self::refresh($id);
   }
   return ['status'=>'scanned','products'=>count($out),'results'=>$out,'package_census_complete'=>count($packages)<=160];
  }finally{self::$busy=false;}
 }
 static function wake():void {
  if(self::$busy||self::$wake)return;self::$wake=true;
  if(class_exists('MAD4B_Rate_Planner',false)&&function_exists('mad4b_rate_authoring_enabled')&&mad4b_rate_authoring_enabled())MAD4B_Rate_Planner::schedule();
 }
 static function postMeta($mid,$id,$key):void {
  if(!class_exists('MAD4B_Product_Cost_Review_Ledger_V1',false)||!class_exists('MAD4B_Cost_Source_Registry_V1',false)||!is_string($key)||str_starts_with($key,'_rate_plan_')||str_starts_with($key,'_mad4b_rate_plan'))return;
  if($key===MAD4B_Product_Cost_Review_Ledger_V1::HISTORY){self::wake();return;}
  foreach(MAD4B_Cost_Source_Registry_V1::mappings() as $m)
   if(in_array($key,[$m['amount_field'],$m['currency_field'],$m['basis_field'],$m['relation_field'],$m['repeater_field']],true)){self::wake();return;}
  if(in_array(get_post_type((int)$id),['transportations','transport-rates','operational-services','transport-com'],true))self::wake();
 }
 static function option($key):void {
  if(in_array($key,['mad4b_cost_source_registry_v1','mad4b_company_policy_registry_v2','mad4b_guide_daily_base_cost_v1','mad4b_company_hotels_uniform_template_nightly_basis_20261122_v1'],true))self::wake();
 }
 static function panel():void {
  if(($_GET['page']??'')!=='mad4b-product-cost-review')return;
  $id=absint($_GET['product_id']??0);
  if(!$id||!self::allowed($id)||!class_exists('MAD4B_SCP_Content_Readiness_Alerts',false))return;
  $issues=[];
  foreach((array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,[]) as $row)
   if((int)($row['object_id']??0)===$id&&in_array($row['source']??'',[self::SOURCE,'mad4b-package-cost-readiness','mad4b-rate-planner'],true)&&($row['status']??'')!=='resolved')$issues[]=$row;
  echo '<div class="notice notice-info"><h3>Central Cost &amp; Pricing Alerts ('.count($issues).')</h3>';
  foreach(array_slice($issues,0,20) as $r){
   echo '<p>'.esc_html($r['label']).'</p>';
   $e=$r['source_evidence']??[];
   if(($r['source']??'')===self::SOURCE&&!empty($e['details']))
    echo '<details><summary>Component / source field evidence</summary><pre style="white-space:pre-wrap;max-height:180px;overflow:auto">'.esc_html(wp_json_encode($e['details'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre></details>';
  }
  echo '<p><a href="'.esc_url(admin_url('admin.php?page=mad4b-content-readiness-alerts')).'">Open Content Readiness</a></p></div>';
 }
 static function boot():void {
  add_action('admin_notices',[self::class,'panel'],97);
  add_action('mad4b/rate-planner/completed',static function(){try{self::batch();}catch(Throwable $e){error_log('MAD4B dynamic cost alerts deferred: '.get_class($e));}},99,0);
  foreach(['added_post_meta','updated_post_meta','deleted_post_meta'] as $h)add_action($h,[self::class,'postMeta'],111,3);
  add_action('save_post',static function($id){if(in_array(get_post_type((int)$id),self::mappedTypes(),true))self::wake();},111,1);
  foreach(['added_term_meta','updated_term_meta','deleted_term_meta','set_object_terms','edited_term','jet-engine/relation/update/after','jet-engine/relation/delete/after','jet-engine/relation/update-all-meta/after'] as $h)add_action($h,[self::class,'wake'],111,0);
  foreach(['added_option','updated_option','deleted_option'] as $h)add_action($h,[self::class,'option'],111,1);
  if(defined('WP_CLI')&&WP_CLI)WP_CLI::add_command('mad4b dynamic-cost-alerts',static function($args,$assoc){
   if(!current_user_can('commerce_manage_pricing'))WP_CLI::error('Authorized pricing actor required.');
   $id=(int)($assoc['product']??0);$r=$id?self::refresh($id):self::batch();WP_CLI::line(wp_json_encode($r));
  });
 }
}
MAD4B_Dynamic_Cost_Alerts_V1::boot();
