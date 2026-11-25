<?php
namespace DynamicFilterSEOBridge {
 class Bootstrap {
  public static function instance(){return new self;}
  public function readiness(){return ['available'=>true,'runtime_ready'=>false];}
 }
}
namespace DynamicFilterSEOBridge\Config {
 class Configuration {
  public function enabled(){return true;}
  public function revision(){return 7;}
  public function validationErrors(){return [];}
  public function all(){return ['profile_mode'=>'read_only'];}
 }
 class ProfileRegistry {
  public function __construct($config){}
  public function all(){return ['p1'=>['name'=>'Draft']];}
  public function validationErrors(){return [];}
  public function discovery(){return ['source'=>'native'];}
  public function blueprint($post,$tax,$id){return ['post_type'=>$post,'taxonomies'=>$tax,'id'=>$id];}
 }
}
namespace DynamicFilterSEOBridge\Diagnostics {
 class RuntimeInventory {
  public function collect(){return ['snapshot_fingerprint'=>str_repeat('b',64),'available'=>true];}
 }
 class BuildIdentity {
  public static function collect(){return ['build_id'=>'native-build'];}
 }
 class InventoryProfilePlanner {
  public static function plan($inventory,$profiles){return [
   'proposals'=>['p1'=>['safe_to_apply'=>$GLOBALS['dfsb_safe']??false,'changes'=>[['field'=>'title']]]]
  ];}
 }
}
namespace DynamicFilterSEOBridge\Presentation {
 class InventoryContentCatalog {
  public function build($inventory,$profiles){return ['content_slots'=>0,'mutation_performed'=>false];}
 }
 class ContentSlotRegistry {
  public function normalize($input){return array_merge(['id'=>'content1'],$input);}
  public function get($id){return [];}
  public function revision(){return 7;}
 }
}
namespace {
 define('ABSPATH',__DIR__.'/');
 define('DFSB_VERSION','0.4.0-alpha.13');
 define('ETG_DFSB_VERSION','0.4.0-alpha.13');
 class WP_Error{
  private $code;
  function __construct($code,$message='',$data=[]){$this->code=$code;}
  function get_error_code(){return $this->code;}
 }
 function is_wp_error($v){return $v instanceof WP_Error;}
 function add_action($event,$callback){$GLOBALS['hooks'][]=$event;}
 function wp_has_ability($name){return array_key_exists($name,$GLOBALS['wp_abilities']);}
 function wp_get_ability($name){return $GLOBALS['wp_abilities'][$name]??null;}
 function sanitize_key($name){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$name));}
 function wp_json_encode($v){return json_encode($v);}
 function dfsb_check($yes,$reason){
  if(!$yes){fwrite(STDERR,"FAIL: ".$reason.PHP_EOL);exit(1);}
  $GLOBALS['checks']=($GLOBALS['checks']??0)+1;
 }
 abstract class MAD4B_SCP_Adapter_Base {
  abstract public function id();
  abstract public function label();
  abstract public function is_available();
  abstract public function ability_names();
  abstract public function register_abilities();
  public function status(){return ['available'=>$this->is_available(),'version'=>'0.4.0-alpha.13'];}
  protected function add_ability($name,$label,$method,$permission,$schema=null){
   $GLOBALS['registered'][$name]=[$method,$permission,$schema];
  }
  protected function schema($schema,$required=[]){return compact('schema','required');}
  protected function unavailable_error(){return new WP_Error('provider_unavailable');}
 }
 class MAD4B_SCP_Object_Cache_Diagnostics {
  public static function status(){return ['read_only'=>true,'memcached'=>'unverified'];}
 }
 class NativeEvidence {
  public $meta=['annotations'=>['readonly'=>true,'destructive'=>false]];
  public function get_meta(){return $this->meta;}
  public function execute($input=[]){return ['opaque'=>'never_invoke'];}
 }
 $native_aliases=[
  ['DynamicFilterSEOBridge\\Bootstrap','ETG\\DynamicFilterSEOBridge\\Bootstrap'],
  ['DynamicFilterSEOBridge\\Config\\Configuration','ETG\\DynamicFilterSEOBridge\\Config\\Configuration'],
  ['DynamicFilterSEOBridge\\Config\\ProfileRegistry','ETG\\DynamicFilterSEOBridge\\Config\\ProfileRegistry'],
  ['DynamicFilterSEOBridge\\Diagnostics\\RuntimeInventory','ETG\\DynamicFilterSEOBridge\\Diagnostics\\RuntimeInventory'],
  ['DynamicFilterSEOBridge\\Diagnostics\\BuildIdentity','ETG\\DynamicFilterSEOBridge\\Diagnostics\\BuildIdentity'],
  ['DynamicFilterSEOBridge\\Diagnostics\\InventoryProfilePlanner','ETG\\DynamicFilterSEOBridge\\Diagnostics\\InventoryProfilePlanner'],
  ['DynamicFilterSEOBridge\\Presentation\\InventoryContentCatalog','ETG\\DynamicFilterSEOBridge\\Presentation\\InventoryContentCatalog'],
  ['DynamicFilterSEOBridge\\Presentation\\ContentSlotRegistry','ETG\\DynamicFilterSEOBridge\\Presentation\\ContentSlotRegistry']
 ];
 foreach($native_aliases as $names)class_alias($names[0],$names[1]);
 $GLOBALS['registered']=[];
 $GLOBALS['wp_abilities']=[];
 $GLOBALS['dfsb_safe']=false;
 require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/adapters/class-mad4b-scp-etg-dfsb-adapter.php';
 require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/adapters/class-mad4b-scp-dfsb-adapter.php';
 $c='MAD4B_SCP_DFSB_Adapter';
 $a=new $c;
 $legacy=new \MAD4B_SCP_ETG_DFSB_Adapter();
 $policy_file=dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/config/adapter-registration-policy.json';
 $owner_policy=json_decode(file_get_contents($policy_file),true);
 dfsb_check(is_array($owner_policy)
  &&in_array('MAD4B_SCP_ETG_DFSB_Adapter',$owner_policy['approved_classes'],true)
  &&!in_array('MAD4B_SCP_DFSB_Adapter',$owner_policy['approved_classes'],true),
  'Only the exact reviewed original ETG class, not the provider-neutral compatibility duplicate, can mount through the native Registry');

 dfsb_check($a->id()==='etg-dfsb' &&$a->is_available()===true
  &&$legacy->id()==='etg-dfsb' &&$legacy->is_available()===true
  &&get_class($legacy)!==get_class($a),
  'Existing approved original ETG and unapproved provider-neutral adapters are distinct; neither can shadow each other');
 $names=$a->ability_names();
 dfsb_check($names['write']??[]===[] &&count($names['content'])===0
  &&count($names['admin'])===0,
  'DFSB exposes only read abilities, never independent Brand/SEO/Elementor writes');
 $status=$a->status();
 dfsb_check($status['authority_mode']==='read_only_non_authorizing'
  &&$status['mutation_exposed']===false
  &&$status['profile_mutation_exposed']===false
  &&$status['ajax_proxy_exposed']===false,
  'Native bridge status is a read-only projection, not a website editing grant');
 $a->register_abilities();
 dfsb_check(count($GLOBALS['registered'])>=20
  &&!isset($GLOBALS['registered']['etg-dfsb/evidence-provider'])
  &&!isset($GLOBALS['registered']['etg-dfsb/evidence-query']),
  'Only existing first-party native abilities get registered; ETG original evidence is never duplicated');
 $neutral_registered=$GLOBALS['registered'];
 $GLOBALS['registered']=[];$legacy->register_abilities();$legacy_registered=$GLOBALS['registered'];
 dfsb_check(count($neutral_registered)===22&&count($legacy_registered)===11,'22 aliases from 11 original descriptors');
 foreach($legacy_registered as $name=>$descriptor){
  $neutral='dfsb/'.substr($name,strlen('etg-dfsb/'));
  dfsb_check(isset($neutral_registered[$name],$neutral_registered[$neutral])&&$neutral_registered[$name]===$descriptor&&$neutral_registered[$neutral]===$descriptor,'same method permission and schema: '.$name);
 }
 $declared=$a->ability_names()['read'];sort($declared);$registered=array_keys($neutral_registered);sort($registered);
 dfsb_check($declared===$registered,'discovery and registration use the same descriptors');
 $GLOBALS['registered']=$neutral_registered;
 $evidence=new NativeEvidence;
 $GLOBALS['wp_abilities']=['etg-dfsb/evidence-provider'=>$evidence,'etg-dfsb/evidence-query'=>$evidence];
 $available=$a->ability_names()['read'];
 $legacy_read=$legacy->ability_names()['read'];
 dfsb_check(in_array('etg-dfsb/evidence-provider',$legacy_read,true)
  &&in_array('etg-dfsb/evidence-query',$legacy_read,true),
  'Approved ETG native reader reuses the original read-only evidence provider without adding a new definition');
 dfsb_check(in_array('etg-dfsb/evidence-provider',$available,true)
  &&in_array('etg-dfsb/evidence-query',$available,true),
  'Original ETG ability annotated readonly=true destructive=false is reusable as a projection');
 foreach([new WP_Error('meta_unknown'),null,1,'true',
  ['annotations'=>['readonly'=>new WP_Error('unknown'),'destructive'=>false]],
  ['annotations'=>['readonly'=>true,'destructive'=>1]]
 ] as $untrusted){
  $evidence->meta=$untrusted;
  $legacy_read=$legacy->ability_names()['read'];
  dfsb_check(!in_array('etg-dfsb/evidence-provider',$legacy_read,true),
   'Approved original ETG reader must reject untrusted or malformed native Ability metadata');
  $read=$a->ability_names()['read'];
  dfsb_check(!in_array('etg-dfsb/evidence-provider',$read,true),
   'Unreadable or unverified native readonly/destructive annotations never become exposed MCP abilities');
 }
 $evidence->meta=['annotations'=>['readonly'=>true,'destructive'=>false]];
 $identity=$a->build_identity();
 dfsb_check($identity['authorizing']===false &&$identity['read_only']===true
  &&$identity['mutation_exposed']===false,
  'Original service identity does not self-authorize write or publication');
 $config=$a->configuration();
 dfsb_check($config['read_only']===true &&$config['mutation_exposed']===false,
  'Original configuration is never an execution grant');
 $result=$a->profile_diff(['profile_id'=>'p1']);
 $legacy_result=$legacy->profile_diff(['profile_id'=>'p1']);
 dfsb_check($legacy_result['structural_plan_eligible']===false,
  'Original ETG source marks structural planning ineligible until positive native evidence');
 dfsb_check($result['structural_plan_eligible']===false
  &&$result['authorizing']===false &&$result['apply_authorized']===false,
  'Source Profile proposal is a bounded read-only review');
 foreach([new WP_Error('unknown'),null,1,'true'] as $untrusted){
  $GLOBALS['dfsb_safe']=$untrusted;
  $legacy_plan=$legacy->profile_diff(['profile_id'=>'p1']);
  dfsb_check($legacy_plan['structural_plan_eligible']===false,
   'Approved original ETG planner never treats error-shaped native safe_to_apply as eligible');
  $result=$a->profile_diff(['profile_id'=>'p1']);
  dfsb_check($result['structural_plan_eligible']===false,
   'Uncertain original DFSB safe_to_apply does not appear eligible for structural plan');
 }
 $slot=$a->slot_plan(['slot'=>['id'=>'reviewed_one']]);
 $old_slot=$legacy->slot_plan(['slot'=>['id'=>'reviewed_one']]);
 dfsb_check($old_slot['mutation_performed']===false
  &&$old_slot['apply_authorized']===false,
  'Legacy ETG slot planner still denies writes despite separate provider-neutral class');
 dfsb_check($slot['authorizing']===false &&$slot['read_only']===true
  &&$slot['mutation_performed']===false &&$slot['apply_authorized']===false,
  'Slot plan never touches Elementor or SEO storage');
 dfsb_check(is_wp_error($a->slot_plan(['slot'=>['id'=>'etg_background_private']])),
  'ETG reserved internal content slot cannot be provisioned through MAD4B');
 dfsb_check($a->object_cache_status()['read_only']===true,
  'Native Object Cache diagnostics are observed without cache purge');
 // Exercise the existing candidate graph against the actual adapter declaration,
 // in an isolated registry fixture. This is not real runtime admission.
 define('MAD4B_SCP_DIR',dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/');
 require_once MAD4B_SCP_DIR.'includes/class-mad4b-scp-provider-compatibility-certification.php';
 $registry_fixture=new class($a) {
  private $adapter;
  public function __construct($adapter){$this->adapter=$adapter;}
  public function all(){return ['etg-dfsb'=>$this->adapter];}
 };
 $graph=MAD4B_SCP_Provider_Compatibility_Certification::runtime_candidate_graph($registry_fixture);
 $declared=$a->ability_names()['read'];
 dfsb_check($graph['candidate_count']===count($declared),'original graph covers all declared read names, including historical aliases');
 foreach($graph['candidates'] as $row){
  dfsb_check(in_array($row['ability'],$declared,true)&&$row['surfaces']===['read']&&$row['authorizing']===false&&$row['auto_activation_allowed']===false,'candidate inventory does not grant execution');
  if($row['policy_state']==='UNCLASSIFIED_FAIL_CLOSED')dfsb_check($row['policy_match_count']===0,'unclassified candidate has no reviewed policy');
 }
 // Generate a review-only policy candidate from the exact registered descriptor
 // pairs. Never write the live provider catalog or evaluate execution authority.
 $proposed=[];
 foreach($neutral_registered as $name=>$descriptor){
  if(strpos($name,'dfsb/')!==0)continue;
  $suffix=substr($name,strlen('dfsb/'));$alias='etg-dfsb/'.$suffix;
  dfsb_check(isset($legacy_registered[$alias])&&$legacy_registered[$alias]===$descriptor&&$neutral_registered[$alias]===$descriptor,'review requires exact original pair');
  $proposed['dfsb.'.str_replace('-','_',$suffix).'.read']=['risk'=>'read','abilities'=>[$name,$alias],'probes'=>[['type'=>'adapter_available']]];
 }
 foreach(array_diff($declared,array_keys($neutral_registered)) as $name){
  // Native evidence is owned by WordPress Abilities, not the adapter writer.
  dfsb_check(in_array($name,['etg-dfsb/evidence-provider','etg-dfsb/evidence-query'],true),'only observed native evidence projection');
  $proposed['dfsb.'.str_replace('-','_',substr($name,strlen('etg-dfsb/'))).'.read']=['risk'=>'read','abilities'=>[$name],'probes'=>[['type'=>'adapter_available']]];
 }
 $candidate_catalog=['providers'=>['etg-dfsb'=>['adapter_id'=>'etg-dfsb','capabilities'=>$proposed]]];
 $review_graph=MAD4B_SCP_Provider_Compatibility_Certification::runtime_candidate_graph($registry_fixture,$candidate_catalog);
 dfsb_check(count($proposed)===13&&$review_graph['candidate_count']===24&&$review_graph['unclassified_count']===0,'13 capabilities cover all 24 observed names in isolated review');
 foreach($review_graph['candidates'] as $row)dfsb_check($row['policy_match_count']===1&&$row['policy_matches'][0]['risk']==='read'&&$row['auto_activation_allowed']===false&&$row['authorizing']===false,'exact unique read overlay only');
 dfsb_check(!MAD4B_SCP_Provider_Compatibility_Certification::supports_provider('etg-dfsb'),'actual policy still unmodified');
 $descriptor_snapshot=$neutral_registered;ksort($descriptor_snapshot,SORT_STRING);
 $descriptor_sha=hash('sha256',json_encode($descriptor_snapshot,JSON_UNESCAPED_SLASHES));
 echo json_encode(['contract'=>'mad4b.dfsb.capability-classification-proposal.v1','scope'=>'isolated_fixture_only','state'=>'REVIEW_REQUIRED','descriptor_sha256'=>$descriptor_sha,'proposed_capabilities'=>$proposed,'capability_count'=>count($proposed),'covered_names'=>$review_graph['candidate_count'],'unclassified_names'=>$review_graph['unclassified_count'],'native_policy_changed'=>false,'runtime_admission_verified'=>false,'authorizing'=>false],JSON_UNESCAPED_SLASHES).PHP_EOL;
 echo json_encode(['contract'=>'mad4b.dfsb.native-qualification-review.v1','scope'=>'isolated_adapter_declaration_fixture','provider'=>'etg-dfsb','candidate_count'=>$graph['candidate_count'],'unclassified_count'=>$graph['unclassified_count'],'provider_policy_registered'=>MAD4B_SCP_Provider_Compatibility_Certification::supports_provider('etg-dfsb'),'neutral_class_in_approved_manifest'=>in_array(get_class($a),$owner_policy['approved_classes'],true),'actual_runtime_admission_verified'=>false,'authorizing'=>false],JSON_UNESCAPED_SLASHES).PHP_EOL;
 echo 'PASS: DFSB_READ_ADAPTER_ORIGINAL '.($GLOBALS['checks']??0).
  ' native ETG version, projection and no-provider-write cases'.PHP_EOL;
}
