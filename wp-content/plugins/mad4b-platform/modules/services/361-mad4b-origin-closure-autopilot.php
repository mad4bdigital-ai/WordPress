<?php
/**
 * Plugin Name: MAD4B Origin Evidence Closure Autopilot
 * Description: Bounded proof-triggered native closure, provider fallback status, and missing pickup field triage.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Origin_Closure_Autopilot {
 const CONTRACT='mad4b.origin-closure-autopilot.v1';
 private static $running=false;

 public static function status($issue_id) {
    $id=strtolower(trim((string)$issue_id));
    $base=['contract'=>self::CONTRACT,'issue_id'=>$id,
        'state'=>'BLOCKED','next_action'=>'inspect_issue',
        'retry_after_utc'=>0,'eligible_for_execution'=>false,
        'automatic_write_attempted'=>false,'mutation_performed'=>false];
    if(!preg_match('/^[a-f0-9]{64}$/D',$id))
        return array_merge($base,['state'=>'INVALID_ISSUE']);
    $issues=(array)get_option('mad4b_content_readiness_issues_v1',[]);
    $r=$issues[$id]??null;
    if(!is_array($r))return array_merge($base,['state'=>'NOT_FOUND']);
    if(($r['status']??'')==='resolved'){
        $receipt=(array)(get_option(MAD4B_Dynamic_Origin_Closure::RECEIPTS,[])[$id]??[]);
        return array_merge($base,['state'=>'ALERT_RESOLVED',
            'next_action'=>empty($receipt['job_completion_verified']) ?
                'reconcile_content_job_completion':'none']);
    }
    if(!in_array($r['code']??'',[
        'origin_missing','pickup_guest_location_capture_unverified'],true))
        return array_merge($base,['state'=>'NOT_AN_ORIGIN_ISSUE']);
    $job=(string)($r['content_job_id']??'');
    if(!$job)return array_merge($base,['state'=>'CONTENT_JOB_INTAKE_PENDING',
        'next_action'=>'native_contentjob_intake']);
    $base['job_id']=$job;
    if(($r['code']??'')==='pickup_guest_location_capture_unverified')
        return array_merge($base,['state'=>'GUEST_HOTEL_CAPTURE_UNVERIFIED',
            'next_action'=>'verify_required_hotel_field_in_active_booking_form',
            'fixed_map_origin_applicable'=>false]);
    $capture=(array)($r['origin_research']??[]);
    if(($capture['state']??'')==='PROVIDER_ERROR'){
        $next=max(0,(int)($capture['retry_after_utc']??0));
        return array_merge($base,['state'=>'PROVIDER_RETRY',
            'next_action'=>'native_firecrawl_retry',
            'provider_error_code'=>(string)($capture['error_code']??''),
            'retry_after_utc'=>$next]);
    }
    if(($capture['state']??'')!=='CAPTURED')
        return array_merge($base,['state'=>'FIRST_PARTY_RESEARCH_PENDING',
            'next_action'=>'native_firecrawl_capture']);
    $plan=MAD4B_Dynamic_Location_Resolver::get_plan_by_id($id);
    if(is_wp_error($plan))return array_merge($base,['state'=>'SOURCE_PLAN_INVALID',
        'next_action'=>'rebuild_from_current_tour','error_code'=>$plan->get_error_code()]);
    $base['plan_sha256']=$plan['plan_sha256'];
    $base['location_kind']=$plan['location_kind'];
    $base['selected_provider']=$plan['selected_provider'];
    $base['candidate_count']=count($plan['queries']);
    if(in_array($plan['location_kind'],['undetermined','city_only_departure',
        'hotel_pickup','airport_or_rail_arrival'],true))
        return array_merge($base,['state'=>'EXACT_POINT_UNRESOLVED',
            'next_action'=>'resolve_supplier_specific_origin_or_transport_branch']);
    $proof=(array)($r['origin_verified_proof']??[]);
    if(($proof['contract']??'')!=='mad4b.origin-independent-validation.v1')
        return array_merge($base,['state'=>'INDEPENDENT_VERIFICATION_PENDING',
            'next_action'=>'obtain_independent_supplier_and_place_evidence']);
    $target=absint($proof['location_term_id']??0);
    $source=(string)($proof['source_url']??'');
    $check=MAD4B_Dynamic_Origin_Closure::eligibility($id,$target,$source);
    $base['eligibility_reasons']=$check['reasons']??[];
    $evidence_blockers=array_values(array_diff((array)($check['reasons']??[]),
       ['authorized_site_editor_required']));
    $base['evidence_ready']=empty($evidence_blockers);
    $base['executor_authorized']=!in_array('authorized_site_editor_required',
       (array)($check['reasons']??[]),true);
    if(!empty($evidence_blockers))
        return array_merge($base,['state'=>'VALIDATION_INCOMPLETE',
            'next_action'=>'repair_precise_validation_blockers']);
    if(!$base['executor_authorized'])
        return array_merge($base,['state'=>'EVIDENCE_READY_EXECUTOR_REQUIRED',
            'next_action'=>'broker_dispatch_authorized_site_executor']);
    return array_merge($base,['state'=>'READY_TO_APPLY',
        'next_action'=>'native_verified_origin_writeback',
        'eligible_for_execution'=>true]);
 }
 public static function on_proof_update($option,$old,$new){
    if(self::$running || $option!=='mad4b_content_readiness_issues_v1' ||
       !is_array($old)||!is_array($new) ||
       wp_get_environment_type()!=='staging' ||
       !class_exists('MAD4B_Dynamic_Origin_Closure') ||
       !class_exists('MAD4B_Dynamic_Location_Resolver') ||
       !current_user_can('manage_options') ||
       !class_exists('MAD4B_SCP_Policy') ||
       MAD4B_SCP_Policy::can_mutate()!==true)
       return;
    self::$running=true;
    try{
      $handled=0;
      foreach($new as $id=>$r){
        if($handled>=2)break;
        if(!preg_match('/^[a-f0-9]{64}$/D',(string)$id)||
           !is_array($r)||($r['status']??'')!=='open'||
           ($r['code']??'')!=='origin_missing')continue;
        $proof=(array)($r['origin_verified_proof']??[]);
        $before=(array)($old[$id]['origin_verified_proof']??[]);
        if(empty($proof)||$proof===$before||
           ($proof['contract']??'')!=='mad4b.origin-independent-validation.v1')
           continue;
        $handled++;
        $plan=self::status($id);
        if(($plan['state']??'')!=='READY_TO_APPLY')continue;
        $result=MAD4B_Dynamic_Origin_Closure::apply($id,
           (string)($proof['plan_sha256']??''),
           absint($proof['location_term_id']??0),
           (string)($proof['source_url']??''),true);
        if(is_wp_error($result)) {
          $receipts=(array)get_option(MAD4B_Dynamic_Origin_Closure::RECEIPTS,[]);
          $receipts[$id]=[
            'contract'=>self::CONTRACT,'state'=>'RETRYABLE_APPLY_ERROR',
            'error_code'=>$result->get_error_code(),
            'job_id'=>$r['content_job_id']??'','at'=>gmdate('c'),
            'source_plan_sha256'=>$proof['plan_sha256']??''
          ];
          update_option(MAD4B_Dynamic_Origin_Closure::RECEIPTS,$receipts,false);
        }
      }
    }finally{self::$running=false;}
 }
 public static function heal_pending_jobs(){
   if(self::$running || wp_get_environment_type()!=='staging' ||
      !current_user_can('manage_options') ||
      !class_exists('MAD4B_SCP_Policy') ||
      MAD4B_SCP_Policy::can_mutate()!==true)return;
   $receipts=(array)get_option(MAD4B_Dynamic_Origin_Closure::RECEIPTS,[]);
   $ledger=(array)get_option('mad4b_content_readiness_issues_v1',[]);
   $attempted=0;$dirty=false;
   foreach($receipts as $id=>$r){
      if($attempted>=2)break;
      if(!is_array($r) || ($r['contract']??'')!==MAD4B_Dynamic_Origin_Closure::CONTRACT ||
         empty($r['alert_closed']) || !empty($r['job_completion_verified']) ||
         ($r['site_uuid']??'')!==MAD4B_SCP_Site_Profile::site_uuid() ||
         ($ledger[$id]['status']??'')!=='resolved' ||
         (int)($r['next_retry_at']??0)>time())continue;
      $attempted++;
      $job=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$r['job_id']??'']);
      if(is_wp_error($job)||!is_array($job['job']??null)){
         $receipts[$id]['last_error_code']='job_readback_unavailable';
      }else{
         $j=$job['job'];
         $post=absint($r['post_id']??0);
         $term=absint($r['location_term_id']??0);
         $loc=$term?get_term($term,'destinations'):null;
         if(!$post || !($loc instanceof WP_Term) ||
            ($j['site_uuid']??'')!==($r['site_uuid']??'') ||
            (int)get_post_meta($post,'tour_origin_location_term',true)!==$term ||
            hash('sha256',(string)get_post_meta($post,'tour_origin_address_place_id',true))!==
                ($r['place_id_sha256']??'')) {
            $receipts[$id]['last_error_code']='origin_readback_drift';
         }elseif(($j['state']??'')==='COMPLETED'){
            $receipts[$id]['job_completion_verified']=true;
            $receipts[$id]['last_error_code']='';
         }elseif(($j['state']??'')==='WAITING_REVIEW' && ($j['stage']??'')==='FACT_QA'){
            $done=MAD4B_SCP_Content_Jobs::transition_job([
               'job_id'=>$j['job_id'],'expected_revision'=>(int)$j['job_revision'],
               'state'=>'COMPLETED','stage'=>'FACT_QA',
               'plan_sha256'=>(string)($r['source_plan_sha256']??''),
               'reason'=>'Verified origin writeback, readback and canonical readiness closure previously committed.'
            ]);
            $receipts[$id]['job_completion_verified']=!is_wp_error($done) &&
               ($done['job']['state']??'')==='COMPLETED';
            $receipts[$id]['last_error_code']=is_wp_error($done)?
               $done->get_error_code():($receipts[$id]['job_completion_verified']?'':'completion_readback_failed');
         }else{
            $receipts[$id]['last_error_code']='content_job_state_not_resumable';
         }
      }
      $receipts[$id]['last_recovery_attempt_at']=gmdate('c');
      $receipts[$id]['next_retry_at']=time()+300;
      $dirty=true;
   }
   if($dirty)update_option(MAD4B_Dynamic_Origin_Closure::RECEIPTS,$receipts,false);
 }
 public static function ability($input=[]){
   return self::status($input['issue_id']??'');
 }
 public static function register(){
   if(!function_exists('wp_register_ability') ||
      (function_exists('wp_has_ability')&&wp_has_ability('mad4b/origin-closure-pipeline-status')))return;
   wp_register_ability('mad4b/origin-closure-pipeline-status',[
      'label'=>'Origin Closure Pipeline Status',
      'description'=>'Reports the next actionable supplier, booking field, fallback, or authorized closure state.',
      'category'=>'mad4b-read',
      'execute_callback'=>[__CLASS__,'ability'],
      'permission_callback'=>['MAD4B_SCP_Policy','can_read'],
      'input_schema'=>['type'=>'object','properties'=>[
         'issue_id'=>['type'=>'string','minLength'=>64,'maxLength'=>64]
      ],'required'=>['issue_id'],'additionalProperties'=>false],
      'output_schema'=>['type'=>'object','additionalProperties'=>true],
      'meta'=>['public'=>false,'show_in_rest'=>false,
         'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
         'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]]
   ]);
 }
}
add_action('admin_init',['MAD4B_Origin_Closure_Autopilot','heal_pending_jobs'],30);
add_action('updated_option',['MAD4B_Origin_Closure_Autopilot','on_proof_update'],20,3);
add_action('wp_abilities_api_init',['MAD4B_Origin_Closure_Autopilot','register'],47);
