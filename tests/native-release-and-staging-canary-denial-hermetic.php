<?php
/**
 * Original read-only Company Release readiness and exact FAORE Staging canary.
 * Source-only test: does not create a write Ability, grant, option, broker
 * task, ticket, deploy, or Original313 receipt.
 */
define('ABSPATH',__DIR__.'/');
$GLOBALS['release_registered']=array();
$GLOBALS['canary_options']=array();
$GLOBALS['canary_env']='production';
$GLOBALS['canary_origin']='https://staging.example.invalid';
$GLOBALS['canary_valid_profile']=true;
$GLOBALS['canary_mutations']=0;
function add_action($hook,$callback,$priority=10){}
function wp_register_ability($name,$config){
 if(isset($GLOBALS['release_registered'][$name]))
  throw new RuntimeException('Duplicate original WordPress Ability: '.$name);
 $GLOBALS['release_registered'][$name]=$config;
}
function wp_has_ability($name){return isset($GLOBALS['release_registered'][$name]);}
function wp_get_environment_type(){return $GLOBALS['canary_env'];}
function wp_is_uuid($v){return (bool)preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',$v);}
function untrailingslashit($v){return rtrim($v,'/');}
function home_url($path='/'){return $GLOBALS['canary_origin'].ltrim($path,'/')===$GLOBALS['canary_origin']? $GLOBALS['canary_origin']:$GLOBALS['canary_origin'].'/';}
function get_option($key,$missing=false){
 return array_key_exists($key,$GLOBALS['canary_options'])?$GLOBALS['canary_options'][$key]:$missing;
}
function update_option($key,$value,$autoload=false){
 ++$GLOBALS['canary_mutations'];throw new RuntimeException('Unapproved option mutation');
}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
class WP_Error{
 private $code;public function __construct($c,$msg='',$data=array()){$this->code=$c;}
 public function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
class MAD4B_SCP_Site_Profile {
 public static function current_environment(){return $GLOBALS['canary_env'];}
 public static function status(){
  return array(
   'contract'=>'mad4b.site-profile.v2',
   'configured'=>$GLOBALS['canary_valid_profile'],
   'origin_match'=>true,
   'environment_match'=>true,
   'deployment_binding_match'=>true,
   'environment'=>'staging',
   'site_uuid'=>'12345678-1234-4123-8123-123456789abc',
   'canonical_origin'=>'https://staging.example.invalid'
  );
 }
}
function assert_readonly($ok,$reason){
 if(!$ok)throw new RuntimeException('FAIL NATIVE RELEASE/CANARY: '.$reason);
}
$root=dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/';
require $root.'class-mad4b-scp-company-code-release-readiness.php';
require $root.'class-mad4b-scp-faore-staging-canary-inspection.php';
$release='MAD4B_SCP_Company_Code_Release_Readiness';
$canary='MAD4B_SCP_FAORE_Staging_Canary_Inspection';
$release::register_ability();
$canary::register_abilities();
$expected=array($release::ABILITY,$canary::PLAN,$canary::VERIFY);
assert_readonly(count($GLOBALS['release_registered'])===3
 && array_keys($GLOBALS['release_registered'])===$expected,
 'Do not register a code release apply, arbitrary option write or broker dispatch');
foreach($GLOBALS['release_registered'] as $name=>$cfg)
 assert_readonly(($cfg['permission_callback']??null)===array('MAD4B_SCP_Policy','can_read')
  && ($cfg['input_schema']['additionalProperties']??null)===false
  && ($cfg['meta']['mcp']['surface']??null)==='read'
  && ($cfg['meta']['annotations']['readonly']??null)===true
  && ($cfg['meta']['annotations']['destructive']??null)===false
  && empty($cfg['meta']['mcp']['public']),
 'Source-registered read-only inventory cannot certify deploy: '.$name);
$before=$release::status();
assert_readonly($before['dispatch_ability_registered']===false
 && $before['production_release_admitted']===false
 && $before['original_313_receipt_verified']===false
 && $before['host_exact_target_verified']===false
 && $before['broker_grant_verified']===false
 && $before['end_to_end_complete']===false
 && $before['authority_created']===false
 && $before['mutation_performed']===false,
 'Presence of read-only Release Readiness cannot become deployment approval');
$invalid=$release::status(array('target_path'=>'/etc/passwd'));
assert_readonly($invalid['input_valid']===false
 && $invalid['production_release_admitted']===false,
 'Unspecified paths cannot be submitted as deploy admission');
$GLOBALS['release_registered'][$release::DISPATCH]=array('fake_dependency_presence'=>true);
$present=$release::status();
assert_readonly($present['dispatch_ability_registered']===true
 && $present['original_313_receipt_verified']===false
 && $present['production_release_admitted']===false,
 'A dynamically registered dispatcher alone must not certify Owner/Host or Original313');
unset($GLOBALS['release_registered'][$release::DISPATCH]);
$input=array('correlation_id'=>'canary_001',
 'option_suffix'=>'cafebabedeadbeef');
assert_readonly($canary::plan($input)->get_error_code()==='mad4b_faore_canary_wrong_environment',
 'Production canary inspection must be rejected before option access');
$GLOBALS['canary_env']='staging';
$bad=$canary::plan(array('correlation_id'=>'too short','option_suffix'=>'../../../bad'));
assert_readonly($bad instanceof WP_Error
 && $bad->get_error_code()==='mad4b_faore_canary_input_invalid',
 'Option key traversal and arbitrary correlation ID are denied');
$GLOBALS['canary_valid_profile']=false;
assert_readonly($canary::verify($input)->get_error_code()==='mad4b_faore_canary_profile_unverified',
 'Staging option inspect needs exact enrolled profile');
$GLOBALS['canary_valid_profile']=true;
$absent=$canary::plan($input);
assert_readonly(is_array($absent) && $absent['option_present']===false
 && $absent['authorization_granted']===false && $absent['mutation_performed']===false
 && $absent['write_ability_registered']===false
 && $absent['rollback_verified']===false,
 'Absent canary is only an observation, never permission to create it');
$key=$canary::PREFIX.'cafebabedeadbeef';
$GLOBALS['canary_options'][$key]='';
$empty=$canary::verify($input);
assert_readonly(is_array($empty) && $empty['option_present']===true
 && $empty['observation_fingerprint']!==$absent['observation_fingerprint'],
 'Empty but existing option cannot be mistaken for absent');
$GLOBALS['canary_options'][$key]=false;
$false=$canary::verify($input);
assert_readonly($false['option_present']===true
 && $false['authorization_granted']===false,
 'Stored false cannot be an approval or a missing canary');
assert_readonly($GLOBALS['canary_mutations']===0,
 'Read-only canary must never mutate options');
echo "RELEASE_AND_FAORE_NATIVE_READONLY: PASS original 3 abilities and denied write/mismatch paths\n";
// The audited execution gate requires an anchored success-only terminal marker.
echo "RELEASE_AND_FAORE_NATIVE_READONLY: PASS\n";
