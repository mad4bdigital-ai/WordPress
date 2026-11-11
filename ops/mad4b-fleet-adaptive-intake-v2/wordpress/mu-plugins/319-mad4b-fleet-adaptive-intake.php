<?php
/**
 * Plugin Name: MAD4B Fleet Adaptive Intake
 * Description: First-party capability-schema-driven remote forms for MAD4B Central and enrolled company sites.
 * Version: 2.2.2
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Fleet_Adaptive_Intake_V2', false)) {
final class MAD4B_Fleet_Adaptive_Intake_V2 {
    const CONTRACT='mad4b.fleet-adaptive-intake.v2';
    const OPTION='mad4b_fleet_adaptive_intake_site_v2';
    const PREFIX='mad4b_fleet_intake_v2_';
    const TTL=86400;
    const MAX_FIELDS=24;
    const MAX_JSON_BYTES=32768;
    const NAME='mad4b_fleet_intake';
    const REQUIRED_MAX=12;
    private static function error($code,$http=422) {
        return new WP_Error('mad4b_fleet_intake_'.$code,
            'The registered capability or its first-party input scope needs verification.',
            array('status'=>$http,'next_ability'=>'mad4b/fleet-intake-plan',
                'secret_values_in_chat'=>false,'retry_original_write'=>false));
    }
    public static function can() {
        return current_user_can('manage_options') &&
            (self::binding()!==null);
    }
    private static function binding() {
        $home=untrailingslashit(home_url('/'));
        if(!is_ssl() && !(defined('WP_CLI')&&WP_CLI))return null;
        if($home==='https://tours.dev.mad4b.com' &&
           class_exists('MAD4B_Company_Environment_Registry_V2',false)) {
            return array('kind'=>'central','company_id'=>'mad4b-central',
              'environment'=>'staging','origin'=>$home,'site_uuid'=>'central');
        }
        $b=get_option(self::OPTION,array());
        if(!is_array($b) || ($b['origin']??'')!==$home ||
           !in_array($b['environment']??'',array('staging','production'),true) ||
           !is_string($b['company_id']??null) ||
           !preg_match('/^[a-z][a-z0-9-]{2,63}$/D',$b['company_id']) ||
           !is_string($b['site_uuid']??null) || strlen($b['site_uuid'])>100)
            return null;
        return array('kind'=>'company','company_id'=>$b['company_id'],
           'environment'=>$b['environment'],'origin'=>$home,'site_uuid'=>$b['site_uuid']);
    }
    private static function secret($key) {
        return (bool)preg_match('/secret|password|passwd|credential|token|api.?key|private.?key|auth(orization)?|nonce|otp|approval|ticket|signature|bearer|session.?cookie|seed.?phrase/i',(string)$key);
    }
    private static function dangerous_schema($schema,$depth=0) {
        if($depth>4 || !is_array($schema))return true;
        foreach(array('properties','patternProperties') as $group) {
            if(!isset($schema[$group]))continue;
            if(!is_array($schema[$group]))return true;
            foreach($schema[$group] as $key=>$sub) {
                if(self::secret($key) || self::dangerous_schema($sub,$depth+1))return true;
            }
        }
        if(isset($schema['items'])&&self::dangerous_schema($schema['items'],$depth+1))return true;
        foreach(array('oneOf','anyOf','allOf') as $choice) {
            if(isset($schema[$choice])) {
                if(!is_array($schema[$choice]))return true;
                foreach($schema[$choice] as $sub)if(self::dangerous_schema($sub,$depth+1))return true;
            }
        }
        $format=(string)($schema['format']??'');
        return in_array($format,array('password','binary','base64','file','secret','credential'),true);
    }
    private static function registry() {
        if(!function_exists('wp_get_ability'))return null;
        return true;
    }
    private static function descriptor($ability_name) {
        if(!is_string($ability_name) || !preg_match('~^[a-z0-9._-]{1,96}/[a-z0-9._-]{1,96}$~D',$ability_name))
            return self::error('ability_name_invalid');
        if(strpos($ability_name,'mad4b/fleet-intake-')===0)
            return self::error('recursive_intake_unsupported');
        if(!self::registry())return self::error('ability_registry_unavailable',503);
        $ability=wp_get_ability($ability_name);
        if(!$ability || !method_exists($ability,'get_input_schema'))
            return self::error('ability_not_registered',404);
        // Abilities API may normalize a JSON object as stdClass. Convert
        // registered schemas to a stable array representation before inspection.
        $schema=json_decode(wp_json_encode($ability->get_input_schema()),true,24);
        if(!is_array($schema) || ($schema['type']??null)!=='object' ||
           !is_array($schema['properties']??null))
            return self::error('ability_schema_missing');
        $required=(array)($schema['required']??array());
        if(count($required)>self::REQUIRED_MAX || count($schema['properties'])>self::MAX_FIELDS)
            return self::error('schema_size_unsupported');
        $items=array();
        foreach($schema['properties'] as $name=>$rule) {
            if(!is_string($name)||!is_array($rule))return self::error('schema_field_rejected');
            // Optional framework-injected approval fields are never collected
            // by a chat form; native write approval remains in the executor.
            if(self::secret($name)) {
                if(in_array($name,$required,true))return self::error('secure_native_handoff_required',409);
                continue;
            }
            if(!preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$name))
                return self::error('schema_field_rejected');
            if(self::dangerous_schema($rule))return self::error('secure_native_handoff_required',409);
            $type=$rule['type']??null;
            if(!in_array($type,array('string','integer','number','boolean','object','array'),true))
                return self::error('schema_type_requires_specialized_adapter',409);
            $item=array('key'=>$name,'label'=>sanitize_text_field((string)($rule['title']??ucwords(str_replace('_',' ',$name)))),
               'type'=>$type,'required'=>in_array($name,$required,true),
               'description'=>sanitize_text_field((string)($rule['description']??'')),
               'format'=>sanitize_key((string)($rule['format']??'')),
               'schema'=>$rule);
            if(in_array($type,array('object','array'),true))$item['control']='json';
            elseif(!empty($rule['enum'])&&is_array($rule['enum']))$item['control']='select';
            elseif($type==='boolean')$item['control']='boolean';
            elseif(in_array($type,array('integer','number'),true))$item['control']='number';
            elseif(in_array($item['format'],array('date','date-time','time','email','uri'),true))$item['control']=$item['format'];
            else $item['control']='text';
            $items[]=$item;
        }
        if(!$items)return self::error('no_schema_fields',409);
        $safe=array('ability'=>$ability_name,'fields'=>$items,'schema'=>$schema);
        $safe['fingerprint']=hash('sha256',wp_json_encode($schema,JSON_UNESCAPED_SLASHES));
        return $safe;
    }
    private static function key($ref){return self::PREFIX.hash('sha256',$ref);}
    /**
     * Every site derives a distinct encryption key from its own server-side
     * WordPress keys and its enrolled company-site identity. Database/Redis
     * stores ciphertext only; API credentials themselves are not accepted.
     */
    private static function crypto_key() {
        if(!function_exists('openssl_encrypt')||!function_exists('hash_hkdf')||
           !defined('AUTH_KEY')||!defined('SECURE_AUTH_KEY') ||
           strlen((string)AUTH_KEY)<32||strlen((string)SECURE_AUTH_KEY)<32)
            return self::error('site_encryption_key_unavailable',503);
        $b=self::binding();
        if(!$b)return self::error('site_scope_unbound',403);
        return hash_hkdf('sha256',(string)AUTH_KEY.'|'.(string)SECURE_AUTH_KEY,32,
            'mad4b.fleet.intake.v2|'.(string)$b['site_uuid'],(string)$b['origin']);
    }
    private static function seal($r) {
        $key=self::crypto_key();
        if(is_wp_error($key))return $key;
        $body=wp_json_encode($r,JSON_UNESCAPED_SLASHES);
        if(!is_string($body)||strlen($body)>self::MAX_JSON_BYTES*3)
            return self::error('sealed_record_too_large');
        try{$nonce=random_bytes(12);}catch(Throwable $e){return self::error('encryption_random_unavailable',503);}
        $tag='';
        $aad=self::CONTRACT.'|'.$r['ref'].'|'.$r['scope']['origin'].'|'.$r['scope']['site_uuid'];
        $cipher=openssl_encrypt($body,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,$aad,16);
        if(!is_string($cipher)||strlen($tag)!==16)return self::error('encryption_failed',503);
        return array('contract'=>self::CONTRACT.'.sealed.v2',
            'expires_at'=>$r['expires_at'],'nonce'=>base64_encode($nonce),
            'tag'=>base64_encode($tag),'ciphertext'=>base64_encode($cipher));
    }
    private static function unseal($sealed,$ref) {
        if(!is_array($sealed)||($sealed['contract']??'')!==self::CONTRACT.'.sealed.v2')
            return self::error('invalid_sealed_checkpoint',409);
        $key=self::crypto_key();
        if(is_wp_error($key))return $key;
        $iv=base64_decode((string)($sealed['nonce']??''),true);
        $tag=base64_decode((string)($sealed['tag']??''),true);
        $cipher=base64_decode((string)($sealed['ciphertext']??''),true);
        if(!is_string($iv)||strlen($iv)!==12||!is_string($tag)||strlen($tag)!==16||
           !is_string($cipher)||strlen($cipher)>self::MAX_JSON_BYTES*3)
            return self::error('encrypted_checkpoint_corrupted',409);
        $b=self::binding();
        $aad=self::CONTRACT.'|'.$ref.'|'.$b['origin'].'|'.$b['site_uuid'];
        $plaintext=openssl_decrypt($cipher,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,$aad);
        if(!is_string($plaintext))return self::error('checkpoint_decryption_failed',409);
        $record=json_decode($plaintext,true,20);
        if(!is_array($record)||($record['ref']??'')!==$ref ||
           ($record['contract']??'')!==self::CONTRACT)
            return self::error('checkpoint_payload_invalid',409);
        return $record;
    }
    private static function first_persist($r) {
        $sealed=self::seal($r);
        if(is_wp_error($sealed))return $sealed;
        if(!add_option(self::key($r['ref']),$sealed,'',false))
            return self::error('checkpoint_write_collision',503);
        return true;
    }
    private static function advance_persist($r) {
        global $wpdb;
        $name=self::key($r['ref']);
        $previous=get_option($name,false);
        if(false===$previous) {
            // Compatibility for outstanding links created before v2.1.
            $legacy=get_transient($name);
            if(!is_array($legacy)||($legacy['ref']??'')!==$r['ref'] ||
               ($legacy['state']??'')!=='WAITING_USER_INPUT')
                return self::error('checkpoint_origin_missing',409);
            $result=self::first_persist($r);
            if(!is_wp_error($result))delete_transient($name);
            return $result;
        }
        $old=self::unseal($previous,$r['ref']);
        if(is_wp_error($old)||$old['state']!=='WAITING_USER_INPUT' ||
           $old['descriptor_sha256']!==$r['descriptor_sha256'])
            return self::error('concurrent_checkpoint_transition',409);
        $sealed=self::seal($r);
        if(is_wp_error($sealed))return $sealed;
        if(!is_object($wpdb)||empty($wpdb->options))
            return self::error('checkpoint_sql_store_unavailable',503);
        $changed=$wpdb->query($wpdb->prepare(
           "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s",
            maybe_serialize($sealed),$name,maybe_serialize($previous)));
        if(1!==$changed)return self::error('concurrent_checkpoint_transition',409);
        wp_cache_delete($name,'options');
        return true;
    }
    public static function cleanup() {
        global $wpdb;
        if(!is_object($wpdb)||empty($wpdb->options))return;
        $like=$wpdb->esc_like(self::PREFIX).'%';
        $names=$wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT 200",
            $like));
        foreach((array)$names as $name) {
            if(!is_string($name)||!str_starts_with($name,self::PREFIX))continue;
            $item=get_option($name,null);
            if(is_array($item)&&isset($item['expires_at'])&&
               (int)$item['expires_at']<time())delete_option($name);
        }
    }
    private static function sensitive_content($v,$depth=0) {
        if($depth>8)return true;
        if(is_array($v)) {
            if(count($v)>128)return true;
            foreach($v as $key=>$value) {
                if(is_string($key)&&self::secret($key))return true;
                if(self::sensitive_content($value,$depth+1))return true;
            }
            return false;
        }
        if(is_string($v)) {
            return strlen($v)>self::MAX_JSON_BYTES ||
                (bool)preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----|Bearer\s+[A-Za-z0-9._~-]{12,}|sk-[A-Za-z0-9_-]{12,}|ghp_[A-Za-z0-9]{20,}|xox[baprs]-[A-Za-z0-9-]{12,}/i',$v);
        }
        return !(is_null($v)||is_int($v)||is_bool($v)||
            (is_float($v)&&is_finite($v)));
    }
    private static function ref_valid($v){return is_string($v)&&preg_match('/^fai\.[a-f0-9]{48}$/D',$v);}
    private static function link($ref){
        return add_query_arg(array('action'=>'mad4b_fleet_intake_form','ref'=>$ref),admin_url('admin-post.php'));
    }
    private static function strip_runtime_context($input) {
        if(!is_array($input))return $input;
        // The site gateway may attach governance metadata to Ability calls.
        // This metadata is not user input and NEVER conveys consent to
        // execute the original WordPress operation.
        foreach(array('_mad4b_approval_ticket_id','_mad4b_context_receipt') as $key)
            unset($input[$key]);
        return $input;
    }
    public static function plan($args=array()) {
        $args=self::strip_runtime_context($args);
        if(!self::can())return self::error('authorized_admin_required',403);
        if(!is_array($args) || array_diff(array_keys($args),array('ability_name','correlation_ref','scope')))return self::error('arguments_invalid');
        $name=$args['ability_name']??null;
        $descriptor=self::descriptor($name);
        if(is_wp_error($descriptor))return $descriptor;
        $correlation=(string)($args['correlation_ref']??'');
        if(strlen($correlation)>120||($correlation!==''&&!preg_match('/^[A-Za-z0-9._:-]{1,120}$/D',$correlation)))
            return self::error('correlation_invalid');
        $scope=self::binding();
        if(!empty($args['scope']) && $args['scope']!==$scope['kind'])return self::error('scope_mismatch',403);
        try{$ref='fai.'.bin2hex(random_bytes(24));}catch(Throwable $e){return self::error('entropy_unavailable',503);}
        $owner=get_current_user_id();
        if(!is_int($owner)||$owner<1)return self::error('authenticated_actor_required',403);
        $record=array('contract'=>self::CONTRACT,'ref'=>$ref,'scope'=>$scope,
           'actor_id'=>$owner,
           'ability'=>$name,'descriptor_sha256'=>$descriptor['fingerprint'],
           'schema'=>$descriptor['schema'],'fields'=>$descriptor['fields'],
           'correlation_ref'=>$correlation,'state'=>'WAITING_USER_INPUT',
           'created_at'=>time(),'expires_at'=>time()+self::TTL,
           'values'=>array(),'input_digest'=>null);
        $written=self::first_persist($record);
        if(is_wp_error($written))return $written;
        if(!wp_next_scheduled('mad4b_fleet_intake_cleanup_v2'))
            wp_schedule_event(time()+3600,'twicedaily','mad4b_fleet_intake_cleanup_v2');
        return self::summary($record);
    }
    /** Agent-facing dynamic resolver. Does not execute the chosen Ability. */
    public static function auto($args=array()) {
        $args=self::strip_runtime_context($args);
        if(!self::can())return self::error('authorized_admin_required',403);
        if(!is_array($args)||array_diff(array_keys($args),
           array('ability_name','task','correlation_ref')))
            return self::error('auto_arguments_invalid');
        $name=trim((string)($args['ability_name']??''));
        $task=trim((string)($args['task']??''));
        $corr=(string)($args['correlation_ref']??'');
        if(($name==='')===($task==='')||strlen($task)>512)
            return self::error('auto_target_must_be_exactly_one');
        $route=null;
        if($name==='') {
            if(!class_exists('MAD4B_SCP_Adaptive_Operation_Router',false))
                return array('contract'=>self::CONTRACT.'.auto.v1',
                    'state'=>'WAITING_CAPABILITY_DISCOVERY',
                    'reason'=>'NATIVE_ROUTER_NOT_PRESENT_ON_THIS_SITE',
                    'checkpoint_created'=>false,'operation_executed'=>false);
            $route=MAD4B_SCP_Adaptive_Operation_Router::plan(array(
               'task'=>$task,'intent'=>'auto','phase'=>'plan'));
            if(is_wp_error($route)||!is_array($route)||
               ($route['state']??'')!=='ROUTE_SELECTED_REQUIRES_EXECUTION_REVALIDATION' ||
               !is_string($route['selected']['ability_name']??null))
                return array('contract'=>self::CONTRACT.'.auto.v1',
                    'state'=>'WAITING_CAPABILITY_DISCOVERY',
                    'reason'=>is_wp_error($route)?$route->get_error_code():
                       (string)($route['state']??'NATIVE_ROUTE_UNAVAILABLE'),
                    'checkpoint_created'=>false,'operation_executed'=>false);
            $name=$route['selected']['ability_name'];
        }
        $plan=self::plan(array('ability_name'=>$name,
            'correlation_ref'=>$corr,'scope'=>self::binding()['kind']));
        if(is_wp_error($plan)) {
            return array('contract'=>self::CONTRACT.'.auto.v1',
                'state'=>$plan->get_error_code()==='mad4b_fleet_intake_secure_native_handoff_required'
                     ?'WAITING_SECURE_PROVIDER_HANDOFF':'WAITING_CAPABILITY_SCHEMA',
                'reason'=>$plan->get_error_code(),
                'operation'=>$name,'checkpoint_created'=>false,
                'operation_executed'=>false);
        }
        $plan['agent_auto_resolved']=true;
        $plan['operation_executed']=false;
        $plan['next_ability']='mad4b/fleet-intake-save';
        return $plan;
    }
    private static function record($ref) {
        if(!self::can() || !self::ref_valid($ref))return self::error('not_authorized',403);
        $packet=get_option(self::key($ref),false);
        if(false!==$packet) {
            $r=self::unseal($packet,$ref);
            if(is_wp_error($r))return $r;
        }else {
            // Old 24-hour legacy transients can finish in the new runtime.
            $r=get_transient(self::key($ref));
        }
        if(!is_array($r)||($r['contract']??'')!==self::CONTRACT)
            return self::error('checkpoint_expired',404);
        if(($r['expires_at']??0)<time()) {
            delete_option(self::key($ref));delete_transient(self::key($ref));
            return self::error('checkpoint_expired',404);
        }
        if($r['scope']!==self::binding())return self::error('site_scope_changed',409);
        $actor=get_current_user_id();
        if(!is_int($actor)||$actor<1||!isset($r['actor_id'])||
           (int)$r['actor_id']!==$actor)
            return self::error('checkpoint_actor_mismatch',403);
        return $r;
    }
    private static function summary($r) {
        return array('contract'=>self::CONTRACT,'checkpoint_ref'=>$r['ref'],
           'source'=>$r['scope']['kind'],'site'=>$r['scope'],
           'ability_name'=>$r['ability'],'state'=>$r['state'],
           'fields'=>array_map(function($f){unset($f['schema']);return $f;},$r['fields']),
           'form_url'=>self::link($r['ref']),'correlation_ref'=>$r['correlation_ref'],
           'expires_at'=>gmdate('c',$r['expires_at']),
           'input_digest'=>$r['input_digest'],'values_saved'=>$r['state']==='INPUT_SAVED',
           'descriptor_sha256'=>$r['descriptor_sha256'],
           'browser_form_optional'=>true,'authenticated_agent_save_supported'=>true,
           'encrypted_at_rest'=>true,'auto_execute'=>false,
           'next_ability'=>$r['state']==='INPUT_SAVED'
              ?'mad4b/fleet-intake-status':'mad4b/fleet-intake-save',
           'resume_mode'=>'agent_readback_then_authorized_operation',
           'secret_handoff'=>'first_party_native_provider_only');
    }
    public static function status($args=array()) {
        $args=self::strip_runtime_context($args);
        if(!is_array($args) || !isset($args['checkpoint_ref']))return self::error('checkpoint_required');
        $r=self::record($args['checkpoint_ref']);
        if(is_wp_error($r))return $r;
        $out=self::summary($r);
        // Nonsecret inputs become available for an authenticated Agent to resume.
        if($r['state']==='INPUT_SAVED')$out['saved_values']=$r['values'];
        return $out;
    }
    private static function convert($raw,$rule) {
        $type=$rule['type']??null;
        if($raw===null)return null;
        if($type==='boolean') {
            if(!in_array($raw,array('0','1',0,1,true,false,'true','false'),true))return null;
            return in_array($raw,array('1',1,true,'true'),true);
        }
        if($type==='integer') {
            $val=filter_var($raw,FILTER_VALIDATE_INT);
            return $val===false?null:$val;
        }
        if($type==='number')return is_numeric($raw)?(float)$raw:null;
        if($type==='array'||$type==='object') {
            if(!is_string($raw)||strlen($raw)>self::MAX_JSON_BYTES)return null;
            $value=json_decode($raw,true,20,JSON_BIGINT_AS_STRING);
            if(json_last_error()!==JSON_ERROR_NONE)return null;
            if($type==='object'&&!is_array($value))return null;
            if($type==='array' && (!is_array($value)||!array_is_list($value)))return null;
            return $value;
        }
        if(!is_scalar($raw))return null;
        $s=trim((string)$raw);
        if(strlen($s)>min(4096,(int)($rule['maxLength']??2048)))return null;
        return sanitize_textarea_field($s);
    }
    public static function validate_values($record,$input) {
        if(!is_array($input)||count($input)>self::MAX_FIELDS)return self::error('values_invalid');
        $fields=$record['fields'];$schema=$record['schema'];
        $accepted=array();
        $names=array_column($fields,'key');
        if(array_diff(array_keys($input),$names))return self::error('extra_input_not_registered');
        foreach($fields as $field) {
            $name=$field['key'];
            if(!array_key_exists($name,$input)) {
                if($field['required'])return self::error('missing_required_value');
                continue;
            }
            if($input[$name]==='' && !$field['required'])continue;
            $value=self::convert($input[$name],$field['schema']);
            if($value===null&&$input[$name]!==null)return self::error('type_invalid');
            if($value===null)return self::error('null_not_supported');
            $accepted[$name]=$value;
        }
        if(strlen(wp_json_encode($accepted))>self::MAX_JSON_BYTES)return self::error('payload_too_large');
        if(self::sensitive_content($accepted))return self::error('secure_native_handoff_required',409);
        if(function_exists('rest_validate_value_from_schema')) {
            $check=rest_validate_value_from_schema($accepted,$schema,'intake');
            if(is_wp_error($check))return self::error('schema_validation_failed');
        }
        return $accepted;
    }
    public static function save($args=array()) {
        $args=self::strip_runtime_context($args);
        if(!is_array($args))return self::error('invalid_save_request');
        if(array_diff(array_keys($args),array('checkpoint_ref','values','descriptor_sha256')))
            return self::error('invalid_save_request');
        $r=self::record($args['checkpoint_ref']??null);
        if(is_wp_error($r))return $r;
        if($r['state']!=='WAITING_USER_INPUT')return self::error('checkpoint_already_completed',409);
        $fresh=self::descriptor($r['ability']);
        if(is_wp_error($fresh)||!hash_equals($r['descriptor_sha256'],$fresh['fingerprint']) ||
           !hash_equals($r['descriptor_sha256'],(string)($args['descriptor_sha256']??'')))
            return self::error('schema_changed',409);
        $values=self::validate_values($r,$args['values']??null);
        if(is_wp_error($values))return $values;
        $r['values']=$values;$r['state']='INPUT_SAVED';
        $r['input_digest']=hash('sha256',wp_json_encode($values,JSON_UNESCAPED_SLASHES));
        $r['saved_at']=gmdate('c');
        $written=self::advance_persist($r);
        if(is_wp_error($written))return $written;
        do_action('mad4b_fleet_intake_values_saved',$r['ref'],$r['ability'],$r['scope'],$r['correlation_ref'],$r['input_digest']);
        return self::summary($r);
    }
    public static function browser() {
        if(!self::can())auth_redirect();
        $ref=isset($_REQUEST['ref'])?sanitize_text_field(wp_unslash((string)$_REQUEST['ref'])):'';
        $r=self::record($ref);
        if(is_wp_error($r))wp_die(esc_html($r->get_error_code()),'',array('response'=>403));
        nocache_headers();header('Cache-Control: private, no-store, max-age=0');
        header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; form-action 'self'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");
        $failure='';
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
            check_admin_referer('mad4b_fai_'.$ref);
            $inputs=array();
            foreach($r['fields'] as $field) {
                $key=$field['key'];
                if(isset($_POST['field_'.$key]))$inputs[$key]=wp_unslash($_POST['field_'.$key]);
            }
            $res=self::save(array('checkpoint_ref'=>$ref,'values'=>$inputs,
                'descriptor_sha256'=>isset($_POST['descriptor_sha256'])?(string)$_POST['descriptor_sha256']:''));
            if(is_wp_error($res))$failure=$res->get_error_code();
            else $r=self::record($ref);
        }
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MAD4B Adaptive Intake</title><body style="font:16px system-ui;max-width:720px;margin:30px auto;padding:18px"><main>';
        echo '<h1>MAD4B Adaptive Intake</h1><p><strong>Site:</strong> '.esc_html($r['scope']['origin']).'</p>';
        echo '<p><strong>Capability:</strong> '.esc_html($r['ability']).'</p>';
        if($r['state']==='INPUT_SAVED')echo '<p>Input saved and available to the authenticated agent. Return to the agent to resume the original operation.</p>';
        else {
            if($failure)echo '<p style="color:#a00">'.esc_html($failure).'</p>';
            echo '<form method="post" action="'.esc_url(self::link($ref)).'" autocomplete="off">';
            wp_nonce_field('mad4b_fai_'.$ref);
            echo '<input type="hidden" name="descriptor_sha256" value="'.esc_attr($r['descriptor_sha256']).'">';
            foreach($r['fields'] as $field) {
                $key=$field['key'];$name='field_'.$key;$control=$field['control'];
                echo '<p><label><strong>'.esc_html($field['label']).($field['required']?' *':'').'</strong><br>';
                if($field['description']!=='')echo '<small>'.esc_html($field['description']).'</small><br>';
                $required=$field['required']?' required':'';
                if($control==='select'){
                    echo '<select name="'.esc_attr($name).'"'.$required.'>';
                    if(!$field['required'])echo '<option value="">Not specified</option>';
                    foreach($field['schema']['enum'] as $option)
                       echo '<option value="'.esc_attr((string)$option).'">'.esc_html((string)$option).'</option>';
                    echo '</select>';
                }elseif($control==='boolean') {
                    echo '<select name="'.esc_attr($name).'"'.$required.'><option value="">Not specified</option><option value="1">Yes</option><option value="0">No</option></select>';
                }elseif($control==='json'){
                    echo '<textarea name="'.esc_attr($name).'" rows="5" style="width:100%" placeholder="Enter valid JSON for '.esc_attr($field['type']).'"'.$required.'></textarea>';
                }else {
                    $htmltype='text';
                    if($control==='number')$htmltype='number';
                    if($control==='date')$htmltype='date';
                    if($control==='date-time')$htmltype='datetime-local';
                    if($control==='time')$htmltype='time';
                    if($control==='email')$htmltype='email';
                    if($control==='uri')$htmltype='url';
                    echo '<input name="'.esc_attr($name).'" type="'.esc_attr($htmltype).'" style="width:100%"'.($field['type']==='number'?' step="any"':'').$required.'>';
                }
                echo '</label></p>';
            }
            echo '<button type="submit">Save inputs for the agent</button></form>';
        }
        echo '<p><small>Expires '.esc_html(gmdate('Y-m-d H:i',$r['expires_at'])).' UTC; no credentials accepted; no original write executed.</small></p></main></body></html>';
        exit;
    }
    public static function menu(){
        if(!self::can())return;
        add_management_page('MAD4B Adaptive Intake','MAD4B Adaptive Intake','manage_options','mad4b-fleet-intake',function(){
            echo '<div class="wrap"><h1>MAD4B Fleet Adaptive Intake</h1><p>Capabilities drive field schemas dynamically. Agents can invoke mad4b/fleet-intake-plan, then submit the returned first-party link to an administrator. This form does not execute original operations.</p>';
            echo '<p>Site: '.esc_html(self::binding()['origin']).'</p></div>';
        });
    }
    public static function abilities(){
        if(!function_exists('wp_register_ability'))return;
        foreach(array(
            'mad4b/fleet-intake-auto'=>'auto',
            'mad4b/fleet-intake-plan'=>'plan',
            'mad4b/fleet-intake-status'=>'status',
            'mad4b/fleet-intake-save'=>'save'
        ) as $name=>$method) {
            if(function_exists('wp_has_ability')&&wp_has_ability($name))continue;
            if($method==='auto') {
                $props=array('ability_name'=>array('type'=>'string','maxLength'=>191),
                    'task'=>array('type'=>'string','maxLength'=>512),
                    'correlation_ref'=>array('type'=>'string','maxLength'=>120));
                $required=array();
            }elseif($method==='plan') {
                $props=array('ability_name'=>array('type'=>'string','maxLength'=>191),
                    'correlation_ref'=>array('type'=>'string','maxLength'=>120),
                    'scope'=>array('type'=>'string','enum'=>array('central','company')));
                $required=array('ability_name');
            }elseif($method==='save') {
                $props=array('checkpoint_ref'=>array('type'=>'string','maxLength'=>52),
                    'descriptor_sha256'=>array('type'=>'string','pattern'=>'^[a-f0-9]{64}$'),
                    'values'=>array('type'=>'object','maxProperties'=>self::MAX_FIELDS,'additionalProperties'=>true));
                $required=array('checkpoint_ref','descriptor_sha256','values');
            }else {
                $props=array('checkpoint_ref'=>array('type'=>'string','maxLength'=>52));
                $required=array('checkpoint_ref');
            }
            wp_register_ability($name,array(
                'label'=>'MAD4B Fleet Intake '.ucfirst($method),
                'description'=>'Capability-schema-driven first-party form and checkpoint for remote agents; no original operation executed.',
                'category'=>'mad4b-fleet-intake',
                'permission_callback'=>array(__CLASS__,'can'),
                'execute_callback'=>array(__CLASS__,$method),
                'input_schema'=>array('type'=>'object','properties'=>$props,
                    'required'=>$required,
                    'additionalProperties'=>false),
                'output_schema'=>array('type'=>'object','additionalProperties'=>true),
                'meta'=>array('show_in_rest'=>false,'public'=>false,
                   'mcp'=>array('public'=>false,'surface'=>$method==='status'?'read':'write','type'=>'tool'),
                   'annotations'=>array('readonly'=>$method==='status',
                       'destructive'=>false,'idempotent'=>$method==='status'))
            ));
        }
    }
    public static function rest(){
        register_rest_route('mad4b/v1','/fleet-intake/auto',array(
            'methods'=>'POST','permission_callback'=>array(__CLASS__,'can'),
            'callback'=>function($request){
               $o=self::auto($request->get_json_params());
               return is_wp_error($o)?$o:new WP_REST_Response($o,200);
            }));
        register_rest_route('mad4b/v1','/fleet-intake/plan',array(
            'methods'=>'POST','permission_callback'=>array(__CLASS__,'can'),
            'callback'=>function($request){
               $o=self::plan($request->get_json_params());
               return is_wp_error($o)?$o:new WP_REST_Response($o,201);
            }));
        register_rest_route('mad4b/v1','/fleet-intake/save',array(
            'methods'=>'POST','permission_callback'=>array(__CLASS__,'can'),
            'callback'=>function($request){
               $o=self::save($request->get_json_params());
               return is_wp_error($o)?$o:new WP_REST_Response($o,200);
            }));
        register_rest_route('mad4b/v1','/fleet-intake/status/(?P<ref>fai\.[a-f0-9]{48})',array(
            'methods'=>'GET','permission_callback'=>array(__CLASS__,'can'),
            'callback'=>function($request){
               $o=self::status(array('checkpoint_ref'=>$request['ref']));
               return is_wp_error($o)?$o:new WP_REST_Response($o,200);
            }));
    }
    public static function boot() {
        add_action('wp_abilities_api_categories_init',function(){
            if(function_exists('wp_register_ability_category'))
               wp_register_ability_category('mad4b-fleet-intake',array(
                 'label'=>'MAD4B Fleet Intake','description'=>'Capability-scoped first-party adaptive forms'));
        });
        add_action('mad4b_fleet_intake_cleanup_v2',array(__CLASS__,'cleanup'));
        add_action('admin_post_mad4b_fleet_intake_form',array(__CLASS__,'browser'));
        add_action('admin_menu',array(__CLASS__,'menu'),88);
        add_action('wp_abilities_api_init',array(__CLASS__,'abilities'),52);
        add_action('rest_api_init',array(__CLASS__,'rest'));
        add_filter('mad4b_scp_dynamic_provider_manifests',function($list){
            if(!is_array($list))$list=array();
            $list[]=array('provider_id'=>'fleet_adaptive_intake','implementation_version'=>'2.2.2',
                'supports'=>array('capability_schema_form','remote_checkpoint','agent_status_readback',
                    'native_secure_handoff_required_for_credentials'),
                'side_effects'=>array('first_party_transient_checkpoint'),
                'soft_elapsed_budget_ms'=>25000,
                'enforceable_deadline_supported'=>false);
            return $list;
        },45);
    }
}
MAD4B_Fleet_Adaptive_Intake_V2::boot();
}
