<?php
/** Hermetic exact provider mapping across enrolled environments; no mutations. */
if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
$GLOBALS['parity_environment']='staging';
function sanitize_key($s) {return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$s));}
class MAD4B_SCP_Site_Profile {
  public static function current_environment(){return $GLOBALS['parity_environment'];}
  public static function write_enabled(){return false;}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-staging-write-grant-reconciliation.php';
function parity_assert($ok,$what){if(!$ok){fwrite(STDERR,'PARITY_DENIED: '.$what.PHP_EOL);exit(1);}}
$shared_exact=[
    'context/brand-profile-save' => 'google_drive_context',
    'context/governed-source-upsert' => 'google_drive_context',
    'context/native-owner-transfer-apply' => 'google_drive_context',
    'context/remote-owner-review-apply' => 'google_drive_context',
    'jetsmartfilters/update-filter-meta' => 'jetsmartfilters',
    'mad4b/approval-plan' => 'core',
    'mad4b/artifact-append' => 'core',
    'mad4b/attachment-transfer' => 'core',
    'mad4b/business-activity-import-approve' => 'core',
    'mad4b/business-activity-import-batch-append' => 'core',
    'mad4b/business-activity-import-batch-approve' => 'core',
    'mad4b/business-activity-import-batch-archive' => 'core',
    'mad4b/business-activity-import-batch-begin' => 'core',
    'mad4b/business-activity-link-apply' => 'core',
    'mad4b/business-activity-sync-advance' => 'core',
    'mad4b/business-activity-sync-archive' => 'core',
    'mad4b/business-activity-sync-begin' => 'core',
    'mad4b/business-activity-sync-cancel' => 'core',
    'mad4b/business-activity-sync-finalize-reconciled' => 'core',
    'mad4b/business-activity-sync-recover' => 'core',
    'mad4b/content-delete-meta' => 'core',
    'mad4b/content-experience-profile-apply' => 'core',
    'mad4b/content-experience-profile-clone-apply' => 'core',
    'mad4b/content-experience-profile-delete-apply' => 'core',
    'mad4b/content-import-bundle' => 'core',
    'mad4b/content-job-cancel' => 'core',
    'mad4b/content-job-create' => 'core',
    'mad4b/content-job-transition' => 'core',
    'mad4b/content-set-meta' => 'core',
    'mad4b/content-trash-post' => 'core',
    'mad4b/content-update-post' => 'core',
    'mad4b/data-processing-record-decision' => 'core',
    'mad4b/database-update' => 'core',
    'mad4b/deployment-binding-native-apply' => 'core',
    'mad4b/draft-apply' => 'core',
    'mad4b/filesystem-patch' => 'core',
    'mad4b/filesystem-write' => 'core',
    'mad4b/host-operation-apply' => 'core',
    'mad4b/intent-registry-reconcile' => 'core',
    'mad4b/market-growth-policy-update' => 'core',
    'mad4b/mutation-undo' => 'core',
    'mad4b/optional-governance-apply' => 'core',
    'mad4b/page-r1-create-apply' => 'core',
    'mad4b/page-r1-publish-apply' => 'core',
    'mad4b/page-r1-update-apply' => 'core',
    'mad4b/plugin-activate' => 'core',
    'mad4b/plugin-deactivate' => 'core',
    'mad4b/post-r1-create-apply' => 'core',
    'mad4b/post-r1-publish-apply' => 'core',
    'mad4b/post-r1-update-apply' => 'core',
    'mad4b/properties-r1-create-apply' => 'core',
    'mad4b/properties-r1-publish-apply' => 'core',
    'mad4b/properties-r1-update-apply' => 'core',
    'mad4b/provider-canary-execute' => 'core',
    'mad4b/taxonomy-update-term' => 'core',
    'mad4b/tours-and-activities-r1-create-apply' => 'core',
    'mad4b/tours-and-activities-r1-publish-apply' => 'core',
    'mad4b/tours-and-activities-r1-update-apply' => 'core',
    'mad4b/tours-and-activities-r2-create-apply' => 'core',
    'mad4b/tours-and-activities-r2-publish-apply' => 'core',
    'mad4b/tours-and-activities-r2-update-apply' => 'core',
    'mad4b/translation-link-posts' => 'translation',
    'mad4b/translation-set-post-language' => 'translation',
    'mad4b/transportations-r1-create-apply' => 'core',
    'mad4b/transportations-r1-publish-apply' => 'core',
    'mad4b/transportations-r1-update-apply' => 'core',
    'media/import-external' => 'media',
    'media/remote-import-apply' => 'media',
];
$staging_specific=[
    'mad4b/acceptance-target-provision' => 'core',
    'mad4b/business-activity-wpai-observation-arm' => 'core',
    'mad4b/control-plane-selected-head-apply' => 'core',
    'mad4b/deployment-binding-setup-apply' => 'core',
    'mad4b/fleet-intake-auto' => 'core',
    'mad4b/fleet-intake-plan' => 'core',
    'mad4b/fleet-intake-save' => 'core',
    'mad4b/hub-source-sync-request' => 'core',
    'mad4b/native-recovery-cleanup' => 'core',
    'mad4b/native-recovery-start' => 'core',
    'mad4b/provider-connection-refresh' => 'core',
    'mad4b/runtime-release-set-apply' => 'core',
    'mad4b/staging-offline-attestor-enroll-apply' => 'core',
    'mad4b/wordpress-native-candidate-opt-in-apply' => 'core',
    'mad4b/wp-native-build-start' => 'core',
    'mad4b/wp-native-self-approve-install' => 'core',
];
parity_assert(count($shared_exact)===68 && count($staging_specific)===16,'counts');
$c='MAD4B_SCP_Staging_Write_Grant_Reconciliation';
$stage=$c::allowed_ability_providers();
$GLOBALS['parity_environment']='production';
$prod=$c::allowed_ability_providers();
$GLOBALS['parity_environment']='development';
$dev=$c::allowed_ability_providers();
foreach($shared_exact as $name=>$provider){
 parity_assert(($stage[$name]??null)===$provider,'Staging '.$name);
 parity_assert(($prod[$name]??null)===$provider,'Production '.$name);
}
foreach($staging_specific as $name=>$provider){
 parity_assert(($stage[$name]??null)===$provider,'Staging-only missing '.$name);
 parity_assert(!isset($prod[$name]),'Staging-only leaked '.$name);
}
$onlyStage=array_diff_key($stage,$prod);
ksort($onlyStage);ksort($staging_specific);
parity_assert($onlyStage===$staging_specific,'Unreviewed environment-specific additions');
foreach(['jetsmartfilters/update-filter-meta','media/import-external',
         'mad4b/attachment-transfer','mad4b/optional-governance-apply',
         'mad4b/tours-and-activities-r2-publish-apply'] as $name)
 parity_assert(!isset($dev[$name]),'Development scope leaked '.$name);
foreach([$stage,$prod,$dev] as $scope){
 foreach(['mad4b/database-raw-query','mad4b/write-execute','mad4b/developer-breakglass','mad4b/unknown-future','thirdparty/unknown'] as $name)
  parity_assert(!isset($scope[$name]),'Breakglass or future ability '.$name);
}
parity_assert(!MAD4B_SCP_Site_Profile::write_enabled(),'No grants may be inferred');
echo 'MAD4B_ENVIRONMENT_PROVIDER_PARITY: PASS shared=68 staging_specific=16 grants_created=0'.PHP_EOL;
