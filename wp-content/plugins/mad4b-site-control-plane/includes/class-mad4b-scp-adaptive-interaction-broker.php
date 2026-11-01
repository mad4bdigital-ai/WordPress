<?php
if ( ! defined('ABSPATH') ) exit;
/**
 * Native MAD4B Adaptive Interaction & Recovery Broker, v1 Local pilot.
 * Error -> value-free intervention -> actor/site-bound checkpoint -> current
 * provider/Ability readback. Never collects secrets, refreshes credentials,
 * grants authority, performs OAuth consent, or retries native writes.
 */
final class MAD4B_SCP_Adaptive_Interaction_Broker {
    const CONTRACT = 'mad4b.adaptive-interaction-broker.v1';
    const NAMESPACE_ = 'mad4b/v1';
    const ROUTE = '/adaptive-interaction';
    const TTL = 600;
    const PREFIX = 'mad4b_aib_v1_';
    private static $booted = false;
    private static function catalog() {
        return array(
            'mad4b_mcp_scoped_credential_required' => array('WAITING_AUTH','operator_credential_resolver'),
            'mad4b_mcp_credential_resolver_unavailable' => array('WAITING_AUTH','operator_credential_resolver'),
            'mad4b_mcp_credential_scope_missing' => array('WAITING_AUTH','operator_credential_resolver'),
            'mad4b_provider_unknown' => array('WAITING_PROVIDER','provider_connection'),
            'mad4b_provider_remote_ticket_required' => array('WAITING_APPROVAL','native_owner_approval'),
            'mad4b_cso01_field_sensitive_or_invalid' => array('WAITING_SECURE_HANDOFF','first_party_secret_handoff'),
            'mad4b_cso01_integration_query_secret_denied' => array('WAITING_SECURE_HANDOFF','first_party_secret_handoff'),
            'mad4b_cso_secret_secrets_disabled' => array('WAITING_SECURE_HANDOFF','first_party_secret_handoff'),
            'mad4b_cso01_stale_descriptor' => array('WAITING_INPUT','fresh_nonsecret_form'),
            'mad4b_cso_form_not_certified_or_target_unsupported' => array('WAITING_CERTIFICATION','native_schema_certification'),
            'mad4b_approval_exact_input_required' => array('WAITING_APPROVAL','native_owner_approval'),
            'mad4b_capability_gateway_input_invalid' => array('WAITING_INPUT','fresh_nonsecret_form'),
        );
    }
    public static function hint( $reason ) {
        $item = self::catalog()[(string)$reason] ?? null;
        if ( ! $item ) return null;
        return array('contract'=>self::CONTRACT.'.hint.v1', 'reason_code'=>$reason,
            'state'=>$item[0], 'resolution_kind'=>$item[1],
            'next_ability'=>'mad4b/adaptive-interaction-plan',
            'client_action'=>'request_bounded_interaction_plan',
            'credential_value_requested_in_chat'=>false,
            'secret_ingress'=>'separate_first_party_only','mutation_authorized'=>false,
            'automatic_retry_authorized'=>false);
    }
    public static function boot() {
        if ( self::$booted ) return;
        self::$booted=true;
        add_action('rest_api_init',array(__CLASS__,'rest'));
        add_action('wp_abilities_api_init',array(__CLASS__,'abilities'),47);
    }
    public static function permitted() {
        return wp_get_environment_type()==='local'
            && class_exists('MAD4B_SCP_Site_Profile')
            && class_exists('MAD4B_SCP_Policy')
            && current_user_can('manage_options') && get_current_user_id()>0
            && MAD4B_SCP_Policy::can_read()
            && MAD4B_SCP_Policy::can_connect_user(get_current_user_id())
            && !empty(MAD4B_SCP_Site_Profile::status()['origin_match'])
            && !empty(MAD4B_SCP_Site_Profile::status()['environment_match']);
    }
    private static function deny($name) {
        return new WP_Error('mad4b_aib_'.$name,'Adaptive interaction requires verified Local site and authorized actor.',array('status'=>403,'authorizing'=>false,'plaintext_in_chat'=>false));
    }
    private static function clean($args,$fields) {
        if(!is_array($args) || array_diff(array_keys($args),$fields))return false;
        if(class_exists('MAD4B_SCP_CSO_Scope') && !MAD4B_SCP_CSO_Scope::safe_data($args))return false;
        return true;
    }
    private static function anchor() {
        $site=MAD4B_SCP_Site_Profile::status();
        return array('site_uuid'=>(string)$site['site_uuid'],
          'profile_digest'=>(string)$site['profile_digest'],
          'revision'=>(int)$site['revision'],'environment'=>'local',
          'actor_id'=>get_current_user_id());
    }
    private static function key($ref){return self::PREFIX.hash('sha256',$ref);}
    private static function ref_valid($v){return is_string($v) && 1===preg_match('/^aib\.[a-f0-9]{48}$/D',$v);}
    private static function public_record($p) {
        return array('contract'=>self::CONTRACT,'checkpoint_ref'=>$p['ref'],'state'=>$p['state'],
            'reason_code'=>$p['reason'],'resolution_kind'=>$p['resolution_kind'],
            'expires_at'=>$p['expires_at'],'provider'=>$p['provider'],
            'requires_human'=>true,'credential_value_requested_in_chat'=>false,
            'authorizing'=>false,'mutation_authorized'=>false,
            'write_resume_supported'=>false,'automatic_retry_authorized'=>false,
            'next_action'=>$p['next_action'], 'resume_mode'=>'explicit_readback_only',
            'input_validated'=>!empty($p['input_digest']));
    }
    /** Creates one nonsecret checkpoint. A request never includes credentials. */
    public static function plan($args=array()) {
        if(!self::permitted())return self::deny('actor_denied');
        if(!self::clean($args,array('reason_code','provider','ability_name')))
            return self::deny('secret_or_unknown_input');
        $reason=(string)($args['reason_code']??'');$hint=self::hint($reason);
        if(!$hint)return self::deny('unrecognized_reason');
        $provider=(string)($args['provider']??'');
        if($provider!==''&&!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D',$provider))return self::deny('provider_invalid');
        $ability=(string)($args['ability_name']??'');
        if($ability!==''&&!preg_match('/^[a-z0-9._-]{1,96}\/[a-z0-9._-]{1,96}$/D',$ability))return self::deny('ability_invalid');
        // Only already-certified read abilities can ever be retried here.
        // CSO01 itself certifies which canonical READ schemas can be exposed.
        // The broker never executes this ability or interprets its write input.
        $read_ability=$hint['resolution_kind']==='fresh_nonsecret_form'?$ability:'';
        try{$ref='aib.'.bin2hex(random_bytes(24));}catch(Throwable $e){return self::deny('randomness_unavailable');}
        $p=array('ref'=>$ref,'scope'=>self::anchor(),'reason'=>$reason,
            'state'=>$hint['state'],'resolution_kind'=>$hint['resolution_kind'],
            'provider'=>$provider,'ability'=>$read_ability,
            'expires_at'=>time()+self::TTL,'next_action'=>$hint['client_action']);
        // WordPress transients expire automatically; a bounded actor quota
        // prevents unbounded credential-free checkpoint creation.
        $quota_key=self::PREFIX.'quota_'.hash('sha256',wp_json_encode(self::anchor()));
        $quota=(int)get_transient($quota_key);
        if($quota>=32)return self::deny('checkpoint_capacity_reached');
        if(!set_transient(self::key($ref),$p,self::TTL))return self::deny('checkpoint_not_persisted');
        set_transient($quota_key,$quota+1,self::TTL);
        $out=self::public_record($p);
        $out['intervention']=self::intervention($p);
        return $out;
    }
    private static function intervention($p) {
        $kind=$p['resolution_kind'];
        $out=array('type'=>$kind,'user_action_required'=>true,
            'form_in_chat_allowed'=>!in_array($kind,array('first_party_secret_handoff','operator_credential_resolver','provider_connection'),true),
            'first_party_secret_value_only'=>true,'ready'=>false);
        if($kind==='fresh_nonsecret_form' && $p['ability']!=='') {
            if(class_exists('MAD4B_SCP_CSO_Forms') && MAD4B_SCP_CSO_Scope::enabled('forms')){
                $schema=MAD4B_SCP_CSO_Forms::schema($p['ability']);
                if(!is_wp_error($schema)){$out['ready']=true;$out['form']=$schema;}
            } elseif(class_exists('MAD4B_SCP_CSO01_Read_Foundation') && MAD4B_SCP_CSO01_Read_Foundation::can_read()) {
                $schema=MAD4B_SCP_CSO01_Read_Foundation::form_schema(array('ability_name'=>$p['ability']));
                if(!is_wp_error($schema)){$out['ready']=true;$out['form']=$schema;}
            }
        }
        if(in_array($kind,array('first_party_secret_handoff','operator_credential_resolver'),true)){
            $out['ready']=false;
            $out['blocker']=wp_parse_url(home_url(),PHP_URL_SCHEME)==='https'
                ? 'certified_first_party_provider_handoff_required'
                : 'verified_https_origin_required';
        }
        // The provider center can give an owner URL only if the original
        // provider permission and exact site identity are already verified.
        if($kind==='provider_connection'&&$p['provider']!==''&&class_exists('MAD4B_SCP_Provider_Connection_Center')){
            $plan=MAD4B_SCP_Provider_Connection_Center::plan(array('provider'=>$p['provider']));
            if(!is_wp_error($plan)){
                $out['provider_action']=$plan['action'];
                if(($plan['consent_requires_browser']??false) && wp_parse_url(home_url(),PHP_URL_SCHEME)==='https'){
                    $out['owner_handoff_url']=$plan['owner_handoff_url'];$out['ready']=true;
                }
            }
        }
        return $out;
    }
    private static function read($args){
        if(!self::permitted())return self::deny('actor_denied');
        if(!self::clean($args,array('checkpoint_ref'))||!self::ref_valid($args['checkpoint_ref']??null))return self::deny('checkpoint_invalid');
        $p=get_transient(self::key($args['checkpoint_ref']));
        if(!is_array($p)||!isset($p['scope'],$p['expires_at']))return self::deny('checkpoint_missing');
        if($p['expires_at']<=time())return self::deny('checkpoint_expired');
        if(self::anchor()!==$p['scope'])return self::deny('checkpoint_scope_changed');
        return $p;
    }
    /** Validates ordinary typed values, returning only an evidence digest.
     * Passwords, API keys and other credentials are never accepted here.
     * This receipt does not execute the original operation or approve writes.
     */
    public static function resolve($args=array()) {
        if(!is_array($args)||array_diff(array_keys($args),array('checkpoint_ref','expected_descriptor_sha256','values'))
            || !is_array($args['values']??null)
            || !preg_match('/^[a-f0-9]{64}$/D',(string)($args['expected_descriptor_sha256']??''))
            || !class_exists('MAD4B_SCP_CSO_Scope')
            || !MAD4B_SCP_CSO_Scope::safe_data($args['values']))
            return self::deny('secret_or_invalid_values');
        $p=self::read(array('checkpoint_ref'=>$args['checkpoint_ref']??''));
        if(is_wp_error($p))return $p;
        if($p['resolution_kind']!=='fresh_nonsecret_form' || $p['ability']===''
            ||!class_exists('MAD4B_SCP_CSO01_Read_Foundation'))
            return self::deny('nonsecret_form_unavailable');
        $result=MAD4B_SCP_CSO01_Read_Foundation::form_validate(array(
            'ability_name'=>$p['ability'],
            'expected_descriptor_sha256'=>$args['expected_descriptor_sha256'],
            'values'=>$args['values']));
        if(is_wp_error($result))return $result;
        if(empty($result['valid']))return array('contract'=>self::CONTRACT,
            'state'=>'INPUT_INVALID','valid'=>false,'issues'=>$result['issues']??array(),
            'field_errors'=>$result['field_errors']??array(),
            'values_echoed'=>false,'mutation_authorized'=>false);
        $p['input_digest']=hash('sha256',wp_json_encode($args['values']));
        $p['schema_digest']=$args['expected_descriptor_sha256'];
        $p['state']='INPUT_VALIDATED';
        if(!set_transient(self::key($p['ref']),$p,max(1,$p['expires_at']-time())))return self::deny('checkpoint_update_failed');
        return array('contract'=>self::CONTRACT,'state'=>'INPUT_VALIDATED',
            'checkpoint_ref'=>$p['ref'],'input_digest'=>$p['input_digest'],
            'values_echoed'=>false,'saved_values'=>false,
            'native_operation_executed'=>false,'mutation_authorized'=>false,
            'next_action'=>'resume_or_replan_original_operation');
    }
    public static function status($args=array()){
        $p=self::read($args);if(is_wp_error($p))return $p;
        $out=self::public_record($p);$out['intervention']=self::intervention($p);
        return $out;
    }
    /** No blind retry: re-observe provider or certified readonly operation. */
    public static function resume($args=array()) {
        $p=self::read($args);if(is_wp_error($p))return $p;
        $out=self::public_record($p);
        $out['state']='WAITING_HUMAN_OR_AUTH';$out['readback_complete']=false;
        if($p['resolution_kind']==='provider_connection'&&$p['provider']!==''
            && class_exists('MAD4B_SCP_Provider_Connection_Center')){
            $plan=MAD4B_SCP_Provider_Connection_Center::plan(array('provider'=>$p['provider']));
            if(!is_wp_error($plan)){
                $out['provider_action']=$plan['action'];
                $out['readback_complete']=($plan['action']==='no_action');
                if($out['readback_complete'])$out['state']='READY_FOR_AGENT_REPLAN';
            }
        } elseif($p['resolution_kind']==='fresh_nonsecret_form' && !empty($p['input_digest'])) {
            // The agent must replan against a current schema and native owner
            // permission; the broker cannot replay a previously blocked write.
            $out['state']='READY_FOR_AGENT_REPLAN';$out['readback_complete']=true;
            $out['validated_input_digest']=$p['input_digest'];
        }
        $out['mutation_authorized']=false;
        $out['write_resume_supported']=false;
        return $out;
    }
    /** Native WP registered MetaFields only. Descriptor metadata, never values.
     * JetEngine, complex relationships and WooCommerce private meta require a
     * separate owner-certified adapter; they are not guessed or autoedited.
     */
    public static function fields($args=array()) {
        if(!self::permitted())return self::deny('actor_denied');
        if(!self::clean($args,array('object_type','object_subtype')))
            return self::deny('field_selector_invalid');
        $type=(string)($args['object_type']??'post');
        $subtype=(string)($args['object_subtype']??'post');
        if($type!=='post'||!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D',$subtype))
            return self::deny('field_source_unsupported');
        $obj=function_exists('get_post_type_object')?get_post_type_object($subtype):null;
        if(!is_object($obj)||empty($obj->show_in_rest)
            || !isset($obj->cap->edit_posts)||!current_user_can($obj->cap->edit_posts))
            return self::deny('field_scope_denied');
        if(!function_exists('get_registered_meta_keys'))return self::deny('field_registry_unavailable');
        $all=get_registered_meta_keys($type,$subtype);
        if(!is_array($all))return self::deny('field_registry_invalid');
        $fields=array();
        foreach($all as $key=>$entry){
            if(count($fields)>=32)break;
            if(!is_string($key)||!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D',$key)
                ||preg_match('/secret|credential|token|password|api.?key|auth|nonce|private|price|stock/i',$key)
                ||!is_array($entry)||empty($entry['show_in_rest'])
                ||(function_exists('is_protected_meta')&&is_protected_meta($key,'post')))continue;
            $schema=is_array($entry['show_in_rest'])?($entry['show_in_rest']['schema']??array()):array();
            if(!is_array($schema))continue;
            $field_type=(string)($schema['type']??$entry['type']??'');
            if(!in_array($field_type,array('string','integer','number','boolean'),true))continue;
            $fields[]=array('key'=>$key,'type'=>$field_type,
                'control'=>$field_type==='boolean'?'checkbox':(in_array($field_type,array('integer','number'),true)?'number':'text'),
                'required'=>false,'value_exposed'=>false,'default_exposed'=>false,
                'schema_source'=>'wp_registered_meta','authorizing'=>false);
        }
        $out=array('contract'=>self::CONTRACT.'.fields.v1','object_type'=>$type,
            'object_subtype'=>$subtype,'fields'=>$fields,'count'=>count($fields),
            'supports_jetengine_unregistered_meta'=>false,
            'supports_private_woocommerce_meta'=>false,
            'supports_relationship_mutation'=>false,
            'read_only'=>true,'values_exposed'=>false,'mutation_authorized'=>false);
        $out['descriptor_sha256']=hash('sha256',wp_json_encode($out));
        return $out;
    }
    public static function rest(){
        foreach(array('plan'=>'POST','status'=>'POST','resolve'=>'POST','resume'=>'POST','fields'=>'POST') as $method=>$verb){
            register_rest_route(self::NAMESPACE_,self::ROUTE.'/'.$method,array(
                'methods'=>$verb,'permission_callback'=>array(__CLASS__,'permitted'),
                'callback'=>static function($request) use($method){
                    $args=$request->get_json_params();
                    if(!is_array($args))return self::deny('input_not_object');
                    return call_user_func(array(__CLASS__,$method),$args);
                },'args'=>array(),
            ));
        }
    }
    public static function abilities(){
        if(!function_exists('wp_register_ability'))return;
        $spec=array('plan'=>array('reason_code','provider','ability_name'),'status'=>array('checkpoint_ref'),'resolve'=>array('checkpoint_ref','expected_descriptor_sha256','values'),'resume'=>array('checkpoint_ref'),'fields'=>array('object_type','object_subtype'));
        foreach($spec as $name=>$keys){
            $id='mad4b/adaptive-interaction-'.$name;
            if(function_exists('wp_has_ability')&&wp_has_ability($id))continue;
            $props=array();foreach($keys as $key)$props[$key]=$key==='values'
                ?array('type'=>'object','maxProperties'=>24,'additionalProperties'=>true)
                :array('type'=>'string','maxLength'=>160);
            wp_register_ability($id,array('label'=>'MAD4B Adaptive Interaction '.ucfirst($name),
              'description'=>'Metadata-only blocker recovery; never accepts plaintext credentials or grants execution.',
              'category'=>'mad4b-read',
              'input_schema'=>array('type'=>'object','properties'=>$props,'additionalProperties'=>false),
              'output_schema'=>array('type'=>'object','additionalProperties'=>true),
              'permission_callback'=>array(__CLASS__,'permitted'),
              'execute_callback'=>array(__CLASS__,$name),
              'meta'=>array('public'=>false,'show_in_rest'=>false,
                'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read','non_authorizing'=>true),
                'annotations'=>array('readonly'=>$name==='fields','destructive'=>false,'idempotent'=>$name==='fields'))));
        }
    }
}
