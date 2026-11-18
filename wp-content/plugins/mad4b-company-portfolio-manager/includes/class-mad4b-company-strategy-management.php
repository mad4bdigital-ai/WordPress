<?php
/**
 * Plugin Name: MAD4B Company Strategy Management (Staging)
 * Description: Company-scoped strategy lifecycle above and separate from Brand Context.
 * Version: 0.1.0-staging
 */
defined('ABSPATH') || exit;

final class MAD4B_Company_Strategy_V1 {
 const CONTRACT='mad4b.company-strategy.v1';
 const STORE='mad4b_company_strategy_records_v1';
 const REGISTRY='mad4b_tourism_company_site_registry_v1';
 const FIELDS=['company_identity','business_strategy','brand_portfolio','products_commerce',
               'marketing_growth','content_distribution','operations','providers','performance'];
 const MODES=['inherit','override','extend','locked'];
 const STORES=['managed_native','wordpress_media','google_drive'];

 static function central() {
  // Read the signed central-host scope from the existing Company Add-on;
  // the Core retains all operation admission and tenancy checks.
  return class_exists('MAD4B_Company_Portfolio_Manager_V1',false)
   && MAD4B_Company_Portfolio_Manager_V1::staging();
 }
 static function error($code,$message,$status=409) {
  return new WP_Error('mad4b_company_strategy_'.$code,$message,['status'=>$status]);
 }
 static function companies() {
  $x=get_option(self::REGISTRY,[]);
  return is_array($x)?$x:[];
 }
 static function binding($code) {
  $code=(string)$code;
  if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$code))return self::error('bad_company','Invalid company code',422);
  $r=self::companies();
  $c=$r[$code]??null;
  if(!is_array($c)||($c['company_code']??'')!==$code||empty($c['host_binding_verified'])||
      !wp_is_uuid((string)($c['company_site_uuid']??''))||
      !wp_is_uuid((string)($c['native_site_profile_uuid']??''))||
      !preg_match('/^[a-f0-9]{64}$/D',(string)($c['native_profile_digest']??'')))
   return self::error('unverified_company','Verified Company Registry site binding required',403);
  $unique=MAD4B_Company_Portfolio_Manager_V1::unique_company_site_identity($code,(string)$c['company_site_uuid'],(string)$c['native_site_profile_uuid'],$r);
  if(is_wp_error($unique))return $unique;
  return ['company_code'=>$code,'company_site_uuid'=>(string)$c['company_site_uuid'],
   'native_site_profile_uuid'=>(string)$c['native_site_profile_uuid'],
   'native_profile_digest'=>(string)$c['native_profile_digest']];
 }
 static function request_for($code) {
  $r=new WP_REST_Request('GET','/mad4b/v1/company-strategy/companies/'.$code);
  $r->set_param('company',$code);
  return $r;
 }
 static function guard($code,$write=false) {
  if(!self::central())return self::error('wrong_environment','Central Staging only',403);
  $b=self::binding($code);if(is_wp_error($b))return $b;
  if(!class_exists('MAD4B_Company_Readiness_Governance'))return self::error('guard_missing','Company governance unavailable',503);
  $p=MAD4B_Company_Readiness_Governance::guard(self::request_for($code));
  if(is_wp_error($p))return $p;
  if($write && !(current_user_can('manage_options')&&current_user_can('activate_plugins')))
   return self::error('write_authority','Platform administrator required for strategy decisions',403);
  return $b;
 }
 static function default_record($binding) {
  return ['contract'=>self::CONTRACT,'company_code'=>$binding['company_code'],
   'company_site_uuid'=>$binding['company_site_uuid'],'revision'=>0,
   'candidate'=>null,'active'=>null,'history'=>[],'updated_at'=>null];
 }
 static function current($binding) {
  $all=get_option(self::STORE,[]);
  if(!is_array($all))return self::error('store_corrupt','Strategy registry invalid',503);
  $code=$binding['company_code'];
  $row=$all[$code]??self::default_record($binding);
  if(!is_array($row)||($row['contract']??'')!==self::CONTRACT ||
   ($row['company_code']??'')!==$code ||
   ($row['company_site_uuid']??'')!==$binding['company_site_uuid'])
   return self::error('scope_drift','Company strategy owner scope drift; reconciliation required',409);
  return $row;
 }
 static function normalize_sections($sections) {
  if(!is_array($sections)||array_is_list($sections))return self::error('sections_invalid','Sections must be a named object',422);
  if(array_diff(array_keys($sections),self::FIELDS))return self::error('sections_unknown','Unknown strategy section',422);
  $out=[];
  foreach(self::FIELDS as $key) {
   $v=$sections[$key]??'';
   if(!is_string($v)||strlen($v)>12000)return self::error('section_invalid','Section must be plain text <= 12KB',422);
   $out[$key]=sanitize_textarea_field($v);
  }
  if(!array_filter($out,static fn($v)=>trim($v)!==''))return self::error('sections_empty','At least one strategy section required',422);
  return $out;
 }
 static function normalize_policies($input) {
  if(!is_array($input)||array_is_list($input)||array_diff(array_keys($input),self::FIELDS))
   return self::error('policy_invalid','Unknown policy sections',422);
  $out=[];
  foreach(self::FIELDS as $key) {
   $v=(string)($input[$key]??'inherit');
   if(!in_array($v,self::MODES,true))return self::error('policy_invalid','Invalid inheritance mode',422);
   $out[$key]=$v;
  }
  return $out;
 }
 static function snapshot_option($binding,$revision) {
  return 'mad4b_cs_hist_v1_'.substr(hash('sha256',$binding['company_site_uuid'].'|'.$binding['company_code']),0,22).'_'.$revision;
 }
 static function revision($code,$revision) {
  $binding=self::guard($code);if(is_wp_error($binding))return $binding;
  if(!is_int($revision)||$revision<1)return self::error('bad_revision','Invalid historical revision',422);
  $row=self::current($binding);if(is_wp_error($row))return $row;
  if($revision>($row['revision']??0))return self::error('revision_future','Revision not committed',404);
  $data=get_option(self::snapshot_option($binding,$revision),null);
  if(!is_array($data)||($data['company_code']??'')!==$code||
    ($data['company_site_uuid']??'')!==$binding['company_site_uuid']||
    ($data['revision']??null)!==$revision)
   return self::error('revision_not_found','Immutable history not found',404);
  return self::public_record($data);
 }
 static function public_record($record,$include_content=true) {
  $candidate=$record['candidate'];
  $active=$record['active'];
  if(!$include_content){
   foreach(['candidate','active'] as $k) {
    $row=$record[$k]??null;
    if(is_array($row))$record[$k]=['state'=>$row['state']??null,
      'sha256'=>$row['sha256']??null,'revision'=>$row['revision']??null];
   }
  }
  return $record+['company_strategy_is_brand_context'=>false,'brand_context_approval_conferred'=>false];
 }
 static function inspect($code) {
  $b=self::guard($code);if(is_wp_error($b))return $b;
  $row=self::current($b);if(is_wp_error($row))return $row;
  return self::public_record($row);
 }
 static function catalog() {
  if(!self::central()||!is_user_logged_in())return self::error('unauthorized','Authentication required',403);
  $codes=array_keys(self::companies());
  $out=[];
  foreach($codes as $code){
   $p=self::guard($code);
   if(is_wp_error($p))continue;
   $row=self::current($p);
   if(is_wp_error($row))continue;
   $out[]=['company_code'=>$code,'company_site_uuid'=>$p['company_site_uuid'],
    'revision'=>$row['revision'],'candidate_state'=>$row['candidate']['state']??'not_started',
    'active'=>!empty($row['active'])];
  }
  return ['contract'=>'mad4b.company-strategy-catalog.v1','companies'=>$out,'count'=>count($out),
   'brand_context_separate'=>true,'production_mutation'=>false];
 }
 // Only an unmodified, empty, revision-zero record can be re-bound to the
 // original, verified company Site UUID. Drafts, histories and approvals HOLD.
 static function is_empty_legacy_binding_record($record,$company):bool {
  if(!is_array($record) || array_keys($record)!==[
   'contract','company_code','company_site_uuid','revision',
   'candidate','active','history','updated_at'])return false;
  return ($record['contract']??null)===self::CONTRACT
   &&($record['company_code']??null)===$company
   &&wp_is_uuid((string)($record['company_site_uuid']??''))
   &&($record['revision']??null)===0
   &&$record['candidate']===null
   &&$record['active']===null
   &&$record['history']===[]
   &&$record['updated_at']===null;
 }
 // Called only from the original reconciler, after its exact company guard,
 // signed Site Profile and ContentJob Skill preflight, under the same GET_LOCK.
 static function rebound_empty_legacy_binding(array $binding,array $all,array $old,array $skill) {
  $code=$binding['company_code'];
  if(!self::is_empty_legacy_binding_record($old,$code)
   ||$old['company_site_uuid']===$binding['company_site_uuid'])
   return self::error('scope_drift','Cannot rebind any non-empty or active strategy record',409);
  $original_sha=hash('sha256',maybe_serialize($old));
  $name='mad4b_cs_legacy_empty_archive_v1_'.substr(hash('sha256',
    $code.'|'.$old['company_site_uuid'].'|'.$binding['company_site_uuid'].'|'.$original_sha),0,40);
  $archive=[
   'contract'=>'mad4b.company-strategy.empty-binding-reconciliation.v1',
   'company_code'=>$code,
   'prior_site_uuid'=>$old['company_site_uuid'],
   'verified_new_site_uuid'=>$binding['company_site_uuid'],
   'prior_record_sha256'=>$original_sha,
   'prior_record'=>$old,
   'reason'=>'verified_company_site_identity_replaced_legacy_empty_record',
   'automated_strategy_activation'=>false,
   'content_job_created'=>false
  ];
  $saved=get_option($name,null);
  if($saved===null) {
   if(!add_option($name,$archive,'',false))
    return self::error('archive_failed','Immutable legacy Strategy record archive failed',503);
   $saved=get_option($name,null);
  }
  if(!is_array($saved)||$saved!==$archive)
   return self::error('archive_conflict','Legacy archive mismatch; cannot update owner scope',409);
  $all[$code]=self::default_record($binding);
  if(!update_option(self::STORE,$all,false))
   return self::error('rebind_write_failed','Empty strategy owner scope could not be reconciled',503);
  $current=self::current($binding);
  if(is_wp_error($current)||($current['revision']??null)!==0
   ||$current['candidate']!==null ||$current['active']!==null
   ||$current['history']!==[])
   return self::error('rebind_readback_failed','Rebind failed exact company readback',503);
  return [
   'contract'=>'mad4b.company-strategy-provision.v1',
   'state'=>'LEGACY_EMPTY_SCOPE_REBOUND',
   'company_code'=>$code,
   'company_site_uuid'=>$binding['company_site_uuid'],
   'previous_site_uuid'=>$old['company_site_uuid'],
   'revision'=>0,'strategy_approved'=>false,
   'archived_legacy_record_sha256'=>$original_sha,
   'legacy_archive_option'=>$name,
   'contentjob_skill_preflight'=>$skill['state'],
   'content_jobs_created'=>0,
   'remote_target_mutated'=>false,
   'financial_authority_issued'=>false
  ];
 }
 static function reconcile_company($code) {
  $binding=self::guard($code,true);if(is_wp_error($binding))return $binding;
  if(!class_exists('MAD4B_Company_ContentJob_Skill_Preflight_V1',false))
   return self::error('contentjob_preflight_missing','Company ContentJob Skill preflight is unavailable',503);
  $skill_preflight=MAD4B_Company_ContentJob_Skill_Preflight_V1::ready_for_strategy_reconcile($code);
  if(is_wp_error($skill_preflight))return $skill_preflight;
  global $wpdb;
  $lock='mad4b:cs:'.substr(hash('sha256',$code),0,40);
  if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1)
   return self::error('busy','Company strategy provision busy',423);
  try {
   $all=get_option(self::STORE,[]);
   if(!is_array($all))return self::error('store_invalid','Strategy registry invalid',503);
   if(isset($all[$code])){
    $record=self::current($binding);
    if(is_wp_error($record)&&
      $record->get_error_code()==='mad4b_company_strategy_scope_drift') {
      return self::rebound_empty_legacy_binding($binding,$all,
        (array)$all[$code],$skill_preflight);
    }
    return is_wp_error($record)?$record:
     ['contract'=>'mad4b.company-strategy-provision.v1','state'=>'EXISTS',
       'company_code'=>$code,'revision'=>$record['revision'],'strategy_approved'=>!empty($record['active']),
       'contentjob_skill_preflight'=>$skill_preflight['state'],
       'content_jobs_created'=>0];
   }
   $all[$code]=self::default_record($binding);
   if(!update_option(self::STORE,$all,false))return self::error('write_failed','Empty strategy provision failed',503);
   $read=self::current($binding);
   if(is_wp_error($read)||($read['revision']??-1)!==0)
    return self::error('readback_failed','Empty company strategy record not confirmed',503);
   do_action('mad4b_company_strategy_enrolled',$code);
   return ['contract'=>'mad4b.company-strategy-provision.v1','state'=>'EMPTY_STRATEGY_PROVISIONED',
      'company_code'=>$code,'company_site_uuid'=>$binding['company_site_uuid'],
      'revision'=>0,'brand_context_linked'=>false,'strategy_approved'=>false,
      'contentjob_skill_preflight'=>$skill_preflight['state'],
      'content_jobs_created'=>0];
  } finally {
   $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
  }
 }
 static function transact($code,$expected,$op,$payload=[]) {
  $b=self::guard($code,true);if(is_wp_error($b))return $b;
  if(!is_int($expected)||$expected<0)return self::error('revision_invalid','Exact expected revision required',422);
  global $wpdb;
  $lock='mad4b:cs:'.substr(hash('sha256',$code),0,40);
  if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1)
   return self::error('busy','Strategy transaction busy',423);
  try {
   $row=self::current($b);if(is_wp_error($row))return $row;
   if($row['revision']!==$expected)return self::error('stale','Strategy revision changed',409);
   $before=$row;
   $actor=(int)get_current_user_id();
   if($op==='save') {
    $sections=self::normalize_sections($payload['sections']??null);
    if(is_wp_error($sections))return $sections;
    $policies=self::normalize_policies($payload['policies']??[]);
    if(is_wp_error($policies))return $policies;
    $store=sanitize_key((string)($payload['asset_store']??'managed_native'));
    if(!in_array($store,self::STORES,true))return self::error('store_invalid','Invalid Company Asset Store',422);
    $note=sanitize_textarea_field((string)($payload['evidence_note']??''));
    if(mb_strlen($note)>2000)return self::error('note_long','Evidence note too long',422);
    $hash=hash('sha256',wp_json_encode([$b['company_site_uuid'],$sections,$policies,$store,$note],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    $row['candidate']=['state'=>'draft','revision'=>$expected+1,'sha256'=>$hash,
       'sections'=>$sections,'policies'=>$policies,'asset_store'=>$store,
       'evidence_note'=>$note,'source'=>'company_strategy_draft',
       'author_id'=>$actor,'approval'=>null,'updated_at'=>gmdate('c')];
   } elseif($op==='submit') {
    if(($row['candidate']['state']??'')!=='draft')return self::error('draft_required','Draft required before review');
    if(trim((string)($row['candidate']['evidence_note']??''))==='')return self::error('evidence_required','Evidence required for review');
    $row['candidate']['state']='in_review';
   } elseif($op==='approve') {
    if(($row['candidate']['state']??'')!=='in_review')return self::error('review_required','Review submission required');
    $row['candidate']['state']='approved';
    $row['candidate']['approval']=['reviewer_id'=>$actor,'sha256'=>$row['candidate']['sha256'],'at'=>gmdate('c')];
   } elseif($op==='activate') {
    $draft=$row['candidate']??null;
    if(!is_array($draft)||($draft['state']??'')!=='approved' ||
       ($draft['approval']['sha256']??'')!==($draft['sha256']??''))
       return self::error('approval_required','Current reviewed strategy required');
    foreach(['company_identity','business_strategy','operations'] as $required) {
     if(trim((string)($draft['sections'][$required]??''))==='')
      return self::error('core_sections_required','Company identity, business strategy and operations are required for activation',422);
    }
    $row['active']=['state'=>'active','revision'=>$expected+1,'sha256'=>$draft['sha256'],
      'sections'=>$draft['sections'],'policies'=>$draft['policies'],
      'asset_store'=>$draft['asset_store'],'approved_at'=>$draft['approval']['at'],
      'activated_at'=>gmdate('c')];
    $row['candidate']=null;
   } else return self::error('op_invalid','Unsupported lifecycle transition',422);
   $row['revision']=$expected+1;$row['updated_at']=gmdate('c');
   $row['history'][]=['event'=>$op,'revision'=>$row['revision'],'actor_id'=>$actor,
      'sha256'=>$row['candidate']['sha256']??$row['active']['sha256']??null,'at'=>gmdate('c')];
   $row['history']=array_slice($row['history'],-35);
   $all=get_option(self::STORE,[]);
   if(!is_array($all))return self::error('store_invalid','Registry unavailable',503);
   $all[$code]=$row;
   // Immutable, company/site-bound revision archive; no full snapshots leak into REST catalog.
   $snapshot=self::snapshot_option($b,$row['revision']);
   if(!add_option($snapshot,$row,'',false))
    return self::error('revision_exists','Immutable revision already exists; reconcile before retry',409);
   if(!update_option(self::STORE,$all,false)) {
    delete_option($snapshot);
    return self::error('write_failed','Cannot persist strategy',503);
   }
   $check=self::current($b);
   if(is_wp_error($check)||$check['revision']!==$row['revision'])
    return self::error('readback_failed','Strategy readback mismatch',503);
   // No cross-company synchronization or Brand approvals are performed here.
   if($op==='activate') do_action('mad4b_company_strategy_activated',$code,$row['active']);
   do_action('mad4b_company_strategy_transition',$code,$op,$row['revision']);
   return self::public_record($check);
  } finally {
   $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
  }
 }
 static function resolution($code,$brand='') {
  $b=self::guard($code);if(is_wp_error($b))return $b;
  $row=self::current($b);if(is_wp_error($row))return $row;
  $active=$row['active']??null;
  if(!is_array($active)||($active['state']??'')!=='active')
   return ['contract'=>'mad4b.company-strategy-resolution.v1','company_code'=>$code,
      'company_site_uuid'=>$b['company_site_uuid'],'state'=>'NOT_ACTIVE',
      'brand_context_consulted'=>false,'commercial_policy_applied'=>false];
  if($brand!=='') {
   if(!preg_match('/^[a-f0-9]{32}$/D',$brand))return self::error('bad_brand','Brand ID invalid',422);
   // A brand is NOT attached to a company merely by matching a name or this WP site.
   $links=get_option('mad4b_company_strategy_verified_brand_links_v1',[]);
   $proof=is_array($links)?($links[$code][$brand]??null):null;
   if(!is_array($proof)||($proof['company_site_uuid']??'')!==$b['company_site_uuid']||
      ($proof['brand_id']??'')!==$brand||empty($proof['reviewed'])||
      !preg_match('/^[a-f0-9]{64}$/D',(string)($proof['identity_evidence_sha256']??'')))
    return self::error('brand_link_unverified','Explicit reviewed Company-to-Brand link required',409);
  }
  $p=get_option('mad4b_portfolio_strategy_defaults_v1',[]);
  $trusted_defaults=is_array($p)&&($p['contract']??'')==='mad4b.portfolio-strategy-defaults.v1'
   &&($p['state']??'')==='approved'&&is_array($p['sections']??null)
   &&preg_match('/^[a-f0-9]{64}$/D',(string)($p['sha256']??''))
   &&hash_equals((string)$p['sha256'],hash('sha256',wp_json_encode($p['sections'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)));
  $defaults=$trusted_defaults?$p['sections']:[];
  $sections=[];$sources=[];
  foreach(self::FIELDS as $key) {
   $mode=(string)($active['policies'][$key]??'inherit');
   $local=(string)($active['sections'][$key]??'');
   $parent=is_string($defaults[$key]??null)?$defaults[$key]:'';
   if($mode==='locked'||$mode==='inherit') {
    $sections[$key]=$parent!==''?$parent:$local;
    $sources[$key]=$parent!==''?'approved_portfolio':'active_company_fallback';
   } elseif($mode==='extend'){
    $sections[$key]=trim($parent."\n".$local);
    $sources[$key]=$parent!==''?'approved_portfolio_plus_company':'active_company';
   } else {
    $sections[$key]=$local;$sources[$key]='active_company';
   }
  }
  return ['contract'=>'mad4b.company-strategy-resolution.v1','state'=>'ACTIVE',
   'company_code'=>$code,'company_site_uuid'=>$b['company_site_uuid'],
   'company_revision'=>$row['revision'],'strategy_sha256'=>$active['sha256'],
   'brand_id'=>$brand?:null,'brand_link_verified'=>$brand!=='',
   'sections'=>$sections,'sources'=>$sources,'policies'=>$active['policies'],
   'approved_portfolio_present'=>!empty($defaults),
   'brand_context_consulted'=>false,'brand_voice_supplied'=>false,
   'brand_context_approval_conferred'=>false,
   'provider_data_shared_across_companies'=>false,'ready_for_company_policy_consumers'=>true];
 }
 static function recovery_plan($code) {
  $b=self::guard($code);if(is_wp_error($b))return $b;
  $r=self::current($b);if(is_wp_error($r))return $r;
  $state= $r['active'] ? 'ACTIVE' :
   (empty($r['candidate'])?'STRATEGY_DRAFT_REQUIRED':strtoupper($r['candidate']['state']));
  return ['contract'=>'mad4b.company-strategy-recovery.v1',
   'company_code'=>$code,'site_uuid'=>$b['company_site_uuid'],
   'state'=>$state,'source'=>'verified_company_registry',
   'steps'=>['company_identity_and_binding','discover_existing_company_evidence',
    'select_company_asset_store','prepare_company_strategy_draft',
    'review_strategy','approve_strategy','activate_company_strategy',
    'resolve_company_policies','notify_consumers'],
   'selected_asset_store'=>$r['candidate']['asset_store']??$r['active']['asset_store']??'managed_native',
   'google_drive_required'=>false,'google_drive_failure_blocks_company_strategy'=>false,
   'brand_strategy_can_substitute_company_strategy'=>false,
   'content_job_transition_performed'=>false,'approved_brand_assets_mutated'=>false,
   'next_action'=>$state==='ACTIVE'?'monitor_strategy_and_resolve':
    ($state==='IN_REVIEW'?'owner_review':
      ($state==='APPROVED'?'activate_reviewed_strategy':'prepare_or_review_company_strategy'))];
 }
 static function rest() {
  register_rest_route('mad4b/v1','/company-strategy/companies',[
   'methods'=>'GET','callback'=>static fn()=>rest_ensure_response(self::catalog()),
   'permission_callback'=>static fn()=>self::central()&&is_user_logged_in()]);
  $path='/company-strategy/companies/(?P<company>[a-z][a-z0-9-]{2,47})';
  register_rest_route('mad4b/v1',$path.'/revisions/(?P<revision>[1-9][0-9]{0,8})',[
   'methods'=>'GET',
   'permission_callback'=>static fn($request)=>self::guard($request->get_param('company')),
   'callback'=>static function($request){
    $out=rest_ensure_response(self::revision($request['company'],(int)$request['revision']));
    if($out instanceof WP_REST_Response)$out->header('Cache-Control','no-store, private');
    return $out;
   }]);
  foreach([''=>'inspect','/resolve'=>'resolution','/recovery-plan'=>'recovery_plan'] as $suffix=>$method){
   register_rest_route('mad4b/v1',$path.$suffix,[
    'methods'=>'GET',
    'permission_callback'=>static fn($request)=>self::guard($request->get_param('company')),
    'callback'=>static function($request)use($method){
     $r= $method==='resolution' ? self::resolution($request['company'],sanitize_text_field((string)($request['brand_id']??''))) :
       self::$method($request['company']);
     $out=rest_ensure_response($r);
     if($out instanceof WP_REST_Response)$out->header('Cache-Control','no-store, private');
     return $out;
    }]);
  }
  register_rest_route('mad4b/v1',$path.'/reconcile',[
   'methods'=>'POST','permission_callback'=>static fn($request)=>self::guard($request->get_param('company'),true),
   'callback'=>static fn($request)=>rest_ensure_response(self::reconcile_company($request['company']))]);
  foreach(['save','submit','approve','activate'] as $action){
   register_rest_route('mad4b/v1',$path.'/'.$action,[
    'methods'=>'POST',
    'permission_callback'=>static fn($request)=>self::guard($request->get_param('company'),true),
    'callback'=>static function($request)use($action){
     $data=$request->get_json_params();
     if(!is_array($data))return self::error('json_required','JSON object required',422);
     if(!array_key_exists('expected_revision',$data)||!is_int($data['expected_revision']))
      return self::error('revision_required','Integer revision required',422);
     $r=self::transact($request['company'],$data['expected_revision'],$action,$data);
     $resp=rest_ensure_response($r);
     if($resp instanceof WP_REST_Response)$resp->header('Cache-Control','no-store, private');
     return $resp;
    }]);
  }
 }
 static function abilities() {
  if(!function_exists('wp_register_ability'))return;
  $defs=[
   'mad4b/company-strategy-catalog'=>['catalog',[],[]],
   'mad4b/company-strategy-status'=>['inspect',['company'],['company'=>['type'=>'string']]],
   'mad4b/company-strategy-recovery-plan'=>['recovery_plan',['company'],['company'=>['type'=>'string']]],
   'mad4b/company-strategy-resolve'=>['resolution',['company'],
    ['company'=>['type'=>'string'],'brand_id'=>['type'=>'string']]]
  ];
  foreach($defs as $name=>$def) {
   if(function_exists('wp_has_ability')&&wp_has_ability($name))continue;
   [$method,$required,$properties]=$def;
   wp_register_ability($name,[
    'label'=>ucwords(str_replace('-',' ',str_replace('mad4b/','',$name))),
    'description'=>'Read only verified company-scoped central Company Strategy; Brand Context remains independent.',
    'category'=>'mad4b-read',
    'input_schema'=>['type'=>'object','properties'=>$properties,'required'=>$required,'additionalProperties'=>false],
    'output_schema'=>['type'=>'object','additionalProperties'=>true],
    'permission_callback'=>static function($input)use($method){
     if($method==='catalog')return self::central()&&is_user_logged_in();
     return self::guard((string)($input['company']??''));
    },
    'execute_callback'=>static function($input)use($method){
     if($method==='catalog')return self::catalog();
     $code=(string)($input['company']??'');
     return $method==='resolution'
       ?self::resolution($code,(string)($input['brand_id']??''))
       :self::$method($code);
    },
    'meta'=>['public'=>false,'show_in_rest'=>false,
      'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
      'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]]
   ]);
  }
 }
 static function admin_menu() {
  if(!self::central())return;
  add_submenu_page('mad4b-company-brand-readiness','Company Strategy Management','Company Strategy',
    'manage_options','mad4b-company-strategy',[__CLASS__,'admin_page']);
 }
 static function admin_page() {
  if(!self::central()||!current_user_can('manage_options')||!current_user_can('activate_plugins'))
   wp_die('Platform strategy administrator required',403);
  $companies=self::catalog();$list=$companies['companies']??[];
  $code=sanitize_key((string)($_GET['company']??''));
  if($code==='' && $list)$code=$list[0]['company_code'];
  $b=$code!==''?self::guard($code):self::error('no_company','No verified company',404);
  $r=is_wp_error($b)?$b:self::current($b);
  echo '<div class="wrap"><h1>MAD4B · Company Strategy Management</h1>';
  echo '<p>Company strategy controls business operations and commercial policy; it never replaces Brand Context or grants brand approval.</p>';
  echo '<nav class="nav-tab-wrapper">';
  foreach($list as $x){
   $url=add_query_arg(['page'=>'mad4b-company-strategy','company'=>$x['company_code']],admin_url('admin.php'));
   echo '<a class="nav-tab '.($x['company_code']===$code?'nav-tab-active':'').'" href="'.esc_url($url).'">'.esc_html($x['company_code']).'</a>';
  }
  echo '</nav>';
  if(is_wp_error($r)){echo '<p>'.esc_html($r->get_error_message()).'</p></div>';return;}
  $cand=$r['candidate']??null;$active=$r['active']??null;
  if((int)$r['revision']===0){
   echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
   wp_nonce_field('mad4b_company_strategy_reconcile_'.$code);
   foreach(['action'=>'mad4b_company_strategy_admin','company'=>$code,
            'expected_revision'=>$r['revision'],'transition'=>'reconcile'] as $key=>$value)
    echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($value).'">';
   submit_button('Reconcile Company Strategy Registry','secondary');
   echo '</form>';
  }
  echo '<h2>'.esc_html($code).' · Strategy Revision '.(int)$r['revision'].'</h2>';
  echo '<p>Draft: '.esc_html($cand['state']??'none').' · Active: '.esc_html($active?'yes':'no').' · Company Site UUID: <code>'.esc_html($b['company_site_uuid']).'</code></p>';
  $notice=sanitize_key((string)($_GET['notice']??''));
  if($notice!=='')echo '<div class="notice notice-info"><p>'.esc_html($notice).'</p></div>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
  wp_nonce_field('mad4b_company_strategy_save_'.$code);
  echo '<input type="hidden" name="action" value="mad4b_company_strategy_admin">';
  echo '<input type="hidden" name="company" value="'.esc_attr($code).'">';
  echo '<input type="hidden" name="expected_revision" value="'.(int)$r['revision'].'">';
  echo '<input type="hidden" name="transition" value="save">';
  echo '<table class="form-table">';
  foreach(self::FIELDS as $key){
   $v=$cand['sections'][$key]??$active['sections'][$key]??'';
   $mode=$cand['policies'][$key]??$active['policies'][$key]??'inherit';
   echo '<tr><th><label for="str-'.esc_attr($key).'">'.esc_html(str_replace('_',' ',ucwords($key,'_'))).'</label></th><td>';
   echo '<textarea id="str-'.esc_attr($key).'" name="sections['.esc_attr($key).']" rows="3" class="large-text">'.esc_textarea($v).'</textarea>';
   echo '<select name="policies['.esc_attr($key).']">';
   foreach(self::MODES as $m)echo '<option value="'.esc_attr($m).'" '.selected($mode,$m,false).'>'.esc_html($m).'</option>';
   echo '</select></td></tr>';
  }
  $store=$cand['asset_store']??$active['asset_store']??'managed_native';
  echo '<tr><th>Company Asset Store</th><td><select name="asset_store">';
  foreach(self::STORES as $opt)echo '<option value="'.esc_attr($opt).'" '.selected($store,$opt,false).'>'.esc_html($opt).'</option>';
  echo '</select><p class="description">Google Drive optional; stored strategy authority remains centrally versioned.</p></td></tr>';
  echo '<tr><th>Evidence / management decision</th><td><textarea name="evidence_note" rows="3" class="large-text">'.esc_textarea($cand['evidence_note']??'').'</textarea></td></tr>';
  echo '</table>';
  submit_button('Save Strategy Draft');
  echo '</form>';
  if(is_array($cand)){
   $allowed=['draft'=>'submit','in_review'=>'approve','approved'=>'activate'];
   $op=$allowed[$cand['state']]??null;
   if($op){
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    wp_nonce_field('mad4b_company_strategy_'.$op.'_'.$code);
    foreach(['action'=>'mad4b_company_strategy_admin','company'=>$code,'expected_revision'=>$r['revision'],'transition'=>$op] as $key=>$value)
     echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($value).'">';
    submit_button(ucwords($op).' Current Strategy','secondary');
    echo '</form>';
   }
  }
  echo '<h2>Operational Recovery</h2><pre>'.esc_html(wp_json_encode(self::recovery_plan($code),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)).'</pre></div>';
 }
 static function admin_post() {
  $code=sanitize_key((string)($_POST['company']??''));
  $op=sanitize_key((string)($_POST['transition']??''));
  $permit=self::guard($code,true);
  if(is_wp_error($permit))wp_die(esc_html($permit->get_error_message()),403);
  check_admin_referer('mad4b_company_strategy_'.$op.'_'.$code);
  $rev=isset($_POST['expected_revision'])?filter_var(wp_unslash($_POST['expected_revision']),FILTER_VALIDATE_INT):false;
  if($rev===false)wp_die('Invalid revision',422);
  $input=[];
  if($op==='save'){
   // A partial POST must not be interpreted as an intentional request to
   // replace a company's complete strategy with empty sections or policies.
   if(!isset($_POST['sections'],$_POST['policies'],$_POST['asset_store'],$_POST['evidence_note'])
      || !is_array($_POST['sections']) || !is_array($_POST['policies'])
      || array_diff(self::FIELDS,array_keys($_POST['sections']))
      || array_diff(self::FIELDS,array_keys($_POST['policies'])))
      wp_die('Company Strategy form is incomplete. No strategy was changed.','Incomplete settings',['response'=>422]);
   $input=['sections'=>array_map('wp_unslash',$_POST['sections']),
    'policies'=>array_map('wp_unslash',$_POST['policies']),
    'asset_store'=>sanitize_key((string)($_POST['asset_store']??'managed_native')),
    'evidence_note'=>sanitize_textarea_field(wp_unslash((string)($_POST['evidence_note']??'')))];
  }
  $result=$op==='reconcile' ? self::reconcile_company($code) :
   self::transact($code,(int)$rev,$op,$input);
  $url=add_query_arg(['page'=>'mad4b-company-strategy','company'=>$code,
    'notice'=>is_wp_error($result)?$result->get_error_code():'saved_'.$op],admin_url('admin.php'));
  wp_safe_redirect($url);exit;
 }
 static function company_enrollment_event($option,$old,$new) {
  // WordPress updated_option passes ($option_name, $old_value, $new_value).
  if(!self::central()||$option!==self::REGISTRY||!is_array($new))return;
  $all=get_option(self::STORE,[]);
  if(!is_array($all))return;
  $changed=false;$discovered=[];
  foreach($new as $company=>$record) {
   if(!is_string($company)||isset($all[$company]))continue;
   $bound=self::binding($company);
   if(is_wp_error($bound))continue;
   $all[$company]=self::default_record($bound);
   $discovered[]=$company;$changed=true;
  }
  if(!$changed)return;
  if(update_option(self::STORE,$all,false)) {
   foreach($discovered as $company)do_action('mad4b_company_strategy_enrolled',$company);
  }
 }
 static function boot() {
  // Event-driven Company Lifecycle; only an empty scoped strategy record is provisioned.
  add_action('updated_option',[__CLASS__,'company_enrollment_event'],25,3);
  add_action('added_option',static function($name,$value){
   if($name===self::REGISTRY)self::company_enrollment_event($name,[],$value);
  },25,2);
  add_action('wp_abilities_api_init',[__CLASS__,'abilities'],70);
  add_action('rest_api_init',[__CLASS__,'rest'],70);
  add_action('admin_menu',[__CLASS__,'admin_menu'],90);
  add_action('admin_post_mad4b_company_strategy_admin',[__CLASS__,'admin_post']);
 }
}
MAD4B_Company_Strategy_V1::boot();
