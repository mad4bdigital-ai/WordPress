<?php
define('ABSPATH',__DIR__);
class WP_Error{private $c,$m,$d;function __construct($c='',$m='',$d=null){$this->c=(string)$c;$this->m=(string)$m;$this->d=$d;}function get_error_code(){return$this->c;}function get_error_message(){return$this->m;}function get_error_data(){return$this->d;}}
function is_wp_error($v){return$v instanceof WP_Error;}function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v));}
final class MAD4B_SCP_Runtime_Metrics{public static $rows=array();public static function record($n,$v=1){self::$rows[$n]=(self::$rows[$n]??0)+$v;return true;}}
require dirname(__DIR__).'/includes/class-mad4b-scp-legacy-dispatch-migration.php';
$fail=static function($m,$v=null){fwrite(STDERR,'FAIL legacy-dispatch-migration-runtime: '.$m.(null===$v?'':' '.json_encode($v)).PHP_EOL);exit(1);};$check=static function($c,$m,$v=null)use($fail){if(!$c)$fail($m,$v);};
$e=MAD4B_SCP_Legacy_Dispatch_Migration::deny('write','missing_prepared_identity','fixture/write');$d=$e->get_error_data();
$check('mad4b_dispatch_preparation_required'===$e->get_error_code(),'stable error code changed',$e);
$check('mad4b.dispatch-preparation-required.v2'===$d['error_contract']&&empty($d['legacy_execution_allowed'])&&!empty($d['signed_preparation_required']),'versioned error contract invalid',$d);
$check(64===strlen($d['ability_name_sha256'])&&!isset($d['raw_input']),'raw legacy caller material leaked',$d);
$check(1===(MAD4B_SCP_Runtime_Metrics::$rows['legacy_dispatch.write.denied']??0),'denial telemetry missing',MAD4B_SCP_Runtime_Metrics::$rows);
$s=MAD4B_SCP_Legacy_Dispatch_Migration::status();$check(!empty($s['ready'])&&'deny_unprepared'===$s['current_state']&&false===$s['sunset_gates']['compatibility_bypass_allowed'],'sunset gates invalid',$s);
echo "mad4b.legacy-dispatch-migration.runtime.v1: PASS\n";
