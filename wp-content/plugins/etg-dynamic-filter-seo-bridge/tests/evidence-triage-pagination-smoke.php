<?php
/** Full Query Builder identity paging and nonauthorizing drift triage. */
function sanitize_key($v){return preg_replace('/[^a-z0-9_\\-]/','',strtolower((string)$v));}
require_once dirname(__DIR__).'/includes/Diagnostics/EvidenceProvider.php';
use ETG\DynamicFilterSEOBridge\Diagnostics\EvidenceProvider;
$ids=array();for($i=1;$i<=209;$i++){$ids[]=array('id'=>(string)$i,'identity_key'=>'q_'.$i);}
$snapshot=array(
    'contract'=>'etg.dfsb.runtime-inventory.v2','snapshot_fingerprint'=>'smoke',
    'inventory'=>array(
        'query_builder'=>array('available'=>true,'identity_index_complete'=>true,'identity_index'=>$ids,'queries'=>array_slice($ids,0,100)),
        'jet_smart_filters'=>array(
            'surfaces'=>array(array('filter_identity_expected'=>true,'identity_resolution_status'=>'empty','template_id'=>101,'node_id'=>'a')),
            'definition_unavailable'=>array(array('filter_id'=>12,'definition_reason'=>'filter_post_missing')),
            'drift'=>array(array('filter_id'=>13,'severity_hint'=>'review','reason'=>'taxonomy_mismatch')),
        ),
        'elementor_topology'=>array('provider_group_drift'=>array(array('template_id'=>101,'node_id'=>'a','reason'=>'group_mismatch'))),
    ),
);
$provider=new EvidenceProvider(static function()use($snapshot){return $snapshot;},static function(){return array('findings'=>array());},static function(){return array();});
function check_evidence($condition,$reason){if(!$condition){throw new RuntimeException($reason);}}
$descriptor=$provider->descriptor();
check_evidence(in_array('query_builder_identities',$descriptor['sections'],true),'identity section undiscoverable');
$page=$provider->query(array('section'=>'query_builder_identities','offset'=>200,'limit'=>50));
check_evidence('ok'===$page['state']&&209===$page['payload']['total']&&9===$page['payload']['returned'],'missing identity page 3');
check_evidence(true===$page['payload']['identity_index_complete']&&false===$page['payload']['has_more'],'identity completeness misreported');
$triage=$provider->query(array('section'=>'drift_triage','limit'=>2));
check_evidence(4===$triage['payload']['total']&&2===$triage['payload']['returned']&&true===$triage['payload']['has_more'],'triage paging');
check_evidence(true===$triage['payload']['findings_may_overlap_across_categories']&&false===$triage['payload']['safe_to_auto_fix'],'triage cannot authorize');
$triagePage2=$provider->query(array('section'=>'drift_triage','offset'=>2,'limit'=>50));
check_evidence(2===$triagePage2['payload']['returned'],'triage second page');
$snapshot['inventory']['query_builder']['identity_index_complete']=false;
$partial=(new EvidenceProvider(static function()use($snapshot){return $snapshot;},static function(){return array();},static function(){return array();}))->query(array('section'=>'query_builder_identities'));
check_evidence(true===$partial['payload']['source_truncated']&&false===$partial['payload']['complete_identity_coverage_claimed'],'truncated index must not report completeness');
echo "PASS paginated Query Builder identities and source-aware Drift triage\n";
