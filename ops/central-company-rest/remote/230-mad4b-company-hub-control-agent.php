<?php
/**
 * Plugin Name: MAD4B Company Hub Remote Control Agent
 * Version: 0.1.0
 * Description: Source-local delegated operations controlled by signed central intents.
 * Never authorizes prices, checkout, form submissions, or arbitrary code execution.
 */
defined('ABSPATH') || exit;

final class MAD4B_Company_Hub_Control_Agent_V1 {
    const ROUTE='/mad4b-company/v1/hub-control';
    const CONTRACT='mad4b.hub.company-remote-control.v1';
    const DELEGATION='mad4b_company_hub_central_delegation_v1';
    const GRANTS='mad4b_company_hub_content_grants_v1';
    const NONCE_PREFIX='mad4b_hubctl_nonce_v1_';
    const RECEIPT_PREFIX='mad4b_hubctl_receipt_v1_';
    const MAX_IDS=50;

    private static function err($code,$status=403) {
        return new WP_Error('mad4b_hubctl_'.$code,$code,
            array('status'=>$status,'contract'=>self::CONTRACT));
    }
    private static function company(){
        return class_exists('MAD4B_Company_PK_Identity_V1') ?
            MAD4B_Company_PK_Identity_V1::identity() : '';
    }
    private static function host(){
        return strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
    }
    private static function id($value) {
        return is_string($value) &&
            preg_match('/^[a-z][a-z0-9-]{2,64}$/D',$value);
    }
    private static function public_key(){
        return openssl_pkey_get_public((string)get_option(
            'mad4b_company_central_pk_trust_v1',''));
    }
    private static function signed_request(WP_REST_Request $request) {
        if($request->get_method()!=='POST' ||
            $request->get_route()!==self::ROUTE ||
            !self::company() ||
            (string)$request->get_header('content-type')!=='application/json')
            return self::err('request_shape');
        $raw=$request->get_body();
        if(!is_string($raw)||strlen($raw)<20||strlen($raw)>16000)
            return self::err('payload_size');
        $data=json_decode($raw,true);
        if(!is_array($data) || ($data['contract']??null)!==self::CONTRACT ||
            ($data['company_id']??null)!==self::company() ||
            ($data['target_host']??null)!==self::host())
            return self::err('company_or_contract');
        $ts=(string)$request->get_header('x-mad4b-time');
        $nonce=(string)$request->get_header('x-mad4b-nonce');
        $sig=(string)$request->get_header('x-mad4b-signature');
        if(!preg_match('/^[0-9]{10}$/D',$ts) ||
           abs(time()-(int)$ts)>120 ||
           !preg_match('/^[a-f0-9]{32}$/D',$nonce) ||
           strlen($sig)>256)
            return self::err('timestamp_nonce');
        $decoded=base64_decode($sig,true);
        $pem=self::public_key();
        $payload="POST\n".self::ROUTE."\n".self::company()."\n".
            self::host()."\n".$ts."\n".$nonce."\n".hash('sha256',$raw);
        if(!$pem || !is_string($decoded) ||
           openssl_verify($payload,$decoded,$pem,OPENSSL_ALGO_SHA256)!==1)
            return self::err('signature_invalid');
        // Atomic replay claim using the UNIQUE option_name index.
        global $wpdb;
        $name=self::NONCE_PREFIX.$nonce;
        $insert=$wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload)
             VALUES (%s,%s,'off')",$name,(string)time()));
        if($insert!==1)return self::err('replayed_or_storage_unavailable',409);
        return $data;
    }
    private static function delegated($data) {
        $delegation=get_option(self::DELEGATION,null);
        if(!is_array($delegation) ||
            ($delegation['company_id']??null)!==self::company() ||
            ($delegation['enabled']??false)!==true ||
            !is_int($delegation['expires_at']??null) ||
            $delegation['expires_at']<=time() ||
            !is_array($delegation['allowed_actions']??null) ||
            !in_array($data['action']??null,$delegation['allowed_actions'],true))
            return self::err('source_delegation_missing');
        return $delegation;
    }
    public static function validate_intent($data,$delegation=null) {
        if(!is_array($data) || ($data['contract']??null)!==self::CONTRACT ||
            !in_array(($data['operation']??''),array('preflight','apply','revoke'),true) ||
            !in_array(($data['action']??''),array('content_release_reference'),true) ||
            !is_string($data['intent_id']??null) ||
            !preg_match('/^[a-f0-9]{40,64}$/D',$data['intent_id']) ||
            !self::id($data['consumer_company']??null) ||
            !is_array($data['selection']??null))
            return self::err('intent_invalid',422);
        $s=$data['selection'];
        if(array_diff(array_keys($s),array('record_ids','languages','source_post_type',
            'valid_until','reference_only','checkout_enabled')) ||
            !is_array($s['record_ids']??null)||
            !$s['record_ids']||count($s['record_ids'])>self::MAX_IDS ||
            !is_array($s['languages']??null) ||
            !$s['languages']||count($s['languages'])>6 ||
            !is_string($s['source_post_type']??null)||
            !preg_match('/^[a-z][a-z0-9_-]{1,45}$/D',$s['source_post_type'])||
            ($s['reference_only']??null)!==true ||
            ($s['checkout_enabled']??null)!==false ||
            !is_int($s['valid_until']??null) ||
            $s['valid_until']<=time() ||
            $s['valid_until']>time()+366*86400)
            return self::err('selection_invalid',422);
        $seen=array();foreach($s['record_ids'] as $id){
            if(!is_int($id)||$id<1||isset($seen[$id]))return self::err('record_ids_invalid',422);
            $seen[$id]=true;
        }
        $seen=array();
        foreach($s['languages'] as $language){
            if(!is_string($language) ||
               !in_array($language,array('en','ar','fr','es','it','de'),true) ||
               isset($seen[$language]))return self::err('languages_invalid',422);
            $seen[$language]=true;
        }
        // Do not accept a signed request as proof of delegated owner authority.
        if($delegation!==null){
            if(!is_array($delegation) ||
                !in_array($data['consumer_company'],
                    (array)($delegation['consumer_companies']??array()),true) ||
                !in_array($s['source_post_type'],
                    (array)($delegation['source_post_types']??array()),true) ||
                $s['valid_until']>$delegation['expires_at'])
                return self::err('delegation_scope_exceeded');
        }
        return true;
    }
    private static function source_records($data) {
        $s=$data['selection'];
        if(!post_type_exists($s['source_post_type']))
            return self::err('source_type_missing',409);
        $wpml=(array)get_option('icl_sitepress_settings',array());
        $active=(array)($wpml['active_languages']??array());
        $hidden=(array)($wpml['hidden_languages']??array());
        if(array_diff($s['languages'],array_diff($active,$hidden)))
            return self::err('source_language_hidden',409);
        global $wpdb;
        foreach($s['record_ids'] as $id){
            $post=get_post($id);
            if(!$post || $post->post_type!==$s['source_post_type'] ||
               $post->post_status!=='publish')
                return self::err('source_record_missing',409);
            $lang=$wpdb->get_var($wpdb->prepare(
                "SELECT language_code FROM {$wpdb->prefix}icl_translations
                 WHERE element_type=%s AND element_id=%d LIMIT 1",
                'post_'.$s['source_post_type'],$id));
            if(!is_string($lang) || !in_array($lang,$s['languages'],true))
                return self::err('record_locale_mismatch',409);
        }
        return true;
    }
    private static function receipt($data,$result) {
        $payload=array('contract'=>self::CONTRACT,
            'company_id'=>self::company(),
            'canonical_host'=>self::host(),
            'intent_id'=>$data['intent_id'],
            'operation'=>$data['operation'],
            'action'=>$data['action'],
            'result'=>$result,'issued_at'=>time());
        $private=openssl_pkey_get_private((string)get_option(
            'mad4b_company_pk_private_v1',''));
        $body=wp_json_encode($payload,JSON_UNESCAPED_SLASHES);
        if(!$private||!is_string($body)||
           !openssl_sign($body,$signed,$private,OPENSSL_ALGO_SHA256))
            return self::err('source_receipt_signature_failed',503);
        $reply=new WP_REST_Response(array('payload'=>$payload,
            'body_sha256'=>hash('sha256',$body),
            'signature'=>base64_encode($signed)),200);
        $reply->header('Cache-Control','private, no-store');
        $reply->header('X-LiteSpeed-Cache-Control','no-cache');
        return $reply;
    }
    public static function handle(WP_REST_Request $request) {
        $data=self::signed_request($request);
        if(is_wp_error($data))return $data;
        $validated=self::validate_intent($data);
        if(is_wp_error($validated))return $validated;
        $delegation=self::delegated($data);
        if(is_wp_error($delegation))return $delegation;
        $validated=self::validate_intent($data,$delegation);
        if(is_wp_error($validated))return $validated;
        $verified=self::source_records($data);
        if(is_wp_error($verified))return $verified;
        if($data['operation']==='preflight'){
            return self::receipt($data,array('state'=>'ready_for_owner_approved_apply',
                'content_release_authorized'=>false,'records_verified'=>count($data['selection']['record_ids']),
                'delegation_expires_at'=>$delegation['expires_at']));
        }
        // No arbitrary endpoint/listing/plugin/option edits. Only an explicitly
        // delegated reference-content release grant can change.
        $option=self::GRANTS;
        $all=get_option($option,array());
        if(!is_array($all))return self::err('grant_registry_invalid',409);
        $key=$data['consumer_company'].':'.$data['intent_id'];
        $state=$data['operation']==='apply'?'active':'revoked';
        if(isset($all[$key])){
            if(($all[$key]['selection_sha256']??'')!==hash('sha256',wp_json_encode(
                $data['selection'],JSON_UNESCAPED_SLASHES)))
                return self::err('intent_payload_conflict',409);
            if($all[$key]['status']===$state)
                return self::receipt($data,array('state'=>$state,'idempotent_replay'=>true));
            if($data['operation']==='apply')
                return self::err('revoked_intent_cannot_reactivate',409);
        } elseif($data['operation']==='revoke'){
            return self::err('grant_missing',404);
        }
        $all[$key]=array(
            'status'=>$state,'source_company'=>self::company(),
            'consumer_company'=>$data['consumer_company'],
            'source_post_type'=>$data['selection']['source_post_type'],
            'record_ids'=>$data['selection']['record_ids'],
            'languages'=>$data['selection']['languages'],
            'valid_until'=>$data['selection']['valid_until'],
            'selection_sha256'=>hash('sha256',wp_json_encode(
                $data['selection'],JSON_UNESCAPED_SLASHES)),
            'updated_at'=>time(),
            'reference_only'=>true,'checkout_enabled'=>false
        );
        if(!update_option($option,$all,false))
            return self::err('grant_registry_commit_failed',503);
        return self::receipt($data,array('state'=>$state,'reference_only'=>true,
            'checkout_enabled'=>false,'content_grant_written'=>true));
    }
    public static function boot(){
        if(!self::company())return;
        add_action('rest_api_init',static function(){
            register_rest_route('mad4b-company/v1','/hub-control',array(
                'methods'=>'POST','callback'=>array(__CLASS__,'handle'),
                'permission_callback'=>'__return_true'));
        },32);
    }
}
MAD4B_Company_Hub_Control_Agent_V1::boot();
