#!/usr/bin/env python3
"""Hermetic real-provider regression for first-party CP4 Content Readiness alerts.
Uses no live WordPress instance, external identity, commercial approval, or pricing writes.
"""
import pathlib
import subprocess
import textwrap
import shutil
import os

ROOT = pathlib.Path(__file__).resolve().parents[1]
PROVIDER = ROOT / "wp-content/mu-plugins/mad4b-cp4-cost-readiness-alerts-v1.php"
SCANNER = ROOT / "wp-content/plugins/mad4b-site-control-plane/includes/trait-mad4b-scp-content-readiness-scanning.php"
RECONCILER = ROOT / "wp-content/plugins/mad4b-site-control-plane/includes/trait-mad4b-scp-content-readiness-reconciliation.php"
WORKFLOW = ROOT / "wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-readiness-workflow-options.php"


def required(blob: str, pieces: list[str], label: str) -> None:
    for text in pieces:
        if text not in blob:
            raise AssertionError(f"{label}: missing source authorization: {text}")


# Ensure the existing native scanner admits only its self-recomputed source rows.
scanner = SCANNER.read_text()
required(scanner, [
    "$source==='mad4b-package-cost-readiness'",
    "get_post_type($object_id)===$type",
    "get_post_status($object_id)==='publish'",
    "MAD4B_CP4_Cost_Alerts_V1::admissible((array)$row)",
    "'owner_group','action','reason_codes','source_sha256'",
    "$destinationTermAlerts||$cp4Cost",
], "original scanning trait")
reconciler = RECONCILER.read_text()
required(reconciler, [
    "($issue['source']??'')==='mad4b-package-cost-readiness'",
    "MAD4B_CP4_Cost_Alerts_V1::can_resolve((array)$issue)",
], "original reconciliation trait")
workflow = WORKFLOW.read_text()
required(workflow, [
    "'mad4b-package-cost-readiness'",
    "preg_match('/^cp4_cost_[a-f0-9]{12}$/D',$code)",
    "return 'pricing';",
], "native ownership routing")
provider = PROVIDER.read_text()
required(provider, [
    "public static function discover():array",
    "public static function can_resolve(array $old):bool",
    "self::receipt_qualified($tour,$stage)['qualified']===true",
    "'commercially_approved_by_this_scan'=>false",
], "source-owned provider")

php_command = ["php"]
provider_runtime_path = str(PROVIDER)
workflow_runtime_path = str(WORKFLOW)
container = os.environ.get("MAD4B_CP4_PHP_CONTAINER", "")
if not shutil.which("php"):
    if not container or not shutil.which("docker"):
        raise AssertionError("PHP binary required; set MAD4B_CP4_PHP_CONTAINER for isolated VPS Docker fixture")
    provider_runtime_path = "/tmp/mad4b-cp4-source-regression-provider.php"
    workflow_runtime_path = "/tmp/mad4b-cp4-source-regression-workflow.php"
    subprocess.run(["docker", "cp", str(PROVIDER), f"{container}:{provider_runtime_path}"], check=True)
    subprocess.run(["docker", "cp", str(WORKFLOW), f"{container}:{workflow_runtime_path}"], check=True)
    php_command = ["docker", "exec", "-i",
                   "-e", f"MAD4B_CP4_PROVIDER={provider_runtime_path}",
                   "-e", f"MAD4B_CP4_WORKFLOW={workflow_runtime_path}",
                   container, "php"]

# The real source file is executed with memory-only WordPress stubs. A fake
# gate supplies mock true/false to test scanner closure, not any production trust.
fixture = textwrap.dedent(r'''
<?php
define('ABSPATH', '/');
$GLOBALS['hooks']=[];
function add_filter($hook,$callback,$priority=10,$accepted=1) {$GLOBALS['hooks'][$hook]=$callback;return true;}
function has_filter($hook) {return $hook==='wpml_object_id';}
function apply_filters($hook,$v,...$args) {
  if($hook==='wpml_default_language')return 'en';
  if($hook==='wpml_object_id')return $v===57563?57562:$v;
  return $v;
}
function post_type_exists($type) {return $type==='tours-and-activities';}
function get_posts($args) {return [57562,57563];} // English and WPML sibling
function get_post_type($id) {return in_array($id,[57562,57563],true)?'tours-and-activities':false;}
function get_post_status($id) {return 'publish';}
function get_post_meta($id,$key,$single=true) {return $GLOBALS['sources'][$id][$key]??null;}
function get_current_blog_id() {return 1;}
function wp_json_encode($v,$flags=0) {return json_encode($v,$flags);}
function sanitize_key($value) {return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$value));}
class MAD4B_Package_Cost_Receipt_Gate_V1 {
  public static function validate($receipt,$context) {
    // Mock signed proof: NOT a production authorization provider.
    $ok=($receipt['status']??'')==='approved' &&
        ($receipt['__mock_signed']??false)===true &&
        ($context['tour_id']??0)===57562;
    return ['qualified'=>$ok,'blockers'=>$ok?[]:['receipt_signed_authority_unverified']];
  }
}
function check($ok,$label) {if(!$ok){fwrite(STDERR,"FAIL ".$label."\n");exit(5);}}
function stage($kind,$key,$day) {
  $data=['lodging_kind'=>$kind,'stage_key'=>$key,'source_day_start'=>$day,'nights'=>1];
  if($kind==='hotel'){$data['selected_property_id']=111;$data['bookable_unit_ids']=[222];}
  if($kind==='cruise'){$data['selected_vessel_id']=333;$data['selected_cabin_unit_id']=444;$data['cruise_program']='4N';$data['sailing_date']='2026-12-12';}
  if($kind==='sleeper_train'){$data['selected_train_id']=54552;$data['selected_train_number']='86';$data['selected_train_cabin_class']='Elite';}
  return $data;
}
$GLOBALS['sources']=[57562=>[
  '_mad4b_package_stay_verified_room_program_links_v1'=>[
    'schema'=>'mad4b.cp4-test-source.v1','tour_id'=>57562,
    'bindings'=>[
      stage('hotel','cairo-one',1),stage('cruise','nile-sail',4),
      stage('sleeper_train','rail-one',7)
    ]
  ]
]];
require getenv('MAD4B_CP4_PROVIDER');
require getenv('MAD4B_CP4_WORKFLOW');
$c='MAD4B_CP4_Cost_Alerts_V1';
$one=$c::discover();
check($one['complete']===true,'complete_source');
check($one['tours']===1,'WPML_canonical_dedup');
check($one['stages']===3 && count($one['issues'])===3,'three_distinct_stage_alerts');
$old=$one['issues'][0];
$group='MAD4B_SCP_Readiness_Workflow_Options';
check($group::group_for_issue($old)==='pricing','cp4_routes_to_pricing_owner');
$wrong=$old;$wrong['source']='external_source';
check($group::group_for_issue($wrong)==='general','untrusted_source_does_not_take_pricing_role');
$wrong=$old;$wrong['source_evidence']['contract']='other_contract';
check($group::group_for_issue($wrong)==='general','invalid_contract_does_not_take_pricing_role');
check($c::admissible($old),'original_admitted');
$bad=$old;$bad['severity']='low';check(!$c::admissible($bad),'spoofed_severity_rejected');
$bad=$old;$bad['source_evidence']['owner_group']='Untrusted override';check(!$c::admissible($bad),'spoofed_owner_rejected');
$two=$c::discover();
check($one['issues']===$two['issues'],'stable_issues');
check(!$c::can_resolve($old),'no_receipt_no_resolution');
// A real source-stage remains present and a simulated trusted validator
// returns true. Only this specific stage may resolve.
$GLOBALS['sources'][57562]['_mad4b_package_stay_verified_room_program_links_v1']['bindings'][0]['supplier_cost_receipt']=[
  'status'=>'approved','__mock_signed'=>true,'currency'=>'USD',
  'occupancy'=>['adults'=>2,'children'=>0,'infants'=>0],
  'availability'=>['service_date'=>'2026-12-12']
];
$third=$c::discover();
check($third['complete'] && count($third['issues'])===2,'approved_stage_excluded');
check($c::can_resolve($old),'approved_original_stage_can_resolve');
// Removing the stage is never evidence of a signed cost.
array_shift($GLOBALS['sources'][57562]['_mad4b_package_stay_verified_room_program_links_v1']['bindings']);
$fourth=$c::discover();
check($fourth['complete'] && count($fourth['issues'])===2,'stage_removed_scan');
check(!$c::can_resolve($old),'deleted_stage_not_resolved');
// Missing source plans never lead to a "complete" scan that might close alerts.
unset($GLOBALS['sources'][57562]['_mad4b_package_stay_verified_room_program_links_v1']);
$fifth=$c::discover();
check(!$fifth['complete'] && !$c::can_resolve($old),'partial_scan_fail_closed');
echo "CP4_READINESS_HERMETIC_PASS: canonical dedup, spoofing, stable stage ids, signed-only source closure, deletion and incomplete-source denial\n";
''')
try:
    completed = subprocess.run(
        php_command, input=fixture, text=True, capture_output=True,
        env={**os.environ, "MAD4B_CP4_PROVIDER": provider_runtime_path,
             "MAD4B_CP4_WORKFLOW": workflow_runtime_path},
    )
finally:
    if container and not shutil.which("php"):
        subprocess.run(["docker", "exec", "--user", "root", container,
                        "rm", "-f", provider_runtime_path, workflow_runtime_path], check=True)
if completed.returncode:
    raise AssertionError(f"PHP real-provider failure: {completed.stdout} {completed.stderr}")
assert "CP4_READINESS_HERMETIC_PASS" in completed.stdout
print(completed.stdout.strip())
print("CP4_CONTENT_READINESS_SOURCE_REGRESSION_PASS")
