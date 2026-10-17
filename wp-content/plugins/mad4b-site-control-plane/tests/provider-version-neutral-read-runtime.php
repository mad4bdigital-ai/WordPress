<?php
define('ABSPATH', __DIR__ . '/');
define('MAD4B_SCP_DIR', dirname(__DIR__) . '/');
function sanitize_key($v) {return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function wp_register_ability(){return true;}
$GLOBALS['available_abilities'] = array('elementor/list-pages' => true, 'elementor/get-page-structure' => true);
function wp_has_ability($name){return !empty($GLOBALS['available_abilities'][$name]);}
function assertion($value,$name){if(!$value){fwrite(STDERR,"FAIL: ".$name."\n");exit(1);}}
require dirname(__DIR__) . '/includes/class-mad4b-scp-provider-contracts.php';
require dirname(__DIR__) . '/includes/class-mad4b-scp-dependency-manager.php';
$elementor=MAD4B_SCP_Provider_Contracts::read_continuity_status('elementor',true);
assertion($elementor['discovery_eligible']===true,'running plugin discovery should be eligible');
assertion($elementor['read_ability_observed']===true,'actual registered reads are recognized');
assertion(count($elementor['observed_read_abilities'])===2,'observed bounded read verbs');
assertion(!in_array('elementor/update-page-settings',$elementor['observed_read_abilities'],true),'write ability never inferred read');
assertion(!$elementor['write_certified'] && !$elementor['write_grants_created'],'no write promotion');
$missing=MAD4B_SCP_Provider_Contracts::read_continuity_status('elementor',false);
assertion(!$missing['discovery_eligible'] && !$missing['read_ability_observed'],'disabled adapter denied');
$unknown=MAD4B_SCP_Provider_Contracts::read_continuity_status('unknown_provider',true);
assertion($unknown['discovery_eligible'] && !$unknown['read_ability_observed'],'unregistered provider does not imply verified read');
$healthy=array('installed'=>true,'plugin_identity_ambiguous'=>false,
    'runtime_loaded'=>true,'runtime_from_official_plugin'=>true,
    'abilities_api_available'=>true,'network_unsupported'=>false,
    'installed_version'=>'0.7.1');
$ready=MAD4B_SCP_Dependency_Manager::adapter_read_runtime_ready($healthy);
assertion($ready===true,'actual official runtime read eligible regardless of version');
$healthy['installed_version']='0.6.1';
assertion(MAD4B_SCP_Dependency_Manager::adapter_read_runtime_ready($healthy)===true,'rollback without blanket lock');
$healthy['installed_version']='9.77.0';
assertion(MAD4B_SCP_Dependency_Manager::adapter_read_runtime_ready($healthy)===true,'future version discovery does not auto deny');
foreach(array('runtime_from_official_plugin','installed','runtime_loaded','abilities_api_available') as $part){
 $bad=$healthy;$bad[$part]=false;
 assertion(!MAD4B_SCP_Dependency_Manager::adapter_read_runtime_ready($bad),'unknown read runtime must be denied: '.$part);
}
$bad=$healthy;$bad['plugin_identity_ambiguous']=true;
assertion(!MAD4B_SCP_Dependency_Manager::adapter_read_runtime_ready($bad),'ambiguous runtime source denied');
$bad=$healthy;$bad['network_unsupported']=true;
assertion(!MAD4B_SCP_Dependency_Manager::adapter_read_runtime_ready($bad),'network unsupported denied');
echo "PROVIDER_VERSION_NEUTRAL_READ_RUNTIME_PASS\n";
