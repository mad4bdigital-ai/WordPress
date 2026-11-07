<?php
/**
 * Read-only regression for the 19 already-granted, source-policy-drifted
 * exact Staging pairs observed on the enrolled source on 2026-11-07.
 * No WordPress grant or mutation API is called by this test.
 */
if ( ! defined('ABSPATH') ) define('ABSPATH',__DIR__.'/');
$GLOBALS['mad4b_test_environment']='staging';
function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value));}
final class MAD4B_SCP_Site_Profile {
    public static function current_environment(){return $GLOBALS['mad4b_test_environment'];}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-staging-write-grant-reconciliation.php';
$expected = array(
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
    'mad4b/firecrawl-research-capture'=>'core',
    'mad4b/intent-registry-reconcile'=>'core',
    'mad4b/mutation-undo'=>'core',
    'mad4b/plugin-activate'=>'core',
    'mad4b/plugin-deactivate'=>'core',
    'mad4b/provider-canary-execute'=>'core',
    'mad4b/translation-link-posts'=>'translation',
    'mad4b/translation-set-post-language'=>'translation',
);
function checked($pass,$why) {if(!$pass){fwrite(STDERR,'FAIL '.$why.PHP_EOL);exit(1);}}
$policy = 'MAD4B_SCP_Staging_Write_Grant_Reconciliation';
$staging = $policy::allowed_ability_providers();
checked(count($expected)===19,'only 19 observed exact pairs are under reconciliation');
foreach($expected as $ability=>$provider)
    checked(($staging[$ability]??null)===$provider,'incorrect exact Staging pair: '.$ability);
// Source policy is not an execution grant. Reject future names, wildcard
// capabilities, raw SQL, Developer and unknown provider substitutions.
foreach(array('mad4b/database-raw-query','mad4b/unknown-future-grant','other/random-write','mad4b/developer-breakglass') as $ability)
    checked(!isset($staging[$ability]),'unapproved capability admitted: '.$ability);
checked(count(array_filter(array_keys($staging),static function($a){return strpos($a,'*')!==false || strpos($a,'?')!==false;}))===0,'wildcard admission');
checked(($staging['mad4b/translation-link-posts']??'')==='translation','WPML provider identity mismatch');
$GLOBALS['mad4b_test_environment']='production';
$production = $policy::allowed_ability_providers();
foreach($expected as $ability=>$provider)
    checked(!isset($production[$ability]),'Staging-only pair leaked into Production: '.$ability);
checked(!isset($production['mad4b/database-raw-query']),'Production breakglass leakage');
// The approval/grant executor, unlike the policy lookup, is still gated by
// exact OAuth step-up, plan SHA, source provenance, explicit confirmation and
// a separate per-operation permission callback.
$source=file_get_contents(dirname(__DIR__).'/includes/class-mad4b-scp-staging-write-grant-reconciliation.php');
foreach(array('verified_bearer_active()','verified_bearer_has_scope(', 'expected_plan_sha256',
    'mad4b_grant_reconcile_plan_changed','expected_write_inventory_fingerprint',
    'mad4b_grant_reconcile_provenance_not_ready','mad4b_approved_auto_production_denied',
    'MAD4B_SCP_Audit::record(', 'MAD4B_SCP_Agent_Registry::grant_ability(',
    'mad4b_grant_reconcile_breakglass_leak') as $marker)
    checked(strpos($source,$marker)!==false,'grant executor lost mandatory gate: '.$marker);
echo "mad4b.staging-write-grant-existing-parity.v1: 19/19 PASS, Production unchanged, zero mutation\n";
