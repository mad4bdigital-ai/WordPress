<?php
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'staging' ||
    untrailingslashit(home_url('/')) !== 'https://tours.dev.mad4b.com') {
    throw new RuntimeException('CENTRAL_SOURCE_STAGING_ONLY');
}
$pass = 0;
$check = static function ($ok, $label) use (&$pass) {
    if (!$ok) throw new RuntimeException('REGRESSION_FAILED_' . $label);
    ++$pass;
    echo 'PASS ' . $label . PHP_EOL;
};
$provider = 'MAD4B_Company_Native_Plugin_Install_Provider_V1';
$handler = 'MAD4B_Company_Native_Plugin_Install_Handler_V1';
$check(class_exists($provider, false), 'exact_provider_loaded');
$check(class_exists($handler, false), 'original_signed_handler_loaded');
$check(has_action('mad4b_original_native_operation_providers_register',
    [$provider, 'register']) !== false, 'original_provider_registration');
$registered = MAD4B_Original_Native_Operation_Providers_V1::profile('plugin.install');
$check(is_array($registered) &&
    ($registered['provider'] ?? null) === $provider::PROFILE_PROVIDER &&
    ($registered['authority_family'] ?? null) === 'original_305' &&
    ($registered['target_scope'] ?? null) === 'wordpress' &&
    ($registered['readback'] ?? null) === [$provider, 'readback'],
    'exact_operation_and_callback');
$discovered = MAD4B_Original_Native_Operation_Providers_V1::discover('plugin.install');
$check(($discovered['provider_count'] ?? 0) === 1, 'no_duplicate_provider');
$cap = $provider::readback();
$ready = ($cap['provider_ready'] ?? false) === true;
$check(($cap['native_305_issued'] ?? null) === false &&
    ($cap['signed_312_verified'] ?? null) === false &&
    ($cap['original_313_certified'] ?? null) === false &&
    ($cap['target_mutated'] ?? null) === false,
    'capability_not_execution_or_receipt');
$check($ready
    ? (($cap['state'] ?? null) === 'EXACT_312_PLUGIN_INSTALL_CAPABLE')
    : (!empty($cap['reason']) &&
       ($cap['state'] ?? null) === 'EXACT_312_PLUGIN_INSTALL_HANDLER_NOT_READY'),
    'capability_heartbeat_may_expire_without_authority_change');
$pre = MAD4B_Company_Native_Write_Executor_V1::preflight_status(
    'wayfatours', 'production', 'plugin.install');
$check(($pre['operation_provider_registered'] ?? false) === true,
    'operation_registered_in_original_core');
$check(($pre['execution_allowed'] ?? null) === false &&
    ($pre['original_native_job_submitted'] ?? null) === false &&
    ($pre['job_verified'] ?? null) === false &&
    ($pre['grant_created'] ?? null) === false &&
    ($pre['production_mutated'] ?? null) === false,
    'preflight_never_dispatches_or_mutates');
$check(($pre['operation_provider_ready'] ?? false) ===
    ($ready && ($pre['transport_provider_ready'] ?? false) === true),
    'readiness_tracks_current_live_capability');
echo 'VERDICT ' . $pass . '/' . $pass . ' PASS' . PHP_EOL;
