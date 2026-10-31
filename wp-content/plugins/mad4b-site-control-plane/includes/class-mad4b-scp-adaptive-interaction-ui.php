<?php
if ( ! defined('ABSPATH') ) exit;
/** First-party WordPress broker companion. No credential plaintext ever enters MCP. */
final class MAD4B_SCP_Adaptive_Interaction_UI {
    const ACTION='mad4b_aib_intervention';
    private static $booted=false;
    public static function boot(){
        if(self::$booted)return;self::$booted=true;
        add_action('admin_post_'.self::ACTION,array(__CLASS__,'page'));
    }
    public static function url($ref){
        if(!is_string($ref)||!preg_match('/^aib\.[a-f0-9]{48}$/D',$ref))return '';
        // This is a navigation URL, not an authorization or secret-session URL.
        return add_query_arg(array('action'=>self::ACTION,'checkpoint_ref'=>$ref),admin_url('admin-post.php'));
    }
    private static function valid_browser(){
        return function_exists('is_user_logged_in') && is_user_logged_in()
            &&function_exists('wp_get_session_token')&&strlen((string)wp_get_session_token())>=20
            &&empty($_SERVER['HTTP_AUTHORIZATION'])&&empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])
            &&MAD4B_SCP_Adaptive_Interaction_Broker::permitted();
    }
    private static function allowed_channel(){
        if(function_exists('is_ssl')&&is_ssl()&&wp_parse_url(home_url(),PHP_URL_SCHEME)==='https')return true;
        return class_exists('MAD4B_SCP_Local_Transport_Policy',false)
            &&MAD4B_SCP_Local_Transport_Policy::request_allowed(rtrim(home_url(),'/'),$_SERVER,($_SERVER['REQUEST_METHOD']??'')==='POST');
    }
    private static function headers(){
        nocache_headers();
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, private, max-age=0');
    }
    private static function start_page($title){
        self::headers();
        echo '<!doctype html><html lang="en" dir="auto"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.esc_html($title).'</title></head><body><main><h1>'.esc_html($title).'</h1>';
    }
    private static function end_page(){echo '</main></body></html>';}
    private static function form_field($field){
        $key=$field['key']??'';
        if(!is_string($key)||!preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]{0,63}$/D',$key))return;
        $type=$field['type']??'';
        if(!in_array($type,array('string','integer','number','boolean'),true))return;
        $control=$field['control']??'text';
        $label=$field['label']??$key;
        if(!is_string($label)||strlen($label)>140)$label=$key;
        echo '<p><label>'.esc_html($label).' ';
        $name='values['.esc_attr($key).']';
        if($type==='boolean')echo '<select name="'.$name.'"><option value="0">No</option><option value="1">Yes</option></select>';
        elseif($control==='select'&&is_array($field['enum']??null)){
            echo '<select name="'.$name.'">';foreach(array_slice($field['enum'],0,64) as $value){
                if(!is_string($value)&&!is_int($value)&&!is_float($value))continue;
                echo '<option value="'.esc_attr((string)$value).'">'.esc_html((string)$value).'</option>';
            }echo '</select>';
        }else{
            $htmltype=in_array($type,array('integer','number'),true)?'number':'text';
            $step=$type==='number'?'any':'1';
            $max=(int)($field['max_length']??256);
            echo '<input name="'.$name.'" type="'.$htmltype.'" step="'.$step.'" maxlength="'.max(1,min(1024,$max)).'"'.(!empty($field['required'])?' required':'').' autocomplete="off">';
        }
        echo '</label></p>';
    }
    private static function typed_values($form,$raw){
        if(!is_array($form)||!is_array($form['fields']??null)||!is_array($raw)||count($raw)>24)return null;
        $map=[];foreach($form['fields'] as $field)if(is_array($field)&&isset($field['key']))$map[$field['key']]=$field;
        $typed=[];foreach($raw as $key=>$value){
            if(!is_string($key)||!isset($map[$key])||!is_string($value)||strlen($value)>8192)return null;
            $type=$map[$key]['type']??'';
            if($type==='boolean'){if(!in_array($value,array('0','1'),true))return null;$typed[$key]=$value==='1';}
            elseif($type==='integer'){if(!preg_match('/^-?(0|[1-9][0-9]{0,15})$/D',$value))return null;$typed[$key]=(int)$value;}
            elseif($type==='number'){if(!is_numeric($value)||!is_finite((float)$value))return null;$typed[$key]=(float)$value;}
            elseif($type==='string')$typed[$key]=$value;
            else return null;
        }return $typed;
    }
    public static function page(){
        if(!self::valid_browser()){status_header(403);exit;}
        $ref=isset($_REQUEST['checkpoint_ref'])&&is_string($_REQUEST['checkpoint_ref'])?wp_unslash($_REQUEST['checkpoint_ref']):'';
        if(!preg_match('/^aib\.[a-f0-9]{48}$/D',(string)$ref)){status_header(400);exit;}
        $status=MAD4B_SCP_Adaptive_Interaction_Broker::status(array('checkpoint_ref'=>$ref));
        if(is_wp_error($status)){status_header(403);exit;}
        $method=$_SERVER['REQUEST_METHOD']??'';
        if(!in_array($method,array('GET','POST'),true)){status_header(405);exit;}
        $view=$status['intervention']??array();
        if($method==='POST'){
            check_admin_referer('mad4b_aib:'.$ref);
            $task=isset($_POST['aib_task'])&&is_string($_POST['aib_task'])?sanitize_key(wp_unslash($_POST['aib_task'])):'';
            if($task==='validate' && ($status['resolution_kind']??'')==='fresh_nonsecret_form' && is_array($view['form']??null)){
                $values=isset($_POST['values'])?wp_unslash($_POST['values']):array();
                $typed=self::typed_values($view['form'],$values);
                $sha=$view['form']['descriptor_sha256']??'';
                $result=null===$typed?new WP_Error('mad4b_aib_values_invalid','Invalid input'):MAD4B_SCP_Adaptive_Interaction_Broker::resolve(array(
                    'checkpoint_ref'=>$ref,'expected_descriptor_sha256'=>$sha,'values'=>$typed));
                self::start_page('Adaptive input result');
                $state=is_wp_error($result)?$result->get_error_code():($result['state']??'UNKNOWN');
                echo '<p>'.esc_html($state).'</p><p>No input values are stored or returned to ChatGPT. The original operation must be replanned under its native permissions.</p>';
                self::end_page();exit;
            }
            if($task==='native_verify' && ($status['resolution_kind']??'')==='first_party_secret_handoff'){
                $verified=MAD4B_SCP_Adaptive_Interaction_Broker::verify_native_handoff($ref);
                self::start_page('Native credential verification');
                $state=is_wp_error($verified)?$verified->get_error_code():($verified['state']??'WAITING_NATIVE_VERIFICATION');
                echo '<p>'.esc_html($state).'</p>';
                echo '<p>Only independent original provider readback can verify this handoff. Return to the agent for a new authorized plan.</p>';
                self::end_page();exit;
            }
            if($task==='native_secret_session' && ($status['resolution_kind']??'')==='first_party_secret_handoff'){
                if(!self::allowed_channel()
                    || !class_exists('MAD4B_SCP_CSO_Scope')||!MAD4B_SCP_CSO_Scope::enabled('secrets')
                    ||!class_exists('MAD4B_SCP_CSO_Secrets')){status_header(403);exit;}
                $provider=(string)($status['provider']??'');
                $field=isset($_POST['field_ref'])&&is_string($_POST['field_ref'])?wp_unslash($_POST['field_ref']):'';
                $mode=isset($_POST['mode'])&&is_string($_POST['mode'])?sanitize_key(wp_unslash($_POST['mode'])):'';
                $consent=isset($_POST['consent'])&&$_POST['consent']==='yes';
                if(!$consent||!preg_match('/^[a-z0-9][a-z0-9._:-]{0,95}$/D',$field)||!in_array($mode,array('configure','rotate'),true)){status_header(400);exit;}
                // Native CSO owns certification, actor/session identity, encrypted
                // store lifecycle, nonce and one-use secret entry in its own page.
                $native=MAD4B_SCP_CSO_Secrets::session(array('provider_id'=>$provider,'field_ref'=>$field,
                    'mode'=>$mode,'ttl'=>180,'consent'=>true));
                if(!is_wp_error($native) && isset($native['handoff_url'],$native['session_ref'])){
                    $attached=MAD4B_SCP_Adaptive_Interaction_Broker::attach_native_handoff($ref,$native['session_ref']);
                    if(is_wp_error($attached)){status_header(403);exit;}
                    $url=(string)$native['handoff_url'];$a=wp_parse_url($url);$b=wp_parse_url(admin_url());
                    if(is_array($a)&&is_array($b)&&($a['scheme']??'')===($b['scheme']??'') && in_array(($a['scheme']??''),array('https','http'),true)
                        &&($a['host']??'')===($b['host']??'') &&($a['port']??443)===($b['port']??443)
                        &&strpos((string)($a['path']??''),'/wp-admin/admin-post.php')!==false){
                        nocache_headers();header('Referrer-Policy: no-referrer');wp_safe_redirect($url,303);exit;
                    }
                }
                self::start_page('Certified credential handoff unavailable');
                echo '<p>Native provider certification or verified first-party session is missing. No key was collected.</p>';
                self::end_page();exit;
            }
            status_header(400);exit;
        }
        self::start_page('MAD4B Adaptive Interaction');
        echo '<p>Case: '.esc_html((string)$status['reason_code']).'</p><p>Status: '.esc_html((string)$status['state']).'</p>';
        if(($status['resolution_kind']??'')==='fresh_nonsecret_form' && !empty($view['ready'])&&is_array($view['form']??null)){
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            echo '<input type="hidden" name="action" value="'.esc_attr(self::ACTION).'">';
            echo '<input type="hidden" name="checkpoint_ref" value="'.esc_attr($ref).'">';
            echo '<input type="hidden" name="aib_task" value="validate">';
            wp_nonce_field('mad4b_aib:'.$ref);
            foreach(array_slice($view['form']['fields']??array(),0,24) as $field)self::form_field($field);
            echo '<button type="submit">Validate input (no save / no execution)</button></form>';
        }elseif(($status['resolution_kind']??'')==='native_owner_settings'){
            $native=$view['owner_status']??null;
            if(!is_array($native)||($native['owner']??'')!=='woocommerce'){
                echo '<p>Original WooCommerce settings not available or permission denied.</p>';
            }else{
                echo '<p>Owner: WooCommerce. License configured: '.(!empty($native['license_key_present'])?'yes':'no').
                    '; GeoLite2 database: '.(!empty($native['geoip_database_ready'])?'ready':'missing').'</p>';
                if(self::allowed_channel()&&!empty($native['transport_attested'])){
                    echo '<p><a rel="noreferrer noopener" href="'.esc_url($native['native_settings_url']).'">Open original WooCommerce MaxMind settings</a></p>';
                }else{
                    echo '<p>Open original WooCommerce settings only from a verified Local browser via SSH tunnel or HTTPS. No credential value belongs in ChatGPT.</p>';
                }
                echo '<p>Saving and downloading the database are handled only by WooCommerce.</p>';
            }
        }elseif(($status['resolution_kind']??'')==='first_party_secret_handoff'){
            if(!empty($status['native_handoff_pending'])){
                echo '<p>Complete the original provider handoff in the separate browser page, then verify here.</p>';
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                echo '<input type="hidden" name="action" value="'.esc_attr(self::ACTION).'">';
                echo '<input type="hidden" name="checkpoint_ref" value="'.esc_attr($ref).'">';
                echo '<input type="hidden" name="aib_task" value="native_verify">';
                wp_nonce_field('mad4b_aib:'.$ref);
                echo '<button type="submit">Verify original provider readback</button></form>';
            }elseif(!empty($status['native_handoff_verified'])){
                echo '<p>Original provider readback verified. The agent must replan under native permissions.</p>';
            }elseif(!self::allowed_channel()||!MAD4B_SCP_CSO_Scope::enabled('secrets')){
                echo '<p>Blocked: verified HTTPS or explicitly attested Local loopback transport, plus a certified native provider, are required. Never enter API keys into a chat.</p>';
            }else{
                echo '<p>Only the original CSO Secrets provider will receive your secret; the broker never collects it.</p>';
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                echo '<input type="hidden" name="action" value="'.esc_attr(self::ACTION).'">';
                echo '<input type="hidden" name="checkpoint_ref" value="'.esc_attr($ref).'">';
                echo '<input type="hidden" name="aib_task" value="native_secret_session">';
                wp_nonce_field('mad4b_aib:'.$ref);
                echo '<label>Certified field reference <input name="field_ref" autocomplete="off" required maxlength="96"></label>';
                echo '<label>Action <select name="mode"><option value="configure">Configure</option><option value="rotate">Rotate</option></select></label>';
                echo '<label><input type="checkbox" name="consent" value="yes" required> I authorize the native first-party credential handoff</label>';
                echo '<button type="submit">Open certified provider session</button></form>';
            }
        }else{
            $url=(string)($view['owner_handoff_url']??'');
            if(!empty($view['ready'])&&$url!=='')echo '<p><a rel="noreferrer noopener" href="'.esc_url($url).'">Open original provider settings</a></p>';
            else echo '<p>This case needs the original provider, site owner, or authenticated agent to resolve it. No automatic authorization was issued.</p>';
        }
        self::end_page();exit;
    }
}
