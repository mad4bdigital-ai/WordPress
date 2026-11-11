<?php
/**
 * Plugin Name: MAD4B Optional Company Authorization Requests (Central)
 * Description: Opt-in company owner email request; never an execution grant or router gate.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Optional_Company_Authorization_Central_V1', false)) {
final class MAD4B_Optional_Company_Authorization_Central_V1 {
    const STORE='mad4b_company_optional_authorization_requests_v1';
    const CONTRACT='mad4b.company.optional-authorization.v1';
    const REST='mad4b-company-management/v1';
    const TYPES=array('oauth_reconnect','write_grants','adaptive_intake','company_configuration','provider_connection');
    const MAX_ROWS=250;
    private static function central():bool {
        return wp_get_environment_type()==='staging'
            && untrailingslashit(home_url('/'))==='https://tours.dev.mad4b.com'
            && class_exists('MAD4B_Company_Environment_Registry_V2',false);
    }
    public static function admin():bool {
        return self::central() && current_user_can('manage_options')
            && current_user_can('activate_plugins');
    }
    private static function error($code,$status=409){
        return new WP_Error('mad4b_optional_auth_'.$code,'Optional company authorization request cannot proceed.',
            array('status'=>$status,'execution_granted'=>false,'required_for_routing'=>false));
    }
    public static function all():array {
        $o=get_option(self::STORE,array());return is_array($o)?$o:array();
    }
    public static function record($id){
        $items=self::all();$item=$items[$id]??null;
        return is_array($item)?$item:null;
    }
    private static function projection(array $r):array {
        $keep=array('id','company','environment','site_uuid','origin','purpose','owner_user_id',
          'state','created_at','expires_epoch','sent_at','updated_at','decided_at',
          'decision_user_id','owner_email_verified','email_delivery_attempted',
          'email_transport_accepted','receipt_digest','failure_code','optional','activation_state',
          'oauth_token_issued','exact_grants_ready','next_original_ability');
        $out=array_intersect_key($r,array_flip($keep));
        $out['execution_granted']=false;
        $out['required_for_routing']=false;
        $out['original_write_authority_unchanged']=true;
        return $out;
    }
    private static function target($company,$environment) {
        if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',(string)$company)
            || !in_array($environment,array('staging','production'),true))
            return self::error('invalid_target',422);
        $row=MAD4B_Company_Environment_Registry_V2::target($company,$environment);
        if(!is_array($row)||($row['company_id']??null)!==$company
           ||($row['environment']??null)!==$environment
           ||!wp_is_uuid((string)($row['site_uuid']??''))
           ||!preg_match('~^https://[a-zA-Z0-9.-]+$~D',(string)($row['canonical_origin']??'')))
           return self::error('unverified_registry_target',409);
        return $row;
    }
    public static function plan($company,$environment) {
        if(!self::central())return self::error('wrong_center',403);
        $row=self::target($company,$environment);
        if(is_wp_error($row))return $row;
        $preferred=null;$ready=false;
        if(class_exists('MAD4B_Company_Central_Preferred_Router_V1',false)){
            $r=MAD4B_Company_Central_Preferred_Router_V1::plan_for($company,$environment,'site.binding.inspect');
            if(is_array($r)){$preferred=$r['selected_transport']??null;$ready=$preferred!==null;}
        }
        return array(
          'contract'=>self::CONTRACT.'.plan','company'=>$company,'environment'=>$environment,
          'site_uuid'=>$row['site_uuid'],'canonical_origin'=>$row['canonical_origin'],
          'optional'=>true,'required_for_routing'=>false,'required_for_existing_write_grants'=>false,
          'send_automatic'=>false,'owner_user_must_be_selected'=>true,
          'email_recipient_source'=>'exact_remote_wordpress_master_admin_user',
          'owner_email_in_registry_required_for_request'=>false,
          'owner_email_in_registry_required_for_automatic_distribution'=>true,
          'registered_transport'=>$preferred,'transport_verified'=>$ready,
          'types'=>self::TYPES,
          'note'=>'Authorization consent receipt is NOT the original WordPress OAuth login or exact native write grant.');
    }
    private static function lock():bool {
        global $wpdb;return (int)$wpdb->get_var($wpdb->prepare(
            'SELECT GET_LOCK(%s,5)','mad4b:central-optional-auth-v1'))===1;
    }
    private static function unlock():void {
        global $wpdb;$wpdb->get_var($wpdb->prepare(
            'SELECT RELEASE_LOCK(%s)','mad4b:central-optional-auth-v1'));
    }
    public static function create($company,$environment,$purpose,$ownerUserId,$note='') {
        if(!self::admin())return self::error('central_admin_required',403);
        return self::insert_request($company,$environment,$purpose,$ownerUserId,$note,'central_authenticated_admin');
    }
    /** Exact authenticated host operator can originate this OPTIONAL request,
        without impersonating a WordPress owner or creating any write grant. */
    public static function host_create($company,$environment,$purpose,$ownerUserId,$note='') {
        if(!defined('WP_CLI')||!WP_CLI||!self::central())
            return self::error('original_host_required',403);
        return self::insert_request($company,$environment,$purpose,$ownerUserId,$note,'vps_client_mcp');
    }
    private static function insert_request($company,$environment,$purpose,$ownerUserId,$note,$initiator) {
        $scope=self::target($company,$environment);
        if(is_wp_error($scope))return $scope;
        if($environment!=='staging')return self::error('staging_pilot_only',422);
        if(!in_array($purpose,self::TYPES,true)
          ||!is_int($ownerUserId)||$ownerUserId<1
          ||!is_string($note)||strlen($note)>240)
          return self::error('invalid_request',422);
        $note=trim(wp_strip_all_tags($note));
        if(!self::lock())return self::error('busy',423);
        try{
            $entries=self::all();
            if(count($entries)>=self::MAX_ROWS)return self::error('capacity',429);
            $id=wp_generate_uuid4();$time=time();
            $entry=array(
              'id'=>$id,'company'=>$company,'environment'=>$environment,
              'site_uuid'=>$scope['site_uuid'],'origin'=>$scope['canonical_origin'],
              'purpose'=>$purpose,'owner_user_id'=>$ownerUserId,'note'=>$note,
              'state'=>'DRAFT_OPTIONAL','created_at'=>gmdate('c',$time),
              'expires_epoch'=>$time+86400,'created_by'=>$initiator==='central_authenticated_admin'?get_current_user_id():0,
              'initiator'=>$initiator,
              'optional'=>true,'owner_email_verified'=>false,
              'email_delivery_attempted'=>false,'email_transport_accepted'=>false,
              'required_for_routing'=>false,'execution_granted'=>false);
            $entries[$id]=$entry;
            if(!update_option(self::STORE,$entries,false)&&self::all()!==$entries)
                return self::error('store_failure',503);
            return self::projection($entry);
        }finally{self::unlock();}
    }
    public static function queue($id) {
        if(!self::admin())return self::error('central_admin_required',403);
        return self::transition($id,'DRAFT_OPTIONAL','QUEUED_EMAIL',array(),'SEND COMPANY AUTHORIZATION EMAIL');
    }
    public static function host_queue($id,$confirmation) {
        if(!defined('WP_CLI')||!WP_CLI||!self::central()
          ||$confirmation!=='SEND COMPANY AUTHORIZATION EMAIL')
            return self::error('host_explicit_confirmation_required',403);
        return self::transition($id,'DRAFT_OPTIONAL','QUEUED_EMAIL',array(),'HOST_EXPLICIT_QUEUE');
    }
    private static function transition($id,$expected,$next,$values,$purpose) {
        if(!self::lock())return self::error('busy',423);
        try{
            $rows=self::all();$row=$rows[$id]??null;
            if(!is_array($row))return self::error('not_found',404);
            if(($row['state']??null)!==$expected)return self::error('state_conflict',409);
            if(time()>=(int)$row['expires_epoch'])return self::error('expired',410);
            $target=self::target($row['company'],$row['environment']);
            if(is_wp_error($target)||($target['site_uuid']??null)!==$row['site_uuid']
              ||($target['canonical_origin']??null)!==$row['origin'])
                return self::error('target_changed',409);
            $rows[$id]=array_merge($row,$values,array('state'=>$next,'updated_at'=>gmdate('c')));
            if(!update_option(self::STORE,$rows,false)&&self::all()!==$rows)
                return self::error('store_failure',503);
            return self::projection($rows[$id]);
        }finally{self::unlock();}
    }
    /** Called only from authenticated original host WP-CLI with verified remote owner identity. */
    public static function host_delivery($id,$siteUuid,$ownerUserId,$ownerEmail,$url,$accepted) {
        if(!defined('WP_CLI')||!WP_CLI||!self::central())
            return self::error('original_host_required',403);
        $row=self::record($id);
        if(!is_array($row)||($row['state']??'')!=='QUEUED_EMAIL'
          ||(string)$row['site_uuid']!==(string)$siteUuid
          ||(int)$row['owner_user_id']!==(int)$ownerUserId
          ||!is_email($ownerEmail) || !is_string($url)
          ||!str_starts_with($url,$row['origin'].'/wp-admin/')
          ||!in_array($accepted,array('send','dry-run'),true))
            return self::error('delivery_identity_invalid',403);
        $text="A MAD4B Central administrator requested optional company authorization.\n\n".
              "Company: ".$row['company']."\n".
              "Environment: ".$row['environment']."\n".
              "Purpose: ".$row['purpose']."\n".
              "This request is OPTIONAL. Existing management via VPS Client and authorized SSH is not suspended.\n".
              "To review this request while signed into your own company account:\n".$url."\n\n".
              "This link expires within 24 hours. Do not share it.\n".
              "Approval does not grant WordPress write access or reconnect somebody else's ChatGPT account.\n".
              "If you did not request this, ignore the email.";
        if($accepted==='dry-run')
          return array('state'=>'DRY_RUN','mail_attempted'=>false,'remote_user_verified'=>true,
            'send_blocked_by_default'=>true,'optional'=>true);
        // Explicitly scoped administrative communication: never marketed/bulk email.
        // Claim BEFORE calling an external email transport. If transport hangs
        // or a PHP worker crashes, this cannot silently send the email twice.
        $claim=self::transition($id,'QUEUED_EMAIL','DELIVERY_IN_PROGRESS',
          array('owner_email_verified'=>true,'email_delivery_attempted'=>true),
          'HOST_ONE_SHOT_EMAIL_CLAIM');
        if(is_wp_error($claim))return $claim;
        $ok=wp_mail($ownerEmail,'MAD4B — Optional company authorization request',$text,
            array('Content-Type: text/plain; charset=UTF-8',
                  'X-MAD4B-Message-Scope: wordpress_core'));
        return self::transition($id,'DELIVERY_IN_PROGRESS',$ok?'EMAIL_ACCEPTED':'EMAIL_FAILED',
          array('owner_email_verified'=>true,'email_delivery_attempted'=>true,
            'email_transport_accepted'=>(bool)$ok,'sent_at'=>$ok?gmdate('c'):null,
            'failure_code'=>$ok?null:'WORDPRESS_MAIL_TRANSPORT_REJECTED'),'HOST_EXACT_DELIVERY');
    }
    /** Verified host owns original cross-SSH readback and can record consent; never grants execution. */
    public static function host_decision($id,$siteUuid,$ownerUserId,$decision,$receiptHash) {
        if(!defined('WP_CLI')||!WP_CLI||!self::central())
            return self::error('original_host_required',403);
        $row=self::record($id);
        if(!is_array($row)||!in_array($row['state'],array('EMAIL_ACCEPTED','EMAIL_FAILED'),true)
          ||$row['site_uuid']!==$siteUuid ||(int)$row['owner_user_id']!==(int)$ownerUserId
          ||!in_array($decision,array('APPROVED','DECLINED'),true)
          ||!preg_match('/^[0-9a-f]{64}$/D',(string)$receiptHash))
          return self::error('decision_invalid',409);
        return self::transition($id,$row['state'],'CONSENT_'.$decision,array(
          'decided_at'=>gmdate('c'),'decision_user_id'=>(int)$ownerUserId,
          'receipt_digest'=>$receiptHash,
          'activation_state'=>$decision==='APPROVED'?'WAITING_CHATGPT_PKCE_OAUTH':'DECLINED',
          'oauth_token_issued'=>false,'exact_grants_ready'=>false,
          'next_original_ability'=>$decision==='APPROVED'
             ?'mad4b/staging-write-grant-reconciliation-plan':''),'HOST_DECISION_READBACK');
    }

    public static function host_candidates($company,$environment,$siteUuid,$candidates) {
        if(!defined('WP_CLI')||!WP_CLI||!self::central())
            return self::error('original_host_required',403);
        $site=self::target($company,$environment);
        if(is_wp_error($site)||$site['site_uuid']!==$siteUuid
          ||!is_array($candidates)||count($candidates)>25)
            return self::error('candidates_invalid',422);
        $allowed=array();$ids=array();
        foreach($candidates as $item){
            if(!is_array($item)||!is_int($item['user_id']??null)
               ||(int)$item['user_id']<1 || !preg_match('/^[0-9a-f]{64}$/D',(string)($item['email_sha256']??'')))
                return self::error('candidate_record_invalid',422);
            if(isset($ids[$item['user_id']]))return self::error('duplicate_candidate',422);
            $ids[$item['user_id']]=true;
            $allowed[]=array('user_id'=>$item['user_id'],
              'label'=>'Company Master Admin #'.$item['user_id'],
              'role'=>'master_admin','email_sha256'=>$item['email_sha256']);
        }
        $all=get_option('mad4b_company_optional_owner_candidates_v1',array());
        if(!is_array($all))$all=array();
        $all[$company.':'.$environment]=array('site_uuid'=>$siteUuid,
          'checked_epoch'=>time(),'candidates'=>$allowed);
        update_option('mad4b_company_optional_owner_candidates_v1',$all,false);
        return array('stored'=>true,'company'=>$company,'environment'=>$environment,
          'eligible_owner_count'=>count($allowed),'plain_emails_stored'=>false);
    }
    public static function recipients($request) {
        if(!self::admin())return self::error('central_admin_required',403);
        $company=(string)$request->get_param('company');
        $environment=(string)$request->get_param('environment');
        $site=self::target($company,$environment);
        if(is_wp_error($site))return $site;
        $all=get_option('mad4b_company_optional_owner_candidates_v1',array());
        $entry=is_array($all)?($all[$company.':'.$environment]??null):null;
        $ready=is_array($entry)&&($entry['site_uuid']??'')===$site['site_uuid']
            &&(int)($entry['checked_epoch']??0)>time()-3600;
        return rest_ensure_response(array(
          'company'=>$company,'environment'=>$environment,
          'site_uuid'=>$site['site_uuid'],'candidates'=>$ready?$entry['candidates']:array(),
          'fresh'=>(bool)$ready,'state'=>$ready?'READY':'WAITING_HOST_CANDIDATE_REFRESH',
          'optional'=>true,'recipient_selection_required'=>true,
          'no_automatic_email'=>true));
    }

    /**
     * Readback from the enrolled company site after actual ChatGPT PKCE.
     * Never fabricates or returns an OAuth bearer credential.
     * A consent receipt alone is insufficient for original write permission.
     */
    public static function host_activation_readback($id,$siteUuid,$ownerUserId,
         $decisionDigest,$activation) {
        if(!defined('WP_CLI')||!WP_CLI||!self::central())
            return self::error('original_host_required',403);
        $row=self::record($id);
        if(!is_array($row)||$row['state']!=='CONSENT_APPROVED'
          ||$row['site_uuid']!==$siteUuid
          ||(int)$row['owner_user_id']!==(int)$ownerUserId
          ||!is_string($decisionDigest)
          ||!hash_equals((string)($row['receipt_digest']??''),$decisionDigest)
          ||!is_array($activation))
          return self::error('activation_not_attested',403);
        $state=(string)($activation['state']??'');
        $token=!empty($activation['oauth_token_issued']);
        $grants=!empty($activation['exact_grants_ready']);
        if(!in_array($state,array(
             'WAITING_CHATGPT_PKCE_OAUTH','WAITING_ORIGINAL_GRANT_CONVERGENCE',
             'READY'),true)
          ||$grants&&!$token
          ||($state==='READY'&&(!$token||!$grants))
          ||($state==='WAITING_CHATGPT_PKCE_OAUTH'&&$token)
          ||($state==='WAITING_ORIGINAL_GRANT_CONVERGENCE'&&(!$token||$grants))
          ||($activation['bearer_token_returned_in_readback']??null)!==false
          ||($activation['requires_original_oauth_authorization_code_and_pkce']??null)!==true
          ||($activation['original_wordpress_grants_not_issued_by_email']??null)!==true)
          return self::error('activation_proof_invalid',422);
        return self::transition($id,'CONSENT_APPROVED','CONSENT_APPROVED',array(
          'activation_state'=>$state,'oauth_token_issued'=>$token,
          'exact_grants_ready'=>$grants,
          'next_original_ability'=>$state==='WAITING_ORIGINAL_GRANT_CONVERGENCE'
            ?'mad4b/staging-write-grant-reconciliation-plan':'',
          'activation_readback_at'=>gmdate('c')),'HOST_PKCE_EVIDENCE_READBACK');
    }
    public static function status($request) {
        if(!self::admin())return self::error('central_admin_required',403);
        $company=(string)$request->get_param('company');
        $rows=array_values(array_filter(self::all(),static fn($r)=>!$company||($r['company']??'')===$company));
        usort($rows,static fn($a,$b)=>strcmp($b['created_at']??'',$a['created_at']??''));
        return rest_ensure_response(array('contract'=>self::CONTRACT,'optional'=>true,
          'required_for_routing'=>false,'requests'=>array_map(array(__CLASS__,'projection'),array_slice($rows,0,100))));
    }
    public static function post($request) {
        $args=$request->get_json_params();
        if(!is_array($args)||array_diff(array_keys($args),array('company','environment','purpose','owner_user_id','note')))
            return self::error('payload_invalid',422);
        return self::create((string)($args['company']??''),(string)($args['environment']??''),
            (string)($args['purpose']??''),$args['owner_user_id']??null,(string)($args['note']??''));
    }
    public static function send($request) {
        if((string)$request->get_param('confirmation')!=='SEND COMPANY AUTHORIZATION EMAIL')
            return self::error('explicit_confirmation_required',422);
        return self::queue((string)$request['id']);
    }
    public static function routes() {
        if(!self::central())return;
        register_rest_route(self::REST,'/authorization-requests',array(
          array('methods'=>'GET','permission_callback'=>array(__CLASS__,'admin'),
            'callback'=>array(__CLASS__,'status')),
          array('methods'=>'POST','permission_callback'=>array(__CLASS__,'admin'),
            'callback'=>array(__CLASS__,'post'))));
        register_rest_route(self::REST,'/authorization-requests/(?P<id>[a-f0-9-]{36})/send',array(
          'methods'=>'POST','permission_callback'=>array(__CLASS__,'admin'),
          'callback'=>array(__CLASS__,'send')));
        register_rest_route(self::REST,'/authorization-requests/recipients',array(
          'methods'=>'GET','permission_callback'=>array(__CLASS__,'admin'),
          'callback'=>array(__CLASS__,'recipients')));
        register_rest_route(self::REST,'/authorization-requests/plan',array(
          'methods'=>'GET','permission_callback'=>array(__CLASS__,'admin'),
          'callback'=>static function($r){return self::plan((string)$r->get_param('company'),
            (string)$r->get_param('environment'));}));
    }

    /**
     * Agent-discoverable, individually permissioned optional actions.
     * The native Write Executor remains the only way to dispatch actual
     * content/configuration changes. A request is consent, not a grant.
     */
    public static function abilities():void {
        if(!self::central()||!function_exists('wp_register_ability'))return;
        $common=array(
          'permission_callback'=>array(__CLASS__,'admin'),
          'output_schema'=>array('type'=>'object','additionalProperties'=>true));
        $plan=array_merge($common,array(
          'label'=>'MAD4B Optional Company Authorization Plan',
          'description'=>'Read-only plan for a non-blocking company authorization email request.',
          'category'=>'mad4b-read',
          'execute_callback'=>static function($v){return self::plan(
            (string)($v['company']??''),(string)($v['environment']??''));},
          'input_schema'=>array('type'=>'object','properties'=>array(
             'company'=>array('type'=>'string'),
             'environment'=>array('type'=>'string','enum'=>array('staging','production'))),
              'required'=>array('company','environment'),'additionalProperties'=>false),
          'meta'=>array('public'=>false,'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
            'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true))));
        $create=array_merge($common,array(
          'label'=>'Create Optional Company Authorization Request',
          'description'=>'Create an unsent optional draft for an explicitly selected company Master Admin user; creates no grant or email.',
          'category'=>'mad4b-write',
          'execute_callback'=>static function($v){return self::create(
            (string)($v['company']??''),(string)($v['environment']??''),
            (string)($v['purpose']??''),$v['owner_user_id']??null,
            (string)($v['note']??''));},
          'input_schema'=>array('type'=>'object','properties'=>array(
            'company'=>array('type'=>'string'),
            'environment'=>array('type'=>'string','enum'=>array('staging','production')),
            'purpose'=>array('type'=>'string','enum'=>self::TYPES),
            'owner_user_id'=>array('type'=>'integer','minimum'=>1),
            'note'=>array('type'=>'string','maxLength'=>240)),
            'required'=>array('company','environment','purpose','owner_user_id'),
            'additionalProperties'=>false),
          'meta'=>array('public'=>false,'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'write'),
            'annotations'=>array('readonly'=>false,'destructive'=>false,'idempotent'=>false))));
        $send=array_merge($common,array(
          'label'=>'Send Optional Company Authorization Email',
          'description'=>'Explicitly queue a previously created optional owner request for email; not an OAuth login or WordPress write permission.',
          'category'=>'mad4b-write',
          'execute_callback'=>static function($v){
            if(($v['confirmation']??'')!=='SEND COMPANY AUTHORIZATION EMAIL')
                return self::error('explicit_confirmation_required',422);
            return self::queue((string)($v['id']??''));
          },
          'input_schema'=>array('type'=>'object','properties'=>array(
            'id'=>array('type'=>'string'),
            'confirmation'=>array('type'=>'string','enum'=>array('SEND COMPANY AUTHORIZATION EMAIL'))),
            'required'=>array('id','confirmation'),'additionalProperties'=>false),
          'meta'=>array('public'=>false,'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'write'),
            'annotations'=>array('readonly'=>false,'destructive'=>false,'idempotent'=>false))));
        foreach(array('mad4b/company-optional-authorization-plan'=>$plan,
            'mad4b/company-optional-authorization-create'=>$create,
            'mad4b/company-optional-authorization-send'=>$send) as $name=>$spec){
            if(function_exists('wp_has_ability')&&wp_has_ability($name))continue;
            wp_register_ability($name,$spec);
        }
    }
    public static function boot():void {
        if(!self::central())return;
        add_action('rest_api_init',array(__CLASS__,'routes'),25);
        add_action('wp_abilities_api_init',array(__CLASS__,'abilities'),50);
    }
}
MAD4B_Optional_Company_Authorization_Central_V1::boot();
}
