<?php
/* Pure local artifact identity tests. Does not bootstrap WordPress or permit writes. */
define('ABSPATH',__DIR__.'/');
$base=sys_get_temp_dir().'/mad4b-provider-artifact-'.bin2hex(random_bytes(6));
mkdir($base,0700,true);
define('WP_PLUGIN_DIR',$base);
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_-]/','',strtolower($v)));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
$GLOBALS['active']=array();
$GLOBALS['plugins']=array();
function get_plugins(){return $GLOBALS['plugins'];}
function get_option($key,$default=array()){return $key==='active_plugins'?$GLOBALS['active']:$default;}
require dirname(__DIR__).'/includes/class-mad4b-scp-provider-installed-artifact.php';
function check($ok,$msg){if(!$ok){fwrite(STDERR,"FAIL $msg\n");exit(1);}}
class MockAdapter {
 private $version;
 public function __construct($version){$this->version=$version;}
 public function runtime_version(){return $this->version;}
}
$plugins=array(
 'seo-by-rank-math/rank-math.php'=>'1.0.280',
 'woocommerce/woocommerce.php'=>'9.9.9',
);
foreach($plugins as $file=>$version){
 $path=WP_PLUGIN_DIR.'/'.$file;
 if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);
 file_put_contents($path,"<?php\n/*\nPlugin Name: Test Fixture\nVersion: $version\n*/\n");
 $GLOBALS['plugins'][$file]=array('Version'=>$version);
 $GLOBALS['active'][]=$file;
}
$cls='MAD4B_SCP_Provider_Installed_Artifact';
$rank=$cls::observe('rank_math',array('artifact_plugin_files'=>array('seo-by-rank-math/rank-math.php')),new MockAdapter('1.0.280'));
check($rank['bound']===true && $rank['installed_version']==='1.0.280','exact active Rank Math file');
check($rank['main_file_sha256']===hash_file('sha256',WP_PLUGIN_DIR.'/seo-by-rank-math/rank-math.php'),'real file fingerprint');
check(!$rank['write_authorized'] && !$rank['behavioral_verified'] && !$rank['external_package_certified'],'identity does not forge permission or certification');
$woo=$cls::observe('woocommerce',array('artifact_plugin_files'=>array('woocommerce/woocommerce.php')),new MockAdapter('9.9.9'));
check($woo['bound']===true,'generic non SEO provider resolved');
$wrong=$cls::observe('rank_math',array('artifact_plugin_files'=>array('seo-by-rank-math/rank-math.php')),new MockAdapter('1.0.279'));
check($wrong['bound']===false && $wrong['state']==='adapter_version_mismatch','mismatched adapter version fails closed');
$GLOBALS['active']=array('woocommerce/woocommerce.php');
$inactive=$cls::observe('rank_math',array('artifact_plugin_files'=>array('seo-by-rank-math/rank-math.php')),new MockAdapter('1.0.280'));
check(!$inactive['bound'],'inactive provider not accepted');
$GLOBALS['active']=array_keys($plugins);
$ambiguous=$cls::observe('ambiguous',array('artifact_plugin_files'=>array_keys($plugins)),null);
check(!$ambiguous['bound'],'ambiguous plugin identity fails closed');
$unsafe=$cls::observe('unsafe',array('artifact_plugin_files'=>array('../secrets.php')),null);
check(!$unsafe['bound'],'path traversal refused');
foreach(array_keys($plugins) as $path)unlink(WP_PLUGIN_DIR.'/'.$path);
rmdir(WP_PLUGIN_DIR.'/seo-by-rank-math');rmdir(WP_PLUGIN_DIR.'/woocommerce');rmdir(WP_PLUGIN_DIR);
echo "PASS demand-driven installed provider artifact identity: 8 checks\n";
