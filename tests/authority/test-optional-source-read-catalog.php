<?php
/** Ensure plugin-only CI does not register optional absent MU Abilities. */
define('ABSPATH',__DIR__);
class MAD4B_SCP_Admin_Remote_Parity { const ABILITY='mad4b/admin-remote-parity-status'; }
class MAD4B_Test_MU_Admission { const ABILITY='mad4b/release-source-preflight-status'; }
class WP_Error {}
function is_wp_error($value){return $value instanceof WP_Error;}
class MAD4B_SCP_Capability_Descriptor_Registry {
    public static $ready=false;
    public static function binding($ability,$scope){
        return self::$ready && $scope==='servers' && ($ability==='mad4b/release-source-preflight-status' || in_array($ability, $GLOBALS['optional_host_register'] ?? [], true))
            ? ['contract'=>'ci.exact-descriptor.v1'] : new WP_Error();
    }
}
function wp_has_ability($name){return (!empty($GLOBALS['source_ability_registered']) && $name==='mad4b/release-source-preflight-status') || in_array($name, $GLOBALS['optional_host_register'] ?? [], true);}
require __DIR__.'/../../wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-server-core-catalog.php';
class MAD4B_Optional_Source_Catalog_Test {
 use MAD4B_SCP_Server_Core_Catalog;
 private static function write_tools(){return array();}
 public static function names($server){return self::declared_core_tools($server);}
}
$name='mad4b/release-source-preflight-status';
$without=MAD4B_Optional_Source_Catalog_Test::names('mad4b-read');
if(in_array($name,$without,true)) {fwrite(STDERR,"FAIL: absent MU declared as registered\n");exit(1);}
class_alias('MAD4B_Test_MU_Admission', 'MAD4B_Release_Source_Admission');
$not_registered=MAD4B_Optional_Source_Catalog_Test::names('mad4b-read');
if(in_array($name,$not_registered,true)){fwrite(STDERR,"FAIL: MU class without Ability must not be published\n");exit(1);}
$GLOBALS['source_ability_registered']=true;
$unbound=MAD4B_Optional_Source_Catalog_Test::names('mad4b-read');
if(in_array($name,$unbound,true)){fwrite(STDERR,"FAIL: registered but unbound optional descriptor advertised\n");exit(1);}
MAD4B_SCP_Capability_Descriptor_Registry::$ready=true;
$with=MAD4B_Optional_Source_Catalog_Test::names('mad4b-read');
if(count(array_keys($with,$name,true))!==1){fwrite(STDERR,"FAIL: installed MU not mounted once\n");exit(1);}
$chat=MAD4B_Optional_Source_Catalog_Test::names('mad4b-chatgpt');
if(in_array($name,$chat,true)){fwrite(STDERR,"FAIL: source proof mounted on wrong surface\n");exit(1);}
// Test exact optional abilities across clean plugin-only installation and
// fully enrolled Host MU installations; never promote them into chatgpt by accident.
$host=array('mad4b/control-plane-test-route','mad4b/execution-unified-plan','mad4b/scoped-build-verification');
foreach(array('mad4b-read','mad4b-chatgpt') as $surface){
 $initial=MAD4B_Optional_Source_Catalog_Test::names($surface);
 foreach($host as $n) if(in_array($n,$initial,true)){fwrite(STDERR,"FAIL: clean plugin falsely advertises $n on $surface\n");exit(1);}
}
$GLOBALS['optional_host_register']=$host;
MAD4B_SCP_Capability_Descriptor_Registry::$ready=false;
foreach(array('mad4b-read','mad4b-chatgpt') as $surface){
 $unbound=MAD4B_Optional_Source_Catalog_Test::names($surface);
 foreach($host as $n) if(in_array($n,$unbound,true)){fwrite(STDERR,"FAIL: unbound descriptor advertised $n on $surface\n");exit(1);}
}
MAD4B_SCP_Capability_Descriptor_Registry::$ready=true;
$withHost=MAD4B_Optional_Source_Catalog_Test::names('mad4b-read');
foreach($host as $n) if(count(array_keys($withHost,$n,true))!==1){fwrite(STDERR,"FAIL: enrolled Host ability missing or duplicated $n\n");exit(1);}
$withChat=MAD4B_Optional_Source_Catalog_Test::names('mad4b-chatgpt');
if(count(array_keys($withChat,'mad4b/control-plane-test-route',true))!==1){fwrite(STDERR,"FAIL: existing direct route not preserved\n");exit(1);}
foreach(array('mad4b/execution-unified-plan','mad4b/scoped-build-verification') as $n){
 if(in_array($n,$withChat,true)){fwrite(STDERR,"FAIL: read-only Host ability leaked onto ChatGPT direct surface\n");exit(1);}
}
foreach(array('mad4b-read','mad4b-chatgpt') as $surface){
 if(!in_array('mad4b/site-info',MAD4B_Optional_Source_Catalog_Test::names($surface),true)){fwrite(STDERR,"FAIL: required site info absent\n");exit(1);}
}
echo "PASS: signed source and optional Host read abilities mount only when registered and descriptor-bound; exact surfaces retained\n";
