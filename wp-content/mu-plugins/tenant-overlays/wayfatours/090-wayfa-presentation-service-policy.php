<?php
/**
 * Plugin Name: Wayfa Native Presentation Service Policy
 * Description: Standalone read-only listing eligibility without central company executor.
 */
defined('ABSPATH') || exit;
if (!class_exists('MAD4B_Tenant_Service_Identity_Boundary_V1',false) || !MAD4B_Tenant_Service_Identity_Boundary_V1::eligible()) return;
if (!function_exists('service_channel_allowed')) {
    function service_channel_allowed($key,$channel): bool {
        if (!in_array($channel,['standalone','tour_component','destination_discovery'],true))return false;
        $owners=['tours'=>'tours','properties'=>'properties','transportations'=>'transportations','visas'=>'visas'];
        $owner=$owners[(string)$key]??null;
        if (!$owner)return false;
        $settings=get_option('service-activation',[]);
        if (!is_array($settings))return false;
        $mode=$settings[$owner.'_channel_mode']??($settings['service_channel_modes'][$owner]??'');
        if ($mode==='')$mode=in_array($owner,(array)($settings['active_services']??[]),true)
            ?'standalone_and_tour':'disabled';
        return $mode==='standalone_and_tour'
            || ($mode==='tour_only'&&in_array($channel,['tour_component','destination_discovery'],true));
    }
}
