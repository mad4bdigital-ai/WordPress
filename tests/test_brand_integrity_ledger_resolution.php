<?php
/** WP-CLI read-only proof: omitted CPT/tenant may never close old issues. */
if(!defined('ABSPATH')){fwrite(STDERR,"WordPress required\n");exit(2);}
$class=getenv('MAD4B_LEDGER_TEST_CLASS')?:'MAD4B_SCP_Content_Readiness_Alerts';
if(!class_exists($class,false)){fwrite(STDERR,"Readiness owner unavailable\n");exit(2);}
$test=new ReflectionMethod($class,'rule_enabled_for');
$site=(string)MAD4B_SCP_Site_Profile::site_uuid();
$issue=array(
    'source'=>'mad4b-brand-experience',
    'post_type'=>'properties',
    'code'=>'brand_identity_attribution_mismatch',
    'source_evidence'=>array('brand_scope'=>array('site_uuid'=>$site))
);
$complete=array('complete'=>true,'coverage_complete'=>true,
    'site_uuid'=>$site,'scan_post_types'=>array('properties','page'));
$missing=$complete;$missing['scan_post_types']=array('page');
$partial=$complete;$partial['coverage_complete']=false;
$cross=$complete;$cross['site_uuid']='aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
$missing_scope=$issue;unset($missing_scope['source_evidence']['brand_scope']);
$quality_issue=$issue;$quality_issue['code']='brand_listing_message_alignment';
$tests=array(
    'covered_cpt_can_close'=>$test->invoke(null,$issue,array(),$complete),
    'unscanned_cpt_cannot_close'=>$test->invoke(null,$issue,array(),$missing),
    'incomplete_coverage_cannot_close'=>$test->invoke(null,$issue,array(),$partial),
    'cross_site_cannot_close'=>$test->invoke(null,$issue,array(),$cross),
    'missing_issue_identity_cannot_close'=>$test->invoke(null,$missing_scope,array(),$complete),
    'separate_quality_issue_unchanged'=>$test->invoke(null,$quality_issue,array(),$complete)
);
$expected=array(true,false,false,false,false,true);
$failed=array();
foreach(array_keys($tests) as $i=>$name){
    if($tests[$name]!==$expected[$i])$failed[]=array('case'=>$name,
        'actual'=>$tests[$name],'expected'=>$expected[$i]);
}
echo wp_json_encode(array('contract'=>'mad4b.brand-integrity-ledger-scope-qa.v1',
    'passed'=>count($tests)-count($failed),'total'=>count($tests),'failures'=>$failed));
if($failed)exit(1);
