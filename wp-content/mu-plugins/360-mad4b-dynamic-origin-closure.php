<?php
/**
 * Plugin Name: MAD4B Dynamic Origin Closure
 * Description: Authorized source-backed repair, independent readback, and canonical ContentJob/Alert closure.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Dynamic_Origin_Closure {
 const CONTRACT='mad4b.dynamic-origin-closure.v1';
 const SOURCE='mad4b_content_readiness_issues_v1';
 const RECEIPTS='mad4b_origin_closure_receipts_v1';

 public static function eligibility($issue_id,$term_id=0,$supplier_source=''){
   $blocked=['contract'=>self::CONTRACT,'eligible'=>false,'writeback_allowed'=>false,
      'reasons'=>[],'status'=>'BLOCKED','issue_id'=>(string)$issue_id,
      'authorized_mutation_performed'=>false,'auto_close_allowed'=>false];
   if(!class_exists('MAD4B_Dynamic_Location_Resolver')||
      !class_exists('MAD4B_SCP_Content_Jobs') ||
      !class_exists('MAD4B_SCP_Content_Readiness_Alerts') ||
      !class_exists('MAD4B_SCP_Site_Profile'))
     return array_merge($blocked,['reasons'=>['required_native_services_missing']]);
   $plan=MAD4B_Dynamic_Location_Resolver::get_plan_by_id((string)$issue_id);
   if(is_wp_error($plan))return array_merge($blocked,['reasons'=>[$plan->get_error_code()]]);
   $blocked['job_id']=$plan['job_id'];
   $blocked['plan_sha256']=$plan['plan_sha256'];
   $blocked['post_id']=$plan['post_id'];
   $blocked['location_kind']=$plan['location_kind'];
   $reasons=[];
   $eligible_kind=in_array($plan['location_kind'],[
      'airport_arrival','rail_arrival','cruise_berth','fixed_meeting_point'],true);
   if(!$eligible_kind)$reasons[]='fixed_origin_not_established_or_not_applicable';
   $issue=((array)get_option(self::SOURCE,[]))[$issue_id]??[];
   if(($issue['status']??'')!=='open'||($issue['code']??'')!=='origin_missing')
      $reasons[]='issue_is_not_open_fixed_origin';
   $job=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$plan['job_id']]);
   if(is_wp_error($job)||!in_array($job['job']['state']??'',
       ['WAITING_REVIEW','BLOCKED'],true) ||
      ($job['job']['site_uuid']??'')!==MAD4B_SCP_Site_Profile::site_uuid())
      $reasons[]='content_job_not_eligible';
   if(get_post_status($plan['post_id'])!=='publish' ||
      trim((string)get_post_meta($plan['post_id'],'tour_origin_address',true))!=='')
      $reasons[]='source_changed_or_already_has_origin';
   $term_id=absint($term_id);
   if(!$term_id)$reasons[]='specific_verified_location_term_required';
   else {
      $term=get_term($term_id,'destinations');
      if(!($term instanceof WP_Term))$reasons[]='origin_destination_term_missing';
      else {
         $kind=sanitize_key((string)get_term_meta($term_id,'destination_type',true));
         if(!in_array($kind,['airport','railway_station','station','marina',
             'cruise_berth','port','meeting_point','pickup_point','transport_hub'],true))
             $reasons[]='origin_term_not_an_exact_pickup_or_arrival_point';
         if(!function_exists('tk_tour_origin_sync_read_location_term_meta') ||
            !function_exists('tk_tour_origin_sync_parse_lat_lng'))
             $reasons[]='origin_native_sync_unavailable';
         else {
             $meta=tk_tour_origin_sync_read_location_term_meta($term_id,'destinations');
             [$lat,$lng]=tk_tour_origin_sync_parse_lat_lng($meta['location']);
             if(trim((string)$meta['address'])===''||
                !preg_match('/^[A-Za-z0-9_:-]{8,250}$/D',(string)$meta['place_id'])||
                !is_numeric($lat)||!is_numeric($lng)||
                abs((float)$lat)>90||abs((float)$lng)>180)
                $reasons[]='specific_term_place_id_coordinates_or_address_unverified';
         }
         if($eligible_kind) {
             $label=mb_strtolower((string)$term->name);
             $queries=array_column($plan['queries'],'query');
             $match=false;
             foreach($queries as $query) {
                 if(str_contains(mb_strtolower($query),$label) ||
                    str_contains($label,mb_strtolower(preg_replace('/, Egypt$/i','',$query))))
                    $match=true;
             }
             if(!$match)$reasons[]='verified_term_does_not_match_published_meeting_place';
         }
      }
   }
   // An arbitrary HTTPS URL is not independent supplier validation.
   // The verifier must first persist a separately sourced, job-bound proof.
   $proof=(array)($issue['origin_verified_proof']??[]);
   $valid_proof=($proof['contract']??'')==='mad4b.origin-independent-validation.v1'
       && ($proof['job_id']??'')===$plan['job_id']
       && ($proof['site_uuid']??'')===$plan['site_uuid']
       && ($proof['plan_sha256']??'')===$plan['plan_sha256']
       && (int)($proof['location_term_id']??0)===$term_id
       && ($proof['exact_pickup_verified']??false)===true
       && ($proof['supplier_source_independently_verified']??false)===true
       && ($proof['place_id_crosschecked']??false)===true
       && !empty($proof['source_url'])
       && (string)($proof['source_url']??'')===trim((string)$supplier_source)
       && isset($proof['verified_at'])
       && (strtotime((string)$proof['verified_at'])?:0)>time()-2592000
       && (strtotime((string)$proof['verified_at'])?:0)<=time()+60;
   if($term_id && isset($meta) && is_array($meta)){
      $valid_proof=$valid_proof
          && hash_equals((string)$meta['place_id'],(string)($proof['place_id']??''))
          && hash_equals((string)$plan['source_sha256'],(string)($proof['source_sha256']??''));
   }
   if($valid_proof && class_exists('MAD4B_SCP_Central_Write_Acceptance',false)) {
      // Reuse the site-independent central source/evidence/revision/freshness
      // contract in addition to origin's native Place ID + supplier checks.
      $c='MAD4B_SCP_Central_Write_Acceptance';
      $scope=$c::site_scope();
      $scope['max_evidence_age_seconds']=259200; // location: 72 h
      $evidence_sha=hash('sha256',wp_json_encode($proof,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
      $native=[
        'site_uuid'=>(string)($plan['site_uuid']??''),
        'environment'=>wp_get_environment_type(),
        'subject_id'=>'post:'.(int)$plan['post_id'],
        'ability'=>'mad4b/location-closure-apply',
        'job_id'=>(string)$plan['job_id'],
        'source_sha256'=>(string)$plan['source_sha256'],
        'revision'=>(string)$plan['plan_sha256'],
        'reference_id'=>(string)($meta['place_id']??''),
        'evidence_sha256'=>$evidence_sha,
        'evidence_verified_at'=>strtotime((string)($proof['verified_at']??''))?:0,
        'independent_verification'=>($proof['supplier_source_independently_verified']??null)===true &&
            ($proof['place_id_crosschecked']??null)===true,
        'quality_pass'=>($proof['exact_pickup_verified']??null)===true,
        'rights_pass'=>($proof['supplier_source_independently_verified']??null)===true
      ];
      $claims=[
        'contract'=>$c::CONTRACT,'site_uuid'=>$native['site_uuid'],
        'environment'=>$native['environment'],
        'subject_id'=>$native['subject_id'],'ability'=>$native['ability'],
        'job_id'=>$native['job_id'],'source_sha256'=>(string)($proof['source_sha256']??''),
        'revision'=>(string)($proof['plan_sha256']??''),
        'reference_id'=>(string)($proof['place_id']??''),
        'evidence_sha256'=>$evidence_sha,
        'verified_at'=>strtotime((string)($proof['verified_at']??''))?:0,
        'independent_verification'=>($proof['supplier_source_independently_verified']??null)===true &&
            ($proof['place_id_crosschecked']??null)===true,
        'quality_pass'=>($proof['exact_pickup_verified']??null)===true,
        'rights_pass'=>($proof['supplier_source_independently_verified']??null)===true
      ];
      $claims['idempotency_key']=$c::intent_key($native,$scope,$evidence_sha);
      $central=$c::verify($claims,$native,$scope);
      if(empty($central['eligible']))
          $reasons[]='central_write_acceptance_'.$central['reason'];
   }
   if(!$valid_proof)$reasons[]='independent_supplier_and_place_evidence_missing';
   $supplier_source=trim((string)$supplier_source);
   $host=wp_parse_url($supplier_source,PHP_URL_HOST);
   if(!$supplier_source || strlen($supplier_source)>1000 ||
      wp_parse_url($supplier_source,PHP_URL_SCHEME)!=='https' ||
      !$host || !wp_http_validate_url($supplier_source) ||
      str_contains($supplier_source,'@') )
      $reasons[]='exact_supplier_proof_url_required';
   if(!current_user_can('manage_options') ||
      !current_user_can('edit_post',$plan['post_id']))
      $reasons[]='authorized_site_editor_required';
   $blocked['reasons']=array_values(array_unique($reasons));
   $blocked['eligible']=empty($reasons);
   $blocked['writeback_allowed']=empty($reasons);
   $blocked['auto_close_allowed']=empty($reasons);
   $blocked['status']=empty($reasons)?'READY_FOR_EXACT_OPERATOR_ATTESTATION':'BLOCKED';
   return $blocked;
 }
 /**
  * A live operator confirmation is a review, not a substitute for the term's
  * location-provider reference. Both are required before mutation.
  */
 public static function apply($issue_id,$plan_sha,$term_id,$supplier_source,$affirmed=false) {
   // Independent, job-bound evidence and an authorized actor replace a
   // redundant per-case manual approval. No caller-supplied boolean grants authority.
   if(!current_user_can('manage_options') || !current_user_can('edit_others_posts') ||
       wp_get_environment_type()!=='staging')
     return new WP_Error('mad4b_origin_authorization_or_supplier_attestation_required');
   $check=self::eligibility($issue_id,$term_id,$supplier_source);
   if(empty($check['eligible']))return new WP_Error('mad4b_origin_not_source_verified',implode(',',(array)$check['reasons']));
   if(!hash_equals((string)$check['plan_sha256'],(string)$plan_sha))
     return new WP_Error('mad4b_origin_plan_changed');
   $id=$check['post_id'];$job_id=$check['job_id'];
   global $wpdb;
   $lock='mad4b_verified_origin_apply_'.get_current_blog_id().'_'.$id;
   if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)
      return new WP_Error('mad4b_origin_closure_lock_busy');
   $original_meta=[];
   $meta_keys=['tour_origin_location_term','tour_origin_address',
       'tour_origin_address_place_id','tour_origin_address_lat','tour_origin_address_lng'];
   foreach($meta_keys as $key)$original_meta[$key]=[
       'exists'=>metadata_exists('post',$id,$key),
       'value'=>get_post_meta($id,$key,true)
   ];
   $committed=false;
   $touched=false;
   try {
      $again=self::eligibility($issue_id,$term_id,$supplier_source);
      if(empty($again['eligible']) ||
         !hash_equals($check['plan_sha256'],$again['plan_sha256']))
         return new WP_Error('mad4b_origin_source_revalidation_failed');
      $old=get_post_meta($id,'tour_origin_location_term',true);
      $touched=true;
      if(!update_post_meta($id,'tour_origin_location_term',absint($term_id)))
         return new WP_Error('mad4b_origin_term_not_written');
      // Reuse the existing WPML-aware, term-authoritative field resolver.
      tk_tour_origin_sync_save($id,get_post($id),true);
      $meta=tk_tour_origin_sync_read_location_term_meta(absint($term_id),'destinations');
      $ok=trim((string)get_post_meta($id,'tour_origin_address',true))===
          trim((string)$meta['address']) &&
          trim((string)get_post_meta($id,'tour_origin_address_place_id',true))===
          trim((string)$meta['place_id']);
      if(!$ok)return new WP_Error('mad4b_origin_native_writeback_readback_failed');
      $scan=MAD4B_SCP_Content_Readiness_Alerts::discover();
      if(empty($scan['complete']))return new WP_Error('mad4b_origin_scan_incomplete_after_writeback');
      foreach($scan['issues'] as $r)
          if(($r['id']??'')===$issue_id)
              return new WP_Error('mad4b_origin_gap_still_present_after_writeback');
      $reconcile=MAD4B_SCP_Content_Readiness_Alerts::reconcile($scan,false);
      if(is_wp_error($reconcile))return $reconcile;
      $issue=((array)get_option(self::SOURCE,[]))[$issue_id]??[];
      if(($issue['status']??'')!=='resolved')
          return new WP_Error('mad4b_origin_alert_closure_readback_failed');
      $committed=true;
      $receipt=[
         'contract'=>self::CONTRACT,'status'=>'VERIFIED_WRITEBACK_AND_ALERT_CLOSED',
         'issue_id'=>$issue_id,'job_id'=>$job_id,'site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid(),
         'post_id'=>$id,'location_term_id'=>absint($term_id),
         'source_plan_sha256'=>$plan_sha,
         'place_id_sha256'=>hash('sha256',(string)$meta['place_id']),
         'supplier_proof_url'=>$supplier_source,
         'actor_user_id'=>get_current_user_id(),'at'=>gmdate('c'),
         'readback_verified'=>true,'alert_closed'=>true
      ];
      $receipts=(array)get_option(self::RECEIPTS,[]);
      $receipts[$issue_id]=$receipt;
      update_option(self::RECEIPTS,$receipts,false);
      $job=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job_id]);
      if(!is_wp_error($job) && ($job['job']['state']??'')==='WAITING_REVIEW'){
          $done=MAD4B_SCP_Content_Jobs::transition_job([
             'job_id'=>$job_id,'expected_revision'=>$job['job']['job_revision'],
             'state'=>'COMPLETED','stage'=>'FACT_QA','plan_sha256'=>$plan_sha,
             'reason'=>'Approved supplier source and exact destination term independently read back; readiness alert closed.'
          ]);
          $receipt['job_completion_verified']=!is_wp_error($done) &&
             ($done['job']['state']??'')==='COMPLETED';
      } else $receipt['job_completion_verified']=false;
      $receipts=(array)get_option(self::RECEIPTS,[]);
      $receipts[$issue_id]=$receipt;
      update_option(self::RECEIPTS,$receipts,false);
      return $receipt;
   } finally {
      // No partial origin metadata survives a failed pre-commit readback.
      if($touched && !$committed)foreach($original_meta as $key=>$row){
         if(!$row['exists'])delete_post_meta($id,$key);
         else update_post_meta($id,$key,$row['value']);
      }
      $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
   }
 }
 public static function read($input=[]){
    $issue_id=(string)($input['issue_id']??'');
    return self::eligibility($issue_id);
 }
 public static function register(){
   if(!function_exists('wp_register_ability') ||
      (function_exists('wp_has_ability') && wp_has_ability('mad4b/location-closure-readiness')))return;
   wp_register_ability('mad4b/location-closure-readiness',[
       'label'=>'Read Dynamic Location Closure Readiness',
       'description'=>'Non-mutating assessment of exact source verification and proof required for authorized writeback and automatic alert closure.',
       'category'=>'mad4b-read','execute_callback'=>[__CLASS__,'read'],
       'permission_callback'=>['MAD4B_SCP_Policy','can_read'],
       'input_schema'=>['type'=>'object','properties'=>[
          'issue_id'=>['type'=>'string','minLength'=>64,'maxLength'=>64]
       ],'required'=>['issue_id'],'additionalProperties'=>false],
       'output_schema'=>['type'=>'object','additionalProperties'=>true],
       'meta'=>['public'=>false,'show_in_rest'=>false,
          'mcp'=>['public'=>false,'type'=>'tool','surface'=>'read'],
          'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]]
   ]);
   if(function_exists('wp_has_ability') && wp_has_ability('mad4b/location-closure-apply'))return;
   wp_register_ability('mad4b/location-closure-apply',[
     'label'=>'Verified Location Closure',
     'description'=>'Write verified location through native term sync and reconcile the readiness issue.',
     'category'=>'mad4b-write',
     'execute_callback'=>[__CLASS__,'ability_apply'],
     'permission_callback'=>['MAD4B_SCP_Policy','can_mutate'],
     'input_schema'=>['type'=>'object','properties'=>[
       'issue_id'=>['type'=>'string','minLength'=>64,'maxLength'=>64],
       'plan_sha256'=>['type'=>'string','minLength'=>64,'maxLength'=>64],
       'term_id'=>['type'=>'integer','minimum'=>1],
       'supplier_source'=>['type'=>'string','minLength'=>10,'maxLength'=>1000],
       'affirmed'=>['type'=>'boolean']
     ],'required'=>['issue_id','plan_sha256','term_id','supplier_source'],
       'additionalProperties'=>false],
     'output_schema'=>['type'=>'object','additionalProperties'=>true],
     'meta'=>['public'=>false,'show_in_rest'=>false,
       'mcp'=>['public'=>false,'type'=>'tool','surface'=>'write'],
       'annotations'=>['readonly'=>false,'destructive'=>false,'idempotent'=>false]]
   ]);
 }
 public static function ability_apply($input=[]){
   if(!is_array($input))return new WP_Error('mad4b_origin_input_invalid');
   return self::apply((string)($input['issue_id']??''),
      (string)($input['plan_sha256']??''),
      absint($input['term_id']??0),
      (string)($input['supplier_source']??''),
      ($input['affirmed']??false)===true);
 }
}
add_action('wp_abilities_api_init',['MAD4B_Dynamic_Origin_Closure','register'],46);
