<?php
if (!defined('ABSPATH')) exit;

/**
 * Read-only GTM4WP dataLayer ownership preflight.
 * It reports potential publishers, not confirmed duplicate conversions.
 */
final class MAD4B_Google_Measurement_GTM4WP {
    const CONTRACT='mad4b.google-measurement.gtm4wp-audit.v1';
    public static function audit() {
        $active=(array)get_option('active_plugins',array());
        $gtm4wp=false; $providers=array();
        $patterns=array(
            'gtm4wp'=>array('gtm4wp','duracelltomi-google-tag-manager'),
            'site_kit'=>array('google-site-kit'),
            'woocommerce_google'=>array('woocommerce-google-analytics-integration','google-listings-and-ads'),
            'facebook_woocommerce'=>array('facebook-for-woocommerce'),
            'pixels'=>array('pixelyoursite','pixel-your-site'),
            'snippets'=>array('wpcode','code-snippets'),
        );
        foreach ($active as $plugin) {
            $path=strtolower((string)$plugin);
            foreach ($patterns as $provider=>$patterns_for_provider) {
                foreach ($patterns_for_provider as $hint) {
                    if (strpos($path,$hint)!==false) {
                        $providers[$provider]=true;
                        if ($provider==='gtm4wp') $gtm4wp=true;
                        break;
                    }
                }
            }
        }
        // GTM4WP option name varies by version. Only return a validated,
        // public container ID; do not return complete plugin settings.
        $options=get_option('gtm4wp-options',array());
        $public_id='';
        if (is_array($options)) {
            foreach (array('gtm-code','gtm_code','container_id') as $key) {
                if (!empty($options[$key]) && is_string($options[$key]) &&
                    preg_match('/^GTM-[A-Z0-9]{5,20}$/D',strtoupper(trim($options[$key])))) {
                    $public_id=strtoupper(trim($options[$key])); break;
                }
            }
        }
        return array(
            'contract'=>self::CONTRACT,
            'gtm4wp_active'=>$gtm4wp,
            'configured_public_container'=>$public_id,
            'possible_publishers'=>array_keys($providers),
            'duplicate_events_confirmed'=>false,
            'source_ownership'=>array(
                'gtm_bootstrap'=>'gtm4wp_when_active',
                'tour_business_events'=>'universal_tracking_companion',
                'woocommerce_ecommerce'=>'choose_one_after_live_dataLayer_audit',
                'lead_conversion'=>'choose_one_tag_per_platform_after_preview'
            ),
            'next_checks'=>array('compare_public_container_with_authenticated_gtm_account',
                'inspect_gtm4wp_woocommerce_options','inspect_tag_assistant_event_firing',
                'check_consent_mode','check_ga4_and_meta_conversion_sources'),
            'read_only'=>true
        );
    }
}
