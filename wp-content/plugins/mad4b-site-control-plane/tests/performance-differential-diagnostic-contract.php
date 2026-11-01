<?php
define('ABSPATH',__DIR__);
function wp_get_environment_type(){return 'staging';}
function get_num_queries(){return 17;}
class MAD4B_SCP_Query_Monitor_Evidence_Bridge {
 public static function db_attribution_status(){if (!empty($GLOBALS['qm_test_throw'])) throw new RuntimeException('secret backend exception');return array('state'=>'ready','qm_db_active'=>true,'query_monitor_active'=>true,'caller_component_trace_expected'=>true);}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-live-acceptance-observer.php';
$result=MAD4B_SCP_Live_Acceptance_Observer::performance_differential_diagnostic();
if(($result['contract']??'')!=='mad4b.performance-differential-diagnostic.v1'
 ||($result['current_request']['queries_so_far']??null)!==17
 ||$result['differential']['comparable_samples_available']!==false
 ||$result['differential']['core_only']!==null
 ||$result['authority_granted']!==false || $result['mutation_performed']!==false
 ||$result['attribution']['caller_component_trace_available']!==true)
 throw new RuntimeException('non-authorizing bounded diagnostic contract violated');
// A broken Query Monitor bridge must not abort MCP or expose an exception.
$GLOBALS['qm_test_throw']=true;
$result2=MAD4B_SCP_Live_Acceptance_Observer::performance_differential_diagnostic();
if ($result2['read_only']!==true || $result2['mutation_performed']!==false || $result2['attribution']['state']!=='attribution_probe_failed' || strpos(json_encode($result2),'secret backend exception')!==false)
 throw new RuntimeException('read-only diagnostic changed state');
echo "PASS: PERFORMANCE_DIFFERENTIAL_DIAGNOSTIC_FAIL_CLOSED\n";
