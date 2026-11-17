<?php
/** Source-reviewed grant eligibility ONLY. No granting, no WordPress writes. */
if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
$GLOBALS['mad4b_contract_env'] = 'staging';
function sanitize_key($str) { return preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$str)); }
class MAD4B_SCP_Site_Profile {
    public static function current_environment() { return $GLOBALS['mad4b_contract_env']; }
}
require dirname(__DIR__).'/includes/class-mad4b-scp-staging-write-grant-reconciliation.php';
$c='MAD4B_SCP_Staging_Write_Grant_Reconciliation';
$required=[
 'context/native-owner-transfer-apply'=>'google_drive_context',
 'mad4b/approval-plan'=>'core',
 'mad4b/content-job-cancel'=>'core',
 'mad4b/content-job-create'=>'core',
 'mad4b/content-job-transition'=>'core',
 'mad4b/content-update-post'=>'core',
 'mad4b/data-processing-record-decision'=>'core',
 'mad4b/database-update'=>'core',
 'mad4b/draft-apply'=>'core',
 'mad4b/filesystem-patch'=>'core',
 'mad4b/filesystem-write'=>'core',
 'mad4b/intent-registry-reconcile'=>'core',
 'mad4b/mutation-undo'=>'core',
 'mad4b/plugin-activate'=>'core',
 'mad4b/plugin-deactivate'=>'core',
 'mad4b/provider-canary-execute'=>'core',
 'mad4b/translation-link-posts'=>'translation',
 'mad4b/translation-set-post-language'=>'translation'
];
$staging=$c::allowed_ability_providers();
foreach ($required as $ability=>$provider) {
 if (($staging[$ability]??null)!==$provider) {
  fwrite(STDERR,"FAIL: mismatch $ability expected=$provider actual=".($staging[$ability]??'MISSING')."\n");
  exit(1);
 }
}
if(isset($staging['mad4b/database-raw-query'])||isset($staging['mad4b/write-execute'])){
 fwrite(STDERR,"FAIL: breakglass or dispatcher present in ability grant list\n");
 exit(1);
}
$GLOBALS['mad4b_contract_env']='production';
$production=$c::allowed_ability_providers();
foreach($required as $ability=>$provider) {
 if(($production[$ability]??null)!==$provider) {
  fwrite(STDERR,"FAIL: dynamic Production provider mismatch: $ability\n");
  exit(1);
 }
}
foreach(['mad4b/database-raw-query','mad4b/write-execute','mad4b/developer-breakglass'] as $ability) {
 if(isset($production[$ability])) {
  fwrite(STDERR,"FAIL: privileged dispatcher or breakglass granted by eligibility: $ability\n");
  exit(1);
 }
}
$GLOBALS['mad4b_contract_env']='development';
$dev=$c::allowed_ability_providers();
foreach($required as $ability=>$provider) {
 if(isset($dev[$ability])) {
  fwrite(STDERR,"FAIL: unreviewed environment got eligibility: $ability\n");
  exit(1);
 }
}
echo "PASS: 18 exact provider pairs shared by Staging and Production; no grants, breakglass or dispatcher\n";
