<?php
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(1);
wp_set_current_user(1);
require_once ABSPATH.'wp-admin/includes/plugin.php';
require_once ABSPATH.'wp-admin/includes/template.php';
$cls='MAD4B_Guide_Bulk_Classification_V1';
if(!$cls::permitted())WP_CLI::error('Owner blocked from guide bulk review');
$cls::adminMenu();
global $submenu;
$found=false;
foreach(($submenu['edit.php?post_type=tours-and-activities']??[]) as $line){
 if(($line[2]??'')===$cls::PAGE){$found=true;break;}
}
if(!$found)WP_CLI::error('Guide bulk menu not registered');
ob_start();$cls::page();$html=ob_get_clean();
if(!str_contains($html,'mad4b-guide-bulk-app')||
 !str_contains($html,'mad4b-guide-bulk-high')||
 !str_contains($html,'mad4b-guide-list'))WP_CLI::error('Bulk UI missing core controls');
WP_CLI::log('PASS admin_menu_and_html_controls');
if(!has_action('wp_ajax_'.$cls::AJAX_INDEX) ||
 !has_action('wp_ajax_'.$cls::AJAX_DETAIL) ||
 !has_action('wp_ajax_'.$cls::AJAX_APPROVE))
 WP_CLI::error('Guide bulk AJAX actions not registered');
WP_CLI::log('PASS AJAX handlers registered');
$script='/var/www/html/wp-content/mu-plugins/assets/mad4b-guide-bulk-v1.js';
if(!is_readable($script)||filesize($script)<1000)WP_CLI::error('JS asset missing');
WP_CLI::log('PASS editor javascript readable');
WP_CLI::success('GUIDE_BULK_ADMIN_UI_VERIFIED');
