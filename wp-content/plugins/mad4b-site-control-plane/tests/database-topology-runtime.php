<?php
define( 'ABSPATH', __DIR__ );
$tmp = sys_get_temp_dir() . '/mad4b-db-topology-' . preg_replace( '/[^a-z0-9]/i', '', uniqid( '', true ) );
mkdir( $tmp, 0700, true );
define( 'WP_CONTENT_DIR', $tmp );
define( 'ARRAY_A', 'ARRAY_A' );

class WP_Error {
	private $code; private $data;
	public function __construct( $code, $message = '', $data = array() ) { $this->code=$code; $this->data=$data; }
	public function get_error_code(){ return $this->code; }
	public function get_error_data(){ return $this->data; }
}
function is_wp_error($v){ return $v instanceof WP_Error; }
function trailingslashit($v){ return rtrim((string)$v,'/\\').'/'; }
function wp_json_encode($v,$flags=0){ return json_encode($v,$flags); }
function sanitize_key($v){ return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v)); }

class MAD4B_Fake_Topology_DB {
	public $dbhost='db.internal:3306';
	public $dbname='wordpress';
	public $last_error='';
	public $connection_id=41;
	public $read_only=0;
	public $probe_ok=true;
	public function suppress_errors($value=null){ return false; }
	public function db_version(){ return '8.0-fixture'; }
	public function get_row($sql,$format){
		$this->last_error='';
		if(!$this->probe_ok){$this->last_error='Lost connection to MySQL server during query';return null;}
		return array('connection_id'=>$this->connection_id,'database_name'=>$this->dbname,'server_hostname'=>'db-writer','server_port'=>3306,'server_read_only'=>$this->read_only);
	}
}
$GLOBALS['wpdb']=new MAD4B_Fake_Topology_DB();

class MAD4B_SCP_Query_Monitor_Evidence_Bridge {
	public static $status = array(
		'dropin_exists'=>false,
		'dropin_owned_by_query_monitor'=>false,
		'dropin_conflict'=>false,
		'dropin_ownership'=>'none',
	);
	public static function db_attribution_status(){ return self::$status; }
}

require dirname(__DIR__).'/includes/class-mad4b-scp-database-topology.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-database-failure-semantics.php';

$fail=static function($m){fwrite(STDERR,"FAIL database-topology-runtime: {$m}\n");@unlink(WP_CONTENT_DIR.'/db.php');@rmdir(WP_CONTENT_DIR);exit(1);};
$check=static function($c,$m)use($fail){if(!$c)$fail($m);};
$code=static function($v){return is_wp_error($v)?$v->get_error_code():'';};

$ready=MAD4B_SCP_Database_Topology::assert_write_ready(true);
$check(is_array($ready)&&!empty($ready['ready'])&&!empty($ready['read_your_writes']),'single writer topology not ready');
$check(1===preg_match('/^[a-f0-9]{64}$/',(string)$ready['connection_fingerprint']),'connection fingerprint missing');

$GLOBALS['wpdb']->connection_id=42;
$changed=MAD4B_SCP_Database_Topology::assert_same_writer($ready);
$check('mad4b_database_topology_writer_changed'===$code($changed),'writer connection change was not fenced');
$GLOBALS['wpdb']->connection_id=41;

$GLOBALS['wpdb']->read_only=1;
$readonly=MAD4B_SCP_Database_Topology::assert_write_ready(true);
$check('mad4b_database_topology_not_write_safe'===$code($readonly),'read-only database was admitted');
$GLOBALS['wpdb']->read_only=0;

file_put_contents(WP_CONTENT_DIR.'/db.php',"<?php\n");
$router=MAD4B_SCP_Database_Topology::assert_write_ready(true);
$check('mad4b_database_topology_not_write_safe'===$code($router),'uncertified db.php router was admitted');

MAD4B_SCP_Query_Monitor_Evidence_Bridge::$status=array(
	'dropin_exists'=>true,
	'dropin_owned_by_query_monitor'=>true,
	'dropin_conflict'=>false,
	'dropin_ownership'=>'mad4b_bounded_loader',
);
$observer=MAD4B_SCP_Database_Topology::assert_write_ready(true);
$check(is_array($observer)&&!empty($observer['ready'])&&!empty($observer['read_your_writes'])&&!empty($observer['observer_dropin_certified']),'certified observer db.php was not admitted');
$check('mad4b_bounded_loader'===$observer['database_dropin_ownership'],'certified observer ownership was not projected');

MAD4B_SCP_Query_Monitor_Evidence_Bridge::$status['dropin_ownership']='query_monitor_native_dropin';
$legacy_marker=MAD4B_SCP_Database_Topology::assert_write_ready(true);
$check('mad4b_database_topology_not_write_safe'===$code($legacy_marker),'marker-only legacy Query Monitor drop-in escaped exact ownership certification');

MAD4B_SCP_Query_Monitor_Evidence_Bridge::$status=array(
	'dropin_exists'=>false,
	'dropin_owned_by_query_monitor'=>false,
	'dropin_conflict'=>false,
	'dropin_ownership'=>'none',
);
unlink(WP_CONTENT_DIR.'/db.php');

$deadlock=MAD4B_SCP_Database_Failure_Semantics::classify('fixture','Deadlock found when trying to get lock; try restarting transaction',true);
$check('deadlock'===$deadlock['failure_class']&&!$deadlock['reconciliation_required']&&!$deadlock['blind_retry_allowed']&&!empty($deadlock['fresh_plan_required']),'deadlock semantics invalid');
$timeout=MAD4B_SCP_Database_Failure_Semantics::classify('fixture','Lock wait timeout exceeded; try restarting transaction',false);
$check('lock_wait_timeout'===$timeout['failure_class']&&!empty($timeout['reconciliation_required'])&&!$timeout['blind_retry_allowed'],'lock timeout semantics invalid');
$lost=MAD4B_SCP_Database_Failure_Semantics::classify('fixture','Lost connection to MySQL server during query',null);
$check('connection_loss'===$lost['failure_class']&&!empty($lost['reconciliation_required'])&&'unknown'===$lost['persistence_state'],'connection-loss semantics invalid');

@rmdir(WP_CONTENT_DIR);
echo "mad4b.database-topology.runtime.v2: PASS\n";
