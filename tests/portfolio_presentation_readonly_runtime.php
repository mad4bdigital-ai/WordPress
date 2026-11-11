<?php
/** Exact offline source-owned field-readback registry, no WordPress runtime. */
error_reporting(E_ALL);
define('ABSPATH','/offline-test/');
$GLOBALS['boot_actions']=[];
$GLOBALS['write_attempted']=false;
function add_action($hook,$cb,$prio=10,$argc=1) { $GLOBALS['boot_actions'][]=$hook;return true; }
function add_filter($hook,$cb,$prio=10,$argc=1) { $GLOBALS['boot_actions'][]=$hook;return true; }
function update_option() { $GLOBALS['write_attempted']=true;throw new RuntimeException('unexpected write'); }
function must($ok,$why) {if(!$ok){fwrite(STDERR,$why."\n");exit(2);}}
$base=dirname(__DIR__).'/wp-content/plugins/mad4b-company-portfolio-manager/includes';
require $base.'/class-mad4b-company-presentation-option-ownership.php';
require $base.'/class-mad4b-company-start-reconcile-completeness.php';
$r=MAD4B_Company_Presentation_Option_Ownership_V1::registry();
$names=['brand.hero_headline','brand.hero_subtitle','brand.hero_cta','brand.archive_cta','brand.tour_inquiry_cta'];
foreach($names as $key){
    must(isset($r[$key]),'Missing semantic field '.$key);
    must(($r[$key]['scope']??null)==='target_owned','Field must preserve company values');
    must(($r[$key]['language']??null)==='wpml_localized','Field must respect language ownership');
    must(($r[$key]['binding']??null)==='mad4b-brand-copy-runtime-proof-required','Field must not claim verified runtime');
}
must(!$GLOBALS['write_attempted'],'Write attempt');
must(in_array('rest_request_after_callbacks',$GLOBALS['boot_actions'],true),'Read-only projection hook missing');
echo json_encode(['contract'=>'mad4b.portfolio-presentation-isolated.v1','field_count'=>count($names),
 'target_owned'=>true,'wpml_ownership'=>true,'writes_performed'=>false,'pass'=>true]).PHP_EOL;
