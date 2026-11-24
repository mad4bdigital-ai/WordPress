<?php
define('ABSPATH','/');define('DAY_IN_SECONDS',86400);
function add_action(...$a){}function add_filter(...$a){}
function get_option($k,$d=false){return $k==='mad4b_page_dependency_schema_v1'?'installed':$d;}
function wp_get_environment_type(){return 'staging';}
function wp_cache_get(...$a){return false;}function wp_cache_set(...$a){return true;}function wp_cache_add(...$a){return true;}function wp_cache_delete(...$a){return true;}
function ok($v,$s){if(!$v)throw new RuntimeException($s);echo "PASS $s\n";}
class DB {
 public $prefix='wp_'; public $logs=[]; public $failAt=0;public $batches=0;
 public function prepare($sql,...$args){if(count($args)===1&&is_array($args[0]))$args=$args[0];return [$sql,$args];}
 public function query($p){$s=is_array($p)?$p[0]:$p;$this->logs[]=$p;if(str_starts_with($s,'INSERT INTO wp_mad4b_pd_edges')){++$this->batches;if($this->batches===$this->failAt)return 0;return count($p[1])/4|0;}return 1;}
}
$wpdb=new DB;require $argv[1];
$c='MAD4B_Page_Dependency_Cache';$f=new ReflectionMethod($c,'insert_edges');
$deps=[];for($i=1;$i<=140;$i++)$deps['post:'.$i]=true;
ok($f->invoke(null,'wp_mad4b_pd_edges',str_repeat('a',64),$deps,'2026-11-25 00:00:00'),'all edges inserted');
ok($wpdb->batches===3,'140 rows use three bounded SQL statements');
$keys=[];foreach($wpdb->logs as $row){for($i=0;$i<count($row[1]);$i+=4){ok($row[1][$i]===hash('sha256',$row[1][$i+2]),'exact edge hash');$keys[]=$row[1][$i+2];}}
ok($keys===array_keys($deps),'exact edge set/order preserved');
$wpdb=new DB;$wpdb->failAt=2;ok(!$f->invoke(null,'wp_mad4b_pd_edges',str_repeat('a',64),$deps,'2026-11-25 00:00:00')&&$wpdb->batches===2,'short write rejects and stops');
$wpdb=new DB;ok($f->invoke(null,'wp_mad4b_pd_edges',str_repeat('a',64),[],'2026-11-25 00:00:00')&&!$wpdb->logs,'empty input emits no SQL');
$source=file_get_contents($argv[1]);ok(str_contains($source,"if(!self::insert_edges(\$edges,\$hash,\$deps,\$expiry)){\n                \$wpdb->query('ROLLBACK');return false;"),'transaction rollback retained on batch failure');
echo "PASS GRAPH BATCH CONTRACT\n";
