<?php
/** Hermetic hook registration validation: no WordPress DB, network or filesystem writes. */
define('ABSPATH','/tmp/');
$GLOBALS['scp_hooks']=[];
function add_action($name,$cb,$priority=10,$args=1) { $GLOBALS['scp_hooks'][]=['action',$name,$priority]; }
function add_filter($name,$cb,$priority=10,$args=1) { $GLOBALS['scp_hooks'][]=['filter',$name,$priority]; }
function add_shortcode($name,$cb) { $GLOBALS['scp_hooks'][]=['shortcode',$name,10]; }
function assert_kernel_hook($kind,$name,$priority=null) {
    foreach($GLOBALS['scp_hooks'] as $h) {
        if ($h[0]===$kind && $h[1]===$name && ($priority===null || $h[2]===$priority))return;
    }
    fwrite(STDERR,'HOOK_MISSING '.implode(':',[$kind,$name,$priority])."\n");
    exit(1);
}
$dir=__DIR__.'/../includes/';
foreach(['market-profiles','switcher-sources','market-context','market-preferences','season-calendar','company-operations-policy'] as $file) {
    require_once $dir.'class-mad4b-scp-'.$file.'.php';
}
MAD4B_SCP_Market_Profiles::boot();
MAD4B_SCP_Market_Context::boot();
MAD4B_SCP_Market_Preferences::boot();
assert_kernel_hook('shortcode','mad4b_market_selector');
assert_kernel_hook('shortcode','mad4b_market_preferences');
assert_kernel_hook('action','wp',9);
assert_kernel_hook('action','template_redirect',7);
assert_kernel_hook('action','admin_post_nopriv_mad4b_market_choose');
assert_kernel_hook('action','admin_post_nopriv_mad4b_save_market_preferences');
assert_kernel_hook('filter','update_post_metadata',30);
assert_kernel_hook('filter','add_post_metadata',30);
assert_kernel_hook('filter','wp_insert_post_data',30);
assert_kernel_hook('filter','pre_update_option_'.MAD4B_SCP_Company_Operations_Policy::OPTION,10);
foreach($GLOBALS['scp_hooks'] as $h) {
    if ($h[1]==='mad4b-season-calendar') {
        fwrite(STDERR,'EARLY_ADMIN_ROUTE_IN_PUBLIC_KERNEL'."\n");exit(1);
    }
}
echo 'Public document kernel market/commercial hooks: PASS '.count($GLOBALS['scp_hooks'])." hooks\n";
