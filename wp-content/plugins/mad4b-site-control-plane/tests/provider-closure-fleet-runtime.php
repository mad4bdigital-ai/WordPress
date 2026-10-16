<?php
define('ABSPATH', '/');
function add_action( $hook, $callback, $priority = 10 ) { return true; }
require_once __DIR__ . '/../includes/class-mad4b-scp-provider-closure-matrix.php';
function ensure( $ok, $msg ) { if ( ! $ok ) { fwrite(STDERR, $msg . PHP_EOL); exit(1); } }
$absent = MAD4B_SCP_Provider_Closure_Matrix::plugin_fleet_summary( array() );
ensure( $absent['ready'] === false && $absent['authority_created'] === false, 'FAIL_CLOSED_MISSING_DISCOVERY' );
$data = array('count'=>3,'items'=>array(
  array('plugin_file'=>'z/z.php','active'=>true,'support_level'=>'L0_inventory','risk'=>'exceptional','next_gate'=>'dedicated_high_risk_path'),
  array('plugin_file'=>'a/a.php','active'=>true,'support_level'=>'L2_read','risk'=>'medium','next_gate'=>'provider_certification'),
  array('plugin_file'=>'c/c.php','active'=>false,'support_level'=>'L4_certified_governed','risk'=>'low','next_gate'=>'none'),
));
$v = MAD4B_SCP_Provider_Closure_Matrix::plugin_fleet_summary($data);
ensure( $v['ready'] && $v['observed_count'] === 3, 'COUNT' );
ensure( $v['level_counts_observed']['L0_inventory'] === 1 && $v['level_counts_observed']['L2_read'] === 1, 'LEVELS' );
ensure( $v['items'][0]['plugin_file'] === 'a/a.php', 'SORT' );
ensure( $v['items'][2]['promotion_approved'] === false && $v['items'][2]['write_authority_granted'] === false, 'NO_GRANTS' );
ensure( $v['auto_promotion'] === false && $v['mutation_performed'] === false, 'READ_ONLY' );
$large = array('count'=>205,'items'=>array_fill(0,205,array('plugin_file'=>'x/x.php','support_level'=>'L0_inventory')));
$bounded = MAD4B_SCP_Provider_Closure_Matrix::plugin_fleet_summary($large);
ensure( $bounded['observed_count'] === 200 && $bounded['output_truncated'] === true, 'BOUNDED_OUTPUT' );
echo "PROVIDER_FLEET_RUNTIME_PASS\n";
