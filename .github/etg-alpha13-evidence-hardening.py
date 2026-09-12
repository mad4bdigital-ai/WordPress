from pathlib import Path
import re

root = Path('wp-content/plugins/etg-dynamic-filter-seo-bridge')

def replace_once(text, old, new, label):
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{label}: expected 1 match, got {count}')
    return text.replace(old, new, 1)

# 1) JetSmartFilters observation completeness.
path = root / 'includes/JetSmartFilters/FilterDefinitionInspector.php'
text = path.read_text()
text = replace_once(text, "        $surfaceCount = 0;\n        $truncated = false;", "        $surfaceCount = 0;\n        $resolvedSurfaceCount = 0;\n        $unresolvedSurfaceCount = 0;\n        $truncated = false;", 'inspector counters')
text = replace_once(text, "            $this->walk( $data, $templateId, $surfaces, $definitions, $surfaceCount, $elementsScanned, $truncated );", "            $this->walk( $data, $templateId, $surfaces, $definitions, $surfaceCount, $resolvedSurfaceCount, $unresolvedSurfaceCount, $elementsScanned, $truncated );", 'inspector walk call')
text = replace_once(text, "        $available = ! empty( $templates['available'] ) && $definitionSourceAvailable;", "        $available = ! empty( $templates['available'] ) && $definitionSourceAvailable;\n        $definitionAvailableCount = 0;\n        foreach ( $definitions as $definition ) { if ( is_array( $definition ) && ! empty( $definition['available'] ) ) { $definitionAvailableCount++; } }\n        $definitionUnavailableCount = max( 0, count( $definitions ) - $definitionAvailableCount );\n        $evidenceReasons = array();\n        if ( ! $available ) { $evidenceReasons[] = 'definition_sources_unavailable'; }\n        if ( $unresolvedSurfaceCount > 0 ) { $evidenceReasons[] = 'filter_identity_unresolved'; }\n        if ( $definitionUnavailableCount > 0 ) { $evidenceReasons[] = 'filter_definition_unavailable'; }\n        if ( $truncated || ! empty( $templates['truncated'] ) || $surfaceCount > self::MAX_SURFACES ) { $evidenceReasons[] = 'filter_observation_truncated'; }\n        $evidenceReasons = array_values( array_unique( $evidenceReasons ) );\n        $evidenceComplete = $available && empty( $evidenceReasons );\n        $evidenceState = ! $available ? 'unavailable' : ( $evidenceComplete ? 'complete' : 'incomplete' );", 'inspector evidence state')
text = replace_once(text, "            'surface_count' => $surfaceCount,\n            'surfaces' => array_slice( $surfaces, 0, self::MAX_SURFACES ),\n            'surfaces_truncated' => $surfaceCount > self::MAX_SURFACES,\n            'definition_count' => count( $definitions ),", "            'surface_count' => $surfaceCount,\n            'candidate_surface_count' => $surfaceCount,\n            'resolved_surface_count' => $resolvedSurfaceCount,\n            'unresolved_surface_count' => $unresolvedSurfaceCount,\n            'surfaces' => array_slice( $surfaces, 0, self::MAX_SURFACES ),\n            'surfaces_truncated' => $surfaceCount > self::MAX_SURFACES,\n            'definition_count' => count( $definitions ),\n            'definition_available_count' => $definitionAvailableCount,\n            'definition_unavailable_count' => $definitionUnavailableCount,\n            'evidence_complete' => $evidenceComplete,\n            'evidence_state' => $evidenceState,\n            'evidence_reasons' => $evidenceReasons,", 'inspector result evidence')
text = replace_once(text, "    private function walk( array $nodes, int $templateId, array &$surfaces, array &$definitions, int &$surfaceCount, int &$elementsScanned, bool &$truncated ): void {", "    private function walk( array $nodes, int $templateId, array &$surfaces, array &$definitions, int &$surfaceCount, int &$resolvedSurfaceCount, int &$unresolvedSurfaceCount, int &$elementsScanned, bool &$truncated ): void {", 'inspector walk signature')
old_block = re.compile(r"            if \( 0 === strpos\( \$widgetType, 'jet-smart-filters-' \) \) \{.*?\n            \}\n            if \( isset\( \$node\['elements'\] \) && is_array\( \$node\['elements'\] \) \) \{\n                \$this->walk\( \$node\['elements'\], \$templateId, \$surfaces, \$definitions, \$surfaceCount, \$elementsScanned, \$truncated \);\n            \}", re.S)
new_block = """            if ( 0 === strpos( $widgetType, 'jet-smart-filters-' ) ) {
                $surfaceCount++;
                $filterId = $this->filterId( $settings );
                $definition = array();
                $resolutionReason = 'filter_id_unresolved';
                if ( $filterId > 0 ) {
                    $resolvedSurfaceCount++;
                    if ( ! array_key_exists( $filterId, $definitions ) ) { $definitions[ $filterId ] = $this->definition( $filterId ); }
                    $definition = is_array( $definitions[ $filterId ] ) ? $definitions[ $filterId ] : array();
                    $resolutionReason = ! empty( $definition['available'] ) ? 'resolved' : 'filter_definition_unavailable';
                } else {
                    $unresolvedSurfaceCount++;
                }
                if ( count( $surfaces ) < self::MAX_SURFACES ) {
                    $surfaces[] = array(
                        'template_id'=>$templateId,
                        'node_id'=>sanitize_text_field( (string) ( $node['id'] ?? '' ) ),
                        'widget_type'=>$widgetType,
                        'filter_id'=>$filterId,
                        'filter_identity_resolved'=>$filterId > 0,
                        'resolution_reason'=>$resolutionReason,
                        'query_id'=>QueryId::normalize( $settings['query_id'] ?? '' ),
                        'content_provider'=>sanitize_key( (string) ( $settings['content_provider'] ?? '' ) ),
                        'definition_available'=>! empty( $definition['available'] ),
                        'data_source'=>sanitize_key( (string) ( $definition['data_source'] ?? '' ) ),
                        'source_taxonomy'=>sanitize_key( (string) ( $definition['source_taxonomy'] ?? '' ) ),
                        'target_taxonomy'=>sanitize_key( (string) ( $definition['target_taxonomy'] ?? '' ) ),
                        'target_source'=>sanitize_key( (string) ( $definition['target_source'] ?? '' ) ),
                        'query_var'=>sanitize_text_field( (string) ( $definition['query_var'] ?? '' ) ),
                        'custom_query_var'=>sanitize_text_field( (string) ( $definition['custom_query_var'] ?? '' ) ),
                    );
                }
            }
            if ( isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
                $this->walk( $node['elements'], $templateId, $surfaces, $definitions, $surfaceCount, $resolvedSurfaceCount, $unresolvedSurfaceCount, $elementsScanned, $truncated );
            }"""
text, count = old_block.subn(new_block, text, count=1)
if count != 1:
    raise SystemExit(f'inspector surface block: expected 1 match, got {count}')
old_definition = """        return array(
            'available'=>true,
            'data_source'=>$dataSource,
            'source_taxonomy'=>$sourceTaxonomy,
            'query_var'=>$queryVar,
            'custom_query_var'=>$customQueryVar,
            'custom_query_enabled'=>$customEnabled,
            'query_builder_query'=>sanitize_text_field( (string) ( $raw['query_builder_query'] ?? $raw['_query_builder_query'] ?? '' ) ),
            'target_taxonomy'=>$targetTaxonomy,
            'target_source'=>$targetSource,
        );"""
new_definition = """        $queryBuilderQuery = sanitize_text_field( (string) ( $raw['query_builder_query'] ?? $raw['_query_builder_query'] ?? '' ) );
        $definitionAvailable = '' !== $dataSource || '' !== $sourceTaxonomy || '' !== $queryVar || '' !== $customQueryVar || '' !== $queryBuilderQuery;
        return array(
            'available'=>$definitionAvailable,
            'data_source'=>$dataSource,
            'source_taxonomy'=>$sourceTaxonomy,
            'query_var'=>$queryVar,
            'custom_query_var'=>$customQueryVar,
            'custom_query_enabled'=>$customEnabled,
            'query_builder_query'=>$queryBuilderQuery,
            'target_taxonomy'=>$targetTaxonomy,
            'target_source'=>$targetSource,
        );"""
text = replace_once(text, old_definition, new_definition, 'inspector definition availability')
path.write_text(text)

# 2) Runtime Inventory cross-checks independent Elementor topology evidence.
path = root / 'includes/Diagnostics/RuntimeInventory.php'
text = path.read_text()
text = replace_once(text, "        $filterDefinitions = $this->filterDefinitions();", "        $filterDefinitions = $this->filterDefinitions();\n        $filterDefinitions = $this->reconcileFilterDefinitionEvidence( $filterDefinitions, $topology );", 'inventory filter parity call')
method = r'''
    private function reconcileFilterDefinitionEvidence( array $inspection, array $topology ): array {
        $candidateCount = (int) ( $inspection['candidate_surface_count'] ?? $inspection['surface_count'] ?? 0 );
        $topologyFilterSurfaceCount = 0;
        foreach ( (array) ( $topology['query_surfaces'] ?? array() ) as $surface ) {
            if ( ! is_array( $surface ) ) { continue; }
            $widgetType = sanitize_key( (string) ( $surface['widget_type'] ?? '' ) );
            if ( 0 === strpos( $widgetType, 'jet-smart-filters-' ) ) { $topologyFilterSurfaceCount++; }
        }
        $parityChecked = ! empty( $topology['available'] ) && empty( $topology['truncated'] );
        $parity = ! $parityChecked ? null : $candidateCount >= $topologyFilterSurfaceCount;
        $reasons = array_values( array_filter( array_map( 'sanitize_key', (array) ( $inspection['evidence_reasons'] ?? array() ) ) ) );
        if ( $parityChecked && false === $parity ) { $reasons[] = 'topology_filter_surface_parity_mismatch'; }
        $reasons = array_values( array_unique( $reasons ) );
        $baseComplete = ! empty( $inspection['evidence_complete'] );
        $inspection['topology_filter_surface_count'] = $topologyFilterSurfaceCount;
        $inspection['topology_parity_checked'] = $parityChecked;
        $inspection['topology_surface_parity'] = $parity;
        $inspection['evidence_reasons'] = $reasons;
        $inspection['evidence_complete'] = $baseComplete && empty( $reasons );
        $inspection['evidence_state'] = empty( $inspection['available'] ) ? 'unavailable' : ( $inspection['evidence_complete'] ? 'complete' : 'incomplete' );
        return $inspection;
    }
'''
close = text.rfind("\n}\n")
if close < 0:
    raise SystemExit('inventory class closing brace not found')
if 'reconcileFilterDefinitionEvidence' in text[text.find('private function filterDefinitions'):]:
    raise SystemExit('inventory parity method already present')
text = text[:close] + method + text[close:]
fallback = "            'definition_count'=>0,\n            'drift_count'=>0,"
text = replace_once(text, fallback, "            'definition_count'=>0,\n            'definition_available_count'=>0,\n            'definition_unavailable_count'=>0,\n            'candidate_surface_count'=>0,\n            'resolved_surface_count'=>0,\n            'unresolved_surface_count'=>0,\n            'evidence_complete'=>false,\n            'evidence_state'=>'unavailable',\n            'evidence_reasons'=>array('definition_inspector_unavailable'),\n            'drift_count'=>0,", 'inventory fallback evidence')
path.write_text(text)

# 3) Reconciler exposes incomplete filter evidence without converting it to authority.
path = root / 'includes/Diagnostics/InventoryReconcilerBindingTrait.php'
text = path.read_text()
old = "$filterInspection=(array)($inventory['jet_smart_filters']??array());$filterDriftCount=(int)($filterInspection['drift_count']??count((array)($filterInspection['drift']??array())));if($filterDriftCount>0){$out[]=$this->findingValue('warning','jetsmartfilters_taxonomy_target_drift_detected','inventory:jet_smart_filters',array('drift_count'=>$filterDriftCount,'drift_truncated'=>!empty($filterInspection['drift_truncated']),'examples'=>array_slice((array)($filterInspection['drift']??array()),0,10),'authorizing'=>false));}"
new = "$filterInspection=(array)($inventory['jet_smart_filters']??array());if(array_key_exists('evidence_complete',$filterInspection)&&empty($filterInspection['evidence_complete'])){$out[]=$this->findingValue('warning','jetsmartfilters_definition_inspection_incomplete','inventory:jet_smart_filters',array('evidence_state'=>(string)($filterInspection['evidence_state']??'incomplete'),'evidence_reasons'=>array_slice((array)($filterInspection['evidence_reasons']??array()),0,10),'candidate_surface_count'=>(int)($filterInspection['candidate_surface_count']??$filterInspection['surface_count']??0),'resolved_surface_count'=>(int)($filterInspection['resolved_surface_count']??0),'unresolved_surface_count'=>(int)($filterInspection['unresolved_surface_count']??0),'definition_count'=>(int)($filterInspection['definition_count']??0),'definition_unavailable_count'=>(int)($filterInspection['definition_unavailable_count']??0),'topology_filter_surface_count'=>(int)($filterInspection['topology_filter_surface_count']??0),'topology_parity_checked'=>!empty($filterInspection['topology_parity_checked']),'topology_surface_parity'=>$filterInspection['topology_surface_parity']??null,'authorizing'=>false));}$filterDriftCount=(int)($filterInspection['drift_count']??count((array)($filterInspection['drift']??array())));if($filterDriftCount>0){$out[]=$this->findingValue('warning','jetsmartfilters_taxonomy_target_drift_detected','inventory:jet_smart_filters',array('drift_count'=>$filterDriftCount,'drift_truncated'=>!empty($filterInspection['drift_truncated']),'examples'=>array_slice((array)($filterInspection['drift']??array()),0,10),'authorizing'=>false));}"
text = replace_once(text, old, new, 'reconciler incomplete filter evidence')
path.write_text(text)

# 4) Planner blocks only route-scoped proven provider-group drift.
path = root / 'includes/Diagnostics/InventoryProfilePlanner.php'
text = path.read_text()
old = """                $resolved = $this->resolveRoute( $provider, $providerQueryId, $route, $identityIndex, $conflicts, $topology );
                $routeEvidence[] = $resolved;
                if ( empty( $resolved['resolved'] ) ) {
                    $blocked[] = (string) ( $resolved['reason'] ?? 'route_unresolved' );
                    continue;
                }
                $postTypeSet = $this->keys( (array) ( $resolved['post_types'] ?? array() ) );"""
new = """                $resolved = $this->resolveRoute( $provider, $providerQueryId, $route, $identityIndex, $conflicts, $topology );
                if ( empty( $resolved['resolved'] ) ) {
                    $routeEvidence[] = $resolved;
                    $blocked[] = (string) ( $resolved['reason'] ?? 'route_unresolved' );
                    continue;
                }
                $routeDrift = $this->routeProviderGroupDrift( $providerQueryId, $topology );
                $resolved['provider_group_drift_count'] = count( $routeDrift );
                $resolved['provider_group_drift'] = $routeDrift;
                $routeEvidence[] = $resolved;
                if ( $routeDrift ) { $blocked[] = 'route_provider_group_drift'; }
                $postTypeSet = $this->keys( (array) ( $resolved['post_types'] ?? array() ) );"""
text = replace_once(text, old, new, 'planner route drift')
helper = r'''
    private function routeProviderGroupDrift( string $providerQueryId, array $topology ): array {
        $providerQueryId = $this->key( $providerQueryId );
        if ( '' === $providerQueryId ) { return array(); }
        $out = array();
        foreach ( (array) ( $topology['provider_group_drift'] ?? array() ) as $drift ) {
            if ( ! is_array( $drift ) || 'blocking' !== (string) ( $drift['severity_hint'] ?? 'warning' ) ) { continue; }
            $expected = array();
            foreach ( (array) ( $drift['expected_provider_query_ids'] ?? array() ) as $candidate ) {
                $candidate = $this->key( (string) $candidate );
                if ( '' !== $candidate ) { $expected[$candidate] = true; }
            }
            if ( ! isset( $expected[$providerQueryId] ) ) { continue; }
            $out[] = $drift;
            if ( count( $out ) >= 20 ) { break; }
        }
        return $out;
    }

'''
marker = "    private function identityIndex( array $queryBuilder ): array {"
text = replace_once(text, marker, helper + marker, 'planner drift helper')
path.write_text(text)

# 5) JetSmartFilters regression: 0 definitions must not mean clean evidence.
path = root / 'tests/alpha13-filter-definition-drift-smoke.php'
text = path.read_text()
text = replace_once(text, "etg_filter_drift_same(2,$inspection['definition_count'],'both distinct filter definitions are read once');\netg_filter_drift_same(1,$inspection['drift_count'],'only Guide Language source/target mismatch is drift');", "etg_filter_drift_same(2,$inspection['definition_count'],'both distinct filter definitions are read once');\netg_filter_drift_same(2,$inspection['candidate_surface_count'],'both JetSmartFilters widgets are candidate surfaces');\netg_filter_drift_same(2,$inspection['resolved_surface_count'],'both candidate surfaces resolve filter identity');\netg_filter_drift_same(0,$inspection['unresolved_surface_count'],'no filter identity is silently dropped');\netg_filter_drift_same(2,$inspection['definition_available_count'],'both filter definitions have observable metadata');\netg_filter_drift_same(0,$inspection['definition_unavailable_count'],'no resolved filter definition is unavailable');\netg_filter_drift_same(true,$inspection['evidence_complete'],'complete injected evidence is explicit');\netg_filter_drift_same('complete',$inspection['evidence_state'],'complete injected evidence has explicit state');\netg_filter_drift_same(1,$inspection['drift_count'],'only Guide Language source/target mismatch is drift');", 'filter regression complete counters')
marker = "$queryRecords=array("
addition = r'''$incompleteTemplateProvider=function(){return array(
    array('id'=>30843,'data'=>array(
        array('id'=>'unresolved-filter','elType'=>'widget','widgetType'=>'jet-smart-filters-checkboxes','settings'=>array('query_id'=>'tours_query_archive','content_provider'=>'jet-engine')),
    )),
);};
$incompleteInspection=(new FilterDefinitionInspector($incompleteTemplateProvider,$filterProvider))->inspect();
etg_filter_drift_same(1,$incompleteInspection['surface_count'],'JetSmartFilters widget is counted even when filter identity is unresolved');
etg_filter_drift_same(1,$incompleteInspection['candidate_surface_count'],'candidate surface count preserves unresolved widget');
etg_filter_drift_same(0,$incompleteInspection['resolved_surface_count'],'unresolved filter identity is not fabricated');
etg_filter_drift_same(1,$incompleteInspection['unresolved_surface_count'],'unresolved filter identity is explicit');
etg_filter_drift_same(0,$incompleteInspection['definition_count'],'no synthetic definition is created');
etg_filter_drift_same(false,$incompleteInspection['evidence_complete'],'unresolved filter identity makes definition evidence incomplete');
etg_filter_drift_same('incomplete',$incompleteInspection['evidence_state'],'unresolved filter identity is not reported as clean');
etg_filter_drift_expect(in_array('filter_identity_unresolved',$incompleteInspection['evidence_reasons'],true),'incomplete evidence names unresolved filter identity');
etg_filter_drift_same('filter_id_unresolved',$incompleteInspection['surfaces'][0]['resolution_reason'],'surface records why definition lookup could not run');

$parityTopology=array(
    'available'=>true,'truncated'=>false,
    'query_surfaces'=>array(
        array('widget_type'=>'jet-smart-filters-checkboxes','query_id'=>'tours_query_archive'),
        array('widget_type'=>'jet-smart-filters-select','query_id'=>'tours_query_archive'),
    ),
);
$reflection=new ReflectionMethod(RuntimeInventory::class,'reconcileFilterDefinitionEvidence');
$reflection->setAccessible(true);
$reconciledIncomplete=$reflection->invoke(new RuntimeInventory(),$incompleteInspection,$parityTopology);
etg_filter_drift_same(2,$reconciledIncomplete['topology_filter_surface_count'],'independent topology counts JetSmartFilters query surfaces');
etg_filter_drift_same(true,$reconciledIncomplete['topology_parity_checked'],'topology parity is checked only with complete topology evidence');
etg_filter_drift_same(false,$reconciledIncomplete['topology_surface_parity'],'topology seeing more filter surfaces prevents false-clean evidence');
etg_filter_drift_same(false,$reconciledIncomplete['evidence_complete'],'topology parity mismatch remains incomplete');
etg_filter_drift_expect(in_array('topology_filter_surface_parity_mismatch',$reconciledIncomplete['evidence_reasons'],true),'parity mismatch is explicit evidence');

'''
text = replace_once(text, marker, addition + marker, 'filter regression incomplete evidence')
marker = "$reconciler=new InventoryReconciler();"
addition = r'''$reconciler=new InventoryReconciler();
$incompleteInventory=$inventory;
$incompleteInventory['jet_smart_filters']=$reconciledIncomplete;
$incompleteSnapshot=$snapshot;
$incompleteSnapshot['inventory']=$incompleteInventory;
$incompleteSnapshot['snapshot_fingerprint']=hash('sha256',json_encode($incompleteInventory,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
$incompleteResult=$reconciler->analyze($incompleteSnapshot,array('tours'=>$profile));
$incompleteFindings=array_values(array_filter($incompleteResult['findings'],static function($finding){return 'jetsmartfilters_definition_inspection_incomplete'===(string)($finding['code']??'');}));
etg_filter_drift_same(1,count($incompleteFindings),'incomplete filter-definition evidence is visible in reconciliation');
etg_filter_drift_same('warning',$incompleteFindings[0]['severity'],'incomplete global filter evidence remains non-authorizing review evidence');
etg_filter_drift_same(2,$incompleteFindings[0]['details']['topology_filter_surface_count'],'reconciliation preserves independent topology count');
'''
text = replace_once(text, marker, addition, 'filter reconciliation warning regression')
path.write_text(text)

# 6) Planner regression: global Properties drift cannot poison Tours; Properties route itself is blocked.
path = root / 'tests/alpha12-inventory-control-smoke.php'
text = path.read_text()
old = """  array('provider'=>'jet-engine','provider_query_id'=>'property_query_archive','status'=>'verified','query_builder_internal_id'=>'239','query_builder_custom_query_id'=>'properties_archive_qb','query_type'=>'posts','post_types'=>array('properties'),'template_ids'=>array(37924)),
 )),
));"""
new = """  array('provider'=>'jet-engine','provider_query_id'=>'property_query_archive','status'=>'verified','query_builder_internal_id'=>'239','query_builder_custom_query_id'=>'properties_archive_qb','query_type'=>'posts','post_types'=>array('properties'),'template_ids'=>array(37924)),
 )),
 'provider_group_drift'=>array(array(
  'template_id'=>37924,'node_id'=>'property-sort','widget_type'=>'jet-smart-filters-sorting','observed_query_id'=>'trans_query_archive',
  'expected_provider_query_ids'=>array('property_query_archive'),'expected_post_types'=>array('properties'),'observed_post_types'=>array('transportations'),
  'reason'=>'provider_group_post_type_mismatch','severity_hint'=>'blocking','authorizing'=>false,
 )),
 'provider_group_drift_count'=>1,'provider_group_drift_truncated'=>false,
));"""
text = replace_once(text, old, new, 'alpha12 topology drift fixture')
marker = "$composed=array('id'=>'composed'"
addition = "$propertiesProfile=array('id'=>'properties','enabled'=>false,'post_types'=>array(),'require_post_type_binding'=>false,'post_type_authority'=>'query_builder','archive_paths'=>array('/properties/'),'routes'=>array(array('provider'=>'jet-engine','query_id'=>'property_query_archive')),'taxonomy_rules'=>array('property-location'=>array('role'=>'location')),'allowed_taxonomy_sets'=>array());\n"
text = replace_once(text, marker, addition + marker, 'alpha12 properties profile')
text = replace_once(text, "$plan=(new InventoryProfilePlanner())->plan($snapshot,array('tours'=>$profile,'composed'=>$composed));", "$plan=(new InventoryProfilePlanner())->plan($snapshot,array('tours'=>$profile,'properties'=>$propertiesProfile,'composed'=>$composed));", 'alpha12 plan profiles')
marker = "$c=$plan['proposals']['composed'];"
addition = r'''$propertiesPlan=$plan['proposals']['properties'];
expect_same(false,$propertiesPlan['safe_to_apply'],'route-scoped blocking provider drift prevents structural apply');
expect_same('blocked',$propertiesPlan['status'],'affected profile is blocked by its own route drift');
expect_true(in_array('route_provider_group_drift',$propertiesPlan['blocking_reasons'],true),'route-scoped provider drift has explicit planner blocker');
expect_same(1,$propertiesPlan['route_evidence'][0]['provider_group_drift_count'],'route evidence carries only matching blocking drift');
expect_same('property-sort',$propertiesPlan['route_evidence'][0]['provider_group_drift'][0]['node_id'],'planner blocker points to affected Properties surface');
expect_true(!in_array('route_provider_group_drift',$p['blocking_reasons'],true),'Properties drift does not create a false Tours blocker');
'''
text = replace_once(text, marker, addition + marker, 'alpha12 route scoped drift assertions')
path.write_text(text)

# 7) Contract notes are additive and non-authorizing.
path = Path('specs/001-etg-dynamic-filter-seo-operational/contracts/alpha11-runtime-topology-dynamic-content-contract.md')
text = path.read_text()
anchor = "9. Filter-definition diagnostics must never mutate JetSmartFilters posts/meta, Elementor templates, Profiles, URLs, or SEO publication authority.\n"
addition = """10. JetSmartFilters definition inspection distinguishes source availability from observation completeness. Every observed JetSmartFilters widget is a candidate surface even when its filter identity cannot be resolved; unresolved identities and unavailable definitions remain explicit evidence rather than disappearing as zero-count clean state.
11. Runtime Inventory may cross-check JetSmartFilters candidate surfaces against independent Elementor topology query-surface evidence. When complete topology observes more JetSmartFilters query surfaces than the definition inspector, the inspection is `incomplete` with `topology_filter_surface_parity_mismatch`; it is never treated as clean drift evidence.
12. Incomplete JetSmartFilters definition evidence remains non-authorizing inventory warning evidence. It does not grant or revoke Profile, URL, canonical, sitemap or indexing authority by itself.
"""
text = replace_once(text, anchor, anchor + addition, 'alpha11 filter completeness contract')
path.write_text(text)

path = Path('specs/001-etg-dynamic-filter-seo-operational/contracts/alpha12-inventory-control-contract.md')
text = path.read_text()
anchor = "14. All writes remain reversible through the authoritative Surface Profiles JSON and are followed by fresh Reconciliation before activation.\n"
addition = "15. Planner safety incorporates only proven blocking provider-group drift scoped to the exact profile route through `expected_provider_query_ids`; site-wide drift on unrelated routes remains visible evidence and must not create a false blocker for an aligned profile.\n"
text = replace_once(text, anchor, anchor + addition, 'alpha12 route drift contract')
path.write_text(text)
