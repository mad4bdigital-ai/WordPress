<?php
/**
 * Plugin Name: MAD4B Google Measurement Bridge (Discovery)
 * Description: Read-only Brand Authority capability discovery for GA4/GTM and GTM4WP.
 * Version: 0.1.0
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;

/**
 * IMPORTANT: No Google API HTTP calls, credentials, consent exchange or mutation here.
 * Google Drive OAuth does not imply Analytics or Tag Manager scopes.
 * Future API operations must use an owner-authorized account-scoped broker.
 */
final class MAD4B_Google_Measurement_Bridge {
    const CONTRACT = 'mad4b.google-measurement-bridge.v1';
    const SCOPE_GA4_READ = 'https://www.googleapis.com/auth/analytics.readonly';
    const SCOPE_GA4_EDIT = 'https://www.googleapis.com/auth/analytics.edit';
    const SCOPE_GTM_READ = 'https://www.googleapis.com/auth/tagmanager.readonly';
    const SCOPE_GTM_EDIT = 'https://www.googleapis.com/auth/tagmanager.edit.containers';
    const SCOPE_GTM_PUBLISH = 'https://www.googleapis.com/auth/tagmanager.publish';

    public static function boot() {
        add_filter('mad4b_provider_connection_adapters', array(__CLASS__, 'providers'));
        MAD4B_Google_Measurement_Admin::boot();
        MAD4B_Google_Measurement_Reports::boot();
        add_filter('mad4b_google_measurement_broker_grant', array(__CLASS__, 'broker_grant'), 10, 2);
        add_action('wp_abilities_api_init', array(__CLASS__, 'register'), 45);
        add_action('wp_abilities_api_init', array('MAD4B_Google_Measurement_API', 'register'), 46);
        add_action('wp_abilities_api_init', array('MAD4B_Google_Measurement_Mutation', 'register'), 47);
        add_action('wp_abilities_api_init', array('MAD4B_Google_Measurement_Reports', 'register'), 48);
    }
    public static function providers($providers) {
        if (!is_array($providers)) $providers = array();
        $providers['google_measurement'] = array(
            'label' => 'Google Analytics 4 / Google Tag Manager',
            'kind' => 'oauth2',
            'status_callback' => array(__CLASS__, 'connection_status'),
            // No refresh_callback. Deliberately discover-only, no inherited write authority.
            'handoff_url' => admin_url('admin.php?page=mad4b-google-measurement'),
        );
        return $providers;
    }
    public static function gate() {
        if (!current_user_can('manage_options')) return false;
        if (class_exists('MAD4B_SCP_Policy') && !MAD4B_SCP_Policy::can_read()) return false;
        return true;
    }
    public static function broker_grant($existing, $scope) {
        if (!class_exists('MAD4B_SCP_Google_Drive_Context') ||
            !method_exists('MAD4B_SCP_Google_Drive_Context', 'measurement_broker_grant_for_scope'))
            return $existing;
        $grant = MAD4B_SCP_Google_Drive_Context::measurement_broker_grant_for_scope($scope);
        return is_array($grant) ? $grant : $existing;
    }
    public static function connection_status() {
        $grants = class_exists('MAD4B_SCP_Google_Drive_Context')
            ? MAD4B_SCP_Google_Drive_Context::workspace_grants_status() : array();
        $selection = isset($grants['selection']) && is_array($grants['selection']) ? $grants['selection'] : array();
        $ga_mode = isset($selection['analytics']) ? $selection['analytics'] : 'off';
        $gtm_mode = isset($selection['tag_manager']) ? $selection['tag_manager'] : 'off';
        $configured = !empty($grants['configured']) && ($ga_mode !== 'off' || $gtm_mode !== 'off');
        $connected = $configured && !empty($grants['connected_grant_present'])
            && empty($grants['missing_scope_count']);
        return array(
            'contract'=>self::CONTRACT,'provider'=>'google_measurement',
            'connected'=>$connected,
            'reason'=>$connected?'scope_projection_present':($configured?'owner_reconsent_or_missing_scopes':'select_measurement_scopes'),
            'oauth_state_independently_verified'=>false,
            'drive_grant_implies_access'=>false,
            'mutation_supported'=>false,
            'analytics_mode'=>$ga_mode,
            'tag_manager_mode'=>$gtm_mode,
            'account_selection_required'=>true,
            'granted_scope_coverage'=>$connected,
            'scopes'=>array('analytics_read'=>self::SCOPE_GA4_READ,'tagmanager_read'=>self::SCOPE_GTM_READ),
        );
    }
    public static function inventory() {
        if (!self::gate()) return new WP_Error('mad4b_measurement_permission_denied', 'Read authority required.');
        $gtm4wp_audit=MAD4B_Google_Measurement_GTM4WP::audit();
        return array(
            'contract' => self::CONTRACT,
            'google' => self::connection_status(),
            'resource_binding' => MAD4B_Google_Measurement_Admin::binding(),
            'site' => array('home_host'=>wp_parse_url(home_url('/'), PHP_URL_HOST)),
            'gtm4wp' => $gtm4wp_audit,
            'capabilities'=>array(
                'ga4_property_discovery'=>'oauth_scope_required',
                'ga4_reports'=>'implemented_requires_authorized_broker_and_binding',
                'ga4_custom_dimensions'=>'staging_exact_ticket_required_for_create',
                'ga4_audience_listing'=>'implemented_requires_analytics_readonly',
                'ga4_custom_reporting'=>'implemented_bounded_runreport_and_presets',
                'gtm_container_discovery'=>'oauth_scope_required',
                'gtm_workspace_plan'=>'implemented_requires_exact_resource_binding',
                'gtm_workspace_create'=>'staging_exact_ticket_required',
                'gtm_import_publish'=>'not_enabled',
                'brand_authority_binding'=>'explicit_site_and_account_selection',
            ),
            'read_only'=>true, 'production_mutation'=>false,
        );
    }
    public static function plan($input = array()) {
        if (!self::gate()) return new WP_Error('mad4b_measurement_permission_denied', 'Read authority required.');
        if (!is_array($input)) $input = array();
        $mode = isset($input['mode']) ? sanitize_key($input['mode']) : 'read';
        if (!in_array($mode, array('read','draft','publish'), true)) return new WP_Error('mad4b_invalid_measurement_mode','Invalid operation mode.');
        $grants = $mode === 'read' ? array(self::SCOPE_GA4_READ, self::SCOPE_GTM_READ)
            : ($mode === 'draft' ? array(self::SCOPE_GA4_EDIT,self::SCOPE_GTM_EDIT)
            : array(self::SCOPE_GTM_PUBLISH));
        $connection=self::connection_status();
        $binding=MAD4B_Google_Measurement_Admin::binding();
        $ready=!empty($connection['connected']) && !empty($binding['ready']);
        return array(
            'contract'=>self::CONTRACT, 'mode'=>$mode,
            'required_scopes'=>$grants,
            'actions'=>array('owner_google_consent','select_ga4_property','select_gtm_account_container_workspace','bind_exact_site_profile','review_existing_gtm4wp_event_ownership','evaluate_consent','preview_plan','approve_exact_write'),
            'blocked'=>!$ready, 'reason'=>$ready?'staging_exact_approval_required_for_mutation':'google_owner_scope_or_resource_binding_missing',
            'write_performed'=>false, 'production_authorized'=>false,
        );
    }
    public static function register() {
        if (!function_exists('wp_register_ability')) return;
        $abilities = array(
            'mad4b/google-measurement-inventory' => array('callback'=>'inventory','schema'=>array('type'=>'object','additionalProperties'=>false)),
            'mad4b/google-measurement-plan' => array('callback'=>'plan','schema'=>array('type'=>'object','properties'=>array('mode'=>array('type'=>'string','enum'=>array('read','draft','publish'))),'additionalProperties'=>false)),
        );
        foreach ($abilities as $name=>$a) {
            if (function_exists('wp_has_ability') && wp_has_ability($name)) continue;
            wp_register_ability($name,array(
                'label'=>$name, 'description'=>'Read-only measurement inventory or plan, never a Google API write.',
                'category'=>'mad4b-read','execute_callback'=>array(__CLASS__,$a['callback']),
                'permission_callback'=>array(__CLASS__,'gate'),
                'input_schema'=>$a['schema'],
                'output_schema'=>array('type'=>'object','additionalProperties'=>true),
                'meta'=>array('public'=>false,'show_in_rest'=>false,'mcp'=>array('public'=>false,'type'=>'tool','surface'=>'read'),
                    'annotations'=>array('readonly'=>true,'destructive'=>false,'idempotent'=>true)),
            ));
        }
    }
}
require_once __DIR__ . '/includes/class-mad4b-google-measurement-api.php';
require_once __DIR__ . '/includes/class-mad4b-google-measurement-mutation.php';
require_once __DIR__ . '/includes/class-mad4b-google-measurement-admin.php';
require_once __DIR__ . '/includes/class-mad4b-google-measurement-gtm4wp.php';
require_once __DIR__ . '/includes/class-mad4b-google-measurement-reports.php';
add_action('plugins_loaded', array('MAD4B_Google_Measurement_Bridge','boot'), 20);
