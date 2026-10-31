<?php
define('ABSPATH','/tmp/');
define('ARRAY_A','ARRAY_A');
class WP_Error {public function __construct(public $code,public $message=''){}}
function is_wp_error($r){return $r instanceof WP_Error;}
function absint($v){return abs((int)$v);}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$v));}
function wp_json_encode($v){return json_encode($v);}
function wp_http_validate_url($v){return str_starts_with($v,'https://');}
function get_current_user_id(){return 7;}
function current_time($format,$gmt=false){return '2026-11-01';}
function esc_url_raw($v){return $v;}
function wp_get_environment_type(){return $GLOBALS['env']??'production';}
function current_user_can($c){return $GLOBALS['admin']??false;}
class DB {
 public $prefix='wp_';public $inserted=0;
 public function prepare($sql,...$params){return ['sql'=>$sql,'params'=>$params];}
 public function get_row($query,$type){return null;}
 public function get_var($query){return 0;}
 public function insert($table,$value){$this->inserted++;return true;}
}
$GLOBALS['wpdb']=new DB;
require __DIR__.'/../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-research-groups.php';
function check($v,$label){if(!$v)throw new Exception($label);}
$c='MAD4B_SCP_Research_Groups';
check(is_wp_error($c::group('valid-task-123','valid-site-12345')),'prod group allowed');
check(is_wp_error($c::add_record(8,'firecrawl','en','https://example.test',[])),'prod evidence allowed');
check($GLOBALS['wpdb']->inserted===0,'production mutated research database');
$GLOBALS['env']='staging';$GLOBALS['admin']=true;
check(is_wp_error($c::group('x','short')),'malformed group identity allowed');
check(is_wp_error($c::add_record(8,'malicious','en','https://example.test',[])),'missing research group allowed');
check($GLOBALS['wpdb']->inserted===0,'malformed staging wrote database');
echo "RESEARCH_PROVIDER_SCOPE_RUNTIME PASS deny prod and unknown records, identity bounds\n";
