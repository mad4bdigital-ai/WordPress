<?php
define( 'ABSPATH', __DIR__ );
class WP_Error { private $code; private $data; public function __construct($code,$message='',$data=array()){$this->code=$code;$this->data=$data;} public function get_error_code(){return $this->code;} public function get_error_data(){return $this->data;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
$GLOBALS['options']=array();
function get_option($key,$default=false){return array_key_exists($key,$GLOBALS['options'])?$GLOBALS['options'][$key]:$default;}
class MAD4B_SCP_Schema { const VERSION=12; const OPTION='schema'; }
class MAD4B_SCP_Site_Profile { const OPTION='profile'; }
require dirname(__DIR__).'/includes/class-mad4b-scp-persisted-contract-compatibility.php';
$fail=static function($m){fwrite(STDERR,"FAIL persisted-contract-compatibility-runtime: {$m}\n");exit(1);};
$check=static function($c,$m)use($fail){if(!$c)$fail($m);};
$code=static function($v){return is_wp_error($v)?$v->get_error_code():'';};
$GLOBALS['options']['schema']=12;
$GLOBALS['options']['profile']=array('contract'=>'mad4b.site-profile.v2','version'=>2);
$current=MAD4B_SCP_Persisted_Contract_Compatibility::assert_write_compatible();
$check(is_array($current)&&!empty($current['ready_for_write']),'current persisted generations were rejected');
$check(1===preg_match('/^[a-f0-9]{64}$/',(string)$current['registry_sha256']),'registry digest unavailable');
$check('current'===MAD4B_SCP_Persisted_Contract_Compatibility::evaluate_schema_generation(12,12),'current schema evaluation failed');
$check('schema_upgrade_required'===MAD4B_SCP_Persisted_Contract_Compatibility::evaluate_schema_generation(11,12),'N-1 schema evaluation failed');
$check('future_schema_downgrade_forbidden'===MAD4B_SCP_Persisted_Contract_Compatibility::evaluate_schema_generation(13,12),'future schema downgrade failed open');
$GLOBALS['options']['schema']=13;
$future=MAD4B_SCP_Persisted_Contract_Compatibility::assert_write_compatible();
$check('mad4b_persisted_contract_incompatible'===$code($future),'future schema was admitted');
$GLOBALS['options']['schema']=12;
$GLOBALS['options']['profile']=array('contract'=>'mad4b.site-profile.v1','version'=>1);
$legacy=MAD4B_SCP_Persisted_Contract_Compatibility::assert_write_compatible();
$check('mad4b_persisted_contract_incompatible'===$code($legacy),'legacy profile was silently reinterpreted for writes');
$GLOBALS['options']['profile']=array('contract'=>'mad4b.site-profile.v999','version'=>999);
$unknown=MAD4B_SCP_Persisted_Contract_Compatibility::assert_write_compatible();
$check('mad4b_persisted_contract_incompatible'===$code($unknown),'unknown/future profile failed open');
echo "mad4b.persisted-contract-compatibility.runtime.v1: PASS\n";
