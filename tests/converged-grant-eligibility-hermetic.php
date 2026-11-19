<?php
/**
 * Exact, source-only positive + negative tests for 23 PR814 provider pairs
 * plus one separately enrolled VPS route. 19 company operations are
 * shared; four purpose-specific Staging operations stay strictly scoped.
 * Executes original policy owner; no WordPress runtime, no grants.
 */
define('ABSPATH',__DIR__.'/');
$GLOBALS['qa_env']='staging';
$GLOBALS['qa_vps_enrolled']=false;
function sanitize_key($value){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$value));}
function wp_has_ability($name){return $name==='mad4b/hub-universal-vps-enqueue';}
class MAD4B_SCP_Site_Profile {
    public static function current_environment(){return $GLOBALS['qa_env'];}
}
class MAD4B_Hub_Universal_VPS_V1 {
    public static function scoped(){return $GLOBALS['qa_vps_enrolled'];}
}
function qa_grant($ok,$description){
    if(!$ok){fwrite(STDERR,"FAILED GRANT ELIGIBILITY: ".$description."\n");exit(1);}
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-staging-write-grant-reconciliation.php';
$c='MAD4B_SCP_Staging_Write_Grant_Reconciliation';
$expected=array(
    'mad4b/artifact-append',
    'mad4b/fleet-intake-auto','mad4b/fleet-intake-plan','mad4b/fleet-intake-save',
    'mad4b/hub-source-sync-request',
    'mad4b/page-r1-create-apply','mad4b/page-r1-publish-apply','mad4b/page-r1-update-apply',
    'mad4b/post-r1-create-apply','mad4b/post-r1-publish-apply','mad4b/post-r1-update-apply',
    'mad4b/properties-r1-create-apply','mad4b/properties-r1-publish-apply','mad4b/properties-r1-update-apply',
    'mad4b/tours-and-activities-r1-create-apply','mad4b/tours-and-activities-r1-publish-apply',
    'mad4b/tours-and-activities-r1-update-apply',
    'mad4b/tours-and-activities-r2-create-apply','mad4b/tours-and-activities-r2-publish-apply',
    'mad4b/tours-and-activities-r2-update-apply',
    'mad4b/transportations-r1-create-apply','mad4b/transportations-r1-publish-apply',
    'mad4b/transportations-r1-update-apply',
);
qa_grant(count($expected)===23,'Expected exact first-party Staging candidates');
// Enrollment and source-provider eligibility do not imply an issued Grant.
$staging_purpose_only=array(
    'mad4b/fleet-intake-auto',
    'mad4b/fleet-intake-plan',
    'mad4b/fleet-intake-save',
    'mad4b/hub-source-sync-request',
);
qa_grant(count($staging_purpose_only)===4
    && count(array_diff($staging_purpose_only,$expected))===0,
    'Exactly four native Staging-purpose first-party operations');

$stage=$c::allowed_ability_providers();
foreach($expected as $ability)qa_grant(($stage[$ability]??null)==='core','Staging pair missing: '.$ability);
qa_grant(!isset($stage['mad4b/hub-universal-vps-enqueue']),
    'A nonenrolled VPS must not inherit a central-only ability');
$GLOBALS['qa_vps_enrolled']=true;
$central=$c::allowed_ability_providers();
qa_grant(($central['mad4b/hub-universal-vps-enqueue']??null)==='core',
    'Exact enrolled VPS should expose a grant-eligible pair, not an actual grant');
$GLOBALS['qa_env']='production';
$production=$c::allowed_ability_providers();
foreach($expected as $ability) {
    if(in_array($ability,$staging_purpose_only,true)) {
        qa_grant(!isset($production[$ability]),
            'Purpose-specific Staging ability leaked to Production: '.$ability);
    } else {
        qa_grant(($production[$ability]??null)==='core',
            'Reviewed general company provider is not environment-neutral: '.$ability);
    }
}
qa_grant(count(array_diff($expected,$staging_purpose_only))===19,
    'Exactly nineteen general first-party provider pairs shared by both environments');
qa_grant(!isset($production['mad4b/hub-universal-vps-enqueue']),
    'Native VPS enqueue leaked into Production');
foreach(array('local','development','unknown') as $env) {
    $GLOBALS['qa_env']=$env;
    $nonprod=$c::allowed_ability_providers();
    foreach($expected as $ability)qa_grant(!isset($nonprod[$ability]),
        'Current Staging pair leaked to '.$env.': '.$ability);
}
qa_grant(!isset($stage['mad4b/database-raw-query'])
    && !isset($stage['mad4b/undefined-proposed-ability'])
    && !isset($stage['malicious/apply']),
    'Uncertified/wildcard grant unexpectedly became eligible');
echo "PASS: PR814_GRANT_ELIGIBILITY_BOUNDARY 19 shared company pairs + 4 purpose-specific Staging pairs + 1 scoped VPS pair\n";
