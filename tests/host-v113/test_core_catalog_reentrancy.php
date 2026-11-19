<?php
/** v113: fail-closed re-entrance guard for declared_core_tools(). */
declare(strict_types=1);
error_reporting(E_ALL);
define('ABSPATH',__DIR__);
final class MAD4B_SCP_Operation_Registry {
    public static int $projections=0;
    public static bool $throwOnce=false;
    public static string $nestedTarget='mad4b-read';
    public static function read_projection($surface):array {
        self::$projections++;
        // Actual production cycle: an optional capability tries to inspect
        // a provider while the full core map is still being assembled.
        $nested=V113Fixture::view(self::$nestedTarget);
        if ($nested!==[]) throw new RuntimeException('nested build must fail closed');
        if (self::$throwOnce) {
            self::$throwOnce=false;
            throw new RuntimeException('injected catalog failure');
        }
        return ['mad4b/verified-staging-read'];
    }
}
final class MAD4B_SCP_Admin_Remote_Parity { const ABILITY='mad4b/admin-remote-parity'; }
require_once '/source/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-server-core-catalog.php';
final class V113Fixture {
    use MAD4B_SCP_Server_Core_Catalog;
    public static function view(string $server):array {
        return self::declared_core_tools($server);
    }
}
$count=0;
function check($value,$label) {global $count;$count++;if(!$value)throw new RuntimeException($label);}
$t=microtime(true);
$one=V113Fixture::view('mad4b-read');
check(in_array('mad4b/verified-staging-read',$one,true),'normal read item preserved');
check(in_array('mad4b/admin-remote-parity',$one,true),'existing original read preserved');
check(count($one)<500,'catalog bounded in scope');
check(MAD4B_SCP_Operation_Registry::$projections<=4,'re-entry invoked a bounded number of times');
check((microtime(true)-$t)<2,'self-recursion remains fast');
MAD4B_SCP_Operation_Registry::$throwOnce=true;
try {
 V113Fixture::view('mad4b-read');
 throw new RuntimeException('test fixture exception was not thrown');
} catch(RuntimeException $e) {
 check($e->getMessage()==='injected catalog failure','exception propagated');
}
$two=V113Fixture::view('mad4b-read');
check(in_array('mad4b/verified-staging-read',$two,true),'request guard reset after throw');
MAD4B_SCP_Operation_Registry::$nestedTarget='mad4b-admin';
$admin=V113Fixture::view('mad4b-admin');
check(is_array($admin),'other server identity allowed after read');
echo json_encode(['state'=>'PASS','checks'=>$count,'catalog_items'=>count($one),
 'projection_calls'=>MAD4B_SCP_Operation_Registry::$projections,
 'authority_granted'=>false]).PHP_EOL;
