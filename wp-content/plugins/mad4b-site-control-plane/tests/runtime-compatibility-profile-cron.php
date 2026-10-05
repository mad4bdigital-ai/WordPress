<?php
define( 'ABSPATH', sys_get_temp_dir() . '/mad4b-compat-cron-root/' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/mad4b-compat-cron-content' );
define( 'DOING_CRON', true );
if ( ! is_dir( ABSPATH ) ) mkdir( ABSPATH,0700,true );
if ( ! is_dir( WP_CONTENT_DIR ) ) mkdir( WP_CONTENT_DIR,0700,true );
class WP_Error { private $code; private $data; public function __construct($c,$m='',$d=array()){$this->code=$c;$this->data=$d;} public function get_error_code(){return $this->code;} public function get_error_data(){return $this->data;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function trailingslashit($v){return rtrim((string)$v,"/\\").'/';}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
function wp_using_ext_object_cache(){return false;}
function get_option($k,$d=false){return array();}
function is_multisite(){return false;}
function wp_doing_cron(){return true;}
class MAD4B_SCP_Transport_Context { public static $server=''; public static function current_server_id(){return self::$server;} }
class MAD4B_SCP_Database_Topology { public static function status($r=false){return array('ready'=>true,'read_your_writes'=>true,'database_dropin_present'=>false,'mode'=>'single_wpdb_writer_session','server_fingerprint'=>str_repeat('a',64),'blockers'=>array());} }
require dirname(__DIR__).'/includes/class-mad4b-scp-runtime-compatibility-profile.php';
$fail=static function($m){fwrite(STDERR,"FAIL runtime-compatibility-profile-cron: {$m}\n");exit(1);};
$check=static function($c,$m)use($fail){if(!$c)$fail($m);};
$base=MAD4B_SCP_Runtime_Compatibility_Profile::assert_governed_write_ready(true);
$check(is_array($base)&&'cron'===$base['execution_context']&&!empty($base['ready']),'internal cron context was not classified as supported');
MAD4B_SCP_Transport_Context::$server='mad4b-chatgpt';
$blocked=MAD4B_SCP_Runtime_Compatibility_Profile::assert_governed_write_ready(true);
$data=is_wp_error($blocked)?$blocked->get_error_data():array();
$check(is_wp_error($blocked)&&in_array('remote_transport_cron_context_conflict',$data['blockers']??array(),true),'remote transport inside cron failed open');
echo "mad4b.runtime-compatibility-profile.cron.v1: PASS\n";
