<?php
/** Host v112 exact descriptor recursion prevention; no WordPress runtime. */
declare(strict_types=1);
error_reporting(E_ALL);
define('ABSPATH','/test');
$GLOBALS['available_abilities']=[
  'mad4b/control-plane-test-route'=>true,
  'mad4b/execution-unified-plan'=>true,
  'mad4b/scoped-build-verification'=>true,
  'mad4b/release-source-admission'=>true
];
function sanitize_key($name){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$name));}
function wp_has_ability($name){return ($GLOBALS['available_abilities'][$name]??false)===true;}
class MAD4B_Release_Source_Admission { const ABILITY='mad4b/release-source-admission'; }
require_once '/source/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-server-write-catalog.php';
final class V112Fixture {
  use MAD4B_SCP_Server_Write_Catalog;
  public static int $fallback=0;
  public static function declared_core_tools($server) {
    return $server==='mad4b-read'?['mad4b/required-core-read']:[];
  }
  public static function provider_for_ability($server,$ability) {
    self::$fallback++;
    return null;
  }
}
$tests=0;
function check($value,$name){global $tests;$tests++;if(!$value)throw new Exception($name);}
foreach ([
  'mad4b/required-core-read',
  'mad4b/control-plane-test-route',
  'mad4b/execution-unified-plan',
  'mad4b/scoped-build-verification',
  'mad4b/release-source-admission'
] as $name) {
  $before=V112Fixture::$fallback;
  check(V112Fixture::provider_for_capability_descriptor('mad4b-read',$name)==='core', 'registered_read_provider_'.$name);
  check(V112Fixture::$fallback===$before,'no_recursive_fallback_'.$name);
}
$GLOBALS['available_abilities']['mad4b/execution-unified-plan']=false;
$before=V112Fixture::$fallback;
check(V112Fixture::provider_for_capability_descriptor('mad4b-read','mad4b/execution-unified-plan')===null,'unregistered_read_denied');
check(V112Fixture::$fallback===$before+1,'unregistered_read_uses_old_fallback');
check(V112Fixture::provider_for_capability_descriptor('mad4b-read','mad4b/unknown')===null,'unknown_read_denied');
echo json_encode(['state'=>'PASS','checks'=>$tests,'new_grants'=>0,'anchor_issued'=>false]).PHP_EOL;
