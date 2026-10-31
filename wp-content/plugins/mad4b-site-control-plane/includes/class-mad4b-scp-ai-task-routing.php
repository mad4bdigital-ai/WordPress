<?php
if (!defined('ABSPATH')) exit;
/** Non-secret, owner-governed route PLANNING. Not an execution dispatcher. */
final class MAD4B_SCP_AI_Task_Routing {
    const CONTRACT='mad4b.ai-task-routing.v1';
    const OPTION='mad4b_scp_ai_task_routes_v1';
    const TASKS=array('code_proposal','code_review','content_draft','source_extraction','local_diagnostics');
    private static $booted=false;
    public static function default_profiles() {
        return array(
            'code_proposal'=>array('provider'=>'codex_cli','model'=>'gpt-6.1-sol','enabled'=>false),
            'code_review'=>array('provider'=>'openai_direct','model'=>'gpt-6-luna','enabled'=>false),
            'content_draft'=>array('provider'=>'google_dedicated','model'=>'gemini-2.5-flash','enabled'=>false),
            'source_extraction'=>array('provider'=>'google_dedicated','model'=>'gemini-2.5-flash','enabled'=>false),
            'local_diagnostics'=>array('provider'=>'local_ollama','model'=>'qwen2.5:1.5b','enabled'=>false),
        );
    }
    public static function permitted_providers($task) {
        switch($task) {
            case 'code_proposal': return array('codex_cli','openai_direct');
            case 'code_review': return array('openai_direct','google_dedicated');
            case 'content_draft': return array('google_dedicated','openai_direct');
            case 'source_extraction': return array('google_dedicated','openai_direct');
            case 'local_diagnostics': return array('local_ollama');
            default: return array();
        }
    }
    public static function normalize($input) {
        if (!is_array($input) || array_diff(array_keys($input),self::TASKS))
            return new WP_Error('mad4b_ai_task_policy_invalid','Unexpected task-routing keys.');
        $defaults=self::default_profiles();$out=array();
        foreach(self::TASKS as $task) {
            $row=$input[$task]??$defaults[$task];
            if(!is_array($row) || array_diff(array_keys($row),array('provider','model','enabled')))
                return new WP_Error('mad4b_ai_task_policy_invalid','Unexpected task profile fields.');
            $provider=(string)($row['provider']??'');
            $model=trim((string)($row['model']??''));
            if(!in_array($provider,self::permitted_providers($task),true))
                return new WP_Error('mad4b_ai_task_provider_denied','Task/provider pairing is not supported.');
            if(strlen($model)<3 || strlen($model)>120 || !preg_match('#^[a-zA-Z0-9][a-zA-Z0-9.:/_-]*$#D',$model)
              || strpos($model,'..')!==false)
                return new WP_Error('mad4b_ai_task_model_invalid','Model identifier invalid.');
            if(($provider==='local_ollama' && $model!=='qwen2.5:1.5b') ||
               ($provider==='google_dedicated' && strpos($model,'gemini-')!==0) ||
               (in_array($provider,array('openai_direct','codex_cli'),true) && strpos($model,'gpt-')!==0))
                return new WP_Error('mad4b_ai_task_model_provider_mismatch','Model does not match the selected provider.');
            $enabled=$row['enabled']??false;
            if(!in_array($enabled,array(false,true,0,1,'0','1'),true))
                return new WP_Error('mad4b_ai_task_enabled_invalid','Task preference must be a boolean.');
            $out[$task]=array('provider'=>$provider,'model'=>$model,
                'enabled'=>in_array($enabled,array(true,1,'1'),true));
        }
        return $out;
    }
    public static function profiles() {
        $saved=get_option(self::OPTION,array());
        if(!is_array($saved) || !$saved)return self::default_profiles();
        $clean=self::normalize($saved);
        // Corrupt persisted policy must NEVER result in a permissive partial config.
        return is_wp_error($clean)?self::default_profiles():$clean;
    }
    /** Explain what would be selected: NEVER call an inference API or Codex from here. */
    public static function plan($task) {
        $task=is_string($task)?$task:'';
        if(!in_array($task,self::TASKS,true))
            return new WP_Error('mad4b_ai_task_unknown','Unknown fixed task ID.');
        $profile=self::profiles()[$task];
        $provider=$profile['provider'];
        $reason=array();
        if(!$profile['enabled'])$reason[]='task_disabled';
        $secret='none';
        if($provider==='openai_direct') {
            $secret=MAD4B_SCP_AI_Model_Router::openai_api_key()!==''?'wordpress_core_openai':'none';
            if($secret==='none')$reason[]='openai_api_key_missing';
        } elseif($provider==='google_dedicated') {
            $secret=MAD4B_SCP_AI_Model_Router::gemini_api_key()!==''?'wordpress_core_google':'none';
            if($secret==='none')$reason[]='gemini_api_key_missing';
        } elseif($provider==='codex_cli') {
            // A WP Core API credential does not prove Codex CLI login or a native stage grant.
            $reason[]='codex_auth_and_native_stage_grant_unverified';
        } elseif($provider==='local_ollama') {
            if(!function_exists('wp_get_environment_type') || wp_get_environment_type()!=='local')
                $reason[]='local_only_provider_denied';
        }
        $reason[]='task_execution_dispatcher_not_certified';
        $reason[]='budget_and_native_approval_pending';
        return array('contract'=>self::CONTRACT,'task'=>$task,'provider'=>$provider,
            'model'=>$profile['model'],'configured'=>$profile['enabled'],
            'credential_source'=>$secret,'requires_native_approval'=>true,
            'can_execute'=>false,'execution_authorized'=>false,'read_only'=>true,
            'reason_codes'=>array_values(array_unique($reason)));
    }
    public static function save() {
        if(!current_user_can('manage_options')
          || !class_exists('MAD4B_SCP_Site_Profile')
          || !MAD4B_SCP_Site_Profile::user_is_enrolled(get_current_user_id()))
            wp_die('Enrolled site administrator required.', 'Forbidden', array('response'=>403));
        check_admin_referer('mad4b_ai_task_routes_save');
        $raw=isset($_POST['mad4b_ai_tasks'])&&is_array($_POST['mad4b_ai_tasks'])
            ?wp_unslash($_POST['mad4b_ai_tasks']):array();
        $profiles=self::normalize($raw);
        if(is_wp_error($profiles)){
            wp_safe_redirect(add_query_arg('mad4b_task_error',$profiles->get_error_code(),admin_url('options-general.php?page=mad4b-ai-routing')));
            exit;
        }
        update_option(self::OPTION,$profiles,false);
        wp_safe_redirect(add_query_arg('task_routes_updated','1',admin_url('options-general.php?page=mad4b-ai-routing')));
        exit;
    }
    public static function render_admin() {
        if(!current_user_can('manage_options'))return;
        $profiles=self::profiles();
        echo '<hr/><h2>Task-based model setup (preview only)</h2>';
        echo '<p>Task profiles choose which existing provider would be used. Saving these preferences does not start paid requests, sign Codex in, or grant MAD4B runtime authority.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('mad4b_ai_task_routes_save');
        echo '<input type="hidden" name="action" value="mad4b_ai_task_routes_save"/><table class="widefat striped"><thead><tr><th>Task</th><th>Provider</th><th>Model</th><th>Enable preference</th><th>Authorization</th></tr></thead><tbody>';
        foreach(self::TASKS as $task) {
            $profile=$profiles[$task];$plan=self::plan($task);
            echo '<tr><td><code>'.esc_html($task).'</code></td><td><select name="mad4b_ai_tasks['.esc_attr($task).'][provider]">';
            foreach(self::permitted_providers($task) as $name)
                echo '<option value="'.esc_attr($name).'" '.selected($profile['provider'],$name,false).'>'.esc_html($name).'</option>';
            echo '</select></td><td><input type="text" maxlength="120" name="mad4b_ai_tasks['.esc_attr($task).'][model]" value="'.esc_attr($profile['model']).'"/></td><td><input type="checkbox" name="mad4b_ai_tasks['.esc_attr($task).'][enabled]" value="1" '.checked($profile['enabled'],true,false).'/></td><td><code>'.esc_html(implode(', ',$plan['reason_codes'])).'</code></td></tr>';
        }
        echo '</tbody></table>';
        submit_button('Save task setup (no execution)');echo '</form>';
    }
    public static function boot() {
        if(self::$booted)return;self::$booted=true;
        add_action('admin_post_mad4b_ai_task_routes_save',array(__CLASS__,'save'));
    }
}
MAD4B_SCP_AI_Task_Routing::boot();
