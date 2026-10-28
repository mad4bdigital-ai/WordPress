<?php
/** ETG Woo Lab: prevent all outbound email and scheduled tasks on this isolated clone. */
if (!defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) { return; }
// Mail and cron sandbox gates apply only inside the Local development lab.
// Staging email admission is owned by 005-mad4b-staging-mail-admission.php;
// Staging and Production cron are governed by their respective schedulers.
// The commerce purchase/payment safety gates below stay intact on lab clones.
if (function_exists('wp_get_environment_type') && wp_get_environment_type() === 'local') {
    add_filter('pre_wp_mail', static function () { return false; }, PHP_INT_MAX);
    add_filter('cron_request', static function ($request) {
        $request['url'] = '';
        $request['args']['blocking'] = false;
        return $request;
    }, PHP_INT_MAX);
}

// The lab is not allowed to accept purchases or payments.
add_filter('woocommerce_is_purchasable', static function () { return false; }, PHP_INT_MAX);
add_filter('woocommerce_available_payment_gateways', static function () { return array(); }, PHP_INT_MAX);
