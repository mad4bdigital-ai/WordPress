<?php
declare(strict_types=1);

function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function absint($v){return abs((int)$v);}
function expect_true($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
function expect_same($e,$a,$m){if($e!==$a){fwrite(STDERR,"FAIL: $m\nEXPECTED ".var_export($e,true)."\nACTUAL ".var_export($a,true)."\n");exit(1);}}

require_once dirname(__DIR__).'/includes/Runtime/RuntimeTopologyDiscoverer.php';

use ETG\DynamicFilterSEOBridge\Runtime\RuntimeTopologyDiscoverer;

final class NestedTopologyQuery {
    public $id;
    public $query_id;
    private $postType;
    public function __construct($id,$queryId,$postType){$this->id=$id;$this->query_id=$queryId;$this->postType=$postType;}
    public function get_query_type(){return'posts';}
    public function get_query_args(){return array('post_type'=>$this->postType);}
}

$queryProvider=function(){return array(new NestedTopologyQuery(274,'destination_related_vehicles_qb','transportations'));};
$emptyQueries=function(){return array();};
$rootData=array(array(
    'id'=>'root-container','elType'=>'container','elements'=>array(array(
        'id'=>'a96b2ed','elType'=>'widget','widgetType'=>'template','settings'=>array('template_id'=>'45850'),
    )),
));
$childData=array(
    array('id'=>'listing','elType'=>'widget','widgetType'=>'jet-listing-grid','settings'=>array('_element_id'=>'destination_term_archive_related_vehicles','custom_query'=>'yes','custom_query_id'=>'274')),
    array('id'=>'sort','elType'=>'widget','widgetType'=>'jet-smart-filters-sorting','settings'=>array('query_id'=>'destination_term_archive_related_vehicles','content_provider'=>'jet-engine')),
);
$rootProvider=function()use($rootData){return array(array('id'=>45649,'data'=>$rootData));};
$referenceProvider=function($id)use($childData){return 45850===$id?$childData:array();};

$topology=new RuntimeTopologyDiscoverer($rootProvider,$queryProvider,$referenceProvider);
$nested=$topology->discover(true);
expect_same(1,$nested['templates_scanned'],'legacy catalog template count stays root-bounded');
expect_same(2,$nested['templates_processed'],'referenced Elementor template is processed');
expect_same(1,$nested['referenced_templates_scanned'],'one referenced template processed on demand');
expect_same(1,$nested['template_reference_count'],'one explicit template edge observed');
expect_same(false,$nested['template_references_truncated'],'simple nested edge is complete');
expect_same('resolved_reference',$nested['template_references'][0]['status'],'out-of-catalog referenced template resolves through bounded loader');
expect_same(array(45649,45850),$nested['template_references'][0]['reference_chain'],'root-to-child provenance retained');
expect_same(false,$nested['template_references'][0]['authorizing'],'template reference evidence is non-authorizing');
expect_same(1,$nested['binding_count'],'nested listing contributes one Query Builder binding');
expect_same('verified',$nested['bindings'][0]['status'],'nested custom query binding is verified');
expect_same(array('transportations'),$nested['bindings'][0]['post_types'],'nested binding keeps Query Builder post-type authority');
expect_same(array(45850),$nested['bindings'][0]['template_ids'],'provider enforcement stays scoped to the child template');

// A child already present in the globally catalogued templates is not scanned twice.
$catalogProvider=function()use($rootData,$childData){return array(array('id'=>45649,'data'=>$rootData),array('id'=>45850,'data'=>$childData));};
$catalogued=(new RuntimeTopologyDiscoverer($catalogProvider,$queryProvider,$referenceProvider))->discover(true);
expect_same(2,$catalogued['templates_processed'],'catalogued child scans exactly once');
expect_same(0,$catalogued['referenced_templates_scanned'],'catalogued child does not count as an on-demand template scan');
expect_same('resolved_catalogued',$catalogued['template_references'][0]['status'],'catalogued relationship remains explicit evidence');
expect_same(1,$catalogued['bindings'][0]['evidence_count'],'nested relationship does not duplicate binding evidence');
$cataloguedAgain=(new RuntimeTopologyDiscoverer($catalogProvider,$queryProvider,$referenceProvider))->discover(true);
expect_same($catalogued['template_references'],$cataloguedAgain['template_references'],'template reference evidence is deterministic');
expect_same($catalogued['bindings'],$cataloguedAgain['bindings'],'nested binding normalization is deterministic');

// Cycles are visible but never recursively re-entered.
$cycleA=array(array('id'=>'to-b','elType'=>'widget','widgetType'=>'template','settings'=>array('template_id'=>'60002')));
$cycleB=array(array('id'=>'to-a','elType'=>'widget','widgetType'=>'template','settings'=>array('template_id'=>'60001')));
$cycleRoot=function()use($cycleA){return array(array('id'=>60001,'data'=>$cycleA));};
$cycleReference=function($id)use($cycleB){return 60002===$id?$cycleB:array();};
$cycle=(new RuntimeTopologyDiscoverer($cycleRoot,$emptyQueries,$cycleReference))->discover(true);
expect_same(2,$cycle['templates_processed'],'cycle scans each unique template once');
expect_same(2,$cycle['template_reference_count'],'both cycle edges remain visible');
expect_true(in_array('cycle_detected',array_column($cycle['template_references'],'status'),true),'cycle is explicit warning evidence');

// Cross-template recursion has a fixed depth budget.
$depthMap=array();
for($id=70002;$id<=70008;$id++){$depthMap[$id]=array(array('id'=>'n'.$id,'elType'=>'widget','widgetType'=>'template','settings'=>array('template_id'=>$id+1)));}
$depthRoot=function(){return array(array('id'=>70001,'data'=>array(array('id'=>'n70001','elType'=>'widget','widgetType'=>'template','settings'=>array('template_id'=>70002)))));};
$depthReference=function($id)use(&$depthMap){return $depthMap[$id]??array();};
$depth=(new RuntimeTopologyDiscoverer($depthRoot,$emptyQueries,$depthReference))->discover(true);
expect_same(RuntimeTopologyDiscoverer::MAX_TEMPLATE_REFERENCE_DEPTH+1,$depth['templates_processed'],'depth zero plus bounded referenced depth is processed');
expect_true(in_array('depth_limit_exceeded',array_column($depth['template_references'],'status'),true),'depth overflow is explicit evidence');
expect_same(true,$depth['template_references_truncated'],'depth overflow marks reference graph incomplete');

// The count of on-demand templates is separately bounded from the global template catalog.
$budgetRootNodes=array();$budgetMap=array();
for($i=1;$i<=RuntimeTopologyDiscoverer::MAX_REFERENCED_TEMPLATES+1;$i++){
    $target=81000+$i;
    $budgetRootNodes[]=array('id'=>'r'.$i,'elType'=>'widget','widgetType'=>'template','settings'=>array('template_id'=>$target));
    $budgetMap[$target]=array(array('id'=>'leaf'.$i,'elType'=>'widget','widgetType'=>'heading','settings'=>array()));
}
$budgetRoot=function()use(&$budgetRootNodes){return array(array('id'=>80001,'data'=>$budgetRootNodes));};
$budgetReference=function($id)use(&$budgetMap){return $budgetMap[$id]??array();};
$budget=(new RuntimeTopologyDiscoverer($budgetRoot,$emptyQueries,$budgetReference))->discover(true);
expect_same(RuntimeTopologyDiscoverer::MAX_REFERENCED_TEMPLATES,$budget['referenced_templates_scanned'],'on-demand template scan budget is enforced');
expect_true(in_array('template_budget_exceeded',array_column($budget['template_references'],'status'),true),'template budget overflow remains explicit warning evidence');
expect_same(true,$budget['template_references_truncated'],'template budget overflow marks graph incomplete');

// Missing and malformed references fail closed and never become authority.
$missingRoot=function(){return array(array('id'=>90001,'data'=>array(
    array('id'=>'missing','elType'=>'widget','widgetType'=>'template','settings'=>array('template_id'=>99999)),
    array('id'=>'malformed','elType'=>'widget','widgetType'=>'template','settings'=>array('template_id'=>array('bad'))),
)));};
$missing=(new RuntimeTopologyDiscoverer($missingRoot,$emptyQueries,function($id){return array();}))->discover(true);
$statuses=array_column($missing['template_references'],'status');
expect_true(in_array('template_reference_unresolved',$statuses,true),'missing child template is visible');
expect_true(in_array('template_reference_invalid',$statuses,true),'malformed child template ID is visible');
foreach($missing['template_references']as$edge){expect_same(false,$edge['authorizing'],'unresolved reference evidence never authorizes');expect_same('warning',$edge['severity_hint'],'unresolved reference evidence stays advisory');}

echo "Alpha13 nested Elementor template topology smoke tests passed.\n";
