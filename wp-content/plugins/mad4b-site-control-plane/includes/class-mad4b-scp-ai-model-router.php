<?php
if (!defined('ABSPATH')) exit;
/**
 * MAD4B AI routing is a consumer of WordPress Core Connectors, not a parallel
 * credential registry. Only route preferences (never secrets) are stored here.
 */
final class MAD4B_SCP_AI_Model_Router {
    const CONTRACT = 'mad4b.ai-model-routing.v1';
    const OPTION = 'mad4b_scp_ai_model_route_v1';
    const CUSTOM_SETTING = 'connectors_mad4b_custom_api_key';
    const GEMINI_SETTING = 'connectors_mad4b_gemini_api_key';
    const OPENROUTER_SETTING = 'connectors_mad4b_openrouter_api_key';

    public static function defaults() {
        return array('mode'=>'wp_core','model'=>'','custom_endpoint'=>'','enabled'=>false,
            'max_jobs_per_run'=>2,'allow_source_repair'=>false,'gemini_extraction_enabled'=>false);
    }
    public static function settings() {
        $v=get_option(self::OPTION,array());
        return array_merge(self::defaults(),is_array($v)?$v:array());
    }
    public static function register_connectors($registry) {
        if (!is_object($registry) || !method_exists($registry,'register')) return;
        $entries=array(
            'mad4b_openrouter'=>array('name'=>'OpenRouter (MAD4B)','description'=>'OpenRouter chat completion models, including free routing.',
                'url'=>'https://openrouter.ai/settings/keys','setting'=>self::OPENROUTER_SETTING,
                'env'=>'MAD4B_OPENROUTER_API_KEY','constant'=>'MAD4B_OPENROUTER_API_KEY'),
            'mad4b_gemini'=>array('name'=>'Gemini API (MAD4B)','description'=>'Explicit Gemini generation and optional document extraction API key, separate from Google Drive OAuth.',
                'url'=>'https://aistudio.google.com/apikey','setting'=>self::GEMINI_SETTING,
                'env'=>'MAD4B_CONTEXT_GEMINI_API_KEY','constant'=>'MAD4B_CONTEXT_GEMINI_API_KEY'),
            'mad4b_custom'=>array('name'=>'Custom AI Endpoint (MAD4B)','description'=>'An explicitly configured OpenAI-compatible HTTPS chat endpoint.',
                'url'=>'','setting'=>self::CUSTOM_SETTING,
                'env'=>'MAD4B_CUSTOM_AI_API_KEY','constant'=>'MAD4B_CUSTOM_AI_API_KEY'),
        );
        foreach($entries as $id=>$entry) {
            if(method_exists($registry,'is_registered') && $registry->is_registered($id))continue;
            // Custom/Direct transports are not registered as WP AI Client providers:
            // WordPress would otherwise attempt unsupported provider validation.
            $registry->register($id,array(
                'name'=>$entry['name'],'description'=>$entry['description'],'type'=>'mad4b_model_route',
                'authentication'=>array('method'=>'api_key',
                    'setting_name'=>$entry['setting'],'credentials_url'=>$entry['url'],
                    'env_var_name'=>$entry['env'],'constant_name'=>$entry['constant']),
                'plugin'=>array('is_active'=>'__return_true'),
            ));
        }
    }
    private static function connector_key($id) {
        $connector=function_exists('wp_get_connector')?wp_get_connector($id):null;
        if(!is_array($connector))return '';
        $auth=$connector['authentication']??array();
        if(($auth['method']??'')!=='api_key')return '';
        $env=(string)($auth['env_var_name']??'');
        if($env!=='' && ($value=getenv($env))!==false && $value!=='')return (string)$value;
        $const=(string)($auth['constant_name']??'');
        if($const!=='' && defined($const) && is_string(constant($const)) && constant($const)!=='')
            return constant($const);
        $setting=(string)($auth['setting_name']??'');
        $value=$setting!==''?get_option($setting,''):'';
        return is_string($value)?$value:'';
    }
    public static function validate_endpoint($url) {
        if(!is_string($url) || strlen($url)>350 || !filter_var($url,FILTER_VALIDATE_URL))
            return false;
        $parts=wp_parse_url($url);
        if(!is_array($parts) || strtolower((string)($parts['scheme']??''))!=='https' ||
           empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) ||
           isset($parts['query']) || isset($parts['fragment']) ||
           (isset($parts['port']) && (int)$parts['port']!==443))return false;
        $host=strtolower((string)$parts['host']);
        if($host==='localhost' || strpos($host,'.')===false || substr($host,-6)==='.local' ||
           substr($host,-9)==='.internal' || preg_match('/[^a-z0-9.\-]/',$host))return false;
        if(filter_var($host,FILTER_VALIDATE_IP))return false;
        return true;
    }
    public static function normalize_settings($input) {
        if(!is_array($input))return new WP_Error('mad4b_ai_route_invalid','Structured route settings required.');
        $mode=(string)($input['mode']??'wp_core');
        if(!in_array($mode,array('wp_core','google_dedicated','openrouter','custom'),true))
            return new WP_Error('mad4b_ai_route_invalid','Unsupported AI provider route.');
        $model=trim((string)($input['model']??''));
        if(strlen($model)>128 || ($model!=='' && !preg_match('#^[A-Za-z0-9./:_-]+$#D',$model)))
            return new WP_Error('mad4b_ai_model_invalid','Model identifier must be a short provider model slug.');
        if($mode==='openrouter' && $model==='')$model='openrouter/free';
        if($mode==='google_dedicated' && $model==='')$model='gemini-2.5-flash';
        $endpoint=trim((string)($input['custom_endpoint']??''));
        if($mode==='custom' && !self::validate_endpoint($endpoint))
            return new WP_Error('mad4b_ai_custom_endpoint_invalid','A public HTTPS endpoint without embedded credentials is required.');
        if($mode!=='custom')$endpoint='';
        return array('mode'=>$mode,'model'=>$model,'custom_endpoint'=>$endpoint,
            'enabled'=>!empty($input['enabled']),
            'max_jobs_per_run'=>max(1,min(5,(int)($input['max_jobs_per_run']??2))),
            'allow_source_repair'=>!empty($input['allow_source_repair']),
            'gemini_extraction_enabled'=>!empty($input['gemini_extraction_enabled']));
    }
    /** One shared key source for Gemini inference and opt-in Media extraction.
     * OAuth grants for Google Drive are never interpreted as generation keys.
     */
    public static function gemini_api_key() {
        // The MAD4B-specific source is an override, but a Gemini API key
        // already entered into WordPress's native Google Connector is reusable
        // as-is. Both remain in WordPress Core's connector registry.
        $dedicated=trim((string)self::connector_key('mad4b_gemini'));
        return $dedicated!==''?$dedicated:trim((string)self::connector_key('google'));
    }
    public static function gemini_extraction_enabled() {
        $legacy=defined('MAD4B_CONTEXT_GEMINI_ENABLED')
            && true===(bool)constant('MAD4B_CONTEXT_GEMINI_ENABLED');
        $cfg=self::settings();
        return $legacy || (!empty($cfg['enabled']) && ($cfg['mode']??'')==='google_dedicated'
            && !empty($cfg['gemini_extraction_enabled']));
    }
    public static function provider_status() {
        $cfg=self::settings();
        $mode=$cfg['mode'];
        $key_source='none';
        if($mode==='openrouter'||$mode==='custom') {
            $id=$mode==='openrouter'?'mad4b_openrouter':'mad4b_custom';
            $con=function_exists('wp_get_connector')?wp_get_connector($id):null;
            $auth=is_array($con)?($con['authentication']??array()):array();
            if(function_exists('_wp_connectors_get_api_key_source'))
                $key_source=_wp_connectors_get_api_key_source((string)($auth['setting_name']??''),
                    (string)($auth['env_var_name']??''),(string)($auth['constant_name']??''));
        } elseif($mode==='google_dedicated') {
            $con=function_exists('wp_get_connector')?wp_get_connector('mad4b_gemini'):null;
            $auth=is_array($con)?($con['authentication']??array()):array();
            if(function_exists('_wp_connectors_get_api_key_source'))
                $key_source=_wp_connectors_get_api_key_source((string)($auth['setting_name']??''),
                    (string)($auth['env_var_name']??''),(string)($auth['constant_name']??''));
            elseif(self::gemini_api_key()!=='')$key_source='existing_config';
            if($key_source==='none') {
                $google=function_exists('wp_get_connector')?wp_get_connector('google'):null;
                $ga=is_array($google)?($google['authentication']??array()):array();
                if(function_exists('_wp_connectors_get_api_key_source'))
                    $source=_wp_connectors_get_api_key_source((string)($ga['setting_name']??''),
                        (string)($ga['env_var_name']??''),(string)($ga['constant_name']??''));
                else $source=self::connector_key('google')!==''?'existing_config':'none';
                if($source!=='none')$key_source='core_google_'.$source;
            }
        } elseif($mode==='wp_core') {
            $registered=function_exists('wp_get_connectors')?wp_get_connectors():array();
            foreach($registered as $c) {
                if(($c['type']??'')!=='ai_provider')continue;
                // Core advertises provider cards even before the actual
                // provider plugin is installed. A key without an active
                // provider is not an inference-ready model.
                $active=$c['plugin']['is_active']??null;
                if(!is_callable($active) || !call_user_func($active))continue;
                $a=$c['authentication']??array();
                $s=function_exists('_wp_connectors_get_api_key_source')
                    ?_wp_connectors_get_api_key_source((string)($a['setting_name']??''),
                        (string)($a['env_var_name']??''),(string)($a['constant_name']??'')):'none';
                if($s!=='none'){$key_source='wp_core_connector';break;}
            }
        }
        return array('contract'=>self::CONTRACT,'mode'=>$mode,'enabled'=>(bool)$cfg['enabled'],
            'model'=>$cfg['model'],'custom_endpoint'=>$cfg['custom_endpoint'],
            'credential_source'=>$key_source,'credential_ready'=>$key_source!=='none',
            'source_of_truth'=>'wordpress_core_connectors','secrets_exposed'=>false,
            'dedicated_google_oauth_is_not_gemini_api_key'=>true,
            'bitflows_integration'=>'php_facade_and_wp_action');
    }
    private static function json_text_from_response($response) {
        if(is_wp_error($response))return $response;
        $code=wp_remote_retrieve_response_code($response);
        if($code<200 || $code>=300)return new WP_Error('mad4b_ai_inference_http_error','AI provider returned HTTP '.$code,array('http_status'=>$code));
        $raw=wp_remote_retrieve_body($response);
        if(!is_string($raw) || strlen($raw)>262144)return new WP_Error('mad4b_ai_inference_response_too_large','AI response exceeded bounds.');
        $data=json_decode($raw,true);
        $text=$data['choices'][0]['message']['content']??null;
        if(is_array($text))$text=implode("\n",array_map(static function($item){return is_array($item)?(string)($item['text']??''):(string)$item;},$text));
        if(!is_string($text)||trim($text)==='')return new WP_Error('mad4b_ai_inference_invalid_response','No model text in chat completion response.');
        return trim($text);
    }
    /** Shared read-only inference facade for MAD4B, Bit Flows, or governed AI workers. */
    public static function generate_text($prompt,$override=null) {
        if(!is_string($prompt) || trim($prompt)==='' || strlen($prompt)>60000)
            return new WP_Error('mad4b_ai_inference_prompt_invalid','Prompt size or format invalid.');
        $config=self::settings();
        if(!empty($override) && is_array($override)) {
            // A caller may narrow model choice only, not substitute an endpoint
            // or load an arbitrary credential from untrusted workflow input.
            if(isset($override['model']))$config['model']=(string)$override['model'];
        }
        if(empty($config['enabled']))return new WP_Error('mad4b_ai_router_disabled','MAD4B AI inference is disabled by site configuration.');
        $mode=$config['mode'];
        $model=(string)$config['model'];
        if($model!=='' && (strlen($model)>128 || !preg_match('#^[A-Za-z0-9./:_-]+$#D',$model)))
            return new WP_Error('mad4b_ai_model_invalid','Invalid model name.');
        if($mode==='wp_core') {
            if(!function_exists('wp_ai_client_prompt') || !function_exists('wp_supports_ai') || !wp_supports_ai())
                return new WP_Error('mad4b_ai_core_unavailable','WordPress AI Client not available.');
            $builder=wp_ai_client_prompt($prompt);
            // WordPress Core selects providers; a chosen valid model ID is
            // passed through the documented model preference API.
            if($model!=='')$builder=$builder->using_model_preference($model);
            $answer=$builder->generate_text();
            return is_wp_error($answer)?$answer:(is_string($answer)?$answer:new WP_Error('mad4b_ai_core_result_invalid','WordPress AI Client returned no text.'));
        }
        $key='';
        if($mode==='google_dedicated') {
            $key=self::gemini_api_key();
            if($key==='')return new WP_Error('mad4b_ai_google_key_missing',
                'Gemini API key unavailable. Configure Gemini (MAD4B) in WordPress Settings > Connectors.');
            if($key==='' || $model==='')
                return new WP_Error('mad4b_ai_google_model_required','Gemini API key and model are required.');
            $url='https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent';
            $body=array('contents'=>array(array('parts'=>array(array('text'=>$prompt)))),
                'generationConfig'=>array('temperature'=>0.1,'maxOutputTokens'=>1800));
            $res=wp_safe_remote_post($url,array('timeout'=>35,'redirection'=>0,
                'headers'=>array('Content-Type'=>'application/json','x-goog-api-key'=>$key),
                'body'=>wp_json_encode($body),'limit_response_size'=>262144));
            if(is_wp_error($res))return $res;
            $status=wp_remote_retrieve_response_code($res);
            if($status<200||$status>=300)return new WP_Error('mad4b_ai_google_http_error','Gemini API returned HTTP '.$status);
            $json=json_decode(wp_remote_retrieve_body($res),true);
            $parts=$json['candidates'][0]['content']['parts']??array();
            $texts=array();
            foreach((array)$parts as $part)if(is_array($part)&&isset($part['text']))$texts[]=(string)$part['text'];
            $output=trim(implode("\n",$texts));
            return $output!==''?$output:new WP_Error('mad4b_ai_google_empty','Gemini response did not contain text.');
        }
        if($mode!=='openrouter' && $mode!=='custom')return new WP_Error('mad4b_ai_unknown_route','Invalid provider route.');
        $id=$mode==='openrouter'?'mad4b_openrouter':'mad4b_custom';
        $key=self::connector_key($id);
        if($key==='')return new WP_Error('mad4b_ai_connector_key_missing','Configure the provider in WordPress Settings > Connectors.');
        $url=$mode==='openrouter'?'https://openrouter.ai/api/v1/chat/completions':(string)$config['custom_endpoint'];
        if(!self::validate_endpoint($url))return new WP_Error('mad4b_ai_provider_endpoint_denied','AI endpoint must be public HTTPS.');
        if($model==='')return new WP_Error('mad4b_ai_provider_model_missing','Choose the exact model identifier.');
        $body=array('model'=>$model,'messages'=>array(array('role'=>'user','content'=>$prompt)),
            'temperature'=>0.1,'max_tokens'=>1800);
        $res=wp_safe_remote_post($url,array('timeout'=>35,'redirection'=>0,'limit_response_size'=>262144,
            'headers'=>array('Authorization'=>'Bearer '.$key,'Content-Type'=>'application/json',
                'X-Title'=>'MAD4B WordPress AI Review'),
            'body'=>wp_json_encode($body)));
        return self::json_text_from_response($res);
    }
    public static function save_route() {
        if(!current_user_can('manage_options'))wp_die('Not authorized.');
        check_admin_referer('mad4b_ai_route_save');
        $data=isset($_POST['mad4b_ai_route'])&&is_array($_POST['mad4b_ai_route'])
            ?wp_unslash($_POST['mad4b_ai_route']):array();
        $clean=self::normalize_settings($data);
        if(is_wp_error($clean)){wp_safe_redirect(add_query_arg('mad4b_ai_error',$clean->get_error_code(),admin_url('options-general.php?page=mad4b-ai-routing')));exit;}
        update_option(self::OPTION,$clean,false);
        wp_safe_redirect(add_query_arg('updated','1',admin_url('options-general.php?page=mad4b-ai-routing')));exit;
    }
    /** Stores into the same Core Connector setting, not a shadow credential. */
    public static function save_gemini_key() {
        if(!current_user_can('manage_options')
           || !class_exists('MAD4B_SCP_Site_Profile')
           || !MAD4B_SCP_Site_Profile::user_is_enrolled(get_current_user_id()))
            wp_die('Enrolled site administrator required.','Forbidden',array('response'=>403));
        check_admin_referer('mad4b_gemini_key_save');
        $action=isset($_POST['mad4b_gemini_credential_action'])
            ?sanitize_key(wp_unslash((string)$_POST['mad4b_gemini_credential_action'])):'';
        $connector=function_exists('wp_get_connector')?wp_get_connector('mad4b_gemini'):null;
        if(!is_array($connector) ||
            ($connector['authentication']['setting_name']??'')!==self::GEMINI_SETTING)
            wp_die('Gemini Core Connector unavailable.','Unavailable',array('response'=>503));
        $from_env=getenv('MAD4B_CONTEXT_GEMINI_API_KEY');
        if(($from_env!==false && $from_env!=='') ||
            (defined('MAD4B_CONTEXT_GEMINI_API_KEY') &&
             is_string(constant('MAD4B_CONTEXT_GEMINI_API_KEY')) &&
             constant('MAD4B_CONTEXT_GEMINI_API_KEY')!==''))
            wp_die('Gemini API key is managed by environment or PHP constant; edit its original source.','Conflict',array('response'=>409));
        if($action==='replace') {
            $key=isset($_POST['mad4b_gemini_api_key'])
                ?trim((string)wp_unslash($_POST['mad4b_gemini_api_key'])):'';
            if(strlen($key)<15 || strlen($key)>512 || preg_match('/\s/',$key))
                wp_die('Gemini API key must be 15-512 characters without whitespace.','Invalid key',array('response'=>400));
            update_option(self::GEMINI_SETTING,$key,false);
        } elseif($action==='clear') {
            delete_option(self::GEMINI_SETTING);
        } else {
            wp_die('Explicit credential operation is required.','Invalid operation',array('response'=>400));
        }
        wp_safe_redirect(add_query_arg('mad4b_gemini_saved','1',
            admin_url('options-general.php?page=mad4b-ai-routing')));
        exit;
    }
    public static function register_settings_page() {
        add_options_page('MAD4B AI Routing','MAD4B AI Routing','manage_options','mad4b-ai-routing',array(__CLASS__,'render_settings_page'));
    }
    public static function render_settings_page() {
        if(!current_user_can('manage_options'))return;
        $config=self::settings();$status=self::provider_status();
        echo '<div class="wrap"><h1>MAD4B AI Routing</h1><p>Credentials are managed in <a href="'.esc_url(admin_url('options-connectors.php')).'">WordPress Settings → Connectors</a>. No API keys are stored in this routing form.</p>';
        echo '<p>Mode: '.esc_html($status['mode']).' — credential source: '.esc_html($status['credential_source']).'. Dedicated Google OAuth alone does not authorize Gemini inference.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('mad4b_ai_route_save');
        echo '<input type="hidden" name="action" value="mad4b_ai_route_save"/><table class="form-table"><tr><th>Provider</th><td><select name="mad4b_ai_route[mode]">';
        foreach(array('wp_core'=>'WordPress AI Client','google_dedicated'=>'MAD4B Dedicated Gemini API Key','openrouter'=>'OpenRouter','custom'=>'Custom OpenAI-compatible HTTPS') as $value=>$label)
            echo '<option value="'.esc_attr($value).'" '.selected($config['mode'],$value,false).'>'.esc_html($label).'</option>';
        echo '</select></td></tr><tr><th>Model</th><td><input class="regular-text" name="mad4b_ai_route[model]" value="'.esc_attr($config['model']).'" placeholder="openrouter/free"/></td></tr>';
        echo '<tr><th>Custom HTTPS chat endpoint</th><td><input class="large-text" name="mad4b_ai_route[custom_endpoint]" value="'.esc_attr($config['custom_endpoint']).'" placeholder="Public HTTPS chat-completions endpoint"/></td></tr>';
        echo '<tr><th>Gemini document extraction</th><td><label><input type="checkbox" name="mad4b_ai_route[gemini_extraction_enabled]" value="1" '.checked(!empty($config['gemini_extraction_enabled']),true,false).'/> Enable this route for governed content extraction (not Google Drive OAuth)</label></td></tr>';
        echo '<tr><th>Max review jobs per run</th><td><input type="number" min="1" max="5" name="mad4b_ai_route[max_jobs_per_run]" value="'.esc_attr($config['max_jobs_per_run']).'"/></td></tr>';
        echo '<tr><th>Enable inference</th><td><label><input type="checkbox" value="1" name="mad4b_ai_route[enabled]" '.checked($config['enabled'],true,false).'/> Allow approved AI routes to invoke providers</label></td></tr>';
        echo '<tr><th>Allow source repair</th><td><label><input type="checkbox" value="1" name="mad4b_ai_route[allow_source_repair]" '.checked($config['allow_source_repair'],true,false).'/> Allow separate certified source repair routines (never bypass review)</label></td></tr>';
        echo '</table>';submit_button('Save AI routing');echo '</form>';
        echo '<h2>Gemini API Key</h2><p>This credential is stored once in the WordPress Core Connector setting. It is not the Google Drive OAuth client secret or access token.</p>';
        $connector=wp_get_connector('mad4b_gemini');
        $auth=is_array($connector)?($connector['authentication']??array()):array();
        $source=function_exists('_wp_connectors_get_api_key_source')
            ?_wp_connectors_get_api_key_source((string)($auth['setting_name']??''),
            (string)($auth['env_var_name']??''),(string)($auth['constant_name']??'')):'unavailable';
        echo '<p><strong>Key source:</strong> '.esc_html($source).'. <a href="'.esc_url(admin_url('options-connectors.php')).'">Open Core Connectors</a> · <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener noreferrer">Google AI Studio</a></p>';
        echo '<p>Prefer the built-in Google Connector when already configured: MAD4B reuses that key automatically. Set an MAD4B-specific key below only when the Google connection must use a different API project.</p>';
        if(!in_array($source,array('env','constant'),true)) {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('mad4b_gemini_key_save');
            echo '<input type="hidden" name="action" value="mad4b_ai_gemini_key_save"/>';
            echo '<label for="mad4b-gemini-api-key">Gemini API key (write-only; current key is never displayed)</label> ';
            echo '<input id="mad4b-gemini-api-key" type="password" autocomplete="new-password" class="regular-text" name="mad4b_gemini_api_key" value="" placeholder="Paste new Gemini API key"/>';
            echo '<button class="button button-primary" type="submit" name="mad4b_gemini_credential_action" value="replace">Save / replace key</button> ';
            echo '<button class="button" type="submit" name="mad4b_gemini_credential_action" value="clear" onclick="return confirm(\'Remove Gemini key stored by WordPress?\')">Clear stored key</button>';
            echo '<p class="description">WordPress database connector keys are masked in the UI, not encrypted. Prefer environment variables for production secrets.</p></form>';
        } else echo '<p>Key is externally managed. This page cannot overwrite it.</p>';
        echo '</div>';
    }
    public static function boot() {
        add_action('wp_connectors_init',array(__CLASS__,'register_connectors'),10,1);
        add_action('admin_menu',array(__CLASS__,'register_settings_page'));
        add_action('admin_post_mad4b_ai_route_save',array(__CLASS__,'save_route'));
        add_action('admin_post_mad4b_ai_gemini_key_save',array(__CLASS__,'save_gemini_key'));
    }
}
MAD4B_SCP_AI_Model_Router::boot();
