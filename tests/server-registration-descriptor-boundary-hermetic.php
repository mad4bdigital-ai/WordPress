<?php
/** Native first-party MCP Server Registration descriptor evidence, no WordPress. */
define('ABSPATH',__DIR__.'/');
class WP_Error {
    private $code;
    public function __construct($code){$this->code=$code;}
    public function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
class MAD4B_SCP_Capability_Descriptor_Registry {
    const CONSUMER_BINDING_CONTRACT='mad4b.capability-descriptor-consumer-binding.v1';
    const CONTRACT='mad4b.capability-descriptor.v2';
    const GENERATION_CONTRACT='mad4b.capability-generation-roots.v1';
    public static $record;
    public static function binding($name,$consumer){return self::$record;}
}
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-server-registration-evidence.php';
function validate_case($allowed,$label,$server_id='mad4b-chatgpt') {
    $result=MAD4B_SCP_Server_Registration_Evidence::capability_descriptor_evidence(
        $server_id,array('mad4b/read-execute','mad4b/read-execute')
    );
    if((!empty($result['ready']))!==$allowed
       || ($allowed && count($result['bindings'])!==1)
       || (!$allowed && (!$result['blockers'] || $result['bindings']))) {
       fwrite(STDERR, "FAIL: Descriptor result unverified: $label\n");
       exit(1);
    }
}
$valid=array(
    'contract'=>MAD4B_SCP_Capability_Descriptor_Registry::CONSUMER_BINDING_CONTRACT,
    'consumer'=>'servers',
    'descriptor_contract'=>MAD4B_SCP_Capability_Descriptor_Registry::CONTRACT,
    'generation_contract'=>MAD4B_SCP_Capability_Descriptor_Registry::GENERATION_CONTRACT,
    'ability_name'=>'mad4b/read-execute',
    'input_schema_sha256'=>str_repeat('a',64),
    'classification_sha256'=>str_repeat('b',64),
    'descriptor_sha256'=>str_repeat('c',64),
    'execution_lane'=>'read',
    'generation_roots'=>array('contract_root'=>str_repeat('d',64),'site_root'=>str_repeat('e',64)),
    'authority_snapshot_is_grant'=>false,
    'authority_revalidation_required'=>true,
    'authorizing'=>false,
);
MAD4B_SCP_Capability_Descriptor_Registry::$record=$valid;
validate_case(true,'exact original descriptor');
foreach (array('read','write','content','admin','enrollment','developer') as $lane) {
    MAD4B_SCP_Capability_Descriptor_Registry::$record=array_merge($valid,array('execution_lane'=>$lane));
    validate_case(true,'registered canonical lane: '.$lane);
    if ('read' !== $lane) validate_case(false,'never expose mutation on read server: '.$lane,'mad4b-read');
}
foreach (array('none','internal','breakglass','developer-breakglass','unclassified','direct_only','') as $lane) {
    MAD4B_SCP_Capability_Descriptor_Registry::$record=array_merge($valid,array('execution_lane'=>$lane));
    validate_case(false,'non-governed or dedicated breakglass lane: '.$lane);
}
MAD4B_SCP_Capability_Descriptor_Registry::$record=$valid;
validate_case(true,'only read on read server','mad4b-read');
foreach(array(
    'wp_error'=>new WP_Error('descriptor_not_ready'),
    'null'=>null,
    'false'=>false,
    'string'=>'yes',
    'empty'=>array(),
    'cross_ability'=>array_merge($valid,array('ability_name'=>'mad4b/another-tool')),
    'cross_consumer'=>array_merge($valid,array('consumer'=>'authorization')),
    'wrong_contract'=>array_merge($valid,array('contract'=>'malformed')),
    'wrong_descriptor_contract'=>array_merge($valid,array('descriptor_contract'=>'malformed')),
    'wrong_generation_contract'=>array_merge($valid,array('generation_contract'=>'malformed')),
    'non_sha'=>array_merge($valid,array('descriptor_sha256'=>'a')),
    'non_schema_sha'=>array_merge($valid,array('input_schema_sha256'=>'a')),
    'non_classification_sha'=>array_merge($valid,array('classification_sha256'=>'a')),
    'nonroot'=>array_merge($valid,array('generation_roots'=>array())),
    'authorizing'=>array_merge($valid,array('authorizing'=>true)),
    'grant_proxy'=>array_merge($valid,array('authority_snapshot_is_grant'=>true)),
    'unchecked_auth'=>array_merge($valid,array('authority_revalidation_required'=>false)),
) as $name=>$record){
    MAD4B_SCP_Capability_Descriptor_Registry::$record=$record;
    validate_case(false,$name);
}
echo "PASS: SERVER_DESCRIPTOR_EXACT_BINDING 37 strict source-backed positive/negative cases\n";
