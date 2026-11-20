<?php
/** Read-only original structured-price tag admission, no WordPress or DB. */
declare(strict_types=1);
$mode=$argv[1]??'presentation';
if(!in_array($mode,['presentation','commerce','off','missing','symlink','no_evidence','already_loaded'],true))exit(4);
define('ABSPATH','/');
if(in_array($mode,['presentation','missing','symlink','no_evidence','already_loaded'],true))
 define('MAD4B_PRESENTATION_RUNTIME_ENABLED',true);
if($mode==='commerce')define('MAD4B_COMMERCE_RUNTIME_ENABLED',true);
if($mode!=='no_evidence'){
 function tour_component_price_evidence($id=0,$audience='auto',$date_mode='inherited'){
  return ['amount'=>70,'currency'=>'USD','unit'=>'per_person','upcoming'=>false];
 }
 function tour_component_price_current_id(){return 60465;}
}
if($mode==='already_loaded'){function tour_component_structured_price_value(){return 'From USD 70';}}
$root=sys_get_temp_dir().'/mad4b-card-price-test-'.getmypid();
$plugin=$root.'/mad4b-commerce';$dir=$plugin.'/modules';
@mkdir($dir.'/presentation',0700,true);@mkdir($dir.'/commerce',0700,true);
$source=dirname(__DIR__).'/wp-content/plugins/mad4b-commerce/modules/presentation/native-rate-runtime-owner-v1.php';
$fixture=$dir.'/presentation/native-rate-runtime-owner-v1.php';
copy($source,$fixture);
$structured=$dir.'/commerce/tour-component-structured-price-tags.php';
if($mode!=='missing')copy(dirname(__DIR__).'/wp-content/plugins/mad4b-commerce/modules/commerce/tour-component-structured-price-tags.php',$structured);
if($mode==='symlink'){
 unlink($structured);symlink($fixture,$structured);
}
$GLOBALS['hooks']=[];$GLOBALS['holds']=[];
function add_action($key,$cb,$priority=10,$n=1){$GLOBALS['hooks'][]=[$key,$cb,$priority];}
function do_action($key,$value=[]){$GLOBALS['holds'][]=[$key,$value];}
require $fixture;
$hooks=$GLOBALS['hooks'];
$setup=array_values(array_filter($hooks,static fn($h)=>$h[0]==='plugins_loaded' && $h[1]==='mad4b_commerce_native_tour_card_price_tags_ensure_v1' && $h[2]===17));
mad4b_commerce_native_tour_card_price_tags_ensure_v1();
$registered=count(array_filter($GLOBALS['hooks'],static fn($h)=>$h[0]==='elementor/dynamic_tags/register'&&$h[2]===21))===1;
$loaded=function_exists('tour_component_structured_price_value');
$ready=in_array($mode,['presentation','already_loaded'],true);
$expectHold=in_array($mode,['missing','symlink','no_evidence'],true);
$held=$GLOBALS['holds'];$safe=!(bool)preg_match('/\b(update_post_meta|wp_insert_post|wc_create_order|wp_remote_post)\s*\(/',file_get_contents($source));
$passed=count($setup)===1 && $loaded===$ready &&
 ($registered===($mode==='presentation')) &&
 ($expectHold===(count($held)>0)) && $safe;
$remove=function($p)use(&$remove){if(is_dir($p)&&!is_link($p)){foreach(scandir($p) as $f){if($f!=='.'&&$f!=='..')$remove($p.'/'.$f);}rmdir($p);}else unlink($p);};
$remove($root);
echo json_encode(['mode'=>$mode,'passed'=>$passed,'loaded'=>$loaded,'registered_tag_hook'=>$registered,'holds'=>count($held),'site_writes'=>0]),"\n";
exit($passed?0:1);
