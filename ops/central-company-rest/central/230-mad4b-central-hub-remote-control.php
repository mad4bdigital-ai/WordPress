<?php
/**
 * Plugin Name: MAD4B Central Hub Remote Control
 * Version: 0.1.0
 * Description: Governed signed single-company source control from tours.dev.mad4b.com.
 * This controller never installs plugins or silently changes JetEngine templates.
 */
defined('ABSPATH') || exit;

final class MAD4B_Central_Hub_Remote_Control_V1 {
    const CONTRACT='mad4b.hub.company-remote-control.v1';
    const REMOTE_ROUTE='/mad4b-company/v1/hub-control';
    const NS='mad4b-central/v1';
    const OWNER_SCOPE='mad4b_central_hub_operator_scopes_v1';
    const MAX_IDS=50;
    const PRIVATE_KEY_OPTION='mad4b_central_pk_private_v1';
    const REMOTE_PUBLIC_PREFIX='mad4b_central_pk_remote_public_v1_';

    private static function err($code,$status=403) {
        return new WP_Error('mad4b_central_hubctl_'.$code,$code,
            array('status'=>$status,'contract'=>self::CONTRACT));
    }
    public static function enabled() {
        return strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST))===
            'tours.dev.mad4b.com' &&
            class_exists('MAD4B_Central_Company_REST_V1');
    }
    private static function company($id) {
        if(!is_string($id)||!preg_match('/^[a-z0-9-]{3,80}$/D',$id))
            return null;
        return MAD4B_Central_Company_REST_V1::COMPANIES[$id]??null;
    }
    public static function status() {
        $states=get_option(MAD4B_Central_Company_REST_V1::OPTION,array());
        $scopes=get_option(self::OWNER_SCOPE,array());
        $out=array();
        foreach(MAD4B_Central_Company_REST_V1::COMPANIES as $id=>$company) {
            $paired=is_string(get_option(self::REMOTE_PUBLIC_PREFIX.
                str_replace('-','_',$id),null));
            $out[]=array('company_id'=>$id,'canonical_host'=>$company['host'],
                'signed_identity_paired'=>$paired,
                'read_connection_state'=>$states[$id]['connection_state']??'unverified',
                'remote_control_delegation_verified'=>false,
                'active_hub_control'=>'not_assumed',
                'source_grant_authority'=>'remote_source_explicit_delegation_required');
        }
        return array('contract'=>self::CONTRACT,
            'central_host'=>'tours.dev.mad4b.com',
            'controller_enabled'=>self::enabled(),
            'companies'=>$out,
            'operator_scopes_configured'=>is_array($scopes)?count($scopes):0,
            'phases'=>array('plan','preflight','apply','revoke'),
            'remote_binding_actions_live'=>false,
            'jwt_or_shared_secret_required'=>false,
            'existing_p256_keypair_reused'=>true,
            'source_owner_delegation_required'=>true,
            'jetengine_provider_binding_separately_certified'=>false,
            'source_read_signature_not_equivalent_to_release'=>true);
    }
    private static function is_list($ids) {
        return is_array($ids) && $ids &&
            array_keys($ids)===range(0,count($ids)-1);
    }
    public static function validate($input) {
        if(!self::enabled())return self::err('central_host_invalid');
        if(!is_array($input) ||
           array_diff(array_keys($input),
             array('company_id','consumer_company','action','selection')) ||
           !($company=self::company($input['company_id']??null)) ||
           !self::company($input['consumer_company']??null) ||
           ($input['company_id']??null)===$input['consumer_company'] ||
           ($input['action']??null)!=='content_release_reference' ||
           !is_array($input['selection']??null))
            return self::err('intent_invalid',422);
        $s=$input['selection'];
        if(array_diff(array_keys($s),array('record_ids','languages',
            'source_post_type','valid_until','reference_only','checkout_enabled')) ||
           !self::is_list($s['record_ids']??null) ||
           count($s['record_ids'])>self::MAX_IDS ||
           !self::is_list($s['languages']??null) ||
           count($s['languages'])>6 ||
           ($s['reference_only']??null)!==true ||
           ($s['checkout_enabled']??null)!==false ||
           !is_string($s['source_post_type']??null) ||
           !preg_match('/^[a-z][a-z0-9_-]{1,45}$/D',$s['source_post_type']) ||
           !is_int($s['valid_until']??null) ||
           $s['valid_until']<=time() ||
           $s['valid_until']>time()+366*86400)
            return self::err('selection_invalid',422);
        $seen=array();
        foreach($s['record_ids'] as $id) {
            if(!is_int($id)||$id<=0||isset($seen[$id]))
                return self::err('record_duplicate_or_invalid',422);
            $seen[$id]=true;
        }
        $seen=array();
        foreach($s['languages'] as $lang) {
            if(!is_string($lang) ||
               !in_array($lang,array('en','ar','es','fr','de','it'),true)||
               isset($seen[$lang]))
                return self::err('language_invalid',422);
            $seen[$lang]=true;
        }
        $intent=array('company_id'=>$input['company_id'],
            'consumer_company'=>$input['consumer_company'],
            'action'=>$input['action'],
            'selection'=>array(
                'record_ids'=>$s['record_ids'],
                'languages'=>$s['languages'],
                'source_post_type'=>$s['source_post_type'],
                'valid_until'=>$s['valid_until'],
                'reference_only'=>true,'checkout_enabled'=>false
            ));
        $hash=hash('sha256',wp_json_encode($intent,JSON_UNESCAPED_SLASHES));
        return array('contract'=>self::CONTRACT,
            'intent_id'=>$hash,'target_host'=>$company['host'],
            'company_id'=>$input['company_id'],
            'consumer_company'=>$input['consumer_company'],
            'action'=>$input['action'],
            'selection'=>$intent['selection'],
            'source_current_state'=>'identity_verified_only',
            'remote_apply_authorized'=>false,
            'publication_to_jetengine_authorized'=>false,
            'commerce_authorized'=>false,'valid_until'=>$s['valid_until']);
    }
    public static function owner_scope($intent,$operation) {
        $user=get_current_user_id();
        if($user<1||!current_user_can('manage_options'))
            return self::err('central_admin_required');
        $scopes=get_option(self::OWNER_SCOPE,array());
        $row=is_array($scopes)?($scopes[$user]??null):null;
        if(!is_array($row) ||
           ($row['enabled']??false)!==true ||
           !is_int($row['expires_at']??null) ||
           $row['expires_at']<=time() ||
           !in_array($intent['company_id'],(array)($row['source_companies']??array()),true) ||
           !in_array($intent['consumer_company'],(array)($row['consumer_companies']??array()),true) ||
           !in_array($intent['action'],(array)($row['actions']??array()),true) ||
           !in_array($operation,(array)($row['operations']??array()),true)) {
            return self::err('central_operator_company_scope_missing');
        }
        return true;
    }
    public static function dispatch($intent,$operation) {
        if(!is_array($intent) ||
           !in_array($operation,array('preflight','apply','revoke'),true))
           return self::err('operation_invalid',422);
        $validated=self::validate(array_intersect_key($intent,array_flip(
            array('company_id','consumer_company','action','selection'))));
        if(is_wp_error($validated))return $validated;
        if(!hash_equals($validated['intent_id'],(string)($intent['intent_id']??'')))
            return self::err('intent_version_or_payload_conflict',409);
        $owner=self::owner_scope($validated,$operation);
        if(is_wp_error($owner))return $owner;
        $company=self::company($validated['company_id']);
        $knownHost=$company['host'];
        $registry=get_option(MAD4B_Central_Company_REST_V1::OPTION,array());
        $companyState=$registry[$validated['company_id']]['connection_state']??null;
        if($companyState!=='authenticated_read_only')
            return self::err('source_identity_connection_not_current',409);
        $private=openssl_pkey_get_private((string)get_option(
            self::PRIVATE_KEY_OPTION,''));
        $remote=openssl_pkey_get_public((string)get_option(
            self::REMOTE_PUBLIC_PREFIX.str_replace('-','_',$validated['company_id']),''));
        if(!$private||!$remote)return self::err('asymmetric_trust_missing',409);
        $payload=array('contract'=>self::CONTRACT,
            'company_id'=>$validated['company_id'],
            'consumer_company'=>$validated['consumer_company'],
            'target_host'=>$knownHost,
            'operation'=>$operation,'action'=>$validated['action'],
            'intent_id'=>$validated['intent_id'],
            'selection'=>$validated['selection']);
        $json=wp_json_encode($payload,JSON_UNESCAPED_SLASHES);
        if(!is_string($json) || strlen($json)>16000)
            return self::err('payload_invalid',422);
        try{$nonce=bin2hex(random_bytes(16));}
        catch(Throwable $e){return self::err('entropy_unavailable',503);}
        $ts=(string)time();
        $canonical="POST\n".self::REMOTE_ROUTE."\n".$validated['company_id']."\n".
            $knownHost."\n".$ts."\n".$nonce."\n".hash('sha256',$json);
        if(!openssl_sign($canonical,$signed,$private,OPENSSL_ALGO_SHA256))
            return self::err('signing_failed',503);
        $response=wp_safe_remote_post($company['url'].'/wp-json'.self::REMOTE_ROUTE,
            array('timeout'=>15,'redirection'=>0,
                'sslverify'=>true,'body'=>$json,
                'headers'=>array('Content-Type'=>'application/json',
                    'Accept'=>'application/json',
                    'Cache-Control'=>'no-store',
                    'X-MAD4B-Time'=>$ts,'X-MAD4B-Nonce'=>$nonce,
                    'X-MAD4B-Signature'=>base64_encode($signed))));
        if(is_wp_error($response))return self::err('source_transport_failed',502);
        $status=(int)wp_remote_retrieve_response_code($response);
        if($status!==200)return self::err('source_rejected_'.$status,409);
        $raw=wp_remote_retrieve_body($response);
        if(!is_string($raw)||strlen($raw)>12000)return self::err('source_response_invalid',502);
        $body=json_decode($raw,true);
        $receipt=$body['payload']??null;
        $sig=base64_decode((string)($body['signature']??''),true);
        if(!is_array($receipt)||!is_string($sig) ||
            ($receipt['company_id']??null)!==$validated['company_id'] ||
            ($receipt['canonical_host']??null)!==$knownHost ||
            ($receipt['intent_id']??null)!==$validated['intent_id'] ||
            ($receipt['operation']??null)!==$operation ||
            ($receipt['action']??null)!==$validated['action'])
            return self::err('source_receipt_identity_invalid',409);
        $payloadBytes=wp_json_encode($receipt,JSON_UNESCAPED_SLASHES);
        if(!is_string($payloadBytes)||
           !hash_equals(hash('sha256',$payloadBytes),(string)($body['body_sha256']??''))||
           openssl_verify($payloadBytes,$sig,$remote,OPENSSL_ALGO_SHA256)!==1)
            return self::err('source_receipt_signature_invalid',409);
        $expected=array('preflight'=>'ready_for_owner_approved_apply',
            'apply'=>'active','revoke'=>'revoked');
        if(($receipt['result']['state']??null)!==$expected[$operation])
            return self::err('source_receipt_outcome_mismatch',409);
        return array('contract'=>self::CONTRACT,
            'company_id'=>$validated['company_id'],
            'intent_id'=>$validated['intent_id'],
            'operation'=>$operation,
            'source_signed_receipt_verified'=>true,
            'source_result'=>$receipt['result']??array(),
            'public_distribution_connected'=>false,
            'jetengine_query_mutated'=>false,
            'commercial_price_authorized'=>false);
    }
    /** Central administration of operator scope; no source consent implied. */
    public static function configure_operator(WP_REST_Request $request) {
        if(!self::enabled()||!current_user_can('manage_options'))
            return self::err('central_admin_required');
        $data=$request->get_json_params();
        if(!is_array($data) ||
           array_diff(array_keys($data),array('enabled','expires_at',
               'source_companies','consumer_companies','actions','operations')) ||
           !is_bool($data['enabled']??null) ||
           !is_int($data['expires_at']??null) ||
           $data['expires_at']<=time()||
           $data['expires_at']>time()+90*86400 ||
           !is_array($data['source_companies']??null) ||
           !is_array($data['consumer_companies']??null) ||
           !$data['source_companies'] || !$data['consumer_companies'] ||
           count($data['source_companies'])>12 ||
           count($data['consumer_companies'])>12 ||
           ($data['actions']??null)!==array('content_release_reference') ||
           !is_array($data['operations']??null)||
           !$data['operations'])
            return self::err('operator_scope_invalid',422);
        foreach(array_merge($data['source_companies'],
                            $data['consumer_companies']) as $company){
            if(!self::company($company))
                return self::err('company_scope_unknown',422);
        }
        foreach($data['operations'] as $op){
            if(!in_array($op,array('preflight','apply','revoke'),true))
                return self::err('operation_not_allowlisted',422);
        }
        $user=get_current_user_id();
        $option=self::OWNER_SCOPE;
        $rows=get_option($option,array());
        if(!is_array($rows))return self::err('scope_registry_invalid',409);
        $rows[$user]=array_merge($data,array(
            'approved_by'=>$user,'approved_at'=>time()));
        update_option($option,$rows,false);
        return array('contract'=>self::CONTRACT,
            'central_host'=>'tours.dev.mad4b.com',
            'user_id'=>$user,
            'central_operator_scope_accepted'=>true,
            'remote_source_delegation_verified'=>false,
            'operations'=>$data['operations'],
            'expires_at'=>$data['expires_at']);
    }
    public static function admin() {
        return self::enabled() && current_user_can('manage_options');
    }
    public static function rest_plan($request) {
        return self::validate($request->get_json_params());
    }
    public static function rest_dispatch($request) {
        $data=$request->get_json_params();
        if(!is_array($data)||!is_array($data['intent']??null))
            return self::err('intent_required',422);
        return self::dispatch($data['intent'],$data['operation']??'');
    }
    public static function boot() {
        if(!self::enabled())return;
        add_action('rest_api_init',static function(){
            register_rest_route(self::NS,'/hub-remote-control/status',array(
                'methods'=>'GET',
                'callback'=>array(__CLASS__,'status'),
                'permission_callback'=>array(__CLASS__,'admin')));
            register_rest_route(self::NS,'/hub-remote-control/plan',array(
                'methods'=>'POST',
                'callback'=>array(__CLASS__,'rest_plan'),
                'permission_callback'=>array(__CLASS__,'admin')));
            register_rest_route(self::NS,'/hub-remote-control/dispatch',array(
                'methods'=>'POST',
                'callback'=>array(__CLASS__,'rest_dispatch'),
                'permission_callback'=>array(__CLASS__,'admin')));
            register_rest_route(self::NS,'/hub-remote-control/operator',array(
                'methods'=>'POST',
                'callback'=>array(__CLASS__,'configure_operator'),
                'permission_callback'=>array(__CLASS__,'admin')));
        },32);
    }
}
MAD4B_Central_Hub_Remote_Control_V1::boot();
