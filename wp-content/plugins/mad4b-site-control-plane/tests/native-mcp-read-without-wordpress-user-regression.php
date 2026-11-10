<?php
defined('WP_CLI') && WP_CLI || exit(2);
$ok = 0;
$assert = static function ($pass,$label) use (&$ok) {
    if (!$pass) throw new RuntimeException('NATIVE_MCP_TEST_FAILED_'.$label);
    ++$ok;
    echo 'PASS '.$label.PHP_EOL;
};
$assert(wp_get_environment_type()==='staging' &&
    untrailingslashit(home_url('/'))==='https://tours.dev.mad4b.com',
    'central_exact_staging_only');
$assert(get_current_user_id()===0 && posix_geteuid()===0,
    'root_without_wordpress_actor');
$previous=$_SERVER['argv']??[];
$_SERVER['argv']=['wp','mcp-adapter','serve','--server=mad4b-vps-local'];
try {
    $assert(MAD4B_SCP_Servers::can_vps_local_transport()===true,
        'native_read_admitted');
    $assert(MAD4B_Hub_Bulk_Jobs_Queue::readable()===true,
        'readable_queue');
    $assert(MAD4B_SCP_Servers::can_vps_local_write_transport()===false,
        'native_apply_not_granted');
    $assert(MAD4B_Hub_Bulk_Jobs_Queue::writable()===false,
        'queue_write_denied');
    wp_set_current_user(1);
    try {
        $denied=MAD4B_SCP_Servers::can_vps_local_transport();
        $assert(is_wp_error($denied),'wordpress_actor_not_native');
    } finally {
        wp_set_current_user(0);
    }
    $_SERVER['argv']=['wp','eval','echo 1;'];
    $assert(is_wp_error(MAD4B_SCP_Servers::can_vps_local_transport()),
        'non_stdio_rejected');
} finally {
    $_SERVER['argv']=$previous;
}
echo 'VERDICT '.$ok.'/'.$ok.' PASS'.PHP_EOL;
