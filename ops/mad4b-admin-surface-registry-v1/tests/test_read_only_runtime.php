<?php
define('ABSPATH','/');
$GLOBALS['h']=[];
function add_action($tag,$callback,$priority=10){$GLOBALS['h'][$tag][]=[$callback,$priority];}
function is_user_logged_in(){return true;}
function current_user_can($cap){return in_array($cap,['mad4b_manage_company_operations','edit_others_posts','mad4b_work_company_tasks','commerce_manage_pricing'],true);}
$source=getenv('MAD4B_ADMIN_SURFACE_SOURCE');
if (!$source||!is_file($source)) exit(71);
require $source;
$class='MAD4B_Admin_Surface_Registry_V1';
if (!$class::allowed('mad4b-company-workspaces','read')) exit(72);
if (!$class::allowed('mad4b-company-content-jobs','read')) exit(73);
if ($class::allowed('mad4b-company-content-jobs','write')) exit(74);
if ($class::allowed('mad4b-company-staff-roles','approve')) exit(75);
if ($class::allowed('unknown-plugin-global-settings','read')) exit(76);
if (count($GLOBALS['h']['admin_menu']??[])!==1) exit(77);
echo "PASS_REGISTRY_READ_ONLY_DEFAULT_DENY\n";
