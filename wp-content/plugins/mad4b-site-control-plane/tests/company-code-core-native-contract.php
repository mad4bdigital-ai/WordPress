<?php
// Test ORIGINAL MAD4B Core Registry and Pipeline classes in a hermetic fixture.
// No site bootstrap, remote shell, credentials or site writes.
define('ABSPATH','/isolated/');
define('MAD4B_SCP_DIR',sys_get_temp_dir().'/mad4b-core-company-registry-'.getmypid().'/');
@mkdir(MAD4B_SCP_DIR.'config',0700,true);
$source=dirname(__DIR__).'/config/operation-registry.json';
$fixture=MAD4B_SCP_DIR.'config/operation-registry.json';
if(!copy($source,$fixture))throw new RuntimeException('catalog_fixture_unavailable');
class WP_Error {
 public $code;
 public function __construct($code,$message,$data=null){$this->code=$code;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($x){return $x instanceof WP_Error;}
function sanitize_key($s){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$s));}
function sanitize_text_field($s){return (string)$s;}
function wp_normalize_path($s){return str_replace('\\','/',$s);}
function wp_json_encode($v,$f=0){return json_encode($v,$f);}
function wp_has_ability($s){return true;}
class MAD4B_SCP_Canonicalization {
 public static function semantic_operation_id($v){return (is_string($v)&&preg_match('/^[a-z0-9][a-z0-9._-]+$/D',$v))?$v:new WP_Error('invalid','');}
 public static function ability_name($v){return (is_string($v)&&preg_match('~^[a-z0-9._-]+/[a-z0-9._-]+$~D',$v))?$v:new WP_Error('invalid','');}
}
class MAD4B_SCP_Capability_Descriptor_Registry {
 const CONTRACT='mock.descriptor.v2';const CONSUMER_BINDING_CONTRACT='mock.binding.v1';
 public static function binding($name,$consumer){return array('descriptor_contract'=>self::CONTRACT,'ability_name'=>$name,'consumer'=>$consumer,'execution_lane'=>'read');}
}
require dirname(__DIR__).'/includes/class-mad4b-scp-operation-registry.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-operation-pipeline.php';
$id='company.code-reconciliation.plan';
$op=MAD4B_SCP_Operation_Registry::operation($id);
if(is_wp_error($op))throw new RuntimeException('core_registry_did_not_bind_real_operation:'.$op->get_error_code());
$compile=MAD4B_SCP_Operation_Pipeline::compile(array('operation'=>$id,'target_kind'=>'company_site'));
if(is_wp_error($compile))throw new RuntimeException('original_core_compiler_refused:'.$compile->get_error_code());
if(($compile['ready']??null)!==true||($compile['operation_id']??'')!==$id ||
   ($compile['pipeline_profile']??'')!=='governed_read_only_v1' ||
   ($compile['planner']??'')!=='mad4b/company-code-reconciliation-plan' ||
   ($compile['executor']??'')!=='mad4b/company-code-reconciliation-compare' ||
   ($compile['mutation_performed']??true)!==false ||
   ($compile['authority_created']??true)!==false){
 throw new RuntimeException('native_pipeline_binding_incorrect');
}
$types=array_column($compile['stages'],'type');
if($types!==array('read_check','planner','executor','verification')||in_array('authorization',$types,true))
 throw new RuntimeException('read_only_pipeline_inherits_mutation_authority');
$catalog=json_decode(file_get_contents($fixture),true);
foreach($catalog['operations'] as &$item)
 if($item['id']===$id)$item['executor']='mad4b/plugin-deactivate';
unset($item);
file_put_contents($fixture,json_encode($catalog));
MAD4B_SCP_Operation_Registry::reset_request_cache();
$bad=MAD4B_SCP_Operation_Registry::operation($id);
if(!is_wp_error($bad)||$bad->get_error_code()!=='mad4b_operation_read_only_executor_not_allowlisted')
 throw new RuntimeException('mutation_executor_substitution_accepted');
$catalog=json_decode(file_get_contents($source),true);
$catalog['pipeline_profiles']['governed_read_only_v1'][2]['required']=false;
file_put_contents($fixture,json_encode($catalog));
MAD4B_SCP_Operation_Registry::reset_request_cache();
$bad=MAD4B_SCP_Operation_Registry::operation($id);
if(!is_wp_error($bad)||$bad->get_error_code()!=='mad4b_operation_read_only_stage_contract_invalid')
 throw new RuntimeException('optional_or_weakened_readonly_executor_accepted');
unlink($fixture);rmdir(MAD4B_SCP_DIR.'config');rmdir(MAD4B_SCP_DIR);
echo "PASS actual original Core Registry and Pipeline, read-only stages, mutation executor substitution denied\n";
