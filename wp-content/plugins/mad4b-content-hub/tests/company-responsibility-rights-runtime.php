<?php
/* Standalone contract test: no WordPress installation, no network or writes. */
define('ABSPATH', __DIR__ . '/');
$GLOBALS['company_policies'] = array();
function sanitize_key($value) {
    return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$value));
}
function sanitize_text_field($value) { return trim((string)$value); }
function get_option($name,$default=false) {
    if ($name === 'mad4b_hub_company_usage_policies_v2') return $GLOBALS['company_policies'];
    return $default;
}
require dirname(__DIR__) . '/includes/class-mad4b-hub-usage-rights.php';
require dirname(__DIR__) . '/../mad4b-jetengine-hub-addon/includes/class-mad4b-hub-jetengine-consumer-runtime.php';
function check($actual,$expected,$case) {
    if ($actual !== $expected) throw new RuntimeException($case . ' failed');
}
$company='wo-vacation';
$source=array('origin'=>'external_reference','company_id'=>'foreign-source',
    'consumer_company_id'=>$company);
$draft=array('quality_state'=>'passed','hard_blockers'=>array(),'external_media'=>true);
$r=MAD4B_Hub_Usage_Rights::assess($source,$draft,'distribution');
check($r['allowed'],true,'external_content_and_media');
check($r['rights_metadata']['license_state'],'not_verified','license_not_implied');
check($r['rights_metadata']['responsible_company_id'],$company,'responsible_company');
check($r['rights_metadata']['legal_reuse_permission'],'not_determined_by_system','no_license_claim');
check(MAD4B_Hub_Usage_Rights::assess($source,$draft,'distribution',array(
    'allow_external_media'=>false))['allowed'],false,'company_override');
$GLOBALS['company_policies'][$company] = array('allow_external_content'=>false);
check(MAD4B_Hub_Usage_Rights::assess($source,$draft,'distribution')['allowed'],false,'company_policy');
$GLOBALS['company_policies'] = array();
$failed=$draft;$failed['quality_state']='not_evaluated';
check(MAD4B_Hub_Usage_Rights::assess($source,$failed,'distribution')['allowed'],false,'qa_still_required');
$conf=array('company_id'=>$company,'source_site_uuid'=>'site-a',
    'source_brand_id'=>'source-brand','content_kind'=>'tour','language'=>'en');
$record=array('state'=>'approved','site_uuid'=>'site-a','brand_id'=>'source-brand',
    'locale'=>'en','normalized'=>array('quality_state'=>'passed',
       'rights_state'=>'not_verified','publish_authorized'=>true,
       'external_media'=>true,'content_kind'=>'tour'),
    'provenance'=>array('source_origin'=>'external_reference','test_only'=>false,
       'external_distribution_authorized'=>true));
check(MAD4B_Hub_JetEngine_Consumer_Runtime::eligible($record,$conf),true,'eligible_unverified_license');
$record['state']='research_candidate';
check(MAD4B_Hub_JetEngine_Consumer_Runtime::eligible($record,$conf),false,'research_candidate');
$record['state']='approved';$record['normalized']['publish_authorized']=false;
check(MAD4B_Hub_JetEngine_Consumer_Runtime::eligible($record,$conf),false,'publish_gate');
$record['normalized']['publish_authorized']=true;
$record['provenance']['external_distribution_authorized']=false;
check(MAD4B_Hub_JetEngine_Consumer_Runtime::eligible($record,$conf),false,'distribution_gate');
$record['provenance']['external_distribution_authorized']=true;
$record['normalized']['quality_state']='not_evaluated';
check(MAD4B_Hub_JetEngine_Consumer_Runtime::eligible($record,$conf),false,'qa_gate');
echo "COMPANY_RESPONSIBILITY_RIGHTS: PASS\n";
