<?php
if (!defined('ABSPATH')) exit;
/**
 * Native WordPress settings locator: no credential provider / vault / form.
 * It may report configuration booleans and point to the ORIGINAL owner UI.
 * All saves, key validation and database downloads remain WooCommerce-owned.
 */
final class MAD4B_SCP_Native_Settings_Locator {
    const CONTRACT='mad4b.native-settings-locator.v1';
    const MAXMIND='woocommerce/maxmind-geolocation';
    public static function status($target) {
        if (!is_string($target)||$target!==self::MAXMIND)
            return new WP_Error('mad4b_native_owner_unknown','Unknown native settings target.',array('status'=>404));
        if (!class_exists('MAD4B_SCP_Adaptive_Interaction_Broker',false)
            || !MAD4B_SCP_Adaptive_Interaction_Broker::permitted()
            || !current_user_can('manage_woocommerce'))
            return new WP_Error('mad4b_native_owner_permission_denied','Original owner permission required.',array('status'=>403));
        if (!class_exists('WooCommerce') || !function_exists('WC') || !WC()->integrations)
            return new WP_Error('mad4b_native_woocommerce_unavailable','WooCommerce not active.',array('status'=>503));
        $integration=WC()->integrations->get_integration('maxmind_geolocation');
        if (!is_object($integration) || !method_exists($integration,'get_database_service'))
            return new WP_Error('mad4b_native_maxmind_unavailable','WooCommerce MaxMind integration unavailable.',array('status'=>503));
        $db=$integration->get_database_service();
        $path=is_object($db)&&method_exists($db,'get_database_path')?$db->get_database_path():null;
        $has_db=is_string($path)&&is_file($path)&&is_readable($path);
        $has_key=(bool)$integration->get_option('license_key'); // Never return key or hash.
        $url=admin_url('admin.php?page=wc-settings&tab=integration&section=maxmind_geolocation');
        $scheme=wp_parse_url($url,PHP_URL_SCHEME);
        $transport=($scheme==='https' && is_ssl())
            ||(class_exists('MAD4B_SCP_Local_Transport_Policy',false)
                &&MAD4B_SCP_Local_Transport_Policy::loopback_enabled(rtrim(home_url(),'/')));
        return array(
            'contract'=>self::CONTRACT,'owner'=>'woocommerce',
            'target'=>self::MAXMIND,'native_settings_url'=>$url,
            'native_storage'=>'woocommerce_maxmind_geolocation_settings',
            'native_field'=>'woocommerce_maxmind_geolocation_license_key',
            'license_key_present'=>$has_key,'geoip_database_ready'=>$has_db,
            'next_action'=>$has_key?($has_db?'none':'verify_native_geoip_database'):'open_original_woocommerce_settings',
            'state'=>$has_key&&$has_db?'READY':($has_key?'WAITING_NATIVE_DATABASE':'WAITING_NATIVE_KEY'),
            'transport_attested'=>$transport,
            'plaintext_in_mcp'=>false,'secret_value_exposed'=>false,
            'secret_value_stored_by_broker'=>false,'native_write_performed'=>false,
            'mutation_authorized'=>false,'automatic_retry_authorized'=>false);
    }
}
