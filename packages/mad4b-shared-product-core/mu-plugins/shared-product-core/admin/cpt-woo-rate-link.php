<?php
/**
 * Bridge native CPTWoo setup to existing WooCommerce Rate Generation.
 * Only navigation and read-only configuration status; no vendor plugin patches.
 */
if(!defined('ABSPATH')||wp_get_environment_type()!=='local'||!is_admin())return;
add_action('admin_menu',static function(){
 if(!current_user_can('manage_woocommerce')||!function_exists('cptwooint'))return;
 $target=admin_url('admin.php?page=wc-settings&tab=rate_generation');
 add_submenu_page('cptwooint-admin','Rate Generation','Rate Generation',
  'manage_woocommerce',$target);
},110);
add_action('woocommerce_settings_tabs_rate_generation',static function(){
 if(!current_user_can('manage_woocommerce'))return;
 $expected=['tour-rates','property-rates','transport-rates'];
 $options=get_option('cptwooint_settings',[]);
 $selected=array_keys($options['selected_post_types']??[]);
 $missing=array_diff($expected,$selected);
 $link=admin_url('admin.php?page=cptwooint-admin');
 echo '<p><strong>CPT WooCommerce Integration:</strong> ';
 if(!$missing)echo 'All three Rate CPTs share the original WordPress Post ID with WooCommerce.';
 else echo 'Missing Rate mappings: '.esc_html(implode(', ',$missing)).'.';
 echo ' <a href="'.esc_url($link).'">Manage CPT Product Integration</a>.</p>';
 echo '<p>New property/transport Rate products remain quote-only until verified approval, inventory and Checkout integration. Generating a Draft does not publish or enable payment.</p>';
},5);
