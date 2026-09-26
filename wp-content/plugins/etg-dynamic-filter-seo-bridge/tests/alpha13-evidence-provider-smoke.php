<?php
declare(strict_types=1);

function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value));}
function sanitize_text_field($value){return trim(strip_tags((string)$value));}
function absint($value){return abs((int)$value);}
function etg_evidence_expect($condition,string $message):void{if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
function etg_evidence_same($expected,$actual,string $message):void{if($expected!==$actual){fwrite(STDERR,"FAIL: $message\nEXPECTED ".var_export($expected,true)."\nACTUAL ".var_export($actual,true)."\n");exit(1);}}

$GLOBALS['etg_evidence_filters']=array();
function add_filter($hook,$callback,$priority=10,$acceptedArgs=1){$GLOBALS['etg_evidence_filters'][$hook][]=$callback;return true;}

$root=dirname(__DIR__);
require_once $root.'/includes/Diagnostics/EvidenceProvider.php';

use ETG\DynamicFilterSEOBridge\Diagnostics\EvidenceProvider;

$surfaces=array(
    array(
        'template_id'=>30843,'node_id'=>'empty-filter','widget_type'=>'jet-smart-filters-checkboxes','surface_role'=>'definition_candidate',
        'filter_identity_expected'=>true,'filter_id'=>0,'identity_resolution_status'=>'empty','identity_resolution_reason'=>'empty_filter_assignment',
        'identity_source'=>'filter_id','resolution_reason'=>'filter_id_unresolved','query_id'=>'tours_query_archive','content_provider'=>'jet-engine',
    ),
    array(
        'template_id'=>30843,'node_id'=>'guide-language','widget_type'=>'jet-smart-filters-checkboxes','surface_role'=>'definition_candidate',
        'filter_identity_expected'=>true,'filter_id'=>15034,'identity_resolution_status'=>'resolved','identity_resolution_reason'=>'resolved',
        'identity_source'=>'filter_id','resolution_reason'=>'resolved','definition_available'=>true,'definition_lifecycle_status'=>'resolved','definition_reason'=>'resolved',
        'data_source'=>'taxonomies','source_taxonomy'=>'guide-languages_jet','query_var'=>'_tax_query::guides-language','custom_query_enabled'=>false,
        'custom_query_var'=>'','query_builder_query'=>'14','target_taxonomy'=>'guides-language','target_source'=>'query_var',
        'taxonomy_semantic_status'=>'mismatch_observed','query_id'=>'tours_query_archive','content_provider'=>'jet-engine',
    ),
    array(
        'template_id'=>40518,'node_id'=>'missing-definition','widget_type'=>'jet-smart-filters-checkboxes','surface_role'=>'definition_candidate',
        'filter_identity_expected'=>true,'filter_id'=>16084,'identity_resolution_status'=>'resolved','identity_resolution_reason'=>'resolved',
        'identity_source'=>'filter_id','resolution_reason'=>'filter_definition_unavailable','definition_available'=>false,
        'definition_lifecycle_status'=>'missing','definition_reason'=>'filter_post_missing','definition_post_exists'=>false,
        'query_id'=>'property_query_archive','content_provider'=>'jet-engine',
    ),
);

$snapshot=array(
    'contract'=>'etg.dfsb.runtime-inventory.v2','expected_contract'=>'etg.dfsb.runtime-inventory.v2','authorizing'=>false,'read_only'=>true,'profile_mutation'=>false,
    'evidence_complete'=>true,'availability_errors'=>array(),'snapshot_fingerprint'=>'snapshot-785f','collected_at_gmt'=>'2026-09-12T20:00:00+00:00',
    'inventory'=>array(
        'availability'=>array('post_types'=>array('available'=>true),'taxonomies'=>array('available'=>true),'languages'=>array('available'=>true),'query_builder'=>array('available'=>true),'archive_path_translations'=>array('available'=>true)),
        'completeness'=>array('query_identity_index'=>array('truncated'=>false)),
        'jet_smart_filters'=>array(
            'contract'=>'etg.dfsb.jet-smart-filters-definition-inspection.v1','diagnostic_contract'=>'etg.dfsb.jet-smart-filters-diagnostic.v2',
            'authorizing'=>false,'read_only'=>true,'profile_mutation'=>false,'available'=>true,'cache_scope'=>'request_memory_only',
            'templates_scanned'=>470,'elements_scanned'=>8857,'truncated'=>false,'surface_count'=>519,'candidate_surface_count'=>326,'control_surface_count'=>193,
            'resolved_surface_count'=>312,'unresolved_surface_count'=>14,'identity_resolution_counts'=>array('empty'=>14,'resolved'=>312),'identity_resolution_counts_truncated'=>false,
            'surfaces'=>$surfaces,'surfaces_truncated'=>false,'definition_count'=>34,'definition_available_count'=>32,'definition_unavailable_count'=>2,
            'definition_reason_counts'=>array('filter_post_missing'=>1,'resolved'=>32),'definition_unavailable'=>array(
                array('filter_id'=>16084,'definition_lifecycle_status'=>'missing','definition_reason'=>'filter_post_missing','post_exists'=>false,'post_status'=>'','post_type'=>'','authorizing'=>false),
            ),
            'definition_unavailable_truncated'=>false,'evidence_complete'=>false,'evidence_state'=>'incomplete','evidence_reasons'=>array('filter_identity_unresolved','filter_definition_unavailable'),
            'drift_count'=>1,'drift'=>array(
                array('filter_id'=>15034,'template_id'=>30843,'node_id'=>'guide-language','query_id'=>'tours_query_archive','status'=>'mismatch_observed','reason'=>'source_taxonomy_query_target_mismatch_observed','severity_hint'=>'review','semantic_status'=>'mismatch_observed','source_taxonomy'=>'guide-languages_jet','target_taxonomy'=>'guides-language','authorizing'=>false),
            ),'drift_truncated'=>false,'topology_filter_surface_count'=>519,'topology_parity_checked'=>true,'topology_surface_parity'=>true,
        ),
        'elementor_topology'=>array(
            'contract'=>'etg.dfsb.runtime-topology.v1','authorizing'=>false,'read_only'=>true,'profile_mutation'=>false,'available'=>true,
            'templates_scanned'=>470,'query_builder_records_observed'=>209,'elements_scanned'=>8857,'truncated'=>false,'binding_count'=>64,'bindings_truncated'=>false,
            'query_surface_count'=>519,'query_surfaces_truncated'=>false,'template_reference_count'=>173,'template_references_truncated'=>false,
            'bindings'=>array(
                array('provider'=>'jet-engine','provider_query_id'=>'tours_query_archive','status'=>'verified','reason'=>'verified','query_builder_internal_id'=>'5','query_builder_custom_query_id'=>'tours_query_archive','query_type'=>'posts','post_types'=>array('tours-and-activities'),'template_ids'=>array(30843),'evidence_count'=>6),
            ),
            'provider_group_drift_count'=>1,'provider_group_drift_truncated'=>false,'provider_group_drift'=>array(
                array('template_id'=>44320,'node_id'=>'b417678','widget_type'=>'jet-smart-filters-sorting','content_provider'=>'jet-engine','provider_query_id'=>'trans_query_archive','observed_provider_query_id'=>'trans_query_archive','expected_provider_query_ids'=>array('property_query_archive'),'reason'=>'provider_group_post_type_mismatch','severity_hint'=>'blocking','authorizing'=>false),
            ),
        ),
    ),
);

$profiles=array(
    'tours'=>array(
        'id'=>'tours','enabled'=>false,'post_types'=>array('tours-and-activities'),'require_post_type_binding'=>true,'post_type_authority'=>'query_builder',
        'archive_paths'=>array('/tours/'),'routes'=>array(array('provider'=>'jet-engine','query_id'=>'tours_query_archive')),
        'taxonomy_rules'=>array('guide-languages_jet'=>array('role'=>'guide_language')),'allowed_taxonomy_sets'=>array(array('guide-languages_jet')),
        'publication'=>array('sitemap'=>false,'provider_observation_verified'=>false,'result_count_parity_verified'=>false),
    ),
    'properties'=>array(
        'id'=>'properties','enabled'=>false,'post_types'=>array('properties'),'require_post_type_binding'=>true,'post_type_authority'=>'query_builder',
        'archive_paths'=>array('/properties/'),'routes'=>array(array('provider'=>'jet-engine','query_id'=>'property_query_archive')),
        'taxonomy_rules'=>array(),'allowed_taxonomy_sets'=>array(),
        'publication'=>array('sitemap'=>false,'provider_observation_verified'=>false,'result_count_parity_verified'=>false),
    ),
);

$reconciliation=array(
    'contract'=>'etg.dfsb.inventory-reconciliation.v3','authorizing'=>false,'read_only'=>true,'profile_mutation'=>false,'requires_operator_review'=>true,
    'state'=>'review_required','summary'=>array('blocking'=>1,'warnings'=>1,'info'=>0,'profiles'=>2),
    'findings'=>array(
        array('severity'=>'warning','code'=>'profile_filter_taxonomy_target_review','scope'=>'profile:tours','details'=>array('provider_query_id'=>'tours_query_archive','profile_enabled'=>false,'authorizing'=>false,'requires_operator_review'=>true)),
        array('severity'=>'blocking','code'=>'profile_provider_group_drift','scope'=>'profile:properties','details'=>array('provider_query_id'=>'property_query_archive','profile_enabled'=>false,'authorizing'=>false)),
    ),
);

$provider=new EvidenceProvider(
    static function()use($snapshot):array{return $snapshot;},
    static function(array $current,array $currentProfiles)use($reconciliation):array{unset($current,$currentProfiles);return $reconciliation;},
    static function()use($profiles):array{return $profiles;}
);

$descriptor=$provider->descriptor();
etg_evidence_same('etg.dfsb.evidence-provider.v1',$descriptor['contract'],'provider contract is versioned');
etg_evidence_same(false,$descriptor['authorizing'],'provider is non-authorizing');
etg_evidence_same(true,$descriptor['read_only'],'provider is read-only');
etg_evidence_same(false,$descriptor['transport_owned_by_provider'],'transport stays outside ETG');
etg_evidence_expect(in_array('filters',$descriptor['sections'],true),'filter section advertised');

$summary=$provider->query(array('section'=>'summary'));
etg_evidence_same('ok',$summary['state'],'summary query succeeds');
etg_evidence_same('snapshot-785f',$summary['snapshot_fingerprint'],'summary preserves snapshot provenance');
etg_evidence_same('etg.dfsb.jet-smart-filters-diagnostic.v2',$summary['payload']['jet_smart_filters']['diagnostic_contract'],'diagnostic v2 is visible without full inventory');
etg_evidence_same(14,$summary['payload']['jet_smart_filters']['unresolved_surface_count'],'summary preserves unresolved count');
etg_evidence_same(1,$summary['payload']['elementor_topology']['provider_group_drift_count'],'summary preserves provider drift count');

$unresolved=$provider->query(array('section'=>'unresolved_surfaces','limit'=>1));
etg_evidence_same(1,$unresolved['payload']['total'],'only unresolved identity is returned');
etg_evidence_same('empty_filter_assignment',$unresolved['payload']['items'][0]['identity_resolution_reason'],'empty assignment classification survives projection');
etg_evidence_same(false,$unresolved['payload']['has_more'],'bounded unresolved response is complete');

$filters=$provider->query(array('section'=>'filters','filter_ids'=>array(15034,16084),'limit'=>50));
etg_evidence_same(array(15034,16084),$filters['payload']['requested_filter_ids'],'requested filter IDs are explicit');
etg_evidence_same(2,$filters['payload']['total'],'only selected filter surfaces returned');
etg_evidence_same('filter_post_missing',$filters['payload']['definition_issues'][0]['definition_reason'],'lifecycle issue is preserved');
etg_evidence_same('review',$filters['payload']['semantic_drift'][0]['severity_hint'],'semantic mismatch stays review-only');

$dedupeInput=array_fill(0,50,15034);$dedupeInput[]=16084;
$deduped=$provider->query(array('section'=>'filters','filter_ids'=>$dedupeInput));
etg_evidence_same('ok',$deduped['state'],'duplicates do not consume the unique-ID budget');
etg_evidence_same(array(15034,16084),$deduped['payload']['requested_filter_ids'],'filter IDs are deduplicated before enforcing the ceiling');

$tooMany=array();for($i=1;$i<=51;$i++){$tooMany[]=$i;}
$overflow=$provider->query(array('section'=>'filters','filter_ids'=>$tooMany));
etg_evidence_same('invalid_request',$overflow['state'],'more than 50 unique filter IDs fail closed');
etg_evidence_expect(in_array('filter_ids_limit_exceeded',$overflow['errors'],true),'filter ID overflow reason is explicit');
etg_evidence_same(51,$overflow['payload']['requested_unique_filter_ids'],'overflow reports the observed unique count without truncating silently');

$negativeOnly=$provider->query(array('section'=>'filters','filter_ids'=>array(-15034,0)));
etg_evidence_same('invalid_request',$negativeOnly['state'],'non-positive filter IDs are not rewritten into valid IDs');
etg_evidence_expect(in_array('filter_ids_required',$negativeOnly['errors'],true),'non-positive IDs do not pass the positive-ID contract');

$tours=$provider->query(array('section'=>'profile_reconciliation','profile_id'=>'tours'));
etg_evidence_same('tours',$tours['payload']['profile_id'],'profile reconciliation is scoped');
etg_evidence_same(false,$tours['payload']['profile']['enabled'],'disabled profile status is preserved');
etg_evidence_same(1,$tours['payload']['total'],'unrelated reconciliation findings are excluded');
etg_evidence_same('profile_filter_taxonomy_target_review',$tours['payload']['items'][0]['code'],'Tours review finding is returned');
etg_evidence_same('tours_query_archive',$tours['payload']['route_bindings'][0]['provider_query_id'],'route binding evidence is returned');
etg_evidence_same(0,count($tours['payload']['route_provider_group_drift']),'observed misbound query ID does not attach unrelated drift to Tours');

$properties=$provider->query(array('section'=>'profile_reconciliation','profile_id'=>'properties'));
etg_evidence_same('ok',$properties['state'],'expected-route provider drift remains queryable');
etg_evidence_same(1,count($properties['payload']['route_provider_group_drift']),'drift is attached to the profile whose route appears in expected_provider_query_ids');
etg_evidence_same('property_query_archive',$properties['payload']['route_provider_group_drift'][0]['expected_provider_query_ids'][0],'canonical expected route identity is preserved');
etg_evidence_same('trans_query_archive',$properties['payload']['route_provider_group_drift'][0]['observed_provider_query_id'],'misbound observed route remains visible as evidence');

$drift=$provider->query(array('section'=>'provider_group_drift','template_id'=>44320,'node_id'=>'b417678'));
etg_evidence_same(1,$drift['payload']['total'],'targeted provider drift is returned');
etg_evidence_same('provider_group_post_type_mismatch',$drift['payload']['items'][0]['reason'],'provider drift reason preserved');
etg_evidence_same(false,$drift['payload']['items'][0]['authorizing'],'provider drift remains non-authorizing');

$unknownProfile=$provider->query(array('section'=>'profile_reconciliation','profile_id'=>'missing-profile'));
etg_evidence_same('invalid_request',$unknownProfile['state'],'unknown profile remains a client request error');
etg_evidence_expect(in_array('profile_not_found',$unknownProfile['errors'],true),'unknown profile reason is explicit');

$snapshotFailure=new EvidenceProvider(
    static function():array{throw new RuntimeException('snapshot failure');},
    static function(array $current,array $currentProfiles):array{unset($current,$currentProfiles);return array();},
    static function():array{return array();}
);
$snapshotUnavailable=$snapshotFailure->query(array('section'=>'summary'));
etg_evidence_same('provider_unavailable',$snapshotUnavailable['state'],'runtime inventory callback failure is provider_unavailable');
etg_evidence_expect(in_array('runtime_inventory_unavailable',$snapshotUnavailable['errors'],true),'runtime inventory failure reason is explicit');

$profileFailure=new EvidenceProvider(
    static function()use($snapshot):array{return $snapshot;},
    static function(array $current,array $currentProfiles)use($reconciliation):array{unset($current,$currentProfiles);return $reconciliation;},
    static function():array{throw new RuntimeException('profile registry failure');}
);
$profilesUnavailable=$profileFailure->query(array('section'=>'profile_reconciliation','profile_id'=>'tours'));
etg_evidence_same('provider_unavailable',$profilesUnavailable['state'],'ProfileRegistry callback failure is not misclassified as invalid_request');
etg_evidence_expect(in_array('profile_registry_unavailable',$profilesUnavailable['errors'],true),'ProfileRegistry failure reason is explicit');

$reconciliationFailure=new EvidenceProvider(
    static function()use($snapshot):array{return $snapshot;},
    static function(array $current,array $currentProfiles):array{unset($current,$currentProfiles);throw new RuntimeException('reconciliation failure');},
    static function()use($profiles):array{return $profiles;}
);
$reconciliationUnavailable=$reconciliationFailure->query(array('section'=>'profile_reconciliation','profile_id'=>'tours'));
etg_evidence_same('provider_unavailable',$reconciliationUnavailable['state'],'reconciliation callback failure is not misclassified as invalid_request');
etg_evidence_expect(in_array('reconciliation_unavailable',$reconciliationUnavailable['errors'],true),'reconciliation failure reason is explicit');

$invalid=$provider->query(array('section'=>'not-a-section'));
etg_evidence_same('invalid_request',$invalid['state'],'unknown section fails closed before transport work');
etg_evidence_expect(in_array('unsupported_section',$invalid['errors'],true),'invalid section reason is explicit');

$provider->register();
etg_evidence_expect(isset($GLOBALS['etg_evidence_filters']['mad4b_mcp_evidence_providers']),'central provider registry hook is registered');
etg_evidence_expect(isset($GLOBALS['etg_evidence_filters']['etg_dfsb_evidence_provider']),'native ETG provider hook is registered');
$registry=call_user_func($GLOBALS['etg_evidence_filters']['mad4b_mcp_evidence_providers'][0],array());
etg_evidence_same('etg-dfsb',$registry['etg-dfsb']['provider_id'],'central registry receives ETG provider');
etg_evidence_expect(is_callable($registry['etg-dfsb']['query_callback']),'central registry exposes bounded query callback');

echo "Alpha13 bounded evidence provider smoke tests passed.\n";
