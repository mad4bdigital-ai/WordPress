<?php
/** Original admin nav is presentation-only, not a bypass around manage_options. */
define('ABSPATH',__DIR__.'/');
class WP_Error{function __construct($c,$m='',$d=[]){}}
function __($s,$domain=''){return $s;}
function current_user_can($cap){return $GLOBALS['can_manage'];}
function nav_check($yes,$why){if(!$yes){fwrite(STDERR,'FAIL '.$why.PHP_EOL);exit(1);}$GLOBALS['checks']=($GLOBALS['checks']??0)+1;}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-admin-navigation-manifest.php';
$c='MAD4B_SCP_Admin_Navigation_Manifest';
$rows=$c::menu_rows();
nav_check(count($rows)>=15 &&($rows['mad4b-control-plane']['capability']??null)==='manage_options',
 'Original menu manifest preserves dynamic native parent/child capability labels');
foreach($rows as $row){
 nav_check(($row['capability']??null)==='manage_options',
  'No original admin route is exposed without WordPress manage_options');
}
$base=[['Other','manage_options','other-plugin-menu'],
 ['Manage','manage_options','mad4b-control-plane-chatgpt']];
foreach([new WP_Error('cap_unknown'),1,'true',null,false] as $bad){
 $GLOBALS['can_manage']=$bad;$GLOBALS['submenu']=[$c::PARENT=>$base];
 $c::normalize_submenus();
 nav_check($GLOBALS['submenu'][$c::PARENT]===$base,
  'Unverified native WordPress capability must not touch admin navigation');
}
$GLOBALS['can_manage']=true;$GLOBALS['submenu']=[$c::PARENT=>$base];
$c::normalize_submenus();
nav_check($GLOBALS['submenu'][$c::PARENT][0][2]==='mad4b-control-plane-chatgpt'
 &&$GLOBALS['submenu'][$c::PARENT][1][2]==='other-plugin-menu',
 'Verified navigation sort preserves unrelated plugin menu without executing it');
echo 'PASS: ADMIN_NAVIGATION_MANIFEST_ORIGINAL '.($GLOBALS['checks']??0).' original WordPress admin route/source tests'.PHP_EOL;
