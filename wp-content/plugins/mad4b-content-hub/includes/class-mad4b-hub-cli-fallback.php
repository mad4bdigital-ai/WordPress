<?php
defined('ABSPATH') || exit;

/**
 * Local, bounded VPS WP-CLI fallback for a connected ChatGPT client.
 * It is a transport, NOT a grant or proof of ChatGPT OAuth.
 */
final class MAD4B_Hub_CLI_Fallback {
    const CONTRACT='mad4b.hub.vps-wpcli-fallback.v1';
    const COMMANDS=array(
        'status'=>'mad4b/hub-executor-status',
        'handoff'=>'mad4b/hub-executor-handoff',
        'resume'=>'mad4b/control-workflow-resume',
        'baseline'=>'mad4b/control-workflow-baseline',
        'submit-keywords'=>'mad4b/hub-executor-submit-keywords',
        'release-assess'=>'mad4b/hub-canonical-release-assess',
        'release'=>'mad4b/hub-canonical-release',
    );

    public static function boot(){
        if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
            WP_CLI::add_command('mad4b hub fallback',array(__CLASS__,'run'));
    }
    private static function denied($reason){
        return new WP_Error('mad4b_hub_cli_fallback_'.$reason,$reason);
    }
    public static function preflight($operation){
        if(!defined('WP_CLI')||!WP_CLI || PHP_SAPI!=='cli'
            ||(defined('REST_REQUEST')&&REST_REQUEST))
            return self::denied('local_wpcli_only');
        if(!array_key_exists($operation,self::COMMANDS))
            return self::denied('operation_not_allowlisted');
        if(!class_exists('MAD4B_Content_Hub')||!MAD4B_Content_Hub::permission())
            return self::denied('central_hub_permission_denied');
        if(!class_exists('MAD4B_SCP_Identity_Context')
            ||!class_exists('MAD4B_SCP_Delegation_Mode'))
            return self::denied('identity_runtime_missing');
        $identity=MAD4B_SCP_Identity_Context::current();
        if(is_wp_error($identity))return $identity;
        $actor=MAD4B_SCP_Delegation_Mode::verified_wordpress_user($identity);
        if(!$actor || (int)$actor->ID!==get_current_user_id()
            ||!user_can($actor,'manage_options'))
            return self::denied('explicit_wpcli_user_required');
        $write=!in_array($operation,array('status','handoff','release-assess'),true);
        if($write&&(!class_exists('MAD4B_SCP_Policy')
            ||!MAD4B_SCP_Policy::can_mutate()))
            return self::denied('site_write_gate_denied');
        // Production fallback writes require a separate rollout and are not
        // silently inherited from Staging.
        if($write&&wp_get_environment_type()==='production')
            return self::denied('production_write_not_enabled');
        if(!function_exists('wp_get_ability')
            ||!wp_get_ability(self::COMMANDS[$operation]))
            return self::denied('ability_not_registered');
        return array('actor_user_id'=>(int)$actor->ID,
            'ability'=>self::COMMANDS[$operation],'write'=>$write,
            'site_origin'=>rtrim(home_url(),'/'));
    }
    public static function execute($operation,$input){
        $authority=self::preflight($operation);
        if(is_wp_error($authority))return $authority;
        if(!is_array($input))return self::denied('input_not_object');
        $encoded=wp_json_encode($input,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(!is_string($encoded)||strlen($encoded)>65536)
            return self::denied('payload_limit');
        $ability=wp_get_ability($authority['ability']);
        $digest=hash('sha256',$encoded);
        $summary=array('transport'=>'vps_wpcli_fallback',
            'ability'=>$authority['ability'],
            'actor_wp_user_id'=>$authority['actor_user_id'],
            'payload_sha256'=>$digest,'job_id'=>(string)($input['job_id']??''),
            'operation'=>$operation,'chatgpt_oauth_attributed'=>false);
        if($authority['write']){
            if(!class_exists('MAD4B_SCP_Audit'))
                return self::denied('audit_unavailable');
            $intent=MAD4B_SCP_Audit::record('mad4b/hub-cli-fallback',$summary,'requested');
            if(is_wp_error($intent))return $intent;
        }
        $result=$ability->execute($input);
        if($authority['write']){
            $status=is_wp_error($result)?'failed':'ok';
            $receipt=MAD4B_SCP_Audit::record('mad4b/hub-cli-fallback',$summary,$status);
            if(is_wp_error($receipt)) {
                // The ability might already have committed; reconcile by
                // idempotency key instead of claiming it was rolled back.
                return new WP_Error('mad4b_hub_cli_fallback_audit_reconciliation_required',
                    'Execution result may be committed; inspect the job and audit before retry.',
                    array('ability_result_is_error'=>is_wp_error($result),
                        'original_error'=>is_wp_error($result)?$result->get_error_code():''));
            }
        }
        if(is_wp_error($result))return $result;
        return array('contract'=>self::CONTRACT,
            'transport'=>'vps_wpcli_fallback',
            'chatgpt_oauth_verified'=>false,
            'actor_wp_user_id'=>$authority['actor_user_id'],
            'ability'=>$authority['ability'],
            'input_sha256'=>$digest,'result'=>$result);
    }
    public static function run($args,$assoc){
        if(count($args)!==1||!empty($assoc))
            WP_CLI::error('Use one allowlisted operation and JSON on STDIN; pass --user globally.');
        $operation=(string)$args[0];
        $check=self::preflight($operation);
        if(is_wp_error($check))
            WP_CLI::error($check->get_error_code());
        $body=stream_get_contents(STDIN,65537);
        if(!is_string($body)||strlen($body)>65536)
            WP_CLI::error('Input exceeds 64 KiB.');
        $input=trim($body)===''?array():json_decode($body,true);
        if(!is_array($input)||array_is_list($input)&&count($input)>0)
            WP_CLI::error('Expected a JSON object.');
        $result=self::execute($operation,$input);
        if(is_wp_error($result))
            WP_CLI::error($result->get_error_code());
        WP_CLI::line(wp_json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
}
