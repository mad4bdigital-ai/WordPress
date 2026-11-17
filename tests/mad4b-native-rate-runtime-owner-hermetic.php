<?php
/** Isolated read-only native Rate presentation fallback contract. */
declare(strict_types=1);
$mode=$argv[1]??'presentation';
if(!in_array($mode,['presentation','missing_asset','symlink','commerce','already_loaded','off'],true))exit(3);
define('ABSPATH','/');
if($mode==='presentation'||$mode==='missing_asset'||$mode==='symlink'||$mode==='already_loaded')
 define('MAD4B_PRESENTATION_RUNTIME_ENABLED',true);
if($mode==='commerce')define('MAD4B_COMMERCE_RUNTIME_ENABLED',true);
$root=sys_get_temp_dir().'/mad4b-rate-present-'.getmypid();
$plugin=$root.'/mad4b-commerce';$mu=$root.'/mu-plugins';
$paths=array(
 $plugin.'/modules/presentation', $plugin.'/modules/commerce', $plugin.'/modules/compat/assets',
 $plugin.'/legacy-mu-tree/shared-product-core/adapters', $plugin.'/assets',
 $mu.'/assets');
foreach($paths as $p)if(!is_dir($p))mkdir($p,0700,true);
define('WPMU_PLUGIN_DIR',$mu);
$source=dirname(__DIR__).'/wp-content/plugins/mad4b-commerce/modules/presentation/native-rate-runtime-owner-v1.php';
$fixture=$plugin.'/modules/presentation/native-rate-runtime-owner-v1.php';copy($source,$fixture);
copy($argv[2]??dirname(__DIR__).'/wp-content/plugins/mad4b-commerce/modules/presentation/mad4b-tour-multilingual-interface-v1.php',
 $plugin.'/modules/presentation/mad4b-tour-multilingual-interface-v1.php');
file_put_contents($plugin.'/legacy-mu-tree/shared-product-core/adapters/rate-selection-actions.php',
 '<?php function shared_product_rate_selection_actions_markup() { return "<button>Select</button>"; }');
file_put_contents($plugin.'/modules/commerce/tour-rates-dynamic-function.php',
 '<?php function tk_tour_rates_dynamic_function_cb($args) { return "Rate 60820 \u{2013} Gold"; }');
file_put_contents($plugin.'/modules/compat/tour-rate-native-display-tags.php',
 '<?php function tour_rate_native_card_price_value($rate,$slot="double") { return "$2,310.00"; }');
foreach(['tour-rate-structured-tags.inc.php','tour-rate-package-disclosure.inc.php',
 'tour-rate-original-price-svg.inc.php','tour-rate-occupancy-label.inc.php'] as $file)
 file_put_contents($plugin.'/modules/compat/assets/'.$file,'<?php');
file_put_contents($mu.'/assets/tour-rate-native-display.css','/* original rate price, read-only */');
if($mode!=='missing_asset')file_put_contents($plugin.'/assets/tour-rate-centered-popover.js','/* no network */');
if($mode==='symlink'){
 $p=$plugin.'/modules/compat/assets/tour-rate-occupancy-label.inc.php';
 unlink($p);symlink($plugin.'/modules/compat/assets/tour-rate-original-price-svg.inc.php',$p);
}
$GLOBALS['hooks']=[];$GLOBALS['holds']=[];
function current_user_can($cap):bool{return $cap==='edit_posts';}
function get_current_screen():object{return (object)['post_type'=>'tour-rates'];}
function sanitize_text_field($s):string{return trim(strip_tags((string)$s));}
function esc_html($s):string{return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function esc_html__($s,$domain):string{return esc_html($s);}
function add_action($hook,$callback,$priority=10){$GLOBALS['hooks'][]=[$hook,$callback,$priority];}
function add_filter($hook,$callback,$priority=10,$accepted=1){$GLOBALS['hooks'][]=[$hook,$callback,$priority];}
function do_action($hook,$value=[]){$GLOBALS['holds'][]=[$hook,$value];}
if($mode==='already_loaded'){
 function shared_product_rate_selection_actions_markup(){return '<button>Select</button>';}
 function tour_rate_native_card_price_value($rate){return '$2,310.00';}
 function tk_tour_rates_dynamic_function_cb($args){return 'Rate 60820 Gold';}
}
require $fixture;
$registered=count(array_filter($GLOBALS['hooks'],fn($h)=>$h[0]==='plugins_loaded'&&$h[1]==='mad4b_commerce_native_rate_runtime_ensure_v1'&&$h[2]===16))===1;
mad4b_commerce_native_rate_runtime_ensure_v1();
mad4b_commerce_native_tour_locale_ensure_v1();
$localeOk=class_exists('MAD4B_Tour_Multilingual_Interface_V1',false);
$localeExpected=in_array($mode,['presentation','missing_asset','symlink','already_loaded'],true);
$status=mad4b_commerce_native_rate_runtime_status_v1();
$ready=$status['functional_readiness']==='ready';
$expectReady=in_array($mode,['presentation','already_loaded'],true);
$expectedHold=in_array($mode,['missing_asset','symlink'],true);
$held=$GLOBALS['holds'];
ob_start();mad4b_commerce_native_rate_admin_warning_v1();$adminNotice=(string)ob_get_clean();
$adminCorrect=($status['functional_readiness']==='hold')
 ?(strpos($adminNotice,'MAD4B Rate presentation is incomplete')!==false
    && strpos($adminNotice,'release acceptance must remain on HOLD')!==false
    && strpos($adminNotice,'<form')===false)
 :$adminNotice==='';
$passed=($localeOk===$localeExpected) && $adminCorrect && $registered && (($status['functions']['rate_heading_provider']===true)===$expectReady) && ($ready===$expectReady) && ($status['new_price_written']===false)
 &&($status['checkout_ready_granted']===false)
 &&(($mode==='off')===($status['functional_readiness']==='not_applicable'))
 && ($expectedHold===(!empty($held)))
 && ($mode!=='missing_asset'||in_array('popover_js',$held[0][1]['missing_dependencies']??[],true))
 && ($mode!=='symlink'||in_array('occupancy_label',$held[0][1]['missing_dependencies']??[],true));
// Never leave temporary runtime test files on disk.
$remove=function($path)use(&$remove){if(is_dir($path)&&!is_link($path)){foreach(scandir($path) as $f)if($f!=='.'&&$f!=='..')$remove($path.'/'.$f);rmdir($path);}else unlink($path);};
$remove($root);
echo json_encode(['contract'=>'mad4b.rate-ui.modular-native-fallback.test.v1','mode'=>$mode,
 'passed'=>$passed,'functional_readiness'=>$status['functional_readiness'],
 'hold_count'=>count($held),'registered_hook'=>$registered,'production_writes'=>0],JSON_UNESCAPED_SLASHES),"\n";
exit($passed?0:1);
