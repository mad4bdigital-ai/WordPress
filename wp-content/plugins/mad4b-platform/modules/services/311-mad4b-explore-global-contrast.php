<?php
/**
 * Plugin Name: MAD4B Global-Color CTA Contrast Fix (Staging)
 * Description: Scope Explorer CTA normal-text contrast to Elementor global primary, preserving hover and global typography.
 * Version: 0.1.0-staging
 */
defined('ABSPATH')||exit;
add_action('wp_head',static function(){
    if(wp_get_environment_type()!=='staging'||is_admin()||wp_parse_url(home_url(),PHP_URL_HOST)!=='tours.dev.mad4b.com')return;
    $p=get_option('mad4b_scp_brand_context_profile_v1',[]);
    if(($p['site_uuid']??'')!=='e5e64bca-ed62-48d2-b2b3-dff693cd702a')return;
    if(empty(get_option('mad4b_explore_global_contrast_v1',[])['enabled']))return;
    // Normal state only. Hover/focus stays governed by the Elementor kit.
    echo '<style id="mad4b-global-cta-contrast" data-no-optimize="1">.elementor-element-40a11b6b a.elementor-button:not(:hover):not(:focus-visible),.elementor-element-40a11b6b a.elementor-button:not(:hover):not(:focus-visible) .elementor-button-text{color:var(--e-global-color-primary,#102D40)!important}</style>';
},90);
