<?php
/**
 * Plugin Name: MAD4B Native Origin ContentJob Intake
 * Description: Exact signed VPS-native research intake from the existing Readiness Issue Ledger.
 * Version: 0.1.0
 */
defined('ABSPATH') || exit;
require_once WP_PLUGIN_DIR . '/mad4b-site-control-plane/includes/class-mad4b-scp-origin-evidence-workflow.php';
require_once WP_PLUGIN_DIR . '/mad4b-site-control-plane/includes/class-mad4b-scp-origin-external-research.php';
final class MAD4B_Native_Origin_Content_Jobs_V1 {
    const CONTRACT='mad4b.native-origin-contentjobs.v1';
    const OPERATION='contentjob.origin.research';
    const POLICY='mad4b_native_origin_contentjob_scope_v1';
    const NONCE='mad4b_origin_native_nonce_';
    const HOST='srv2030908';
    const COMPANY='tours-central';
    const PRINCIPAL='vps-native:srv2030908';
    const FIELDS=['contract','action','host_id','company','site_uuid','canonical_origin',
       'environment','operation','policy_sha256','issued','expires','nonce'];
    private static $admitted=null;

    public static function actor():array {
        // Stable 36-char NHI reference, not a WordPress user ID.
        $h=hash('sha256','mad4b|origin-native|'.self::PRINCIPAL);
        return ['type'=>'nhi','id'=>substr($h,0,8).'-'.substr($h,8,4).'-5'.
            substr($h,13,3).'-a'.substr($h,17,3).'-'.substr($h,20,12),
            'nhi'=>substr($h,0,8).'-'.substr($h,8,4).'-5'.
            substr($h,13,3).'-a'.substr($h,17,3).'-'.substr($h,20,12)];
    }
    public static function is_admitted():bool {
        return is_array(self::$admitted) && !empty(self::$admitted['token']);
    }
    public static function admitted_for($input,$scope):bool {
        if(!self::is_admitted() || !is_array($input) || !is_array($scope))return false;
        $a=self::$admitted;
        if(($scope['site_uuid']??'')!==$a['site_uuid'] ||
            ($scope['brand_ref']??'')!==($input['brand_id']??'') ||
            ($scope['environment']??'')!=='staging' ||
            ($input['content_type']??'')!=='research_observation' ||
            ($input['target_post_type']??'')!=='tours-and-activities' ||
            ($input['language']??'')!=='en' || ($input['country']??'')!=='global' ||
            ($input['automation_level']??'')!=='assisted' ||
            !str_contains((string)($input['subject']??''),'origin-gap-ref:'.$a['token'])) return false;
        return true;
    }
    public static function checkpoint_admitted($checkpoint):bool {
        if(!self::is_admitted() || !is_array($checkpoint) ||
           (int)($checkpoint['actor_user_id']??-1)!==0 ||
           ($checkpoint['scope']['site_uuid']??'')!==self::$admitted['site_uuid'] ||
           ($checkpoint['scope']['environment']??'')!=='staging')return false;
        $grant=get_option(self::POLICY,false);
        $slot=MAD4B_Native_VPS_Principal_V1::existing();
        $scope=MAD4B_Native_VPS_Principal_V1::scope();
        return is_array($grant) && ($grant['enabled']??false)===true &&
           ($grant['operation']??'')===self::OPERATION &&
           is_array($slot) && is_array($scope) &&
           MAD4B_Native_VPS_Principal_V1::ledger($slot,$scope) &&
           hash_equals((string)($grant['policy_sha256']??''),(string)$slot['policy_sha256']) &&
           ($grant['site_uuid']??'')===self::$admitted['site_uuid'];
    }

    private static function deny($reason,$status=403) {
        return new WP_Error('mad4b_native_origin_'.$reason,$reason,
            ['status'=>$status,'contract'=>self::CONTRACT]);
    }
    private static function verify($proof) {
        if(!is_array($proof) ||
            array_keys($proof)!==array_merge(self::FIELDS,['signature']))
            return self::deny('proof_shape');
        $now=time();
        if(($proof['contract']??'')!==self::CONTRACT || $proof['action']!=='claim' ||
           $proof['host_id']!==self::HOST || $proof['company']!==self::COMPANY ||
           $proof['operation']!==self::OPERATION ||
           $proof['environment']!=='staging' || $proof['canonical_origin']!==rtrim(home_url(),'/') ||
           !is_int($proof['issued']) || !is_int($proof['expires']) ||
           $proof['issued']<$now-60 || $proof['issued']>$now+10 ||
           $proof['expires']<$now || $proof['expires']>$proof['issued']+60 ||
           !preg_match('/^[a-f0-9]{32}$/D',(string)$proof['nonce']) ||
           !preg_match('/^[a-f0-9]{64}$/D',(string)$proof['policy_sha256']) ||
           wp_get_environment_type()!=='staging' ||
           !class_exists('MAD4B_Native_VPS_Principal_V1',false) ||
           !class_exists('MAD4B_SCP_Site_Profile') ||
           $proof['site_uuid']!==(string)MAD4B_SCP_Site_Profile::site_uuid())
            return self::deny('host_or_operation_scope');
        $slot=MAD4B_Native_VPS_Principal_V1::existing();
        $scope=MAD4B_Native_VPS_Principal_V1::scope();
        $policy=get_option(self::POLICY,false);
        if(!is_array($slot)||!is_array($scope)||
           !MAD4B_Native_VPS_Principal_V1::ledger($slot,$scope)||
           !is_array($policy)||($policy['enabled']??false)!==true||
           ($policy['contract']??'')!==self::CONTRACT||
           ($policy['operation']??'')!==self::OPERATION||
           ($policy['host_id']??'')!==self::HOST||
           ($policy['site_uuid']??'')!==$proof['site_uuid']||
           ($policy['principal']??'')!==self::PRINCIPAL||
           !hash_equals((string)$slot['policy_sha256'],(string)$proof['policy_sha256'])||
           !hash_equals((string)$policy['policy_sha256'],(string)$proof['policy_sha256']))
            return self::deny('native_grant_or_binding');
        $sig=base64_decode((string)$proof['signature'],true);
        $key=class_exists('MAD4B_Central_VPS_Broker_V1',false)
            ? base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true) : false;
        $parts=[];
        foreach(self::FIELDS as $field) {
            if(!is_string($proof[$field]) && !is_int($proof[$field]))
                return self::deny('proof_type');
            $parts[]=(string)$proof[$field];
        }
        if(!is_string($sig)||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES||
           !is_string($key)||strlen($key)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES||
           !sodium_crypto_sign_verify_detached($sig,implode("\n",$parts),$key))
            return self::deny('original_broker_signature');
        // Durable single-use nonce, consumed only after all proof checks.
        if(!add_option(self::NONCE.$proof['nonce'],gmdate('c'),'',false))
            return self::deny('nonce_replay',409);
        return ['site_uuid'=>$proof['site_uuid'],'policy_sha256'=>$proof['policy_sha256']];
    }
    public static function claim($request) {
        if(!($request instanceof WP_REST_Request))return self::deny('request_invalid');
        $params=$request->get_json_params();
        if(!is_array($params)||array_keys($params)!==['proof'])
            return self::deny('payload_shape',422);
        $verified=self::verify($params['proof']);
        if(is_wp_error($verified))return $verified;
        if(!class_exists('MAD4B_SCP_Content_Readiness_Alerts') ||
           !class_exists('MAD4B_SCP_Content_Jobs')||
           !class_exists('MAD4B_SCP_Operational_Integrity')||
           !class_exists('MAD4B_SCP_Schema'))
            return self::deny('provider_missing',503);
        $ck=MAD4B_SCP_Operational_Integrity::capture();
        if(is_wp_error($ck))return $ck;
        $scope=$ck['scope'];
        if(($scope['site_uuid']??'')!==$verified['site_uuid'] ||
            ($scope['environment']??'')!=='staging' || empty($scope['brand_ref']))
            return self::deny('site_identity_changed',409);
        global $wpdb;
        $table=MAD4B_SCP_Schema::tables()['content_jobs']??'';
        if(!$table)return self::deny('contentjob_schema_missing',503);
        $lock='mad4b_origin_intake_'.get_current_blog_id();
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)
            return ['contract'=>self::CONTRACT,'state'=>'LOCK_BUSY','claimed'=>0];
        $result=['contract'=>self::CONTRACT,'state'=>'COMPLETED','claimed'=>0,'linked'=>0,
          'already_linked'=>0,'held'=>0,'queued'=>0,'researched'=>0,'review_required'=>0,'firecrawl_captured'=>0,'firecrawl_errors'=>0,'jobs'=>[]];
        try {
            $issues=(array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,array());
            foreach($issues as $id=>$issue) {
                if($result['claimed']>=4)break;
                $req=(array)($issue['content_job_request']??[]);
                if(($issue['status']??'')!=='open' || ($issue['code']??'')!=='origin_missing' ||
                   ($issue['post_type']??'')!=='tours-and-activities' ||
                   ($req['status']??'')!=='PRIORITY_INTAKE' ||
                   ($req['site_uuid']??'')!==$verified['site_uuid'] ||
                   !empty($issue['content_job_id']))continue;
                $token=(string)($req['deduplication_key']??'');
                $source=absint($req['source_post_id']??0);
                $canonical=absint($req['canonical_post_id']??0);
                if(!preg_match('/^[a-f0-9]{64}$/D',$token)||
                   !$source||!$canonical || get_post_status($source)!=='publish' ||
                   get_post_status($canonical)!=='publish' ||
                   trim((string)get_post_meta($source,'tour_origin_address',true))!=='')continue;
                $canonical_check=apply_filters('wpml_object_id',$source,'tours-and-activities',false,
                    (string)apply_filters('wpml_default_language','en'));
                if((int)($canonical_check?:$source)!==$canonical)continue;
                $terms=wp_get_object_terms($source,'destinations',['fields'=>'ids']);
                if(is_wp_error($terms))continue;
                $terms=array_map('intval',(array)$terms);sort($terms,SORT_NUMERIC);
                $fresh=hash('sha256',wp_json_encode([
                    'canonical_id'=>$canonical,'origin_term'=>get_post_meta($source,'tour_origin_location_term',true),
                    'destination_terms'=>$terms],JSON_UNESCAPED_SLASHES));
                if(!hash_equals((string)($req['source_sha256']??''),$fresh))continue;
                $marker='origin-gap-ref:'.$token;
                $found=$wpdb->get_col($wpdb->prepare(
                    "SELECT job_id FROM {$table} WHERE site_uuid=%s AND brand_id=%s AND subject LIKE %s LIMIT 3",
                    $verified['site_uuid'],$scope['brand_ref'],'%'.$wpdb->esc_like($marker).'%'));
                if(count($found)>1){$result['held']++;continue;}
                $job_id=$found?(string)$found[0]:'';
                if(!$job_id) {
                    $post=get_post($canonical);
                    $subject='Origin source research: '.wp_strip_all_tags((string)$post->post_title).
                        ' [tours-and-activities #'.$canonical.'] '.$marker;
                    self::$admitted=['token'=>$token,'site_uuid'=>$verified['site_uuid']];
                    try {
                        $created=MAD4B_SCP_Content_Jobs::create_job([
                            'brand_id'=>$scope['brand_ref'],'subject'=>mb_substr($subject,0,5000),
                            'language'=>'en','country'=>'global',
                            'content_type'=>'research_observation','target_post_type'=>'tours-and-activities',
                            'research_depth'=>'standard','automation_level'=>'assisted',
                            'reason'=>'Origin gap '.$id.'; source evidence '.$fresh.
                              '. Research official source and verify geolocation; no price or automatic publishing.'
                        ]);
                    } finally {self::$admitted=null;}
                    if(is_wp_error($created)) {
                        $result['held']++;
                        $result['jobs'][]=['issue_id'=>$id,'error'=>$created->get_error_code()];
                        continue;
                    }
                    $job_id=(string)($created['job']['job_id']??'');
                    if(!$job_id){$result['held']++;continue;}
                    $result['claimed']++;
                } else $result['already_linked']++;
                $readback=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job_id]);
                if(is_wp_error($readback)||($readback['job']['job_id']??'')!==$job_id){
                    $result['held']++;continue;
                }
                $latest=(array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,array());
                if(($latest[$id]['content_job_request']['deduplication_key']??'')!==$token ||
                    ($latest[$id]['status']??'')!=='open')continue;
                $latest[$id]['content_job_id']=$job_id;
                $latest[$id]['content_job_request']['status']='LINKED';
                $latest[$id]['content_job_request']['linked_at']=gmdate('c');
                update_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,$latest,false);
                $check=(array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,array());
                if(($check[$id]['content_job_id']??'')===$job_id){
                    $result['linked']++;
                    $result['jobs'][]=['issue_id'=>$id,'job_id'=>$job_id,'readback_verified'=>true];
                }else $result['held']++;
            }
            // Signed Native Client may queue only its own newly created, exact
            // origin research jobs. Normal ContentJob revision + audit still apply.
            $linked=(array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,array());
            $native_id=self::actor()['id'];
            foreach($linked as $id=>$issue) {
                if($result['queued']>=4)break;
                $req=(array)($issue['content_job_request']??array());
                if(($issue['status']??'')!=='open' || ($issue['code']??'')!=='origin_missing' ||
                   ($req['status']??'')!=='LINKED' ||
                   ($req['site_uuid']??'')!==$verified['site_uuid'] ||
                   empty($issue['content_job_id']))continue;
                $token=(string)($req['deduplication_key']??'');
                if(!preg_match('/^[a-f0-9]{64}$/D',$token))continue;
                $job_id=(string)$issue['content_job_id'];
                $info=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job_id]);
                $job=is_array($info)?(array)($info['job']??array()):array();
                if(!$job || ($job['state']??'')!=='NEW' || ($job['stage']??'')!=='INTAKE' ||
                   ($job['created_by_nhi']??'')!==$native_id ||
                   ($job['content_type']??'')!=='research_observation' ||
                   ($job['site_uuid']??'')!==$verified['site_uuid'] ||
                   !str_contains((string)($job['subject']??''),'origin-gap-ref:'.$token))continue;
                self::$admitted=['token'=>$token,'site_uuid'=>$verified['site_uuid']];
                try {
                    $transition=MAD4B_SCP_Content_Jobs::transition_job([
                        'job_id'=>$job_id,'expected_revision'=>(int)$job['job_revision'],
                        'state'=>'QUEUED','stage'=>'INTAKE',
                        'reason'=>'Signed Native Origin ContentJob intake verified and queued for source research.'
                    ]);
                } finally {self::$admitted=null;}
                $verified_job=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job_id]);
                if(!is_wp_error($verified_job) &&
                   ($verified_job['job']['state']??'')==='QUEUED' &&
                   (int)($verified_job['job']['job_revision']??0)===(int)$job['job_revision']+1) {
                    $result['queued']++;
                } else $result['held']++;
            }
            // Execute bounded, signed first-party evidence capture in the existing
            // Research Groups and ContentJob lifecycle, never a second queue.
            $linked=(array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,array());
            foreach($linked as $issue){
                if($result['researched']>=4)break;
                $r=(array)($issue['content_job_request']??[]);
                if(($issue['status']??'')!=='open'||($issue['code']??'')!=='origin_missing'||
                   ($r['status']??'')!=='LINKED'||
                   ($r['site_uuid']??'')!==$verified['site_uuid']||
                   empty($issue['content_job_id']))continue;
                $token=(string)($r['deduplication_key']??'');
                if(!preg_match('/^[a-f0-9]{64}$/D',$token))continue;
                $j=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$issue['content_job_id']]);
                if(is_wp_error($j)||($j['job']['state']??'')!=='QUEUED')continue;
                self::$admitted=['token'=>$token,'site_uuid'=>$verified['site_uuid']];
                try{
                    $done=MAD4B_SCP_Origin_Evidence_Workflow::process_one($issue,$scope);
                } finally{self::$admitted=null;}
                if(is_wp_error($done)){
                    $result['held']++;
                    $result['jobs'][]=['job_id'=>$issue['content_job_id'],
                        'evidence_error'=>$done->get_error_code()];
                    continue;
                }
                $result['researched']++;
                if(($done['state']??'')==='WAITING_REVIEW')$result['review_required']++;
            }
            // Existing Research Groups: one provider fetch per signed native cycle.
            // Persist provider outcomes on the canonical Issue Ledger to prevent
            // uncontrolled retries, duplicate scraping, or silent failures.
            $linked=(array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,array());
            foreach($linked as $issue_id=>$issue) {
                $req=(array)($issue['content_job_request']??array());
                $prior=(array)($issue['origin_research']??array());
                if(($issue['status']??'')!=='open'||($issue['code']??'')!=='origin_missing'||
                   ($req['status']??'')!=='LINKED'||
                   ($req['site_uuid']??'')!==$verified['site_uuid']||
                   empty($issue['content_job_id']) ||
                   ($prior['state']??'')==='CAPTURED' ||
                   (int)($prior['retry_after_utc']??0)>time())continue;
                $token=(string)($req['deduplication_key']??'');
                if(!preg_match('/^[a-f0-9]{64}$/D',$token))continue;
                $job=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$issue['content_job_id']]);
                if(is_wp_error($job)||($job['job']['state']??'')!=='WAITING_REVIEW')continue;
                self::$admitted=['token'=>$token,'site_uuid'=>$verified['site_uuid']];
                try {
                    $external=MAD4B_SCP_Origin_External_Research::process_one($issue_id,$issue,$scope);
                } finally {self::$admitted=null;}
                $latest=(array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,array());
                if(($latest[$issue_id]['content_job_request']['deduplication_key']??'')!==$token ||
                   ($latest[$issue_id]['status']??'')!=='open')break;
                $state=is_wp_error($external)?'PROVIDER_ERROR':
                    (($external['state']??'')==='CAPTURED' ||
                     ($external['state']??'')==='ALREADY_CAPTURED'?'CAPTURED':'NEEDS_REVIEW');
                $latest[$issue_id]['origin_research']=[
                    'contract'=>'mad4b.origin-external-research.v1',
                    'state'=>$state,'provider'=>'firecrawl',
                    'last_attempt_at'=>gmdate('c'),
                    'retry_after_utc'=>$state==='PROVIDER_ERROR'?time()+3600:0,
                    'error_code'=>is_wp_error($external)?$external->get_error_code():'',
                    'evidence_record_uid'=>is_array($external)?($external['record_uid']??''):'',
                    'exact_pickup_verified'=>false,'writeback_authorized'=>false
                ];
                update_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,$latest,false);
                $check=(array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,array());
                if(($check[$issue_id]['origin_research']['state']??'')===$state) {
                    if($state==='CAPTURED')$result['firecrawl_captured']++;
                    if($state==='PROVIDER_ERROR')$result['firecrawl_errors']++;
                    $result['jobs'][]=['issue_id'=>$issue_id,
                        'external_research_state'=>$state,
                        'provider_error_code'=>is_wp_error($external)?$external->get_error_code():''];
                } else $result['held']++;
                break; // A single bounded external provider request per cycle.
            }
        } finally {self::$admitted=null;$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
        return $result;
    }
    public static function boot():void {
        add_action('rest_api_init',static function() {
            register_rest_route('mad4b/v1','/native-origin-contentjobs/claim',[
                'methods'=>'POST','callback'=>[__CLASS__,'claim'],
                // Signed Ed25519 proof and scoped native grant are verified in claim().
                'permission_callback'=>'__return_true'
            ]);
        },95);
    }
}
MAD4B_Native_Origin_Content_Jobs_V1::boot();
