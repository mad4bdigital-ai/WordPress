<?php
/**
 * Original MAD4B Presentation Integrity scan and browser evidence ingestion.
 * Hermetic: no frontend fetch, theme override, SQL mutation or Elementor save.
 */
define('ABSPATH', __DIR__.'/');
define('ARRAY_A', 'ARRAY_A');
define('WP_CLI', true);
define('WP_CONTENT_DIR', '/tmp/mad4b-isolated-wp-content');
$GLOBALS['presentation_test_rows'] = [];
$GLOBALS['presentation_test_error'] = '';
$GLOBALS['presentation_test_reads'] = 0;
$GLOBALS['presentation_test_writes'] = 0;
$GLOBALS['wp_filter'] = [];
class TestPresentationDB {
 public $posts='wp_posts', $postmeta='wp_postmeta', $last_error='';
 public function prepare($sql,...$args){return $sql;}
 public function get_results($sql,$type=null){
  ++$GLOBALS['presentation_test_reads'];$this->last_error=$GLOBALS['presentation_test_error'];
  return $GLOBALS['presentation_test_rows'];
 }
}
$GLOBALS['wpdb'] = new TestPresentationDB();
$GLOBALS['presentation_test_options']=[];
class WP_CLI {
 public static function add_command($name,$cb){}
 public static function line($text){$GLOBALS['presentation_test_cli_lines'][]=$text;}
 public static function error($text,$stop=true){throw new RuntimeException('WP_CLI_ERROR: '.$text);}
}
function get_option($name,$default=null){
 return $GLOBALS['presentation_test_options'][$name]??$default;
}
function update_option($name,$value,$autoload=false){
 ++$GLOBALS['presentation_test_writes'];
 $GLOBALS['presentation_test_options'][$name]=$value;
 return true;
}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function get_locale(){return 'en_US';}
function home_url($p='/'){return 'https://tours.example.invalid'.$p;}
function wp_parse_url($url,$part){return parse_url($url,$part);}
function get_current_blog_id(){return 1;}
function p_check($ok,$msg){if(!$ok)throw new RuntimeException('PRESENTATION_INTEGRITY: '.$msg);}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-presentation-integrity.php';
$c='MAD4B_SCP_Presentation_Integrity_V1';
$field = static function($id,$settings){return [
 'id'=>$id, 'widgetType'=>'jet-listing-dynamic-field','settings'=>$settings,'elements'=>[]
];};
$nodes=[
 ['id'=>'container','elements'=>[
  $field('fieldhidden',['dynamic_field_source'=>'query_var','hide_if_empty'=>'yes',
   'dynamic_field_var_name'=>'','field_fallback'=>'[jet-query-count]']),
  $field('fieldmissing',['dynamic_field_source'=>'query_var','hide_if_empty'=>false,
   'dynamic_field_var_name'=>'']),
  $field('fieldlegacy',['dynamic_field_source'=>'query_var','hide_if_empty'=>false,
   'dynamic_field_var_name'=>'','field_fallback'=>'Legacy authored value'])
 ]]
];
$GLOBALS['presentation_test_rows']=[
 ['ID'=>11,'post_type'=>'elementor_library','post_status'=>'publish','elementor_data'=>json_encode($nodes)],
 ['ID'=>12,'post_type'=>'elementor_library','post_status'=>'draft','elementor_data'=>json_encode($nodes)]
];
$r=$c::discover();
p_check($r['complete']===true && $c::covered()===true,'Registered scan must succeed');
p_check($r['counts']['templates']===2 && $r['counts']['dynamic_fields']===6
 && $r['counts']['unreachable_fallback']===2
 && $r['counts']['unbound_empty_fields']===2
 && $r['counts']['intentional_fallback_candidates']===2,
 'Only native JetEngine Dynamic Field source, hide and fallback conditions are classified');
p_check(count($r['issues'])===2 && $r['issues'][0]['severity']==='high'
 && $r['issues'][1]['severity']==='medium',
 'Draft-only findings must not become published issues');
foreach($r['issues'] as $issue)
 p_check($issue['remediation_policy']==='review_original_elementor_design_no_implicit_mutation',
  'Original Elementor visual design remains owner; no automatic CSS repair');
$GLOBALS['presentation_test_rows'] = [
 ['ID'=>14,'post_type'=>'elementor_library','post_status'=>'publish','elementor_data'=>'{invalid']
];
$invalid=$c::discover();
p_check($invalid['complete']===false && $c::covered()===false
 && $invalid['reason']==='elementor_template_invalid_json' && !$invalid['issues'],
 'Malformed Elementor native JSON must fail closed, never certify coverage');
$GLOBALS['presentation_test_rows'] = [];
$GLOBALS['presentation_test_error'] = 'WordPress database failed';
$dbfail=$c::discover();
p_check($dbfail['complete']===false && $dbfail['reason']==='template_inventory_unavailable',
 'Unavailable database inventory is not an empty, complete scan');
p_check($GLOBALS['presentation_test_writes']===0 && $GLOBALS['presentation_test_reads']===3,
 'Native audit may read only; no template, plugin, cache or authorization mutation');
$GLOBALS['presentation_test_error']='';
$GLOBALS['presentation_test_rows']=[];
$c::cli([],[]);
p_check($GLOBALS['presentation_test_writes']===0,
 'Default WordPress CLI presentation audit is read-only, no snapshot by default');
$c::cli([],['persist'=>true]);
p_check($GLOBALS['presentation_test_writes']===1
 && isset($GLOBALS['presentation_test_options']['mad4b_presentation_integrity_last_audit_v1']),
 'Only explicit CLI persist choice may save a presentation readiness report');
p_check($c::browser_covered()===false,
 'Missing live Browser audit must not be reported as observed visual coverage');
echo "PRESENTATION_INTEGRITY: PASS JetEngine, drafts, invalid templates, CLI opt-in, no implicit writes\n";
echo "PRESENTATION_INTEGRITY: PASS\n";
