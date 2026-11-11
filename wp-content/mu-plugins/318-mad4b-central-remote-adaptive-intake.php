<?php
/**
 * Plugin Name: MAD4B Central Remote Adaptive Intake
 * Description: Dynamic, actor-bound, provider-neutral nonsecret input forms for registered agent operations.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Central_Remote_Adaptive_Intake_V1', false)) {
final class MAD4B_Central_Remote_Adaptive_Intake_V1 {
    const CONTRACT = 'mad4b.central-remote-adaptive-intake.v1';
    const ACTION = 'mad4b_remote_adaptive_intake';
    const CREATE_ACTION = 'mad4b_remote_adaptive_intake_create';
    const TTL = DAY_IN_SECONDS;
    const PREFIX = 'mad4b_remote_intake_v1_';
    const MAX_FIELDS = 16;
    private static function site_ready() {
        return wp_get_environment_type()==='staging' &&
            untrailingslashit(home_url('/'))==='https://tours.dev.mad4b.com' &&
            class_exists('MAD4B_Company_Environment_Registry_V2',false);
    }
    public static function permitted() {
        return self::site_ready() && current_user_can('manage_options');
    }
    private static function error($code,$http=400) {
        return new WP_Error('mad4b_remote_intake_'.$code,'Registered operation and verified input scope are required.',
            array('status'=>$http));
    }
    private static function secret($key) {
        return (bool)preg_match('/(?:password|passwd|secret|credential|token|api.?key|private.?key|authorization|nonce|otp|recovery.?code|bearer|session.?cookie)/i',(string)$key);
    }
    private static function ref_ok($ref) {
        return is_string($ref) && preg_match('/^rai\.[a-f0-9]{48}$/D',$ref)===1;
    }
    private static function stored_key($ref) {
        return self::PREFIX.hash('sha256',$ref);
    }
    private static function company_site($company,$environment) {
        if(!is_string($company)||!is_string($environment) ||
            !preg_match('/^[a-z][a-z0-9-]{2,63}$/D',$company) ||
            !in_array($environment,array('staging','production'),true))return null;
        $registry=MAD4B_Company_Environment_Registry_V2::discover();
        $row=$registry[$company]['environments'][$environment]??null;
        if(!is_array($row))return null;
        $origin=(string)($row['canonical_origin']??'');
        $u=wp_parse_url($origin);
        if(!is_array($u)||($u['scheme']??'')!=='https'||empty($u['host'])||
            isset($u['user'])||isset($u['pass'])||isset($u['port'])||
            isset($u['query'])||isset($u['fragment'])||
            !in_array($u['path']??'',array('','/'),true))return null;
        return array('company'=>$company,'environment'=>$environment,'origin'=>$origin,
            'site_uuid'=>$row['site_uuid']??null);
    }
    private static function fields_for($target) {
        if($target==='company.cache.hostinger.refresh') {
            return array(
                array('name'=>'api_website_domain_candidate','label'=>'Hostinger website domain',
                      'type'=>'string','format'=>'hostname','maxLength'=>253,'required'=>true,
                      'description'=>'Provider website domain as shown in Hostinger; not an API key'),
            );
        }
        // Generic adapter: consume registered WordPress Ability input schemas,
        // rather than letting an agent supply an arbitrary form or field type.
        if(!is_string($target)||!preg_match('~^[a-z0-9._-]{1,80}/[a-z0-9._-]{1,100}$~D',$target) ||
           !function_exists('wp_get_ability'))return self::error('operation_not_registered',404);
        $ability=wp_get_ability($target);
        if(!$ability || !method_exists($ability,'get_input_schema'))return self::error('operation_not_registered',404);
        $schema=$ability->get_input_schema();
        if(!is_array($schema)||($schema['type']??'')!=='object'||
            !is_array($schema['properties']??null))return self::error('invalid_registered_schema',422);
        $required=$schema['required']??array();
        if(!is_array($required)||count($required)>self::MAX_FIELDS)return self::error('schema_out_of_bounds',422);
        $out=array();
        foreach($required as $key) {
            if(!is_string($key)||!preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$key) ||
               !isset($schema['properties'][$key])||!is_array($schema['properties'][$key]))
                return self::error('schema_field_unsupported',422);
            if(self::secret($key))return self::error('secure_provider_handoff_required',409);
            $entry=$schema['properties'][$key];$type=$entry['type']??'';
            if(!in_array($type,array('string','integer','number','boolean'),true))
                return self::error('schema_field_unsupported',422);
            $field=array('name'=>$key,'label'=>ucwords(str_replace('_',' ',$key)),
                'type'=>$type,'required'=>true,'maxLength'=>min(512,max(1,(int)($entry['maxLength']??256))));
            if(isset($entry['enum'])) {
                if(!is_array($entry['enum'])||!$entry['enum']||count($entry['enum'])>32||
                   count(array_filter($entry['enum'],'is_scalar'))!==count($entry['enum']))
                    return self::error('schema_enum_unsupported',422);
                $field['enum']=array_values($entry['enum']);
            }
            $out[]=$field;
        }
        if(!$out)return self::error('no_missing_required_inputs',409);
        return $out;
    }
    private static function digest($fields) {
        return hash('sha256',wp_json_encode($fields,JSON_UNESCAPED_SLASHES));
    }
    private static function origin_url($ref) {
        return add_query_arg(array('action'=>self::ACTION,'ref'=>$ref),admin_url('admin-post.php'));
    }
    public static function create($params=array()) {
        if(!self::permitted())return self::error('admin_required',403);
        if(!is_array($params)||array_diff(array_keys($params),
            array('company_id','environment','operation','correlation_ref')))return self::error('invalid_input',422);
        $company=$params['company_id']??null;$environment=$params['environment']??null;
        $site=self::company_site($company,$environment);
        if(!$site)return self::error('company_site_not_enrolled',404);
        $target=$params['operation']??null;
        if(!is_string($target)||strlen($target)>191)return self::error('operation_invalid',422);
        $fields=self::fields_for($target);
        if(is_wp_error($fields))return $fields;
        $corr=(string)($params['correlation_ref']??'');
        if(strlen($corr)>120||($corr!==''&&!preg_match('/^[a-zA-Z0-9._:-]{1,120}$/D',$corr)))
            return self::error('correlation_invalid',422);
        $actor=get_current_user_id();
        if($actor<1)return self::error('user_session_required',403);
        try{$ref='rai.'.bin2hex(random_bytes(24));}catch(Throwable $e){return self::error('random_unavailable',503);}
        $record=array('contract'=>self::CONTRACT,'ref'=>$ref,'site'=>$site,
            'actor_id'=>$actor,'operation'=>$target,'correlation_ref'=>$corr,
            'field_schema'=>$fields,'schema_digest'=>self::digest($fields),
            'created_at'=>time(),'expires_at'=>time()+self::TTL,
            'state'=>'WAITING_INPUT','values'=>array());
        if(!set_transient(self::stored_key($ref),$record,self::TTL))
            return self::error('record_not_saved',503);
        return self::public_record($record);
    }
    private static function read($ref) {
        if(!self::permitted()||!self::ref_ok($ref))return self::error('scope_invalid',403);
        $record=get_transient(self::stored_key($ref));
        if(!is_array($record)||($record['contract']??'')!==self::CONTRACT ||
           ($record['expires_at']??0)<time())return self::error('missing_or_expired',404);
        if((int)($record['actor_id']??0)!==get_current_user_id())
            return self::error('actor_mismatch',403);
        $site=$record['site']??array();
        $live=self::company_site($site['company']??null,$site['environment']??null);
        if(!$live || $live!==$site)return self::error('site_identity_changed',409);
        return $record;
    }
    private static function public_record($record) {
        return array('contract'=>self::CONTRACT,'checkpoint_ref'=>$record['ref'],
            'company'=>$record['site']['company'],'environment'=>$record['site']['environment'],
            'canonical_origin'=>$record['site']['origin'],'operation'=>$record['operation'],
            'correlation_ref'=>$record['correlation_ref'],'state'=>$record['state'],
            'form_url'=>self::origin_url($record['ref']),'expires_at'=>gmdate('c',$record['expires_at']),
            'fields'=>$record['field_schema'],
            'values_saved'=>$record['state']==='INPUT_SAVED',
            'input_values_exposed_in_chat'=>false,'auto_write_authorized'=>false,
            'resume'=>'agent_readback_and_replan','secret_capture_supported'=>false);
    }
    public static function status($params=array()) {
        if(!is_array($params)||!isset($params['checkpoint_ref']))return self::error('missing_checkpoint',422);
        $record=self::read($params['checkpoint_ref']);
        if(is_wp_error($record))return $record;
        $value=self::public_record($record);
        $value['saved_at']=$record['saved_at']??null;
        $value['input_digest']=$record['input_digest']??null;
        // Safe, typed, non-secret values are retrieved by the authenticated
        // owner; not echoed to any third-party/public form or access token.
        if($record['state']==='INPUT_SAVED')$value['saved_values']=$record['values'];
        return $value;
    }
    private static function parse_field($field,$raw) {
        if(!is_scalar($raw))return new WP_Error('mad4b_remote_intake_bad_scalar');
        $type=$field['type'];$s=(string)$raw;
        if(strlen($s)>1024||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$s))
            return new WP_Error('mad4b_remote_intake_bad_value');
        if($type==='boolean') {
            if(!in_array($s,array('0','1'),true))return new WP_Error('mad4b_remote_intake_bad_bool');
            $value=$s==='1';
        }elseif($type==='integer') {
            if(!preg_match('/^-?[0-9]{1,12}$/D',$s))return new WP_Error('mad4b_remote_intake_bad_integer');
            $value=(int)$s;
        }elseif($type==='number') {
            if(!preg_match('/^-?[0-9]{1,10}(?:\.[0-9]{1,8})?$/D',$s))return new WP_Error('mad4b_remote_intake_bad_number');
            $value=(float)$s;
        }else {
            $s=trim($s);
            if($s===''||strlen($s)>($field['maxLength']??256))
                return new WP_Error('mad4b_remote_intake_bad_string');
            if(($field['format']??'')==='hostname') {
                if(!filter_var($s,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)||preg_match('/[\/:@?#]/',$s))
                    return new WP_Error('mad4b_remote_intake_bad_hostname');
                $s=strtolower($s);
            }
            $value=sanitize_text_field($s);
        }
        if(isset($field['enum'])&&!in_array($value,$field['enum'],true))
            return new WP_Error('mad4b_remote_intake_bad_enum');
        return $value;
    }
    public static function validate_values($fields,$params) {
        if(!is_array($fields)||!is_array($params)||count($params)!==count($fields))
            return self::error('fields_missing_or_extra',422);
        $out=array();
        foreach($fields as $field) {
            $key=$field['name'];
            if(self::secret($key)||!array_key_exists($key,$params))
                return self::error('field_invalid_or_sensitive',422);
            $value=self::parse_field($field,$params[$key]);
            if(is_wp_error($value))return self::error('field_validation_failed',422);
            $out[$key]=$value;
        }
        return $out;
    }
    private static function update_company_proposal($record,$values) {
        if($record['operation']!=='company.cache.hostinger.refresh')return true;
        if(!class_exists('MAD4B_Company_Hostinger_CDN_Operation_V1',false))return self::error('provider_missing',409);
        $site=$record['site'];$key=$site['company'].':'.$site['environment'];
        $routes=get_option(MAD4B_Company_Hostinger_CDN_Operation_V1::ROUTES,array());
        $registered=$routes['sites'][$key]??null;
        if(!is_array($registered)||empty($registered['ssh_verified'])||
           ($registered['canonical_origin']??'')!==$site['origin'])
            return self::error('external_ssh_readback_required',409);
        $target=(string)$registered['ssh_target'];
        if(!isset(MAD4B_Company_Hostinger_CDN_Operation_V1::HOST_ACCOUNTS[$target]))
            return self::error('unsupported_ssh_target',409);
        $proposals=get_option(MAD4B_Company_Hostinger_CDN_Operation_V1::PROPOSALS,array());
        if(!is_array($proposals))$proposals=array();
        $proposals[$key]=array(
            'company_id'=>$site['company'],'environment'=>$site['environment'],
            'origin'=>$site['origin'],'company_site_uuid'=>$site['site_uuid'],
            'ssh_target'=>$target,
            'api_account_alias'=>MAD4B_Company_Hostinger_CDN_Operation_V1::HOST_ACCOUNTS[$target],
            'api_website_domain_candidate'=>$values['api_website_domain_candidate'],
            'status'=>'PROPOSED_PENDING_EXTERNAL_SSH_AND_API_WEBSITE_VERIFICATION',
            'source'=>'adaptive_remote_intake','correlation_ref'=>$record['correlation_ref'],
            'proposed_utc'=>gmdate('c'));
        update_option(MAD4B_Company_Hostinger_CDN_Operation_V1::PROPOSALS,$proposals,false);
        return true;
    }
    public static function save($ref,$values,$descriptor_digest) {
        $record=self::read($ref);
        if(is_wp_error($record))return $record;
        if(($record['state']??'')!=='WAITING_INPUT')return self::error('already_resolved',409);
        $now=self::fields_for($record['operation']);
        if(is_wp_error($now)||self::digest($now)!==$record['schema_digest']||
            !is_string($descriptor_digest)||$descriptor_digest!==$record['schema_digest'])
            return self::error('descriptor_changed',409);
        $typed=self::validate_values($now,$values);
        if(is_wp_error($typed))return $typed;
        $saved=self::update_company_proposal($record,$typed);
        if(is_wp_error($saved))return $saved;
        $record['values']=$typed;$record['state']='INPUT_SAVED';
        $record['saved_at']=gmdate('c');
        $record['input_digest']=hash('sha256',wp_json_encode($typed,JSON_UNESCAPED_SLASHES));
        if(!set_transient(self::stored_key($ref),$record,max(1,$record['expires_at']-time())))
            return self::error('save_failed',503);
        return self::public_record($record);
    }
    public static function browser_create() {
        if(!self::permitted())wp_die('Administrator required','',array('response'=>403));
        check_admin_referer('mad4b_remote_intake_create_v1');
        $site=(string)($_POST['company_scope']??'');
        $parts=explode(':',$site,2);
        if(count($parts)!==2)wp_die('Invalid scope','',array('response'=>422));
        $result=self::create(array('company_id'=>$parts[0],'environment'=>$parts[1],
            'operation'=>'company.cache.hostinger.refresh'));
        if(is_wp_error($result))wp_die(esc_html($result->get_error_code()),'',array('response'=>422));
        wp_safe_redirect($result['form_url'],303);exit;
    }
    public static function browser() {
        if(!self::permitted())auth_redirect();
        $ref=isset($_REQUEST['ref'])?sanitize_text_field(wp_unslash((string)$_REQUEST['ref'])):'';
        $record=self::read($ref);
        if(is_wp_error($record))wp_die(esc_html($record->get_error_code()),'',array('response'=>403));
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        if($_SERVER['REQUEST_METHOD']==='POST') {
            check_admin_referer('mad4b_rai_submit_'.$ref);
            $keys=array_column($record['field_schema'],'name');
            $submitted=array();
            foreach($keys as $key)if(isset($_POST['field_'.$key]))$submitted[$key]=wp_unslash($_POST['field_'.$key]);
            $value=self::save($ref,$submitted,(string)($_POST['schema_digest']??''));
            if(is_wp_error($value))wp_die(esc_html($value->get_error_code()),'',array('response'=>422));
            $record=self::read($ref);
        }
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MAD4B Remote Intake</title></head><body style="max-width:650px;margin:40px auto;font:16px system-ui;padding:16px"><main>';
        echo '<h1>MAD4B · Adaptive Remote Intake</h1><p>Company: '.esc_html($record['site']['company']).' · '.esc_html($record['site']['environment']).'</p>';
        echo '<p>Operation: <code>'.esc_html($record['operation']).'</code></p>';
        if($record['state']==='INPUT_SAVED') {
            echo '<p><strong>Saved.</strong> Nonsecret values are available to the authenticated agent via the same checkpoint reference. Return to ChatGPT to recheck the original operation.</p>';
        } else {
            echo '<p>Enter only the requested nonsecret values. Passwords, access tokens and API keys must be connected through the original provider credential flow.</p>';
            echo '<form method="post" autocomplete="off" action="'.esc_url(self::origin_url($ref)).'">';
            wp_nonce_field('mad4b_rai_submit_'.$ref);
            echo '<input type="hidden" name="schema_digest" value="'.esc_attr($record['schema_digest']).'">';
            foreach($record['field_schema'] as $field) {
                $key=$field['name'];$name='field_'.$key;
                echo '<p><label>'.esc_html($field['label']).'<br>';
                if(($field['type']??'')==='boolean'){
                    echo '<select name="'.esc_attr($name).'" required><option value="1">Yes</option><option value="0">No</option></select>';
                }elseif(isset($field['enum'])){
                    echo '<select name="'.esc_attr($name).'" required>';
                    foreach($field['enum'] as $item)echo '<option value="'.esc_attr((string)$item).'">'.esc_html((string)$item).'</option>';
                    echo '</select>';
                }else{
                    $type=in_array($field['type'],array('integer','number'),true)?'number':'text';
                    echo '<input style="width:100%;max-width:500px" type="'.esc_attr($type).'" name="'.esc_attr($name).'" maxlength="'.esc_attr((string)($field['maxLength']??256)).'" required>';
                }
                echo '</label></p>';
            }
            echo '<button type="submit">Save required inputs</button></form>';
        }
        echo '<p><small>Expiry: '.esc_html(gmdate('Y-m-d H:i:s',$record['expires_at'])).' UTC. No automatic mutation or credential capture.</small></p></main></body></html>';exit;
    }
    public static function register_abilities() {
        if(!function_exists('wp_register_ability'))return;
        foreach(array('mad4b/remote-intake-request'=>'create',
                       'mad4b/remote-intake-status'=>'status') as $name=>$callback) {
            if(function_exists('wp_has_ability')&&wp_has_ability($name))continue;
            wp_register_ability($name,array(
                'label'=>'MAD4B Remote Intake '.ucfirst($callback),
                'description'=>'Create or inspect a bounded first-party dynamic nonsecret input form for a registered operation. Return its URL to the user.',
                'category'=>'mad4b-read','permission_callback'=>array(__CLASS__,'permitted'),
                'execute_callback'=>array(__CLASS__,$callback),
                'input_schema'=>array('type'=>'object','additionalProperties'=>false,
                    'properties'=>$callback==='create'?
                      array('company_id'=>array('type'=>'string'),'environment'=>array('type'=>'string'),
                            'operation'=>array('type'=>'string'),'correlation_ref'=>array('type'=>'string')):
                      array('checkpoint_ref'=>array('type'=>'string'))),
                'output_schema'=>array('type'=>'object','additionalProperties'=>true),
                'meta'=>array('show_in_rest'=>false,
                    'mcp'=>array('public'=>false,'surface'=>'read','type'=>'tool'),
                    'annotations'=>array('readonly'=>false,'destructive'=>false,'idempotent'=>false))
            ));
        }
    }
    public static function boot() {
        add_action('admin_post_'.self::ACTION,array(__CLASS__,'browser'));
        add_action('admin_post_'.self::CREATE_ACTION,array(__CLASS__,'browser_create'));
        add_action('wp_abilities_api_init',array(__CLASS__,'register_abilities'),55);
        add_action('rest_api_init',function(){
            register_rest_route('mad4b/v1','/remote-intake-request',array(
                'methods'=>'POST',
                'permission_callback'=>array(__CLASS__,'permitted'),
                'callback'=>function($req) {
                    $p=$req->get_json_params();$out=self::create($p);
                    return is_wp_error($out)?$out:new WP_REST_Response($out,201);
                }));
            register_rest_route('mad4b/v1','/remote-intake-status/(?P<ref>rai\.[a-f0-9]{48})',array(
                'methods'=>'GET',
                'permission_callback'=>array(__CLASS__,'permitted'),
                'callback'=>function($req){
                    $out=self::status(array('checkpoint_ref'=>$req['ref']));
                    return is_wp_error($out)?$out:new WP_REST_Response($out,200);
                }));
        });
    }
}
MAD4B_Central_Remote_Adaptive_Intake_V1::boot();
}
