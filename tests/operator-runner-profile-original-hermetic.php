<?php
/** Original signed Host Operator runner identity: no WordPress user or VPS action. */
define('ABSPATH',__DIR__.'/');
class WP_Error {
 private $code;
 function __construct($code,$message='',$data=[]){$this->code=$code;}
 function get_error_code(){return $this->code;}
}
function is_wp_error($v){return $v instanceof WP_Error;}
function home_url($path='/'){return 'https://company.example.com'.$path;}
function untrailingslashit($uri){return rtrim($uri,'/');}
function sanitize_key($id){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$id));}
function operator_check($ok,$why){
 if(!$ok){fwrite(STDERR,'FAIL: '.$why.PHP_EOL);exit(1);}
 $GLOBALS['checks']=($GLOBALS['checks']??0)+1;
}
class MAD4B_SCP_Site_Profile {
 static $configured=true,$origin=true,$matched=true,$environment='staging';
 static function configured(){return self::$configured;}
 static function origin_enrolled(){return self::$origin;}
 static function site_urls_match_enrollment(){return self::$matched;}
 static function current_environment(){return self::$environment;}
 static function site_uuid(){return '11111111-1111-4111-8111-111111111111';}
}
class MAD4B_Company_Host_Topology_Registry_V1 {
 static $signed;
 static function verified_local_registry($site,$env,$origin){
  if(!empty($GLOBALS['host_registry_throw']))throw new \RuntimeException('host inventory failed');
  return self::$signed;
 }
}
class MAD4B_SCP_Host_Bridge {
 static $fingerprint;
 static function target_fingerprint_readonly(){return self::$fingerprint;}
}
$sha=str_repeat('a',64);
MAD4B_SCP_Host_Bridge::$fingerprint=$sha;
$sample=[
 'contract'=>'mad4b.company-host-topology-registry.v1',
 'state'=>'VERIFIED',
 'registration_authority_readback'=>'ORIGINAL_SIGNED_RECEIPT_VERIFIED',
 'required_nodes'=>[
  ['runtime_role'=>'wordpress_mcp','target_fingerprint'=>$sha,
   'runner_profile_id'=>'wayfa-host-operator']
 ]
];
MAD4B_Company_Host_Topology_Registry_V1::$signed=$sample;
require dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-operator-runner-profile.php';
$c='MAD4B_SCP_Operator_Runner_Profile';
operator_check($c::from_verified_inventory($sample,$sha)==='wayfa-host-operator',
 'Exact single original Host fingerprint and signed topology resolve a runner profile ID');
operator_check($c::current_id()==='wayfa-host-operator',
 'Original Host NHI runner profile resolves independently of WordPress user ID');
foreach([
 ['state'=>'PENDING'],['registration_authority_readback'=>'NOT_VERIFIED'],
 ['contract'=>'unknown']
] as $delta){
 operator_check(is_wp_error($c::from_verified_inventory(array_replace($sample,$delta),$sha)),
  'Unsigned topology or unverified original Host receipt cannot choose an operator');
}
foreach([
 ['required_nodes'=>[]],
 ['required_nodes'=>[$sample['required_nodes'][0],$sample['required_nodes'][0]]],
 ['required_nodes'=>[array_replace($sample['required_nodes'][0],['runner_profile_id'=>'bad!!'])]]
] as $delta){
 operator_check(is_wp_error($c::from_verified_inventory(array_replace($sample,$delta),$sha)),
  'Unknown, duplicate or malformed runner registry bindings are denied');
}
operator_check(is_wp_error($c::from_verified_inventory($sample,str_repeat('b',64)))
 &&is_wp_error($c::from_verified_inventory($sample,'not-a-sha')),
 'Cross-Host fingerprint and non-SHA input do not inherit old operator identity');
foreach([new WP_Error('config_unknown'),null,1,'true',false] as $unknown) {
 MAD4B_SCP_Site_Profile::$configured=$unknown;
 operator_check(is_wp_error($c::current_id()),
  'Unknown Site Profile configured status cannot authorize Host runner');
}
MAD4B_SCP_Site_Profile::$configured=true;
foreach([new WP_Error('origin_unknown'),null,1,'true',false] as $unknown) {
 MAD4B_SCP_Site_Profile::$origin=$unknown;
 operator_check(is_wp_error($c::current_id()),
  'Unknown Site Profile origin proof cannot authorize Host runner');
}
MAD4B_SCP_Site_Profile::$origin=true;
foreach([new WP_Error('url_unknown'),null,1,'true',false] as $unknown) {
 MAD4B_SCP_Site_Profile::$matched=$unknown;
 operator_check(is_wp_error($c::current_id()),
  'Unknown Site URL enrollment must not authorize Host Operator identity');
}
MAD4B_SCP_Site_Profile::$matched=true;
MAD4B_SCP_Site_Profile::$environment='production';
operator_check(is_wp_error($c::current_id()),
 'Original Staging runner never self-upgrades to Production without governed site policy');
MAD4B_SCP_Site_Profile::$environment='staging';
MAD4B_SCP_Host_Bridge::$fingerprint=new WP_Error('host_binding_unknown');
operator_check(is_wp_error($c::current_id()),
 'Unknown Host Bridge fingerprint cannot trigger a PHP crash or fallback to foreign Runner');
MAD4B_SCP_Host_Bridge::$fingerprint=$sha;
$GLOBALS['host_registry_throw']=true;
operator_check(is_wp_error($c::current_id()),
 'Original signed Host registry exception must deny operator selection instead of fatal error');
echo 'PASS: OPERATOR_RUNNER_PROFILE '.($GLOBALS['checks']??0).
 ' original Site Profile, signed Host inventory and NHI separation cases'.PHP_EOL;
