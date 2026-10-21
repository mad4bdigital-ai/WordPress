<?php
define('ABSPATH',__DIR__);
class WP_Error {}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
class MAD4B_SCP_Site_Profile {public static function site_uuid(){return '';}}
class MAD4B_SCP_Context_Authority {
 public static $coverage=array();
 public static function brand_core_coverage(){return array('coverage'=>self::$coverage,'ready'=>false,'registry_revision'=>7);}
 public static function sources(){return array();}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-brand-context-builder.php';
function check_existing($ok,$why){if(!$ok)throw new RuntimeException($why);}
foreach(array('brand_strategy','tone_of_voice','editorial_guidelines') as $category){
 MAD4B_SCP_Context_Authority::$coverage[$category]=array('observed_assets'=>array(array('asset_id'=>$category,'review_status'=>'unreviewed')));
}
// No Site Profile/generation/provider services exist: this must remain a cheap review plan.
$p=MAD4B_SCP_Brand_Context_Builder::convergence_plan();
foreach($p['actions'] as $row){check_existing($row['action']==='review_or_repair_existing_assets'&&$row['human_decision_required']&&$row['replacement_creation_authorized']===false,'Existing draft routed to creation/approval');}
check_existing($p['auto_approval']===false&&$p['mutation_performed']===false,'Plan became authorizing');
MAD4B_SCP_Context_Authority::$coverage['brand_strategy']['ready']=true;
MAD4B_SCP_Context_Authority::$coverage['tone_of_voice']['conflict']=true;
$p=MAD4B_SCP_Brand_Context_Builder::convergence_plan();
check_existing($p['actions'][0]['action']==='none','Approved strategy replacement suggested');
check_existing($p['actions'][1]['action']==='resolve_existing_authority_conflict','Conflict hidden by existing-assets route');
check_existing($p['actions'][2]['action']==='review_or_repair_existing_assets','Pending editorial draft duplicated');
echo "BRAND_EXISTING_ASSETS_RUNTIME_PASS\n";

MAD4B_SCP_Context_Authority::$coverage['tone_of_voice']=array('observed_assets'=>array());
check_existing(is_wp_error(MAD4B_SCP_Brand_Context_Builder::convergence_plan()),'Missing draft bypassed original generation identity gate');
