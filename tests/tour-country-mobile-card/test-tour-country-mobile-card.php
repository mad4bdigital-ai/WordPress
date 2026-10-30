<?php
/** No WordPress DB required: scoped mobile CSS regression tests. */
error_reporting(E_ALL);
define('ABSPATH', __DIR__);
$GLOBALS['hooks']=[];
$GLOBALS['post_type']='tours-and-activities';
$GLOBALS['registered']=[];
$GLOBALS['enqueued']=[];
$GLOBALS['inline']=[];
function add_action($hook,$callable,$priority=10){$GLOBALS['hooks'][$hook][]=$callable;}
function is_singular($type){return $GLOBALS['post_type']===$type;}
function wp_register_style($handle,$url,$deps,$version){$GLOBALS['registered'][]=$handle;}
function wp_enqueue_style($handle){$GLOBALS['enqueued'][]=$handle;}
function wp_add_inline_style($handle,$style){$GLOBALS['inline'][$handle]=$style;}
function chk($result,$name){if(!$result){fwrite(STDERR,"FAIL: ".$name.PHP_EOL);exit(1);}}
require dirname(__DIR__,2).'/wp-content/mu-plugins/mad4b-tour-country-mobile-card.php';
foreach($GLOBALS['hooks']['wp_enqueue_scripts'] as $callback){$callback();}
chk(count($GLOBALS['enqueued'])===1,'Single tour loads CSS');
$css=$GLOBALS['inline']['mad4b-tour-country-mobile']??'';
chk(strpos($css,'@media screen and (max-width: 767px)')!==false,'Only phone breakpoint');
chk(strpos($css,'body.single-tours-and-activities #single_tour_countries')!==false,'Only single tour and country section');
chk(strpos($css,'max-width: none !important')!==false,'Remove max-width restriction on phones');
chk(strpos($css,'--width: 100% !important')!==false,'Override Elementor 140px mobile width');
chk(strpos($css,'grid-template-columns: minmax(0, 1fr)')!==false,'Single column');
chk(strpos($css,'#single_tour_countries .elementor-element-72ce2a0')!==false,'Target the exact country card');
$GLOBALS['post_type']='page';
$GLOBALS['registered']=[];
$GLOBALS['enqueued']=[];
$GLOBALS['inline']=[];
foreach($GLOBALS['hooks']['wp_enqueue_scripts'] as $callback){$callback();}
chk($GLOBALS['inline']===[]&&$GLOBALS['enqueued']===[],'All non-tour pages unaffected');
$GLOBALS['post_type']='tours-and-activities';
echo "PASS: single-tour phone country card sizing scope, width, CSS breakpoint and non-tour exclusion\n";
