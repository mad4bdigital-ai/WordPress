<?php
require __DIR__ . '/g4-domain-fixture.php';
final class G4FixtureProvider extends MAD4B_SCP_Domain_Provider {
	public $mode=''; public $version='1.0.0'; public $allowed=true; public $state=array('title'=>'Before'); public $reads=0;
	public function id(){return 'fixture-native';}
	public function capabilities(){return array('object'=>array('family'=>'wordpress-breadth','profile'=>'wordpress_object','read_ability'=>'fixture/read-object','target_schema'=>array('type'=>'object','additionalProperties'=>false,'properties'=>array('id'=>array('type'=>'integer','minimum'=>1)),'required'=>array('id'))));}
	public function runtime(){return array('active'=>true,'version'=>$this->version,'artifact_sha256'=>hash('sha256',$this->version));}
	public function authorize($cap,array $target,array $desired){return $this->allowed && 'object'===$cap && 1===($target['id']??null);}
	public function inspect($cap,array $target){
		++$this->reads;
		if('throw'===$this->mode)throw new RuntimeException('Sensitive provider message must not escape.');
		if('generation'===$this->mode)++MAD4B_SCP_Runtime_Generation_Fence::$epoch;
		if('descriptor'===$this->mode)++MAD4B_SCP_Capability_Descriptor_Registry::$epoch;
		if('runtime'===$this->mode)$this->version='2.0.0';
		if('permission'===$this->mode)$this->allowed=false;
		return array('state_sha256'=>MAD4B_SCP_Domain_Contracts::digest($this->state),'target_sha256'=>MAD4B_SCP_Domain_Contracts::digest('wrong-object'===$this->mode?array('id'=>2):$target),'facts'=>array('admitted_object_kinds'=>array('runtime-cpt'),'object_id'=>'p1','object_access'=>true,'field_contracts'=>array('title'=>g4_field())),'projection'=>$this->state,'private_diagnostic'=>'private@example.invalid');
	}
	public function matches($cap,array $desired,array $context){if('compare-generation'===$this->mode)++MAD4B_SCP_Runtime_Generation_Fence::$epoch;if('compare-permission'===$this->mode)$this->allowed=false;return $this->state===$desired['fields'];}
}
$provider=new G4FixtureProvider();g4_assert(true===MAD4B_SCP_WordPress_Domain_Coverage::register($provider),'Typed provider registration failed.');
g4_assert(is_wp_error(MAD4B_SCP_WordPress_Domain_Coverage::register($provider)),'Duplicate provider replaced trusted object.');
$request=array('provider_id'=>'fixture-native','capability_id'=>'object','target'=>array('id'=>1));
$observation=MAD4B_SCP_WordPress_Domain_Coverage::readback($request);g4_assert(!is_wp_error($observation),'Initial native state observation failed.');
g4_assert(false===strpos(json_encode($observation),'private@example.invalid'),'Raw provider diagnostics leaked.');
$desired=array('kind'=>'runtime-cpt','object_id'=>'p1','fields'=>array('title'=>'After'));
$input=$request;$input['desired']=$desired;$input['expected_state_sha256']=$observation['state_sha256'];
$plan=MAD4B_SCP_WordPress_Domain_Coverage::plan($input);g4_assert(!is_wp_error($plan) && false===$plan['execution_supported'] && false===$plan['authorizing'],'Preflight plan failed or acquired authority.');
g4_assert(array('title'=>'Before')===$provider->state,'Planning mutated native state.');
$result=MAD4B_SCP_WordPress_Domain_Coverage::readback(array('plan'=>$plan,'desired'=>$desired));g4_assert(!is_wp_error($result) && false===$result['desired_matches_observation'],'Readback reported unapplied desired state as accepted.');
$provider->state=array('title'=>'After');$result=MAD4B_SCP_WordPress_Domain_Coverage::readback(array('plan'=>$plan,'desired'=>$desired));g4_assert(!is_wp_error($result) && true===$result['desired_matches_observation'] && false===$result['approved_receipt_created'] && false===$result['live_provider_acceptance'],'Observation forged an approved live receipt.');
g4_assert(is_wp_error(MAD4B_SCP_WordPress_Domain_Coverage::plan($input)),'Stale state was planned again.');
$provider->state=array('title'=>'Before');
$bad=$input;$bad['facts']=array('object_access'=>true);g4_assert(is_wp_error(MAD4B_SCP_WordPress_Domain_Coverage::plan($bad)),'Caller forged provider-owned facts.');
$bad=$request;$bad['provider_id']='unregistered';g4_assert(is_wp_error(MAD4B_SCP_WordPress_Domain_Coverage::readback($bad)),'Unknown provider was admitted.');
$bad=$request;$bad['target']['extra']=true;g4_assert(is_wp_error(MAD4B_SCP_WordPress_Domain_Coverage::readback($bad)),'Unknown target field passed native schema.');
$provider->allowed=false;$before=$provider->reads;g4_assert(is_wp_error(MAD4B_SCP_WordPress_Domain_Coverage::readback($request)) && $before===$provider->reads,'Permission denial still read provider object.');$provider->allowed=true;
foreach(array('generation','descriptor','runtime','permission','throw','wrong-object') as $mode){$provider->mode=$mode;g4_assert(is_wp_error(MAD4B_SCP_WordPress_Domain_Coverage::plan($input)),'Provider changed during plan: '.$mode);$provider->allowed=true;$provider->version='1.0.0';MAD4B_SCP_Runtime_Generation_Fence::$epoch=0;MAD4B_SCP_Capability_Descriptor_Registry::$epoch=0;}
$provider->mode='';
$bad=$plan;$bad['capability_binding']='invalid';$basis=$bad;unset($basis['plan_sha256']);$bad['plan_sha256']=MAD4B_SCP_Domain_Contracts::digest($basis);g4_assert(is_wp_error(MAD4B_SCP_WordPress_Domain_Coverage::readback(array('plan'=>$bad,'desired'=>$desired))),'Malformed binding caused unsafe readback.');
$bad=$plan;$bad['unexpected']='unadmitted';$basis=$bad;unset($basis['plan_sha256']);$bad['plan_sha256']=MAD4B_SCP_Domain_Contracts::digest($basis);g4_assert(is_wp_error(MAD4B_SCP_WordPress_Domain_Coverage::readback(array('plan'=>$bad,'desired'=>$desired))),'Unknown plan field was admitted.');
$bad=$plan;$bad['target']['id']=2;g4_assert(is_wp_error(MAD4B_SCP_WordPress_Domain_Coverage::readback(array('plan'=>$bad,'desired'=>$desired))),'Tampered plan target passed digest binding.');
$GLOBALS['g4_actor']=13;g4_assert(is_wp_error(MAD4B_SCP_WordPress_Domain_Coverage::readback(array('plan'=>$plan,'desired'=>$desired))),'Other actor reused plan.');$GLOBALS['g4_actor']=12;
MAD4B_SCP_Time_Policy::$now+=301;g4_assert(is_wp_error(MAD4B_SCP_WordPress_Domain_Coverage::readback(array('plan'=>$plan,'desired'=>$desired))),'Expired plan was reused.');MAD4B_SCP_Time_Policy::$now-=301;
foreach(array('compare-generation','compare-permission') as $mode){$provider->mode=$mode;g4_assert(is_wp_error(MAD4B_SCP_WordPress_Domain_Coverage::readback(array('plan'=>$plan,'desired'=>$desired))),'Comparator drift returned accepted observation.');$provider->allowed=true;MAD4B_SCP_Runtime_Generation_Fence::$epoch=0;}
$provider->mode='';$GLOBALS['g4_admin']=false;g4_assert(is_wp_error(MAD4B_SCP_WordPress_Domain_Coverage::inventory()),'Non-admin inspected domain coverage.');$GLOBALS['g4_admin']=true;
MAD4B_SCP_WordPress_Domain_Coverage::register_abilities();g4_assert(count($GLOBALS['g4_abilities'])===3,'Registration count drifted.');
foreach($GLOBALS['g4_abilities'] as $definition){g4_assert('mad4b-admin'===$definition['category'] && false===$definition['meta']['public'] && false===$definition['meta']['show_in_rest'] && false===$definition['meta']['mcp']['public'] && 'admin'===$definition['meta']['mcp']['surface'],'Domain ability escaped private admin scope.');}
foreach(array('form_config','form_submissions','commerce_catalog','commerce_private','commerce_financial','builder_tree','operations_backup','operations_cache','operations_redirect','operations_security','wordpress_hierarchy','wordpress_object','wordpress_private_collection') as $profile){g4_deny($profile,array(),array(),'provider_fact_types');}
g4_assert(is_wp_error(MAD4B_SCP_Domain_Contracts::bounded(array('value'=>1.5))),'Ambiguous floating-point hash material was accepted.');
g4_assert(is_wp_error(MAD4B_SCP_Domain_Contracts::bounded(array('value'=>"hidden\u{202E}text"))),'Hidden bidi control entered canonical hash material.');
g4_assert(MAD4B_SCP_Domain_Contracts::digest(array('a'=>1,'b'=>2))===MAD4B_SCP_Domain_Contracts::digest(array('b'=>2,'a'=>1)),'Object hash changed under key order.');
g4_done('domain-coverage-runtime');
