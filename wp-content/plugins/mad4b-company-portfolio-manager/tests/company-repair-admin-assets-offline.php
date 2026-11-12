<?php
/** Offline rendering — emits a JS string only for external syntax validation. */
define('ABSPATH','/');
define('WP_ADMIN',true);
function add_action(...$args){}
function is_admin(){return true;}
function current_user_can($cap){return $cap==='manage_options';}
function sanitize_key($s){return preg_replace('/[^a-z0-9_-]/','',strtolower($s));}
function rest_url($p){return 'https://tours.dev.mad4b.com/wp-json/'.$p;}
function wp_create_nonce($x){return 'SYNTHETIC_TEST_NONCE';}
function wp_json_encode($x,$flags=0){return json_encode($x,$flags);}
function wp_register_script(...$args){}
function wp_enqueue_script(...$args){}
function wp_add_inline_script($id,$src){$GLOBALS['TEST_JS']=$src;}
function esc_html($s){return htmlspecialchars($s,ENT_QUOTES);}
function esc_attr($s){return htmlspecialchars($s,ENT_QUOTES);}
class MAD4B_Company_Portfolio_Manager_V1{
 public static function staging(){return true;}
}
class MAD4B_Company_Environment_Registry_V2{}
class MAD4B_Company_Reconcile_Work_Plan_V1 {
 public static function original_policy(){
  return json_decode(file_get_contents(
    '/work/ops/deployment-overlays/center/company-start-reconcile/policy.json'),true);
 }
}
require dirname(__DIR__).'/includes/class-mad4b-company-repair-job-contract.php';
require dirname(__DIR__).'/includes/class-mad4b-company-repair-capability-discovery.php';
require dirname(__DIR__).'/includes/class-mad4b-company-repair-jobs.php';
$_GET['page']='mad4b-company-repair-jobs';
ob_start();
MAD4B_Company_Reconcile_Repair_Jobs_V1::screen();
$html=ob_get_clean();
MAD4B_Company_Reconcile_Repair_Jobs_V1::assets();
$js=$GLOBALS['TEST_JS']??'';
$meta=[
 'screen_visible'=>str_contains($html,'Company Repair Jobs'),
 'queue_button'=>str_contains($html,'mad4b-repair-queue-button'),
 'resource_button'=>str_contains($html,'mad4b-repair-resource-button'),
 'baseline_review_button'=>str_contains($html,'mad4b-repair-baseline-button'),
 'resource_selector_labeled'=>str_contains($html,'mad4b-repair-resource-type')
      &&str_contains($html,'mad4b-repair-resource-id'),
 'form_labeled'=>str_contains($html,'mad4b-repair-company')
                  &&str_contains($html,'mad4b-repair-environment'),
 'incident_count'=>substr_count($html,'<option value=')-2,
 'javascript_has_dynamic_queue'=>str_contains($js,'queueUrl'),
 'javascript_has_dynamic_plan'=>str_contains($js,'u.searchParams.set("incident"'),
 'javascript_has_baseline_review'=>str_contains($js,'baselineUrl')
      &&str_contains($js,'rollback_sha256:d.rollback_sha256')
      &&str_contains($js,'expected_after_sha256:d.expected_after_sha256'),
 'javascript_has_resource_preview'=>str_contains($js,'resourceUrl')
       &&str_contains($js,'u.searchParams.set("resource_type"')
       &&str_contains($js,'u.searchParams.set("resource_id"'),
 'javascript_uses_no_unsafe_html'=>!str_contains($js,'innerHTML'),
 'javascript_bytes'=>strlen($js),
];
if(!in_array(false,array_values(array_diff_key($meta,['incident_count'=>1,'javascript_bytes'=>1])),true)
 &&$meta['incident_count']===19){
 echo json_encode(['pass'=>true,'metadata'=>$meta,'base64js'=>base64_encode($js)]).PHP_EOL;
 exit;
}
echo json_encode(['pass'=>false,'metadata'=>$meta]).PHP_EOL;exit(1);
