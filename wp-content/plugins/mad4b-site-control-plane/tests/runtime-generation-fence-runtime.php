<?php
$tmp=sys_get_temp_dir().'/mad4b-generation-'.preg_replace('/[^a-z0-9]/i','',uniqid('',true));
mkdir($tmp,0700,true);
$main=$tmp.'/plugin.php';
file_put_contents($main,"<?php // boot\n");
define('ABSPATH',__DIR__);
define('MAD4B_SCP_VERSION','fixture-v1');
define('MAD4B_SCP_FILE',$main);
define('MAD4B_SCP_DIR',$tmp.'/');
define('MAD4B_SCP_BOOT_RUNTIME_FILE_SHA256',hash_file('sha256',$main));
define('MAD4B_SCP_BOOT_PROVENANCE_SHA256','');
class WP_Error{private $code;private $data;function __construct($code,$message='',$data=array()){$this->code=$code;$this->data=$data;}function get_error_code(){return $this->code;}function get_error_data(){return $this->data;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
$GLOBALS['schema']=12;$GLOBALS['profile']=array('contract'=>'mad4b.site-profile.v2','version'=>2);$GLOBALS['policy_sha']=str_repeat('a',64);$GLOBALS['catalog_generation']='mad4b.ability-catalog-transport.v2:fixture-v1';
function get_option($key,$default=false){if('schema'===$key)return $GLOBALS['schema'];if('profile'===$key)return $GLOBALS['profile'];return $default;}
class MAD4B_SCP_Schema{const VERSION=12;const OPTION='schema';}
class MAD4B_SCP_Site_Profile{const OPTION='profile';}
class MAD4B_SCP_Policy_Resolution{static function config_digest(){return $GLOBALS['policy_sha'];}}
class MAD4B_SCP_Ability_Catalog_Transport{static function wire_generation(){return $GLOBALS['catalog_generation'];}}
require dirname(__DIR__).'/includes/class-mad4b-scp-persisted-contract-compatibility.php';
require dirname(__DIR__).'/includes/class-mad4b-scp-runtime-generation-fence.php';
$fail=static function($m)use($tmp){fwrite(STDERR,"FAIL runtime-generation-fence-runtime: {$m}\n");@unlink($tmp.'/plugin.php');@rmdir($tmp);exit(1);};
$check=static function($c,$m)use($fail){if(!$c)$fail($m);};
$code=static function($v){return is_wp_error($v)?$v->get_error_code():'';};
$captured=MAD4B_SCP_Runtime_Generation_Fence::capture();
$check(is_array($captured)&&1===preg_match('/^[a-f0-9]{64}$/',(string)$captured['generation_sha256']),'baseline generation capture failed');
$check(is_array(MAD4B_SCP_Runtime_Generation_Fence::assert_current($captured)),'unchanged generation failed revalidation');
$GLOBALS['policy_sha']=str_repeat('b',64);
$check('mad4b_runtime_generation_changed'===$code(MAD4B_SCP_Runtime_Generation_Fence::assert_current($captured)),'policy generation drift failed open');
$GLOBALS['policy_sha']=str_repeat('a',64);
$GLOBALS['catalog_generation']='mad4b.ability-catalog-transport.v2:fixture-v2';
$check('mad4b_runtime_generation_changed'===$code(MAD4B_SCP_Runtime_Generation_Fence::assert_current($captured)),'catalog generation drift failed open');
$GLOBALS['catalog_generation']='mad4b.ability-catalog-transport.v2:fixture-v1';
file_put_contents($main,"<?php // replaced on disk\n");
$check('mad4b_runtime_worker_recycle_required'===$code(MAD4B_SCP_Runtime_Generation_Fence::assert_current()),'stale loaded worker survived on-disk runtime replacement');
file_put_contents($main,"<?php // boot\n");
$GLOBALS['schema']=13;
$check('mad4b_runtime_worker_recycle_required'===$code(MAD4B_SCP_Runtime_Generation_Fence::assert_current()),'future schema did not fence old runtime');
$GLOBALS['schema']=12;
$check(is_array(MAD4B_SCP_Runtime_Generation_Fence::assert_current()),'generation fence did not recover after fixture restoration');
@unlink($main);@rmdir($tmp);
echo "mad4b.runtime-generation-fence.runtime.v1: PASS\n";
