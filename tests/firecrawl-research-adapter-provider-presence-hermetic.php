<?php
/**
 * Execute the original Firecrawl Research Adapter class using only an
 * original Adapter Base and fake bridge. No real capture, HTTP, ContentJob,
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
function wp_register_ability($name,$cfg){$GLOBALS['adapter_abilities'][$name]=$cfg;}
function add_action(...$args){}
function add_filter(...$args){}
function apply_filters($name,$default){return $default;}
function sanitize_key($value){return strtolower((string)$value);}
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
function adapter_check($yes,$reason){
 if(!$yes)throw new RuntimeException('FAIL FIRECRAWL ADAPTER: '.$reason);
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/adapters/class-mad4b-scp-adapter-base.php';
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/adapters/class-mad4b-scp-firecrawl-research-adapter.php';
$a=new MAD4B_SCP_Firecrawl_Research_Adapter();
$a->register_abilities();
$ability=$GLOBALS['adapter_abilities']['mad4b/firecrawl-research-capture']??null;
adapter_check(is_array($ability)
 && $a->id()==='firecrawl-research'
 && $ability['meta']['mcp']['surface']==='write'
 && $ability['meta']['annotations']['readonly']===false
 && $ability['meta']['annotations']['idempotent']===false
 && $ability['input_schema']['required']===['job_id','draft_post_id','source_url']
 && $ability['input_schema']['additionalProperties']===false,
 'Original adapter registration remains a bounded write Ability, never read');
$GLOBALS['adapter_admin']=false;
adapter_check(!call_user_func($ability['permission_callback'],[]),
 'Anonymous/low-privilege actor must not be granted capture');
$GLOBALS['adapter_admin']=true;$GLOBALS['adapter_write']=false;
adapter_check(!call_user_func($ability['permission_callback'],[]),
 'Global write policy must be respected even by administrator');
$GLOBALS['adapter_write']=true;
$unauthorized=call_user_func($ability['permission_callback'],[]);
adapter_check(is_wp_error($unauthorized)
 && $unauthorized->get_error_code()==='mad4b_adapter_unavailable',
 'Original Adapter Base must deny missing provider before requesting any central mutation grant');
adapter_check($a->is_available()===false,
 'Bridge alone cannot satisfy the Adapter readiness predicate');
$GLOBALS['firecrawl_adapter_autoload_calls']=0;
$fixture_autoload=function($name){
 if($name==='MAD4B_SCP_Firecrawl_Provider')
  ++$GLOBALS['firecrawl_adapter_autoload_calls'];
};
spl_autoload_register($fixture_autoload);
adapter_check($a->is_available()===false
 && $GLOBALS['firecrawl_adapter_autoload_calls']===0,
 'Availability must inspect LOADED runtime providers without autoloading one');
spl_autoload_unregister($fixture_autoload);
$preMount=$a->mutation_ability_runtime_eligibility('mad4b/firecrawl-research-capture');
adapter_check(is_wp_error($preMount)
 && $preMount->get_error_code()==='mad4b_adapter_unavailable',
 'Native server write projection must deny an unready Bridge/Provider pair');
$unready=$a->capture(['job_id'=>'fixture','source_url'=>'https://example.invalid']);
adapter_check(is_wp_error($unready)
 && $unready->get_error_code()==='mad4b_firecrawl_provider_unavailable'
 && $GLOBALS['bridge_captures']===0,
 'Adapter capture must deny provider absence even when legacy bridge class exists');
class_alias('MockFirecrawlProvider','MAD4B_SCP_Firecrawl_Provider');
adapter_check($a->is_available()===true,
 'Both original expected runtime classes make adapter available');
adapter_check($a->mutation_ability_runtime_eligibility('mad4b/firecrawl-research-capture')===true,
 'Ready provider may become a mutation catalog candidate, not a grant');
$no_grant=call_user_func($ability['permission_callback'],[]);
adapter_check(is_wp_error($no_grant)
 && $no_grant->get_error_code()==='mad4b_authorization_unavailable',
 'Available provider still requires an independent central mutation grant');
adapter_check(is_wp_error($a->mutation_ability_runtime_eligibility('mad4b/other-ability')),
 'Unknown ability may not inherit provider mount eligibility');
$yes=$a->capture(['job_id'=>'fixture','draft_post_id'=>10,'source_url'=>'https://example.invalid']);
adapter_check(is_array($yes)&&$GLOBALS['bridge_captures']===1,
 'Normal adapter dispatch remains delegated to the original bridge only when both present');
echo "PASS: FIRECRAWL_ADAPTER_PROVIDER capability metadata and runtime provider-absence denial\n";
