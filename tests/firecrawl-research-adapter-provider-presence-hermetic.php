<?php
/**
 * Execute the original Firecrawl Research Adapter class using only an
 * instrumented base-shell and fake bridge. No real capture, HTTP, ContentJob,
 * WordPress mutation, provider credential or production ability invocation.
 */
define('ABSPATH',__DIR__.'/');
$GLOBALS['adapter_abilities']=[];
$GLOBALS['bridge_captures']=0;
$GLOBALS['adapter_admin']=true;
$GLOBALS['adapter_write']=true;
class WP_Error{
 private $code;
 public function __construct($code,$message=''){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($r){return $r instanceof WP_Error;}
function wp_has_ability($name){return isset($GLOBALS['adapter_abilities'][$name]);}
function current_user_can($cap){return $cap==='manage_options' && $GLOBALS['adapter_admin'];}
class MAD4B_SCP_Policy{
 public static function can_mutate(){return $GLOBALS['adapter_write'];}
}
class MockFirecrawlProvider{}
class MAD4B_Firecrawl_Research_Bridge_V1{
 public static function execute($input){
  ++$GLOBALS['bridge_captures'];
  return ['contract'=>'hermetic_bridge_only','input'=>$input];
 }
}
abstract class MAD4B_SCP_Adapter_Base{
 abstract public function id();
 abstract public function label();
 abstract public function is_available();
 abstract public function ability_names();
 abstract public function register_abilities();
 protected function schema($properties,$required=[]){
  return ['type'=>'object','properties'=>$properties,
   'additionalProperties'=>false,'required'=>$required];
 }
 protected function add_ability($name,$label,$method,$permission,
  $schema=null,$surface='read',$readonly=true,$destructive=false,$idempotent=true){
  $GLOBALS['adapter_abilities'][$name]=compact('label','method','permission',
   'schema','surface','readonly','destructive','idempotent');
 }
}
function adapter_check($yes,$reason){
 if(!$yes)throw new RuntimeException('FAIL FIRECRAWL ADAPTER: '.$reason);
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/adapters/class-mad4b-scp-firecrawl-research-adapter.php';
$a=new MAD4B_SCP_Firecrawl_Research_Adapter();
$a->register_abilities();
$ability=$GLOBALS['adapter_abilities']['mad4b/firecrawl-research-capture']??null;
adapter_check(is_array($ability)
 && $a->id()==='firecrawl-research'
 && $ability['surface']==='write'
 && $ability['readonly']===false
 && $ability['idempotent']===false
 && $ability['schema']['required']===['job_id','draft_post_id','source_url']
 && $ability['schema']['additionalProperties']===false,
 'Original adapter registration remains a bounded write Ability, never read');
$GLOBALS['adapter_admin']=false;
adapter_check(!call_user_func($ability['permission'],[]),
 'Anonymous/low-privilege actor must not be granted capture');
$GLOBALS['adapter_admin']=true;$GLOBALS['adapter_write']=false;
adapter_check(!call_user_func($ability['permission'],[]),
 'Global write policy must be respected even by administrator');
$GLOBALS['adapter_write']=true;
adapter_check(call_user_func($ability['permission'],[]),
 'Existing user+policy permission is tested independently of NHI in the base');
adapter_check($a->is_available()===false,
 'Bridge alone cannot satisfy the Adapter readiness predicate');
$unready=$a->capture(['job_id'=>'fixture','source_url'=>'https://example.invalid']);
adapter_check(is_wp_error($unready)
 && $unready->get_error_code()==='mad4b_firecrawl_provider_unavailable'
 && $GLOBALS['bridge_captures']===0,
 'Adapter capture must deny provider absence even when legacy bridge class exists');
class_alias('MockFirecrawlProvider','MAD4B_SCP_Firecrawl_Provider');
adapter_check($a->is_available()===true,
 'Both original expected runtime classes make adapter available');
$yes=$a->capture(['job_id'=>'fixture','draft_post_id'=>10,'source_url'=>'https://example.invalid']);
adapter_check(is_array($yes)&&$GLOBALS['bridge_captures']===1,
 'Normal adapter dispatch remains delegated to the original bridge only when both present');
echo "FIRECRAWL_ADAPTER_PROVIDER: PASS capability metadata and runtime provider-absence denial\n";
