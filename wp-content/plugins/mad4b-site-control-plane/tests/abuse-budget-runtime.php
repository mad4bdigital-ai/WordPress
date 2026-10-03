<?php
define('ABSPATH',__DIR__);
class WP_Error{private $c;private $d;public function __construct($c='',$m='',$d=null){$this->c=(string)$c;$this->d=$d;}public function get_error_code(){return $this->c;}public function get_error_data(){return $this->d;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v));}
function wp_json_encode($v,$f=0){return json_encode($v,$f);}
function get_current_blog_id(){return 7;} function home_url($p='/'){return 'https://fixture.test'.$p;} function get_current_user_id(){return 42;}
final class MAD4B_SCP_Identity_Context{public static function current(){return array('subject_fingerprint'=>str_repeat('a',64),'client_fingerprint'=>str_repeat('b',64),'wp_user_id'=>42,'session_fingerprint'=>'','origin'=>'mcp');}}
final class MAD4B_SCP_Site_Profile{public static function site_uuid(){return '11111111-1111-4111-8111-111111111111';}}
final class MAD4B_SCP_Transport_Context{public static function current_server_id(){return 'mad4b-chatgpt';}}
final class MAD4B_SCP_Database_Topology{public static function assert_write_ready($refresh=false){return array('ready'=>true);}}
final class MAD4B_SCP_Schema{public static function tables(){return array('metric_buckets'=>'wp_metric_buckets');}}
final class MAD4B_SCP_Time_Policy{public static function now_epoch(){return 2000000042;}}
class FakeWPDB{
 public $counts=array();
 public function prepare($q,...$args){foreach($args as $arg){$v=is_int($arg)?(string)$arg:"'".str_replace("'","''",(string)$arg)."'";$q=preg_replace('/%[sd]/',$v,$q,1);}return$q;}
 public function query($sql){if(preg_match("/VALUES \('([a-f0-9]{64})','([^']+)'/",$sql,$m)){if(!isset($this->counts[$m[1]]))$this->counts[$m[1]]=0;$this->counts[$m[1]]++;return 1;}return false;}
 public function get_var($sql){if(preg_match("/bucket_key=BINARY '([a-f0-9]{64})'/",$sql,$m))return isset($this->counts[$m[1]])?$this->counts[$m[1]]:null;return null;}
}
$GLOBALS['wpdb']=new FakeWPDB();
require dirname(__DIR__).'/includes/class-mad4b-scp-abuse-budget.php';
$fail=static function($m,$v=null){fwrite(STDERR,'FAIL abuse-budget-runtime: '.$m.(null===$v?'':' '.json_encode($v)).PHP_EOL);exit(1);};
$check=static function($c,$m,$v=null)use($fail){if(!$c)$fail($m,$v);};$code=static function($v){return is_wp_error($v)?$v->get_error_code():'';};
$policy=MAD4B_SCP_Abuse_Budget::policy();
$check(is_array($policy)&&false===$policy['authorizing']&&isset($policy['surfaces']['execute']),'abuse budget policy unavailable',$policy);
$ok=MAD4B_SCP_Abuse_Budget::admit('discovery',array('query'=>'posts seo','limit'=>20));
$check(is_array($ok)&&1===$ok['rate']['count']&&64===strlen($ok['site_fingerprint']),'bounded discovery was rejected',$ok);
$deep=array();$cursor=&$deep;for($i=0;$i<20;$i++){ $cursor['x']=array();$cursor=&$cursor['x']; }
$check('mad4b_abuse_depth_exceeded'===$code(MAD4B_SCP_Abuse_Budget::admit('discovery',$deep)),'deep nesting was accepted');
$schema=array('properties'=>array());for($i=0;$i<140;$i++)$schema['properties']['p'.$i]=array('type'=>'string');
$check('mad4b_abuse_schema_properties_exceeded'===$code(MAD4B_SCP_Abuse_Budget::admit('discovery',array('schema'=>$schema))),'schema bomb was accepted');
$evil=array('query'=>'(.*)+(.*)+(.*)+(.*)+(.*)+(.*)+(.*)+(.*)+(.*)+(.*)+(.*)+(.*)+(.*)+');
$check('mad4b_abuse_search_complexity_denied'===$code(MAD4B_SCP_Abuse_Budget::admit('discovery',$evil)),'pathological search/regex term was accepted');
$huge=array('metadata'=>str_repeat('x',20000));
$check('mad4b_abuse_metadata_bytes_exceeded'===$code(MAD4B_SCP_Abuse_Budget::admit('discovery',$huge)),'oversized metadata was accepted');
$second=MAD4B_SCP_Abuse_Budget::admit('discovery',array('query'=>'safe'));
$check(is_array($second)&&2===$second['rate']['count'],'atomic per-identity bucket did not increment',$second);
echo "mad4b.abuse-budget.runtime.v1: PASS\n";
