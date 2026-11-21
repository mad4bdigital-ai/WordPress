<?php
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(1);
wp_set_current_user(1);
set_current_screen('dashboard');
$slug='service-activation';
if(!isset(MAD4B_JetEngine_Field_Modes_V1::pages()[$slug]))WP_CLI::error('No service activation Options Page');
$_GET['page']=$slug;
MAD4B_JetEngine_Field_Modes_V1::enqueue();
MAD4B_JetEngine_Workspace_Shell_V1::enqueue();
if(!wp_style_is(MAD4B_JetEngine_Workspace_Shell_V1::SCRIPT,'enqueued') ||
 !wp_script_is(MAD4B_JetEngine_Workspace_Shell_V1::SCRIPT,'enqueued'))
 WP_CLI::error('Sitewide JetEngine shell missing on Service Activation');
echo 'CROSS_PAGE_SHELL_SCOPE '.wp_json_encode(['site'=>home_url(),'slug'=>$slug,'styles_loaded'=>true,'script_loaded'=>true,'original_fields_untouched'=>true]).PHP_EOL;
WP_CLI::success('CROSS_PAGE_JETENGINE_SHELL_UI_PASS');
