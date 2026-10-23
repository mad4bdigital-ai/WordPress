<?php
if (!defined('ABSPATH')) exit;

/**
 * Authenticated, explicitly injected Google Measurement API read adapter.
 * This class never stores credentials, accepts no caller-supplied URL and never
 * treats a Google Drive access grant as an Analytics/Tag Manager grant.
 */
final class MAD4B_Google_Measurement_API {
    const GA_ADMIN = 'https://analyticsadmin.googleapis.com/v1beta/';
    const GA_DATA = 'https://analyticsdata.googleapis.com/v1beta/';
    const GTM = 'https://tagmanager.googleapis.com/tagmanager/v2/';
    const MAX_RESPONSE = 1048576;

    public static function token($scope) {
        // Broker must be registered by a separate owner-approved Google App
        // Connection. Only the trusted broker may supply the token in memory.
        $grant = apply_filters('mad4b_google_measurement_broker_grant', null, $scope);
        if (!is_array($grant) || empty($grant['access_token']) ||
            empty($grant['scopes']) || !is_array($grant['scopes']) ||
            !in_array($scope, $grant['scopes'], true) ||
            empty($grant['subject_binding_verified']) ||
            empty($grant['site_binding_verified'])) {
            return new WP_Error('mad4b_measurement_google_consent_required',
                'Connect the owner Google account for this exact site, provider and scope.');
        }
        $token = (string)$grant['access_token'];
        if (strlen($token) > 8192 || preg_match('/[\r\n]/', $token)) {
            return new WP_Error('mad4b_measurement_invalid_grant','Invalid broker credential.');
        }
        return $token;
    }
    private static function get($base, $path, $scope, $query = array()) {
        if (!in_array($base, array(self::GA_ADMIN,self::GA_DATA,self::GTM), true) ||
            !preg_match('~^[A-Za-z0-9_./-]+$~D', $path)) {
            return new WP_Error('mad4b_measurement_bad_path','Path rejected.');
        }
        $token = self::token($scope);
        if (is_wp_error($token)) return $token;
        $url = $base . $path;
        if ($query) $url = add_query_arg($query, $url);
        $r = wp_remote_get($url, array(
            'timeout'=>8,'redirection'=>0,
            'headers'=>array('Authorization'=>'Bearer '.$token,'Accept'=>'application/json')
        ));
        unset($token);
        if (is_wp_error($r)) return new WP_Error('mad4b_measurement_transport_error','Google API transport unavailable.');
        $status = (int)wp_remote_retrieve_response_code($r);
        $body = (string)wp_remote_retrieve_body($r);
        if (strlen($body) > self::MAX_RESPONSE) return new WP_Error('mad4b_measurement_response_too_large','Response budget exceeded.');
        if ($status !== 200) {
            return new WP_Error('mad4b_measurement_google_api_error','Google API call was not successful.',array('http_status'=>$status));
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) return new WP_Error('mad4b_measurement_bad_response','Unexpected Google JSON response.');
        return $decoded;
    }
    public static function execute($input = array()) {
        if (!MAD4B_Google_Measurement_Bridge::gate())
            return new WP_Error('mad4b_measurement_permission_denied','Site read authority required.');
        $action = isset($input['action']) ? (string)$input['action'] : '';
        $id = isset($input['resource_id']) ? (string)$input['resource_id'] : '';
        $page = isset($input['page_token']) ? (string)$input['page_token'] : '';
        if (strlen($page) > 250 || ($page && !preg_match('/^[A-Za-z0-9_=-]+$/D', $page)))
            return new WP_Error('mad4b_measurement_invalid_page_token','Invalid page token.');
        $q = array('pageSize'=>50);
        if ($page !== '') $q['pageToken'] = $page;
        switch ($action) {
            case 'ga4_accounts':
                return self::get(self::GA_ADMIN,'accounts',MAD4B_Google_Measurement_Bridge::SCOPE_GA4_READ,$q);
            case 'ga4_properties':
                if (!preg_match('/^accounts\/([0-9]+)$/D', $id)) return self::invalid();
                return self::get(self::GA_ADMIN,'properties',MAD4B_Google_Measurement_Bridge::SCOPE_GA4_READ,array('filter'=>'parent:'.$id,'pageSize'=>50));
            case 'ga4_custom_dimensions':
                if (!preg_match('/^properties\/([0-9]+)$/D', $id)) return self::invalid();
                return self::get(self::GA_ADMIN,$id.'/customDimensions',MAD4B_Google_Measurement_Bridge::SCOPE_GA4_READ,$q);
            case 'ga4_key_events':
                if (!preg_match('/^properties\/([0-9]+)$/D', $id)) return self::invalid();
                return self::get(self::GA_ADMIN,$id.'/keyEvents',MAD4B_Google_Measurement_Bridge::SCOPE_GA4_READ,$q);
            case 'gtm_accounts':
                return self::get(self::GTM,'accounts',MAD4B_Google_Measurement_Bridge::SCOPE_GTM_READ,$q);
            case 'gtm_containers':
                if (!preg_match('/^accounts\/([0-9]+)$/D', $id)) return self::invalid();
                return self::get(self::GTM,$id.'/containers',MAD4B_Google_Measurement_Bridge::SCOPE_GTM_READ,$q);
            case 'gtm_workspaces':
                if (!preg_match('/^accounts\/[0-9]+\/containers\/[0-9]+$/D', $id)) return self::invalid();
                return self::get(self::GTM,$id.'/workspaces',MAD4B_Google_Measurement_Bridge::SCOPE_GTM_READ,$q);
            case 'gtm_tags':
            case 'gtm_triggers':
            case 'gtm_variables':
                if (!preg_match('/^accounts\/[0-9]+\/containers\/[0-9]+\/workspaces\/[0-9]+$/D', $id)) return self::invalid();
                $suffix = array('gtm_tags'=>'tags','gtm_triggers'=>'triggers','gtm_variables'=>'variables');
                return self::get(self::GTM,$id.'/'.$suffix[$action],MAD4B_Google_Measurement_Bridge::SCOPE_GTM_READ,$q);
            default: return self::invalid();
        }
    }
    private static function invalid() { return new WP_Error('mad4b_measurement_invalid_resource','Explicit valid resource identity required.'); }
    public static function register() {
        if (!function_exists('wp_register_ability')) return;
        $name = 'mad4b/google-measurement-api-read';
        if (function_exists('wp_has_ability') && wp_has_ability($name)) return;
        wp_register_ability($name,array(
            'label'=>'MAD4B Google Measurement API Read',
            'description'=>'Read allowlisted Google Analytics Admin and Google Tag Manager resources using an externally attested owner consent broker.',
            'category'=>'mad4b-read','execute_callback'=>array(__CLASS__,'execute'),
            'permission_callback'=>array('MAD4B_Google_Measurement_Bridge','gate'),
            'input_schema'=>array('type'=>'object','additionalProperties'=>false,
                'required'=>array('action'),
                'properties'=>array(
                    'action'=>array('type'=>'string','enum'=>array('ga4_accounts','ga4_properties','ga4_custom_dimensions','ga4_key_events','gtm_accounts','gtm_containers','gtm_workspaces','gtm_tags','gtm_triggers','gtm_variables')),
                    'resource_id'=>array('type'=>'string','maxLength'=>150),
                    'page_token'=>array('type'=>'string','maxLength'=>250)
                )),
            'output_schema'=>array('type'=>'object','additionalProperties'=>true),
            'meta'=>array('public'=>false,'show_in_rest'=>false,'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
                'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true))
        ));
    }
}
