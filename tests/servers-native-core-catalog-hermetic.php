<?php
/** Native catalog declaration source test, without loading WordPress or granting writes. */
define('ABSPATH',__DIR__.'/');
// The first-party facade loads this exact source owner before catalog reads.
// Hermetic test uses only its original class constant, not a parallel router.
class MAD4B_SCP_Admin_Remote_Parity {
    const ABILITY='mad4b/admin-remote-parity-snapshot';
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-server-core-catalog.php';
final class MAD4B_Core_Catalog_Harness {
    use MAD4B_SCP_Server_Core_Catalog;
    public static function tools($id) { return self::declared_core_tools($id); }
}
function core_assert($ok,$msg) {
    if(!$ok)throw new RuntimeException('SERVER_CORE_FAILURE: '.$msg);
}
$read=MAD4B_Core_Catalog_Harness::tools('mad4b-read');
$chatgpt=MAD4B_Core_Catalog_Harness::tools('mad4b-chatgpt');
$enrollment=MAD4B_Core_Catalog_Harness::tools('mad4b-enrollment');
core_assert(in_array('mad4b/site-info',$read,true),'Original read catalog missing');
core_assert(in_array('mad4b/read-execute',$chatgpt,true),'Original ChatGPT gateway missing');
core_assert(in_array('mad4b/staging-write-grant-reconciliation-plan',$enrollment,true),
    'Native enrollment plan disappeared');
core_assert(!in_array('mad4b/hub-universal-vps-enqueue',$read,true),
    'VPS enqueue incorrectly published onto generic read');
core_assert(!in_array('mad4b/hub-universal-vps-enqueue',$chatgpt,true),
    'VPS enqueue incorrectly published onto generic ChatGPT');
core_assert(!in_array('mad4b/hub-universal-vps-enqueue',$enrollment,true),
    'VPS enqueue incorrectly published onto enrollment');
core_assert(MAD4B_Core_Catalog_Harness::tools('mad4b-vps-local')===array(),
    'Exact native host-only catalog must be composed in guarded Server facade');
echo "MAD4B single native core catalog: PASS read/ChatGPT/enrollment isolation\n";// Success-only audited completion marker after all denial assertions.
echo "NATIVE_SERVER_CORE_CATALOG: PASS\n";
