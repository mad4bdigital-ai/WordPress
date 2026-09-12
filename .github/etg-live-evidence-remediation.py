from pathlib import Path
import re

root = Path('wp-content/plugins/etg-dynamic-filter-seo-bridge')

# 1) JetSmartFilters live identity extraction and control-surface semantics.
inspector = root / 'includes/JetSmartFilters/FilterDefinitionInspector.php'
text = inspector.read_text()
if text.count('    const MAX_TEMPLATES = 250;') != 1:
    raise SystemExit('FilterDefinitionInspector MAX_TEMPLATES guard failed')
text = text.replace('    const MAX_TEMPLATES = 250;', '    const MAX_TEMPLATES = 500;', 1)
old = "        $surfaceCount = 0;\n        $resolvedSurfaceCount = 0;"
new = "        $surfaceCount = 0;\n        $candidateSurfaceCount = 0;\n        $controlSurfaceCount = 0;\n        $resolvedSurfaceCount = 0;"
if text.count(old) != 1:
    raise SystemExit('FilterDefinitionInspector counter guard failed')
text = text.replace(old, new, 1)
old = "$this->walk( $data, $templateId, $surfaces, $definitions, $surfaceCount, $resolvedSurfaceCount, $unresolvedSurfaceCount, $elementsScanned, $truncated );"
new = "$this->walk( $data, $templateId, $surfaces, $definitions, $surfaceCount, $candidateSurfaceCount, $controlSurfaceCount, $resolvedSurfaceCount, $unresolvedSurfaceCount, $elementsScanned, $truncated );"
if text.count(old) != 1:
    raise SystemExit('FilterDefinitionInspector root walk guard failed')
text = text.replace(old, new, 1)
old = "            'candidate_surface_count' => $surfaceCount,\n            'resolved_surface_count' => $resolvedSurfaceCount,"
new = "            'candidate_surface_count' => $candidateSurfaceCount,\n            'control_surface_count' => $controlSurfaceCount,\n            'resolved_surface_count' => $resolvedSurfaceCount,"
if text.count(old) != 1:
    raise SystemExit('FilterDefinitionInspector result counters guard failed')
text = text.replace(old, new, 1)
pattern = r"    private function walk\(.*?\n    private function definition\( int \$filterId \): array \{"
match = re.search(pattern, text, re.S)
if not match:
    raise SystemExit('FilterDefinitionInspector walk/filterId block not found')
replacement = r'''    private function walk( array $nodes, int $templateId, array &$surfaces, array &$definitions, int &$surfaceCount, int &$candidateSurfaceCount, int &$controlSurfaceCount, int &$resolvedSurfaceCount, int &$unresolvedSurfaceCount, int &$elementsScanned, bool &$truncated ): void {
        foreach ( $nodes as $node ) {
            if ( $elementsScanned >= self::MAX_ELEMENTS ) { $truncated = true; return; }
            if ( ! is_array( $node ) ) { continue; }
            $elementsScanned++;
            $widgetType = sanitize_key( (string) ( $node['widgetType'] ?? '' ) );
            $settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
            if ( 0 === strpos( $widgetType, 'jet-smart-filters-' ) ) {
                $surfaceCount++;
                $identityExpected = $this->filterIdentityExpected( $widgetType );
                $filterId = 0;
                $definition = array();
                $resolutionReason = 'filter_identity_not_applicable';
                if ( $identityExpected ) {
                    $candidateSurfaceCount++;
                    $filterId = $this->filterId( $settings );
                    $resolutionReason = 'filter_id_unresolved';
                    if ( $filterId > 0 ) {
                        $resolvedSurfaceCount++;
                        if ( ! array_key_exists( $filterId, $definitions ) ) { $definitions[ $filterId ] = $this->definition( $filterId ); }
                        $definition = is_array( $definitions[ $filterId ] ) ? $definitions[ $filterId ] : array();
                        $resolutionReason = ! empty( $definition['available'] ) ? 'resolved' : 'filter_definition_unavailable';
                    } else {
                        $unresolvedSurfaceCount++;
                    }
                } else {
                    $controlSurfaceCount++;
                }
                if ( count( $surfaces ) < self::MAX_SURFACES ) {
                    $surfaces[] = array(
                        'template_id'=>$templateId,
                        'node_id'=>sanitize_text_field( (string) ( $node['id'] ?? '' ) ),
                        'widget_type'=>$widgetType,
                        'surface_role'=>$identityExpected ? 'definition_candidate' : 'control',
                        'filter_identity_expected'=>$identityExpected,
                        'filter_id'=>$filterId,
                        'filter_identity_resolved'=>$identityExpected && $filterId > 0,
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
                $this->walk( $node['elements'], $templateId, $surfaces, $definitions, $surfaceCount, $candidateSurfaceCount, $controlSurfaceCount, $resolvedSurfaceCount, $unresolvedSurfaceCount, $elementsScanned, $truncated );
            }
            if ( $truncated ) { return; }
        }
    }

    private function filterIdentityExpected( string $widgetType ): bool {
        $controls = array(
            'jet-smart-filters-active',
            'jet-smart-filters-active-tags',
            'jet-smart-filters-apply-button',
            'jet-smart-filters-items-number-switcher',
            'jet-smart-filters-listing',
            'jet-smart-filters-map-sync',
            'jet-smart-filters-pagination',
            'jet-smart-filters-remove-filters',
            'jet-smart-filters-sorting',
            'jet-smart-filters-user-geolocation',
        );
        return ! in_array( $widgetType, $controls, true );
    }

    private function filterId( array $settings ): int {
        $ids = array();
        foreach ( array( 'filter_id', 'filter' ) as $key ) {
            if ( ! array_key_exists( $key, $settings ) ) { continue; }
            $value = $settings[ $key ];
            if ( is_array( $value ) ) {
                if ( array_key_exists( 'id', $value ) ) { $value = array( $value['id'] ); }
                foreach ( $value as $candidate ) {
                    if ( is_scalar( $candidate ) && is_numeric( $candidate ) ) {
                        $id = absint( $candidate );
                        if ( $id > 0 ) { $ids[ $id ] = true; }
                    }
                }
            } elseif ( is_scalar( $value ) && is_numeric( $value ) ) {
                $id = absint( $value );
                if ( $id > 0 ) { $ids[ $id ] = true; }
            }
        }
        $keys = array_keys( $ids );
        return 1 === count( $keys ) ? (int) $keys[0] : 0;
    }

    private function definition( int $filterId ): array {'''
text = text[:match.start()] + replacement + text[match.end():]
inspector.write_text(text)

# 2) Bounded topology ceiling high enough for current Staging while MAX_ELEMENTS stays independent.
topology = root / 'includes/Runtime/RuntimeTopologyDiscoverer.php'
text = topology.read_text()
if text.count('    const MAX_TEMPLATES = 250;') != 1:
    raise SystemExit('RuntimeTopologyDiscoverer MAX_TEMPLATES guard failed')
topology.write_text(text.replace('    const MAX_TEMPLATES = 250;', '    const MAX_TEMPLATES = 500;', 1))

# 3) Parity compares total JSF surfaces, not only definition-bearing candidates.
inventory = root / 'includes/Diagnostics/RuntimeInventory.php'
text = inventory.read_text()
old = "            'candidate_surface_count'=>0,\n            'resolved_surface_count'=>0,"
new = "            'candidate_surface_count'=>0,\n            'control_surface_count'=>0,\n            'resolved_surface_count'=>0,"
if text.count(old) != 1:
    raise SystemExit('RuntimeInventory fallback counters guard failed')
text = text.replace(old, new, 1)
old = "        $candidateCount = (int) ( $inspection['candidate_surface_count'] ?? $inspection['surface_count'] ?? 0 );"
new = "        $observedSurfaceCount = (int) ( $inspection['surface_count'] ?? 0 );"
if text.count(old) != 1:
    raise SystemExit('RuntimeInventory parity counter guard failed')
text = text.replace(old, new, 1)
old = "$parity = ! $parityChecked ? null : $candidateCount >= $topologyFilterSurfaceCount;"
new = "$parity = ! $parityChecked ? null : $observedSurfaceCount >= $topologyFilterSurfaceCount;"
if text.count(old) != 1:
    raise SystemExit('RuntimeInventory parity expression guard failed')
inventory.write_text(text.replace(old, new, 1))

# 4) Read-only Safe Boot state evidence.
boot = root / 'includes/Runtime/BootGuard.php'
text = boot.read_text()
marker = '    private static function writeState(array $state): void {'
if text.count(marker) != 1:
    raise SystemExit('BootGuard insertion marker guard failed')
status = r'''    public static function status(): array {
        $state = self::state();
        $registeredBuild = trim( self::$build );
        $stateBuild = trim( (string) ( $state['build'] ?? '' ) );
        $buildMatches = '' !== $registeredBuild && '' !== $stateBuild && $registeredBuild === $stateBuild;
        $stateHold = ! empty( $state['hold'] );
        $faulted = ! empty( $state['faulted'] );
        $forced = defined( 'ETG_DFSB_FORCE_FULL_BOOT' ) && ETG_DFSB_FORCE_FULL_BOOT;
        $effectiveHold = $forced ? false : ( ! $buildMatches || $stateHold || $faulted );
        $reason = sanitize_key( (string) ( $state['reason'] ?? '' ) );
        return array(
            'contract' => 'etg.dfsb.safe-boot-status.v1',
            'authorizing' => false,
            'read_only' => true,
            'mutation_exposed' => false,
            'registered_build' => $registeredBuild,
            'state_build' => $stateBuild,
            'build_matches' => $buildMatches,
            'state_hold' => $stateHold,
            'effective_hold' => $effectiveHold,
            'faulted' => $faulted,
            'force_full_boot' => $forced,
            'monitoring' => self::$monitoring,
            'reason' => $reason,
            'boot_ok' => $buildMatches && ! $effectiveHold && ! $faulted && 'boot_ok' === $reason,
            'updated_at' => (int) ( $state['updated_at'] ?? 0 ),
        );
    }

'''
boot.write_text(text.replace(marker, status + marker, 1))

# 5) Surface Safe Boot evidence through the existing ETG readiness adapter path.
bootstrap = root / 'includes/Bootstrap.php'
text = bootstrap.read_text()
use_line = "use ETG\\DynamicFilterSEOBridge\\Runtime\\BootGuard;\n"
anchor = "use ETG\\DynamicFilterSEOBridge\\Runtime\\Readiness;\n"
if use_line not in text:
    if anchor not in text:
        raise SystemExit('Bootstrap BootGuard import anchor missing')
    text = text.replace(anchor, use_line + anchor, 1)
old = "    public function readiness():array{return$this->readiness?$this->readiness->report():array();}"
new = "    public function readiness():array{$report=$this->readiness?$this->readiness->report():array();$report['safe_boot']=BootGuard::status();return$report;}"
if text.count(old) != 1:
    raise SystemExit('Bootstrap readiness guard failed')
bootstrap.write_text(text.replace(old, new, 1))

# 6) Regression focused on live Staging shapes and Safe Boot visibility.
test = root / 'tests/alpha13-live-evidence-hardening-smoke.php'
test.write_text(r'''<?php
declare(strict_types=1);

function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value));}
function sanitize_text_field($value){return trim(strip_tags((string)$value));}
function absint($value){return abs((int)$value);}
if(!function_exists('add_action')){function add_action($hook,$callback,$priority=10,$acceptedArgs=1){return true;}}
$GLOBALS['etg_live_evidence_options']=array();
if(!function_exists('get_option')){function get_option($name,$default=false){return array_key_exists($name,$GLOBALS['etg_live_evidence_options'])?$GLOBALS['etg_live_evidence_options'][$name]:$default;}}
if(!function_exists('update_option')){function update_option($name,$value,$autoload=null){$GLOBALS['etg_live_evidence_options'][$name]=$value;return true;}}
function etg_live_expect($condition,string $message):void{if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}}
function etg_live_same($expected,$actual,string $message):void{if($expected!==$actual){fwrite(STDERR,"FAIL: {$message}\nEXPECTED ".var_export($expected,true)."\nACTUAL ".var_export($actual,true)."\n");exit(1);}}

$root=dirname(__DIR__);
require_once $root.'/includes/JetSmartFilters/FilterDefinitionInspector.php';
require_once $root.'/includes/Runtime/BootGuard.php';
use ETG\DynamicFilterSEOBridge\JetSmartFilters\FilterDefinitionInspector;
use ETG\DynamicFilterSEOBridge\Runtime\BootGuard;

$templateProvider=static function(){return array(array('id'=>30843,'data'=>array(
    array('id'=>'select','widgetType'=>'jet-smart-filters-select','settings'=>array('filter_id'=>array('16032'),'query_id'=>'tours_query_archive','content_provider'=>'jet-engine')),
    array('id'=>'range','widgetType'=>'jet-smart-filters-range','settings'=>array('filter_id'=>array('24515'),'query_id'=>'tours_query_archive','content_provider'=>'jet-engine')),
    array('id'=>'remove','widgetType'=>'jet-smart-filters-remove-filters','settings'=>array('query_id'=>'tours_query_archive','content_provider'=>'jet-engine')),
    array('id'=>'sorting','widgetType'=>'jet-smart-filters-sorting','settings'=>array('query_id'=>'tours_query_archive','content_provider'=>'jet-engine')),
)));};
$filterProvider=static function(int $id):array{return in_array($id,array(16032,24515),true)?array('_data_source'=>'taxonomies','_source_taxonomy'=>'location_jet','_query_var'=>'_tax_query::location_jet'):array();};
$inspection=(new FilterDefinitionInspector($templateProvider,$filterProvider))->inspect();
etg_live_same(4,$inspection['surface_count'],'all JetSmartFilters surfaces remain observable');
etg_live_same(2,$inspection['candidate_surface_count'],'only definition-bearing widgets require filter identity');
etg_live_same(2,$inspection['control_surface_count'],'control/query widgets are explicit rather than unresolved definitions');
etg_live_same(2,$inspection['resolved_surface_count'],'numeric-array filter IDs resolve');
etg_live_same(0,$inspection['unresolved_surface_count'],'valid live array shapes do not remain unresolved');
etg_live_same(2,$inspection['definition_count'],'resolved filters load distinct definitions');
etg_live_same(true,$inspection['evidence_complete'],'complete filter evidence stays complete when controls have no Filter post ID');
etg_live_same('control',$inspection['surfaces'][2]['surface_role'],'remove-filters is an explicit control surface');
etg_live_same('filter_identity_not_applicable',$inspection['surfaces'][3]['resolution_reason'],'sorting does not fabricate a missing Filter post identity');

$unknownProvider=static function(){return array(array('id'=>1,'data'=>array(array('id'=>'future','widgetType'=>'jet-smart-filters-future-filter','settings'=>array('query_id'=>'q')))));};
$unknown=(new FilterDefinitionInspector($unknownProvider,$filterProvider))->inspect();
etg_live_same(1,$unknown['candidate_surface_count'],'unknown JetSmartFilters widgets fail closed as definition candidates');
etg_live_same(1,$unknown['unresolved_surface_count'],'unknown widget without identity stays unresolved');
etg_live_same(false,$unknown['evidence_complete'],'unknown widget cannot create false-clean evidence');

$ambiguousProvider=static function(){return array(array('id'=>2,'data'=>array(array('id'=>'ambiguous','widgetType'=>'jet-smart-filters-select','settings'=>array('filter_id'=>array('16032','24515'),'query_id'=>'q')))));};
$ambiguous=(new FilterDefinitionInspector($ambiguousProvider,$filterProvider))->inspect();
etg_live_same(0,$ambiguous['resolved_surface_count'],'multiple different filter IDs fail closed');
etg_live_same(1,$ambiguous['unresolved_surface_count'],'ambiguous identity is not selected arbitrarily');

etg_live_same(500,FilterDefinitionInspector::MAX_TEMPLATES,'filter-definition root scan remains bounded at 500 templates');
$topologySource=file_get_contents($root.'/includes/Runtime/RuntimeTopologyDiscoverer.php');
etg_live_expect(false!==strpos($topologySource,'const MAX_TEMPLATES = 500;'),'topology root scan uses the same bounded 500-template ceiling');

$GLOBALS['etg_live_evidence_options']=array();
BootGuard::register('identity:'.str_repeat('a',40).':'.str_repeat('b',40));
BootGuard::holdOnFirstLoad('package_change');
$held=BootGuard::status();
etg_live_same('etg.dfsb.safe-boot-status.v1',$held['contract'],'Safe Boot read contract is explicit');
etg_live_same(true,$held['build_matches'],'stored and registered exact build identities match');
etg_live_same(true,$held['effective_hold'],'Safe Boot hold is observable read-only');
etg_live_same(false,$held['boot_ok'],'hold is not misreported as successful boot');
etg_live_same(false,$held['authorizing'],'Safe Boot evidence is non-authorizing');
etg_live_expect(BootGuard::run(static function(){}),'guarded boot succeeds');
$ready=BootGuard::status();
etg_live_same(false,$ready['effective_hold'],'successful guarded boot clears effective hold');
etg_live_same(true,$ready['boot_ok'],'successful guarded boot is explicit');
etg_live_same('boot_ok',$ready['reason'],'successful boot reason is observable');

$bootstrap=file_get_contents($root.'/includes/Bootstrap.php');
etg_live_expect(false!==strpos($bootstrap,'use ETG\\DynamicFilterSEOBridge\\Runtime\\BootGuard;'),'Bootstrap imports BootGuard for readiness evidence');
etg_live_expect(false!==strpos($bootstrap,"\$report['safe_boot']=BootGuard::status()"),'existing ETG status adapter can read Safe Boot evidence through readiness');

echo "Alpha13 live evidence hardening smoke tests passed.\n";
''')

# 7) Explicitly run filter-definition + live-evidence regressions in both PHP matrix jobs.
ci = Path('.github/workflows/etg-dfsb-ci.yml')
text = ci.read_text()
needle = "            alpha13-ajax-state-smoke.php \\\n            alpha13-usage-guide-smoke.php \\\n"
replacement = "            alpha13-ajax-state-smoke.php \\\n            alpha13-filter-definition-drift-smoke.php \\\n            alpha13-live-evidence-hardening-smoke.php \\\n            alpha13-usage-guide-smoke.php \\\n"
if text.count(needle) != 1:
    raise SystemExit('Operational CI test-list anchor missing')
ci.write_text(text.replace(needle, replacement, 1))

# 8) Close workflow trigger symmetry maintenance debt.
jet_ci = Path('.github/workflows/etg-dfsb-jetengine-ci.yml')
text = jet_ci.read_text()
needle = "      - '.github/workflows/etg-dfsb-jetengine-ci.yml'"
if text.count(needle) != 2:
    raise SystemExit('JetEngine CI trigger anchor count changed')
text = text.replace(needle, "      - '.github/workflows/etg-dfsb-ci.yml'\n" + needle)
jet_ci.write_text(text)

# 9) Contract the new semantics.
contract = Path('specs/001-etg-dynamic-filter-seo-operational/contracts/alpha13-ajax-runtime-state-contract.md')
text = contract.read_text()
addition = '''\n\n### Live inventory and Safe Boot evidence hardening\n\n- JetSmartFilters Elementor filters may persist `filter_id` as a one-item numeric array; exact filter identity discovery MUST normalize that shape without selecting among multiple distinct IDs.\n- Definition-bearing JetSmartFilters widgets and control/query widgets MUST be distinguished. Control widgets such as remove-filters, sorting, pagination, active filters, apply buttons, map sync, listings, item-count switching, and user-geolocation MUST remain observable surfaces without being misreported as unresolved Filter post identities. Unknown JetSmartFilters widget types remain fail-closed definition candidates unless explicitly classified as controls.\n- Filter-definition and topology root scans remain bounded but use a 500-template ceiling while the element budget remains independently bounded. Truncation MUST remain explicit evidence rather than a clean result.\n- Safe Boot MUST expose a read-only, non-authorizing status record containing registered/stored build identity matching, hold/fault state, effective hold, reason, and explicit `boot_ok`. Existing read adapters MAY surface this record through Bootstrap readiness; reading it MUST NOT retry boot or mutate state.\n'''
if '### Live inventory and Safe Boot evidence hardening' not in text:
    contract.write_text(text.rstrip() + addition.rstrip() + '\n')
