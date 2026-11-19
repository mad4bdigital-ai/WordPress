<?php
define('ABSPATH','/');
$GLOBALS['mock_hooks']=[];
$GLOBALS['mock_cap_write_calls']=0;
function add_action($event,$callback,$priority=10){
    $GLOBALS['mock_hooks'][$event][]=[$callback,$priority];
}
function get_role($slug){
    $GLOBALS['mock_cap_write_calls']++;
    throw new RuntimeException("Unexpected role access during boot");
}
$target=getenv('MAD4B_WORKSPACES_BOOT_FILE');
if (!$target || !is_file($target)) {
    fwrite(STDERR,"FIXTURE_TARGET_NOT_FOUND\n");exit(70);
}
require $target;
if (isset($GLOBALS['mock_hooks']['init'])) {
    fwrite(STDERR,"UNEXPECTED_INIT_HOOK\n");exit(71);
}
if (count($GLOBALS['mock_hooks']['admin_menu']??[])!==1
    || $GLOBALS['mock_cap_write_calls']!==0) {
    fwrite(STDERR,"ROLE_WRITE_OR_ADMIN_MENU_REGRESSION\n");exit(72);
}
echo "PASS_NO_ROLE_READ_OR_WRITE_ON_BOOT\n";
