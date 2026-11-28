<?php
/** Read-only REST review extension. No grant mutation or authority issuance. */
if (!defined('ABSPATH')) exit;
final class MAD4B_AI_Grant_REST_Review {
    public static function permission() {
        return get_current_user_id() > 0 && current_user_can('manage_options')
            && class_exists('MAD4B_SCP_Site_Profile')
            && MAD4B_SCP_Site_Profile::user_is_enrolled(get_current_user_id());
    }
    public static function register() {
        foreach (array('/status', '/readback') as $suffix) {
            register_rest_route('mad4b/v1', '/ai-grant-mode'.$suffix, array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array(__CLASS__, 'status'),
                'permission_callback' => array(__CLASS__, 'permission'),
            ));
        }
        register_rest_route('mad4b/v1', '/ai-grant-mode/plan', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'plan'),
            'permission_callback' => array(__CLASS__, 'permission'),
            'args' => array('mode'=>array('type'=>'string','required'=>true,'enum'=>array('off','ai_agent_only'))),
        ));
    }
    public static function status() {
        if (!self::permission()) return new WP_Error('mad4b_rest_owner_required','Enrolled owner required.',array('status'=>403));
        if (!class_exists('MAD4B_SCP_AI_Grant_Mode')) return new WP_Error('mad4b_rest_provider_unavailable','Original provider unavailable.',array('status'=>503));
        return array('contract'=>'mad4b.ai-grant-rest-review.v1','status'=>MAD4B_SCP_AI_Grant_Mode::status(),'authorizing'=>false,'mutation_performed'=>false);
    }
    public static function plan($request) {
        $status=self::status();
        if (is_wp_error($status)) return $status;
        $mode=$request->get_param('mode');
        if (!in_array($mode,array('off','ai_agent_only'),true)) return new WP_Error('mad4b_rest_invalid_mode','Invalid mode.',array('status'=>400));
        return array('contract'=>'mad4b.ai-grant-rest-review.v1','requested_mode'=>$mode,'status'=>$status['status'],'owner_action_url'=>admin_url('options-general.php?page=mad4b-ai-routing'),'owner_confirmation_required'=>true,'authorizing'=>false,'mutation_performed'=>false);
    }
}
add_action('rest_api_init',array('MAD4B_AI_Grant_REST_Review','register'));
