<?php
/** Ensure plugin-only CI does not register optional absent MU Abilities. */
define('ABSPATH',__DIR__);
class MAD4B_SCP_Admin_Remote_Parity { const ABILITY='mad4b/admin-remote-parity-status'; }
class MAD4B_Test_MU_Admission { const ABILITY='mad4b/release-source-preflight-status'; }
function wp_has_ability($name){return !empty($GLOBALS['source_ability_registered']) && $name==='mad4b/release-source-preflight-status';}
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
$with=MAD4B_Optional_Source_Catalog_Test::names('mad4b-read');
if(count(array_keys($with,$name,true))!==1){fwrite(STDERR,"FAIL: installed MU not mounted once\n");exit(1);}
$chat=MAD4B_Optional_Source_Catalog_Test::names('mad4b-chatgpt');
if(in_array($name,$chat,true)){fwrite(STDERR,"FAIL: source proof mounted on wrong surface\n");exit(1);}
echo "PASS: optional source-preflight Ability absent without MU, present once on read when MU is installed\n";
