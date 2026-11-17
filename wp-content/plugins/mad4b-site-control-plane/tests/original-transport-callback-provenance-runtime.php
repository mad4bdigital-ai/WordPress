<?php
/** Hermetic real-callback provenance for original Direct/dispatch MCP Tools. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { private $code; public function __construct($code, $message=''){$this->code=$code;} public function get_error_code(){return $this->code;} }
function is_wp_error($v){return $v instanceof WP_Error;}
$GLOBALS['registered_origins'] = array();
$GLOBALS['raw_registrations'] = array();
function wp_has_ability($name){return isset($GLOBALS['registered_origins'][$name]);}
function wp_get_ability($name){return $GLOBALS['registered_origins'][$name] ?? null;}
function wp_get_abilities(){return $GLOBALS['registered_origins'];}
function wp_register_ability($name,$args){$GLOBALS['raw_registrations'][$name]=$args;$GLOBALS['registered_origins'][$name] = new WP_Ability($name,$args);return true;}
class WP_Ability {
    private $name;
    private $meta;
    private $category;
    private $execute_callback;
    private $permission_callback;
    public function __construct($name,array $args) {
        $this->name=$name;$this->meta=$args['meta'];$this->category=$args['category'];
        $this->execute_callback=$args['execute_callback'];$this->permission_callback=$args['permission_callback'];
    }
    public function get_name(){return $this->name;}
    public function get_meta(){return $this->meta;}
    public function get_category(){return $this->category;}
    public function get_input_schema(){return array('type'=>'object');}
    public function execute($args=null){throw new RuntimeException('Do not execute callbacks in structural test');}
    public function replace_permission($callback){$this->permission_callback=$callback;}
    public function replace_execute($callback){$this->execute_callback=$callback;}
    public function replace_meta($meta){$this->meta=$meta;}
}
class MAD4B_SCP_Execution_Fence {
    public static $enabled=true;
    public static function final_execution_wrapper_verified($name){return self::$enabled;}
}
require __DIR__.'/../includes/class-mad4b-scp-governed-ability-census.php';
require __DIR__.'/../includes/class-mad4b-scp-direct-profile.php';
require __DIR__.'/../includes/class-mad4b-scp-staging-write-authority.php';
require __DIR__.'/../includes/class-mad4b-scp-abilities.php';
require __DIR__.'/../includes/class-mad4b-scp-governed-runtime-gates.php';
$check=static function($ok,$message){if(!$ok)throw new RuntimeException($message);};
$direct='MAD4B_SCP_Governed_Ability_Census';
MAD4B_SCP_Direct_Profile::register_abilities();
foreach(array('mad4b/direct-create-post','mad4b/direct-update-post') as $name){
    // Check the real pre-registration WordPress args, not a hand-written
    // generic metadata fixture. The exact Direct Profile must be kept out of
    // the global NHI/ticket augmenter; foreign callbacks must not be.
    $original_args=$GLOBALS['raw_registrations'][$name];
    $same=MAD4B_SCP_Staging_Write_Authority::augment_write_ability($original_args,$name);
    $check($same===$original_args,'Direct post was rewritten as a governed NHI mutation: '.$name);
    $forged=$original_args;
    $forged['permission_callback']=static function($input=null){return true;};
    $denied=MAD4B_SCP_Staging_Write_Authority::augment_write_ability($forged,$name);
    $check(($denied['meta']['mcp']['mad4b_governed_write_authority']??'')===MAD4B_SCP_Staging_Write_Authority::CONTRACT,
        'Forged direct permission bypassed regular governed write fence: '.$name);
    $misnamed=MAD4B_SCP_Staging_Write_Authority::augment_write_ability($original_args,'mad4b/other-direct-post');
    $check(($misnamed['meta']['mcp']['mad4b_governed_write_authority']??'')===MAD4B_SCP_Staging_Write_Authority::CONTRACT,
        'Unregistered direct name bypassed global governed write fence.');
    $ability=wp_get_ability($name);
    $check($direct::original_direct_post_boundary_verified($ability),'Original direct post callback rejected: '.$name);
    MAD4B_SCP_Execution_Fence::$enabled=false;
    $check(!$direct::original_direct_post_boundary_verified($ability),'Direct post accepted without final fence: '.$name);
    MAD4B_SCP_Execution_Fence::$enabled=true;
    $permission=new ReflectionProperty('WP_Ability','permission_callback');
    if(PHP_VERSION_ID<80100)$permission->setAccessible(true);
    $original=$permission->getValue($ability);
    $ability->replace_permission(static function($input=null){return true;});
    $check(!$direct::original_direct_post_boundary_verified($ability),'Foreign direct permission closure accepted: '.$name);
    $ability->replace_permission($original);
    $execute=new ReflectionProperty('WP_Ability','execute_callback');
    if(PHP_VERSION_ID<80100)$execute->setAccessible(true);
    $original_execute=$execute->getValue($ability);
    $ability->replace_execute(array('MAD4B_SCP_Direct_Profile','list_posts'));
    $check(!$direct::original_direct_post_boundary_verified($ability),'Read callback masqueraded as Direct mutation: '.$name);
    $ability->replace_execute($original_execute);
}
$abilities=new MAD4B_SCP_Abilities();
$dispatch=array(
    'mad4b/write-execute'=>array('write-dispatch','write_execute','can_write_dispatch'),
    'mad4b/developer-execute'=>array('developer-dispatch','developer_execute','can_developer_dispatch'),
    'mad4b/enrollment-execute'=>array('enrollment','enrollment_execute','can_enrollment_dispatch'),
);
foreach($dispatch as $name=>$pair){
    $mcp=array('surface'=>$pair[0],'generic_remote_admin'=>false);
    if($name==='mad4b/enrollment-execute'){
        $mcp['conditional_enrollment_policy']='per_child_registered_write_policy_v1';
        $mcp['production_mutation_allowed']='policy_governed';
        $mcp['production_allowed']='policy_governed';
    }else{$mcp['production_mutation_allowed']=false;$mcp['breakglass_allowed']=false;}
    $ability=new WP_Ability($name,array(
        'category'=>'mad4b-admin',
        'execute_callback'=>array($abilities,$pair[1]),
        'permission_callback'=>array($abilities,$pair[2]),
        'meta'=>array('mcp'=>$mcp,'annotations'=>array('readonly'=>false)),
    ));
    $GLOBALS['registered_origins'][$name]=$ability;
    $check($direct::original_dispatch_transport_boundary_verified($ability),'Original dispatch callback rejected: '.$name);
    MAD4B_SCP_Execution_Fence::$enabled=false;
    $check(!$direct::original_dispatch_transport_boundary_verified($ability),'Dispatch accepted without final fence: '.$name);
    MAD4B_SCP_Execution_Fence::$enabled=true;
    $ability->replace_permission(array($abilities,$pair[1]));
    $check(!$direct::original_dispatch_transport_boundary_verified($ability),'Mutating dispatch permission callback accepted: '.$name);
    $ability->replace_permission(array($abilities,$pair[2]));
    $bad=$ability->get_meta();$bad['mcp']['generic_remote_admin']=true;
    $ability->replace_meta($bad);
    $check(!$direct::original_dispatch_transport_boundary_verified($ability),'Generic remote admin accepted for dispatch: '.$name);
    $bad['mcp']['generic_remote_admin']=false;
    $ability->replace_meta($bad);
}
// Runtime Gates is direct-only: never enroll it in the generic Staging broker.
MAD4B_SCP_Governed_Runtime_Gates::register_abilities();
$gate=wp_get_ability(MAD4B_SCP_Governed_Runtime_Gates::APPLY_ABILITY);
$check($direct::original_runtime_gate_direct_boundary_verified($gate),'Original Runtime Gates callback was not bound.');
$check(!isset($direct::structural_step_up()['mad4b/runtime-gates-apply']),
    'High-risk Runtime Gates unexpectedly entered generic Enrollment broker.');
$check(isset($direct::direct_only()['mad4b/runtime-gates-apply']),
    'Exact Runtime Gates is not in the strict direct-only source census.');
MAD4B_SCP_Execution_Fence::$enabled=false;
$check(!$direct::original_runtime_gate_direct_boundary_verified($gate),'Runtime Gates accepted without Final Fence.');
MAD4B_SCP_Execution_Fence::$enabled=true;
$permission=new ReflectionProperty('WP_Ability','permission_callback');
if(PHP_VERSION_ID<80100)$permission->setAccessible(true);
$old_permission=$permission->getValue($gate);
$gate->replace_permission(array('MAD4B_SCP_Governed_Runtime_Gates','can_access'));
$check(!$direct::original_runtime_gate_direct_boundary_verified($gate),'Read permission substituted for high-risk step-up.');
$gate->replace_permission($old_permission);
$exec=new ReflectionProperty('WP_Ability','execute_callback');
if(PHP_VERSION_ID<80100)$exec->setAccessible(true);
$old_execute=$exec->getValue($gate);
$gate->replace_execute(array('MAD4B_SCP_Governed_Runtime_Gates','plan'));
$check(!$direct::original_runtime_gate_direct_boundary_verified($gate),'Runtime Gates plan callback substituted for apply.');
$gate->replace_execute($old_execute);
$valid_meta=$gate->get_meta();
foreach (array('direct_only_excluded_from_staging_broker','generic_raw_sql_breakglass_configuration',
               'exact_chatgpt_client_required','production_allowed') as $field) {
    $tampered=$valid_meta;$tampered['mcp'][$field]=false;
    $gate->replace_meta($tampered);
    $check(!$direct::original_runtime_gate_direct_boundary_verified($gate),
        'Runtime Gates admitted with missing original high-risk policy: '.$field);
}
$gate->replace_meta($valid_meta);
$check($direct::original_runtime_gate_direct_boundary_verified($gate),
    'Original Runtime Gates callback did not recover after restored metadata.');
echo "ORIGINAL_TRANSPORT_CALLBACK_PROVENANCE_RUNTIME: PASS (6 originals, high-risk direct-only, foreign callbacks, fence and policy denials)\n";
