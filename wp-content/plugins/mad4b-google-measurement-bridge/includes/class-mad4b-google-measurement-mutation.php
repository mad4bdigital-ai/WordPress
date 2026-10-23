<?php
if (!defined('ABSPATH')) exit;

/**
 * Bounded Google Measurement mutation lane.
 * Never self-approves: a separately reviewed MAD4B one-time-ticket authority
 * must explicitly implement mad4b_google_measurement_exact_write_authorized.
 * Supports GA4 dimension and GTM workspace creation only, with readback.
 */
final class MAD4B_Google_Measurement_Mutation {
    const CONTRACT = 'mad4b.google-measurement-write.v1';
    const ALLOWED = array('ga4_create_dimension','gtm_create_workspace');

    public static function canonical($input) {
        $action = isset($input['action']) ? (string)$input['action'] : '';
        $parent = isset($input['resource_id']) ? (string)$input['resource_id'] : '';
        $name = isset($input['name']) ? (string)$input['name'] : '';
        $parameter = isset($input['parameter_name']) ? (string)$input['parameter_name'] : '';
        $scope = isset($input['dimension_scope']) ? (string)$input['dimension_scope'] : 'EVENT';
        if (!in_array($action, self::ALLOWED, true)) return new WP_Error('mad4b_measurement_unknown_write','Operation not allowed.');
        if (strlen($name) < 2 || strlen($name) > 80 || preg_match('/[<>\r\n]/', $name))
            return new WP_Error('mad4b_measurement_invalid_name','Invalid display name.');
        if ($action === 'ga4_create_dimension') {
            if (!preg_match('~^properties/[0-9]+$~D', $parent) ||
                !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/D', $parameter) ||
                !in_array($scope,array('EVENT','ITEM'),true))
                return new WP_Error('mad4b_measurement_invalid_dimension','Invalid GA4 property or event parameter.');
        } else if (!preg_match('~^accounts/[0-9]+/containers/[0-9]+$~D', $parent)) {
            return new WP_Error('mad4b_measurement_invalid_container','Invalid GTM container.');
        }
        return array('action'=>$action,'resource_id'=>$parent,'name'=>$name,
            'parameter_name'=>$action==='ga4_create_dimension'?$parameter:'',
            'dimension_scope'=>$action==='ga4_create_dimension'?$scope:'');
    }

    public static function plan($input = array()) {
        if (!MAD4B_Google_Measurement_Bridge::gate())
            return new WP_Error('mad4b_measurement_denied','Owner site permission required.');
        $normalized = self::canonical($input);
        if (is_wp_error($normalized)) return $normalized;
        $profile = class_exists('MAD4B_SCP_Site_Profile') ? MAD4B_SCP_Site_Profile::status() : array();
        if (!is_array($profile) || empty($profile['origin_match']) ||
            empty($profile['environment_match']) || empty($profile['site_uuid']) ||
            empty($profile['profile_digest']))
            return new WP_Error('mad4b_measurement_site_not_bound','Exact MAD4B site profile required.');
        $binding = array('site_uuid'=>(string)$profile['site_uuid'],
            'profile_digest'=>(string)$profile['profile_digest'],
            'environment'=>(string)($profile['configured_environment'] ?? ''));
        $sha = hash('sha256',wp_json_encode(array('operation'=>$normalized,'site'=>$binding), JSON_UNESCAPED_SLASHES));
        return array('contract'=>self::CONTRACT,'operation'=>$normalized,'site'=>$binding,
            'plan_sha256'=>$sha,'write_executed'=>false,'publish_executed'=>false,
            'requires_separate_ticket'=>true,'dry_run'=>true);
    }
    private static function post($url, $payload, $scope) {
        $token = MAD4B_Google_Measurement_API::token($scope);
        if (is_wp_error($token)) return $token;
        $result = wp_remote_post($url,array('timeout'=>10,'redirection'=>0,
            'headers'=>array('Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json',
                'Accept'=>'application/json'),
            'body'=>wp_json_encode($payload)));
        unset($token);
        if (is_wp_error($result)) return new WP_Error('mad4b_measurement_write_transport','Google write transport failed.');
        $status=(int)wp_remote_retrieve_response_code($result);
        $body=(string)wp_remote_retrieve_body($result);
        if (strlen($body)>131072 || $status<200 || $status>=300)
            return new WP_Error('mad4b_measurement_write_rejected','Google write was rejected.',array('http_status'=>$status));
        $json=json_decode($body,true);
        if (!is_array($json)) return new WP_Error('mad4b_measurement_write_bad_reply','Unexpected provider response.');
        return $json;
    }
    public static function apply($input = array()) {
        if (!MAD4B_Google_Measurement_Bridge::gate() || !current_user_can('manage_options'))
            return new WP_Error('mad4b_measurement_write_denied','Owner authority required.');
        if (!function_exists('wp_get_environment_type') || wp_get_environment_type()!=='staging')
            return new WP_Error('mad4b_measurement_staging_only','Only staging writes are implemented.');
        $plan=self::plan($input);
        if (is_wp_error($plan)) return $plan;
        $expected=isset($input['expected_plan_sha256'])?(string)$input['expected_plan_sha256']:'';
        $ticket=isset($input['approval_ticket_id'])?(string)$input['approval_ticket_id']:'';
        if (!hash_equals($plan['plan_sha256'],$expected) ||
            !preg_match('/^[a-fA-F0-9-]{36}$/D',$ticket))
            return new WP_Error('mad4b_measurement_missing_exact_approval','Fresh plan and one-time ticket required.');
        $approved=apply_filters('mad4b_google_measurement_exact_write_authorized',false,
            $ticket,$plan,$input);
        // Broker must consume the ticket once, verify operation+site+Google
        // subject+resource IDs, and decline concurrent/replayed requests.
        if ($approved!==true)
            return new WP_Error('mad4b_measurement_write_authority_unavailable','Exact approval ticket was not verified.');
        $op=$plan['operation'];
        if ($op['action']==='ga4_create_dimension') {
            $endpoint=MAD4B_Google_Measurement_API::GA_ADMIN.$op['resource_id'].'/customDimensions';
            $result=self::post($endpoint,array('displayName'=>$op['name'],
                'parameterName'=>$op['parameter_name'],'scope'=>$op['dimension_scope']),
                MAD4B_Google_Measurement_Bridge::SCOPE_GA4_EDIT);
        } else {
            $endpoint=MAD4B_Google_Measurement_API::GTM.$op['resource_id'].'/workspaces';
            $result=self::post($endpoint,array('name'=>$op['name']),
                MAD4B_Google_Measurement_Bridge::SCOPE_GTM_EDIT);
        }
        if (is_wp_error($result)) return $result;
        return array('contract'=>self::CONTRACT,'operation'=>$op['action'],
            'created_resource'=>isset($result['name'])?(string)$result['name']:
                (isset($result['path'])?(string)$result['path']:''),
            'provider_acknowledged'=>true,'readback_verified'=>false,
            'publish_executed'=>false,'production_mutation'=>false);
    }
    public static function register() {
        if (!function_exists('wp_register_ability')) return;
        $input = array('type'=>'object','additionalProperties'=>false,
            'required'=>array('action','resource_id','name'),
            'properties'=>array(
                'action'=>array('type'=>'string','enum'=>self::ALLOWED),
                'resource_id'=>array('type'=>'string','maxLength'=>128),
                'name'=>array('type'=>'string','maxLength'=>80),
                'parameter_name'=>array('type'=>'string','maxLength'=>40),
                'dimension_scope'=>array('type'=>'string','enum'=>array('EVENT','ITEM')),
                'expected_plan_sha256'=>array('type'=>'string','pattern'=>'^[a-f0-9]{64}$'),
                'approval_ticket_id'=>array('type'=>'string','maxLength'=>36)
            ));
        foreach (array('mad4b/google-measurement-write-plan'=>'plan','mad4b/google-measurement-write-apply'=>'apply') as $name=>$callback) {
            if (function_exists('wp_has_ability') && wp_has_ability($name)) continue;
            $read=($callback==='plan');
            wp_register_ability($name,array(
                'label'=>$name,'description'=>'Exact-bound Google Measurement staged plan or separately approved staging mutation.',
                'category'=>$read?'mad4b-read':'mad4b-admin',
                'execute_callback'=>array(__CLASS__,$callback),
                'permission_callback'=>array('MAD4B_Google_Measurement_Bridge','gate'),
                'input_schema'=>$input,'output_schema'=>array('type'=>'object','additionalProperties'=>true),
                'meta'=>array('public'=>false,'show_in_rest'=>false,
                    'mcp'=>array('public'=>false,'type'=>'tool','surface'=>$read?'read':'write','production_mutation_allowed'=>false),
                    'annotations'=>array('readonly'=>$read,'destructive'=>!$read,'idempotent'=>$read))
            ));
        }
    }
}
