<?php
// Standalone real metrics boundary with a controllable database failure surface.
define('ABSPATH', __DIR__);
define('ARRAY_A', 'ARRAY_A');
class WP_Error {
    private $code;
    public function __construct($code, $message = '', $data = null) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error($v) { return $v instanceof WP_Error; }
function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v)); }
function wp_has_ability($name) { return $name === 'fixture/read'; }
function absint($v) { return abs((int)$v); }
class MAD4B_SCP_Schema {
    public static function tables() { return array('metric_buckets' => 'fixture_metric_buckets'); }
}
class MetricsDB {
    public $mode = 'ok';
    public $last_error = '';
    public $writes = 0;
    public function prepare($sql, ...$args) {
        if ($this->mode === 'prepare_throw') throw new TypeError('private-db-prepare');
        return $sql;
    }
    public function query($sql) {
        ++$this->writes;
        if ($this->mode === 'write_throw') throw new RuntimeException('private-db-write');
        return $this->mode === 'write_false' ? false : 1;
    }
    public function get_results($sql, $format) {
        $this->last_error = '';
        if ($this->mode === 'read_throw') throw new RuntimeException('private-db-read');
        if ($this->mode === 'read_null') { $this->last_error = 'private-db-unavailable'; return null; }
        if ($this->mode === 'read_error') { $this->last_error = 'private-db-unavailable'; return array(); }
        return array();
    }
}
require dirname(__DIR__).'/includes/class-mad4b-scp-runtime-metrics.php';
$wpdb = new MetricsDB();
function check_metric($ok, $message) { if (!$ok) { fwrite(STDERR, $message."\n"); exit(1); } }
check_metric(MAD4B_SCP_Runtime_Metrics::record('fixture.metric') === true, 'healthy metric write failed');
$r = MAD4B_SCP_Runtime_Metrics::summary(array('fixture.metric'));
check_metric(is_array($r) && $r['metrics'] === array(), 'healthy empty query rejected');
foreach (array('write_throw', 'write_false', 'prepare_throw') as $mode) {
    $wpdb->mode = $mode; $before = $wpdb->writes;
    $r = MAD4B_SCP_Runtime_Metrics::record('fixture.metric');
    check_metric(is_wp_error($r), 'metric failure escaped: '.$mode);
    check_metric($wpdb->writes - $before <= 1, 'metric failure retried: '.$mode);
}
foreach (array('read_null', 'read_error', 'read_throw', 'prepare_throw') as $mode) {
    $wpdb->mode = $mode;
    $r = MAD4B_SCP_Runtime_Metrics::summary(array('fixture.metric'));
    check_metric(is_wp_error($r), 'failed query reported empty successful history: '.$mode);
}
$wpdb->mode = 'ok';
$before = $wpdb->writes;
check_metric(is_wp_error(MAD4B_SCP_Runtime_Metrics::record('invalid metric')), 'invalid name accepted');
check_metric($wpdb->writes === $before, 'invalid name reached database');
echo "RUNTIME_METRICS_FAILURE_RUNTIME_PASS\n";

// Exercise actual consumers, so unavailable DB evidence cannot certify a status.
class MAD4B_SCP_ChatGPT_Tool_Projection {
 public static function all_site_ability_names() { return array('fixture/read'); }
 public static function projected_ability_names() { return array(); }
 public static function describe_ability($name) { return array('projection_eligible'=>true,'lane'=>'read','readonly'=>true); }
}
require dirname(__DIR__).'/includes/class-mad4b-scp-projection-hotset-recommender.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-observability.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-g7-workload-measurement.php';
foreach(array('write_throw','write_false','prepare_throw') as $mode){
 $wpdb->mode=$mode;
 foreach(array('fixed_dispatch','direct_projection') as $lane){
  $before=$wpdb->writes;
  $r=MAD4B_SCP_Projection_Hotset_Recommender::record_usage('fixture/read',$lane);
  check_metric(is_wp_error($r)&&$wpdb->writes-$before<=1,'Hot-set recording escaped or retried: '.$lane);
 }
}
foreach(array('read_null','read_error','read_throw','prepare_throw') as $mode){
 $wpdb->mode=$mode;
 check_metric(is_wp_error(MAD4B_SCP_Projection_Hotset_Recommender::recommend()),'Recommendation treated outage as no usage.');
 check_metric(is_wp_error(MAD4B_SCP_G7_Workload_Measurement::status()),'Workload status hid unavailable storage.');
 $slo=MAD4B_SCP_Observability::slo_status();
 foreach($slo['stages'] as $stage)check_metric(empty($stage['ready']),'SLO became ready during DB outage.');
}
echo "METRICS_CONSUMER_FAILURE_PASS\n";
