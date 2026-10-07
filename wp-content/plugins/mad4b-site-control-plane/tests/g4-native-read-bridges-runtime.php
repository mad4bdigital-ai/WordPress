<?php
require __DIR__ . '/g4-domain-fixture.php';
class MAD4B_SCP_Execution_Fence {
	public static $deny=false; public static $calls=array();
	public static function with_governed_child($name,$input,$callback,$reason){if(self::$deny)return new WP_Error('fixture_child_denied');self::$calls[]=array($name,$input,$reason);return $callback();}
}
class MAD4B_SCP_Provider_Compatibility_Certification {
	public static $eligible=true;
	public static function ability_status($provider,$ability,$adapter=null){return array('read_eligible'=>self::$eligible,'risk'=>'read','artifact'=>array('runtime_artifact_fingerprint'=>hash('sha256',$provider)));}
}
class G4FixtureAdapter {public function status(){return array('version'=>'1.2.3');}public function is_available(){return true;}}
class MAD4B_SCP_Adapter_Registry {public static function instance(){return new self();}public function get($id){return new G4FixtureAdapter();}}
$GLOBALS['g4_provider_rows']=array(
	array('provider_id'=>'contact-form-7','family_id'=>'forms','installed'=>true,'installed_version'=>'6.0.0','observed_plugin_identities'=>array(array('plugin_file'=>'contact-form-7/wp-contact-form-7.php','slug'=>'contact-form-7','version'=>'6.0.0','active'=>true,'network_active'=>false)),'certification_state'=>'installed_version_unprofiled','adapter_registered'=>false,'read_surface_ready'=>false),
	array('provider_id'=>'wpforms','family_id'=>'forms','installed'=>true,'installed_version'=>'1.0.0','observed_plugin_identities'=>array(array('plugin_file'=>'wpforms-lite/wpforms.php','slug'=>'wpforms','version'=>'1.0.0','active'=>false,'network_active'=>false)),'certification_state'=>'installed_version_unprofiled','adapter_registered'=>false,'read_surface_ready'=>false),
	array('provider_id'=>'gravityforms','family_id'=>'forms','installed'=>false,'installed_version'=>'','observed_plugin_identities'=>array(),'certification_state'=>'not_observed','adapter_registered'=>false,'read_surface_ready'=>false),
	array('provider_id'=>'fluentforms','family_id'=>'forms','installed'=>false,'installed_version'=>'','observed_plugin_identities'=>array(),'certification_state'=>'not_observed','adapter_registered'=>false,'read_surface_ready'=>false),
	array('provider_id'=>'jetformbuilder','family_id'=>'forms','installed'=>false,'installed_version'=>'','observed_plugin_identities'=>array(),'certification_state'=>'not_observed','adapter_registered'=>false,'read_surface_ready'=>false),
	array('provider_id'=>'woocommerce','family_id'=>'commerce','installed'=>false,'installed_version'=>'','observed_plugin_identities'=>array(),'certification_state'=>'not_observed','adapter_registered'=>false,'read_surface_ready'=>false),
	array('provider_id'=>'elementor','family_id'=>'builders','installed'=>false,'installed_version'=>'','observed_plugin_identities'=>array(),'certification_state'=>'not_observed','adapter_registered'=>false,'read_surface_ready'=>false),
	array('provider_id'=>'divi','family_id'=>'builders','installed'=>false,'installed_version'=>'','observed_plugin_identities'=>array(),'certification_state'=>'not_observed','adapter_registered'=>false,'read_surface_ready'=>false),
	array('provider_id'=>'kadence','family_id'=>'builders','installed'=>false,'installed_version'=>'','observed_plugin_identities'=>array(),'certification_state'=>'not_observed','adapter_registered'=>false,'read_surface_ready'=>false),
	array('provider_id'=>'wordpress-core','family_id'=>'builders','installed'=>true,'installed_version'=>'6.9','observed_plugin_identities'=>array(),'certification_state'=>'installed_version_unprofiled','adapter_registered'=>false,'read_surface_ready'=>false),
	array('provider_id'=>'wordpress-core','family_id'=>'wordpress-breadth','installed'=>true,'installed_version'=>'6.9','observed_plugin_identities'=>array(),'certification_state'=>'installed_version_unprofiled','adapter_registered'=>false,'read_surface_ready'=>false),
	array('provider_id'=>'updraftplus','family_id'=>'site-operations','installed'=>false,'installed_version'=>'','observed_plugin_identities'=>array(),'certification_state'=>'not_observed','adapter_registered'=>false,'read_surface_ready'=>false),
	array('provider_id'=>'w3-total-cache','family_id'=>'site-operations','installed'=>false,'installed_version'=>'','observed_plugin_identities'=>array(),'certification_state'=>'not_observed','adapter_registered'=>false,'read_surface_ready'=>false),
	array('provider_id'=>'all-in-one-wp-migration','family_id'=>'site-operations','installed'=>false,'installed_version'=>'','observed_plugin_identities'=>array(),'certification_state'=>'not_observed','adapter_registered'=>false,'read_surface_ready'=>false),
	array('provider_id'=>'wordfence','family_id'=>'site-operations','installed'=>false,'installed_version'=>'','observed_plugin_identities'=>array(),'certification_state'=>'not_observed','adapter_registered'=>false,'read_surface_ready'=>false),
	array('provider_id'=>'redirection','family_id'=>'site-operations','installed'=>false,'installed_version'=>'','observed_plugin_identities'=>array(),'certification_state'=>'not_observed','adapter_registered'=>false,'read_surface_ready'=>false),
	array('provider_id'=>'litespeed','family_id'=>'site-operations','installed'=>false,'installed_version'=>'','observed_plugin_identities'=>array(),'certification_state'=>'not_observed','adapter_registered'=>false,'read_surface_ready'=>false),
	array('provider_id'=>'wpml','family_id'=>'wordpress-breadth','installed'=>false,'installed_version'=>'','observed_plugin_identities'=>array(),'certification_state'=>'not_observed','adapter_registered'=>false,'read_surface_ready'=>false),
	array('provider_id'=>'polylang','family_id'=>'wordpress-breadth','installed'=>false,'installed_version'=>'','observed_plugin_identities'=>array(),'certification_state'=>'not_observed','adapter_registered'=>false,'read_surface_ready'=>false),
	array('provider_id'=>'advanced-custom-fields','family_id'=>'wordpress-breadth','installed'=>false,'installed_version'=>'','observed_plugin_identities'=>array(),'certification_state'=>'not_observed','adapter_registered'=>false,'read_surface_ready'=>false),
	array('provider_id'=>'buddypress','family_id'=>'wordpress-breadth','installed'=>false,'installed_version'=>'','observed_plugin_identities'=>array(),'certification_state'=>'not_observed','adapter_registered'=>false,'read_surface_ready'=>false),
	array('provider_id'=>'the-events-calendar','family_id'=>'wordpress-breadth','installed'=>false,'installed_version'=>'','observed_plugin_identities'=>array(),'certification_state'=>'not_observed','adapter_registered'=>false,'read_surface_ready'=>false),
);
class MAD4B_SCP_G4_Provider_Families {
	public static $calls=0;
	public static function readiness($input=array()){++self::$calls;return array('contract'=>'mad4b.g4-provider-readiness.v1','providers'=>array_values($GLOBALS['g4_provider_rows']),'provider_count'=>count($GLOBALS['g4_provider_rows']),'authorizing'=>false,'mutation_performed'=>false);}
}
class G4NativeAbility {
	public $result;public $allowed=true;public $readonly=true;public $destructive=false;public $calls=0;
	public function __construct($result){$this->result=$result;}
	public function check_permissions($target){return $this->allowed;}
	public function get_meta(){return array('annotations'=>array('readonly'=>$this->readonly,'destructive'=>$this->destructive));}
	public function execute($input){++$this->calls;return $this->result;}
}
require dirname(__DIR__).'/includes/domains/class-mad4b-scp-domain-native-providers.php';
$native=new G4NativeAbility(array('post_id'=>5,'sha256'=>str_repeat('a',64),'elements'=>array(array('id'=>'root','elType'=>'container','elements'=>array(array('id'=>'child','elType'=>'widget','widgetType'=>'text-editor','elements'=>array()))))));
$GLOBALS['g4_native_abilities']['elementor/get-document']=$native;
$provider=new MAD4B_SCP_Domain_Native_Read_Provider(array('elementor','document','builders','builder_tree','elementor/get-document','post_id'));
g4_assert(true===MAD4B_SCP_WordPress_Domain_Coverage::register($provider),'Compiled bridge registration failed.');
$request=array('provider_id'=>'elementor','capability_id'=>'document','target'=>array('post_id'=>5));
$observation=MAD4B_SCP_WordPress_Domain_Coverage::readback($request);g4_assert(!is_wp_error($observation) && 1===$native->calls,'Bridge did not use exact canonical read.');
g4_assert(MAD4B_SCP_Execution_Fence::$calls[0]===array('elementor/get-document',array('post_id'=>5),'wordpress_domain_read'),'Native bridge bypassed governed child dispatch.');
$desired=array('native_format'=>'elementor_tree','mode'=>'outline','nodes'=>array(array('id'=>'root','parent'=>'','type'=>'container','settings'=>array()),array('id'=>'child','parent'=>'root','type'=>'text-editor','settings'=>array())));
$input=$request;$input['desired']=$desired;$input['expected_state_sha256']=$observation['state_sha256'];
$plan=MAD4B_SCP_WordPress_Domain_Coverage::plan($input);g4_assert(!is_wp_error($plan),'Native outline proposal failed.');
$readback=MAD4B_SCP_WordPress_Domain_Coverage::readback(array('plan'=>$plan,'desired'=>$desired));g4_assert(!is_wp_error($readback) && $readback['desired_matches_observation'] && false===$readback['live_provider_acceptance'],'Native outline readback was not exact/non-authorizing.');
$desired['mode']='update';$desired['nodes'][1]['settings']=array('editor'=>'Must require provider control schema');$input['desired']=$desired;g4_assert(is_wp_error(MAD4B_SCP_WordPress_Domain_Coverage::plan($input)),'Metadata outline invented writable control authority.');
MAD4B_SCP_Provider_Compatibility_Certification::$eligible=false;$before=$native->calls;g4_assert(is_wp_error(MAD4B_SCP_WordPress_Domain_Coverage::readback($request)) && $native->calls===$before,'Uncertified read dispatched.');MAD4B_SCP_Provider_Compatibility_Certification::$eligible=true;
$native->allowed=false;g4_assert(is_wp_error(MAD4B_SCP_WordPress_Domain_Coverage::readback($request)) && $native->calls===$before,'Object permission denial dispatched.');$native->allowed=true;
MAD4B_SCP_Execution_Fence::$deny=true;g4_assert(is_wp_error(MAD4B_SCP_WordPress_Domain_Coverage::readback($request)) && $native->calls===$before,'Child fence denial dispatched.');MAD4B_SCP_Execution_Fence::$deny=false;
$native->readonly=false;g4_assert(is_wp_error(MAD4B_SCP_WordPress_Domain_Coverage::readback($request)) && $native->calls===$before,'Mutable provider target ran as a domain read.');$native->readonly=true;
$native->destructive=true;g4_assert(is_wp_error(MAD4B_SCP_WordPress_Domain_Coverage::readback($request)) && $native->calls===$before,'Destructive provider target ran as a domain read.');$native->destructive=false;
$native->result['post_id']=6;g4_assert(is_wp_error(MAD4B_SCP_WordPress_Domain_Coverage::readback($request)),'Foreign native document was accepted.');$native->result['post_id']=5;
$native->result['elements'][0]['settings']=array('spacing'=>1.5);g4_assert(!is_wp_error(MAD4B_SCP_WordPress_Domain_Coverage::readback($request)),'Native numeric settings blocked a reduced structural outline.');
$native->result['elements'][0]['elements'][0]['id']='root';g4_assert(is_wp_error(MAD4B_SCP_WordPress_Domain_Coverage::readback($request)),'Duplicate native IDs were observed as a valid outline.');$native->result['elements'][0]['elements'][0]['id']='child';
$native->result['sha256']='missing';g4_assert(is_wp_error(MAD4B_SCP_WordPress_Domain_Coverage::readback($request)),'Unbound native document hash was accepted.');$native->result['sha256']=str_repeat('a',64);
MAD4B_SCP_G4_Provider_Families::$calls=0;
$GLOBALS['g4_plugins']=array_fill_keys(range(1,513),array('Version'=>'scanner-must-not-run'));
$discovery=MAD4B_SCP_Domain_Native_Providers::discovery();g4_assert($discovery['ready'] && $discovery['complete'],'G4-backed provider discovery failed.');
g4_assert(1===MAD4B_SCP_G4_Provider_Families::$calls,'Domain discovery queried G4 readiness more than once.');
g4_assert('mad4b.g4-provider-readiness.v1'===$discovery['identity_source'] && false===$discovery['duplicate_plugin_scanner'],'Domain discovery did not use the canonical G4 identity source.');
$rows=array_column($discovery['providers'],null,'provider_id');
g4_assert($rows['contact-form-7']['installed'] && !$rows['contact-form-7']['runtime_certification_inferred'] && false===$rows['contact-form-7']['plan_contract_inferred'],'Discovery invented runtime certification or contracts.');
g4_assert(!$rows['gravityforms']['installed'] && false===$rows['wpforms']['observed_plugins'][0]['active'],'G4 provider readiness lost absent/inactive truth.');
g4_assert(isset($rows['advanced-custom-fields']) && !isset($rows['acf']),'ACF alias drift reappeared.');
g4_assert(isset($rows['the-events-calendar']) && !isset($rows['events-calendar']),'Events Calendar alias drift reappeared.');
g4_assert(isset($rows['all-in-one-wp-migration']) && !isset($rows['duplicator']),'Migration provider widened outside the G4 reviewed catalog.');
$core_rows=array_values(array_filter($discovery['providers'],static function($row){return 'wordpress-core'===($row['provider_id']??'');}));
$core_families=array_values(array_unique(array_map(static function($row){return $row['family']??'';},$core_rows)));sort($core_families);
g4_assert(2===count($core_rows) && array('builders','wordpress-breadth')===$core_families,'Multi-family WordPress core identity was collapsed or rejected.');
$removed=null;
foreach($GLOBALS['g4_provider_rows'] as $index=>$row){if('the-events-calendar'===($row['provider_id']??'')){$removed=$row;unset($GLOBALS['g4_provider_rows'][$index]);break;}}
$GLOBALS['g4_provider_rows']=array_values($GLOBALS['g4_provider_rows']);
g4_assert(is_array($removed),'Events Calendar fixture row missing.');
$blocked=MAD4B_SCP_Domain_Native_Providers::discovery();g4_assert(!$blocked['ready'] && 'domain_overlay_provider_not_in_g4_catalog'===$blocked['reason'],'Overlay provider missing from G4 catalog did not fail closed.');
$GLOBALS['g4_provider_rows'][]=$removed;
$duplicate=$GLOBALS['g4_provider_rows'][0];$GLOBALS['g4_provider_rows'][]=$duplicate;
$blocked=MAD4B_SCP_Domain_Native_Providers::discovery();g4_assert(!$blocked['ready'] && 'g4_provider_duplicate'===$blocked['reason'],'Duplicate provider-family pair did not fail closed.');
array_pop($GLOBALS['g4_provider_rows']);
g4_done('native-read-bridges-runtime');
