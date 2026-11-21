<?php
/** Original Cache Lifecycle: source relation graph and safe target cascade. */
error_reporting(E_ALL);
define('ABSPATH','/');
$GLOBALS['actions']=[];
function add_action($a,$cb,$p=10,$args=1){$GLOBALS['actions'][]=$a;}
function add_filter($a,$cb,$p=10,$args=1){}
function get_option($key,$default=null){return $key==='home'?'https://wayfatours.com':$default;}
function wp_parse_url($url,$component=null){return $component===null?parse_url($url):parse_url($url,$component);}
function wp_is_post_revision($id){return false;}
function wp_is_post_autosave($id){return false;}
function get_post($id){return $GLOBALS['records'][(int)$id]??null;}
function get_post_type($id){return get_post($id)->post_type??false;}
function get_post_status($id){return get_post($id)->post_status??false;}
function get_post_type_object($type){return in_array($type,['units','properties','tours-and-activities','transportations'],true)?(object)['public'=>true]:null;}
function get_permalink($id){return 'https://wayfatours.com/'.get_post_type($id).'/post-'.(int)$id.'/';}
class MockRelation {
 private array $data;
 private array $args;
 function __construct(string $parent,string $child,array $data){
  $this->args=['parent_object'=>$parent,'child_object'=>$child];$this->data=$data;
 }
 function get_args(){return $this->args;}
 function get_parents($id,$mode='ids'){return $this->data['parents'][(int)$id]??[];}
 function get_children($id,$mode='ids'){return $this->data['children'][(int)$id]??[];}
}
class MockRelations {
 public array $active=[];
 function get_active_relations(){return $this->active;}
}
class MockJetEngine {public $relations;function __construct(){$this->relations=new MockRelations();}}
$GLOBALS['jet']=new MockJetEngine();
function jet_engine(){return $GLOBALS['jet'];}
$GLOBALS['records']=[
 21=>(object)['ID'=>21,'post_type'=>'units','post_status'=>'publish'],
 30=>(object)['ID'=>30,'post_type'=>'properties','post_status'=>'publish'],
 40=>(object)['ID'=>40,'post_type'=>'tours-and-activities','post_status'=>'publish'],
 41=>(object)['ID'=>41,'post_type'=>'tours-and-activities','post_status'=>'publish'],
 50=>(object)['ID'=>50,'post_type'=>'transportations','post_status'=>'publish'],
 51=>(object)['ID'=>51,'post_type'=>'units','post_status'=>'publish'],
 52=>(object)['ID'=>52,'post_type'=>'transportations','post_status'=>'publish'],
 60=>(object)['ID'=>60,'post_type'=>'properties','post_status'=>'publish'],
 70=>(object)['ID'=>70,'post_type'=>'tours-and-activities','post_status'=>'draft']
];
$GLOBALS['jet']->relations->active=[
 new MockRelation('posts::properties','posts::units',['parents'=>[21=>[30],51=>[]]]),
 new MockRelation('posts::properties','posts::tours-and-activities',['children'=>[30=>[40,70],60=>[]]]),
 new MockRelation('posts::tours-and-activities','posts::transportations',['parents'=>[50=>[41,40],52=>[]]])
];
require_once __DIR__.'/../wp-content/plugins/mad4b-cache-lifecycle/includes/runtime.php';
$c='MAD4B_Cache_Lifecycle_V1';
function ok($pass,$msg){if(!$pass){fwrite(STDERR,"FAIL $msg\n");exit(3);}echo "PASS $msg\n";}
$unit=$c::source_relation_impact_preview(21);
ok($unit['linked_property_ids']===[30]&&$unit['linked_tour_ids']===[40],'unit to property to published tour only');
ok($unit['negative_impact_certified']===false&&$unit['mutation_performed']===false,'no false certified negative');
$trans=$c::source_relation_impact_preview(50);
ok($trans['linked_tour_ids']===[40,41],'transport to two linked published tours');
$property=$c::source_relation_impact_preview(30);
ok($property['linked_tour_ids']===[40],'property targets tour');
$unknown=$c::source_relation_impact_preview(52);
ok(!$unknown['linked_tour_ids']&&$unknown['negative_impact_certified']===false,'unlinked transport remains uncertain');
$unsupported=$c::source_relation_impact_preview(40);
ok($unsupported['status']==='unsupported_source_type','unrelated CPT not reinterpreted');
$c::post(21);
$reflection=new ReflectionClass($c);
$field=$reflection->getProperty('posts');
$rows=$field->getValue();
ok(isset($rows[21],$rows[30],$rows[40])&&count($rows)===3,'original cache queue includes proven unit property tour targets');
$c::post(50);
$rows=$field->getValue();
ok(isset($rows[50],$rows[41])&&count($rows)===5,'transport edit invalidates both tours without global certificate');
$c::post(52);
$rows=$field->getValue();
ok(isset($rows[52])&&count($rows)===6,'unlinked transport original invalidation remains, not silently omitted');
ok(in_array('shutdown',$GLOBALS['actions'],true),'original cache shutdown flushing retained');
echo "CACHE_SOURCE_RELATION_CONTRACT_9_OF_9_PASS\n";
