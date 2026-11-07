<?php
/**
 * Plugin Name: MAD4B Wayfatours — Unified Destination Presentation
 * Description: Read-only portable Elementor + JetEngine destination cards and hierarchy adapter.
 * Version: 0.2.0
 */
defined('ABSPATH') || exit;
if (!defined('MAD4B_TENANT_ORIGIN') ||
    !defined('MAD4B_TENANT_SITE_UUID') ||
    !defined('MAD4B_TENANT_ENVIRONMENT') ||
    !defined('MAD4B_TENANT_COMPANY_CODE') ||
    !class_exists('MAD4B_Portable_MU_Context', false)) return;

/** This is not a commerce, authentication, policy or remote-command grant. */
const MAD4B_DESTINATION_UNIFIED_MODE = 'live';
function mad4b_wayfa_destination_unified_enabled(): bool {
    if (is_admin() || !is_tax('destinations')) return false;
    if (wp_get_environment_type() !== MAD4B_TENANT_ENVIRONMENT) return false;
    if (rtrim((string)get_option('home'),'/') !== rtrim((string)MAD4B_TENANT_ORIGIN,'/')) return false;
    if (!class_exists('MAD4B_SCP_Site_Profile') ||
        !method_exists('MAD4B_SCP_Site_Profile','configured') ||
        !MAD4B_SCP_Site_Profile::configured() ||
        MAD4B_SCP_Site_Profile::site_uuid() !== MAD4B_TENANT_SITE_UUID) return false;
    if (MAD4B_DESTINATION_UNIFIED_MODE === 'live') return true;
    // Canary is presentation-only; it cannot authorize any write.
    return MAD4B_DESTINATION_UNIFIED_MODE === 'canary'
        && (string)($_GET['mad4b_destination_canary']??'') === '20261107';
}
if (!class_exists('Shared_Product_Destination_Term_Presentation_v1',false)) {
    require_once __DIR__ . '/mad4b-destination-unified/presentation-destination-term-v1.php';
}
require_once __DIR__ . '/mad4b-destination-unified/registry-destination-presentation-ui.php';
