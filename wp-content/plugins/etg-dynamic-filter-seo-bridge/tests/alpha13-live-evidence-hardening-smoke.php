<?php
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
