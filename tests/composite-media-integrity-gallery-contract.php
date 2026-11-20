<?php
/** Regression fixtures for MAD4B JetEngine gallery reference classification. */
define('ABSPATH',__DIR__.'/');
class WP_Error { public function __construct(public string $code='error'){} }
function add_filter(...$args){}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function wp_parse_url($url,$part=-1){return parse_url($url,$part);}
function home_url($path='/'){return 'https://wayfatours.com'.$path;}
function maybe_unserialize($raw){$v=@unserialize($raw,['allowed_classes'=>false]);return $v===false?$raw:$v;}
function jet_engine(){return (object)['taxonomies'=>new class {function get_meta_fields_for_object($x){return [['name'=>'image_gellery','type'=>'gallery']];}},'meta_boxes'=>new class {function get_meta_fields_for_object($x){return [['name'=>'unit_gellery','type'=>'gallery']];}}];}
$GLOBALS['testdir']=sys_get_temp_dir().'/mad4b-gallery-contract-'.getmypid();
mkdir($GLOBALS['testdir'].'/2025/12',0770,true);
file_put_contents($GLOBALS['testdir'].'/2025/12/exists.jpg','test-fixture');
function wp_get_upload_dir(){return ['basedir'=>$GLOBALS['testdir'],'baseurl'=>'https://wayfatours.com/wp-content/uploads'];}
function get_post_type($id){return in_array($id,[101,102],true)?'attachment':'units';}
function get_post_mime_type($id){return 'image/jpeg';}
function get_attached_file($id){return $id===101?$GLOBALS['testdir'].'/2025/12/exists.jpg':($id===102?$GLOBALS['testdir'].'/2025/12/exists.jpg':false);}
function get_term_meta($id,$key,$single=true){return $GLOBALS['test_value'];}
function get_post_meta($id,$key,$single=true){return $GLOBALS['test_value'];}
$GLOBALS['wpdb']=new class {
 public string $termmeta='wp_termmeta';public string $postmeta='wp_postmeta';
 function prepare($sql,...$args){return $sql;}
 function get_var($query){return $GLOBALS['test_raw'];}
};
require __DIR__.'/../wp-content/mu-plugins/mad4b-media-composite-integrity.php';
$tests=0;
function fixture($input,$want,$kind='post',$raw=null){
 global $tests;
 $GLOBALS['test_value']=$input;$GLOBALS['test_raw']=$raw??$input;
 $r=MAD4B_Composite_Media_Integrity_V1::inspect($kind,$kind==='term'?'destinations':'units',3,$kind==='term'?'image_gellery':'unit_gellery');
 foreach($want as $k=>$expected){
  $actual=$r[$k]??null;
  if($actual!==$expected)throw new RuntimeException("Fixture failed: ".json_encode(['key'=>$k,'expected'=>$expected,'actual'=>$actual]));
 }
 $tests++;
}
$local='https://wayfatours.com/wp-content/uploads/2025/12/exists.jpg';
$missing='https://wayfatours.com/wp-content/uploads/2025/12/missing.jpg';
$remote='https://staging.egypttourgates.com/wp-content/uploads/2025/12/remote.jpg';
$foreignLocal='https://staging.egypttourgates.com/wp-content/uploads/2025/12/exists.jpg';
fixture('101,102',['local_missing_count'=>0,'external_unresolved_count'=>0,'issues'=>[]]);
fixture('101,9999',['local_missing_count'=>1,'external_unresolved_count'=>0,'issues'=>['gallery_local_file_missing']]);
fixture($local,['local_missing_count'=>0,'issues'=>[]]);
fixture($missing,['local_missing_count'=>1,'issues'=>['gallery_local_file_missing']]);
fixture($remote,['local_missing_count'=>0,'external_unresolved_count'=>1,'raw_foreign_count'=>1,'issues'=>['gallery_stored_foreign_host','gallery_external_source_unresolved','gallery_foreign_host_media']]);
fixture($foreignLocal,['local_missing_count'=>0,'external_unresolved_count'=>0,'raw_foreign_count'=>1,'issues'=>['gallery_stored_foreign_host','gallery_foreign_host_media']]);
fixture('101,'.$local.','.$remote,['local_missing_count'=>0,'external_unresolved_count'=>1,'issues'=>['gallery_stored_foreign_host','gallery_external_source_unresolved','gallery_foreign_host_media']]);
fixture('https://wayfatours.com/wp-content/uploads/../private.txt',['local_missing_count'=>0,'issues'=>['gallery_invalid_url']]);
fixture($local,['local_missing_count'=>0,'raw_foreign_count'=>1,'issues'=>['gallery_stored_foreign_host']], 'term',$foreignLocal);
file_put_contents('php://stdout',json_encode(['contract'=>'mad4b.composite-media-integrity-gallery-regression.v1','pass'=>$tests])."\n");
unlink($GLOBALS['testdir'].'/2025/12/exists.jpg');rmdir($GLOBALS['testdir'].'/2025/12');rmdir($GLOBALS['testdir'].'/2025');rmdir($GLOBALS['testdir']);
