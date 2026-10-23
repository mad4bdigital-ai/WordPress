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
        add_action('wp_abilities_api_init', array(__CLASS__, 'register'), 45);
        add_action('wp_abilities_api_init', array('MAD4B_Google_Measurement_API', 'register'), 46);
        add_action('wp_abilities_api_init', array('MAD4B_Google_Measurement_Mutation', 'register'), 47);
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
    public static function connection_status() {
        return array(
            'contract' => self::CONTRACT,
            'provider' => 'google_measurement',
            'connected' => false,
            'reason' => 'measurement_oauth_binding_not_implemented',
            'drive_grant_implies_access' => false,
            'mutation_supported' => false,
            'account_selection_required' => true,
            'scopes' => array('analytics_read'=>self::SCOPE_GA4_READ,'tagmanager_read'=>self::SCOPE_GTM_READ),
        );
    }
    public static function inventory() {
        if (!self::gate()) return new WP_Error('mad4b_measurement_permission_denied', 'Read authority required.');
        $gtm4wp_active = (function_exists('is_plugin_active') && is_plugin_active('duracelltomi-google-tag-manager/duracelltomi-google-tag-manager-for-wordpress.php'))
            || (function_exists('is_plugin_active') && is_plugin_active('gtm4wp/gtm4wp.php'));
        if (!$gtm4wp_active) {
            foreach ((array)get_option('active_plugins', array()) as $name) {
                if (stripos((string)$name, 'duracelltomi-google-tag-manager') !== false || stripos((string)$name, 'gtm4wp') !== false) {
                    $gtm4wp_active = true; break;
                }
            }
        }
        return array(
            'contract' => self::CONTRACT,
            'google' => self::connection_status(),
            'resource_binding' => MAD4B_Google_Measurement_Admin::binding(),
            'site' => array('home_host'=>wp_parse_url(home_url('/'), PHP_URL_HOST)),
            'gtm4wp' => array('active'=>$gtm4wp_active, 'event_source_policy'=>'gtm4wp_or_companion_not_both'),
            'capabilities' => array(
                'ga4_property_discovery'=>'needs_measurement_oauth',
                'ga4_reports'=>'needs_measurement_oauth',
                'ga4_custom_dimensions'=>'needs_analytics_edit_and_approval',
                'gtm_container_discovery'=>'needs_measurement_oauth',
                'gtm_workspace_plan'=>'needs_measurement_oauth',
                'gtm_import_publish'=>'disabled_without_exact_approval',
                'brand_authority_binding'=>'requires_explicit_account_and_site_profile',
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
        return array(
            'contract'=>self::CONTRACT, 'mode'=>$mode,
            'required_scopes'=>$grants,
            'actions'=>array('owner_google_consent','select_ga4_property','select_gtm_account_container_workspace','bind_exact_site_profile','review_existing_gtm4wp_event_ownership','evaluate_consent','preview_plan','approve_exact_write'),
            'blocked'=>true, 'reason'=>'measurement_oauth_binding_not_implemented',
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
add_action('plugins_loaded', array('MAD4B_Google_Measurement_Bridge','boot'), 20);
