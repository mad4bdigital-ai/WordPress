<?php
/** Readback of missing values must not synthesize an enabled setting. */
define('ABSPATH','/');
$GLOBALS['options']=[];
$GLOBALS['hooks']=[];
function add_action($name,$callback,$priority=10){$GLOBALS['hooks'][$name]=[$callback,$priority];}
function get_option($key,$default=null){return $GLOBALS['options'][$key]??$default;}
class LocalPage {
 public string $storage_type='separate';
 public string $slug='etg-dynamic-pricing-controls';
 public array $page=['fields'=>[
  'booking_enabled'=>['name'=>'booking_enabled'],
  'booking_fulfillment_mode'=>['name'=>'booking_fulfillment_mode']
 ]];
 public function get_separate_option_name($key){return $this->slug.'_'.$key;}
}
function check_it($ok,$name){if(!$ok)throw new RuntimeException($name);}
require dirname(__DIR__).'/wp-content/mu-plugins/mad4b-options-live-readback-v1.php';
$c=MAD4B_Options_Live_Readback_V1::class;
$p=new LocalPage();
$a=$c::option_state($p,'booking_enabled');
check_it($a['status']==='not_configured'&&$a['value']===null,'missing not inferred');
$GLOBALS['options']['etg-dynamic-pricing-controls_booking_enabled']='false';
$b=$c::option_state($p,'booking_enabled');
check_it($b['status']==='explicit'&&$b['value']==='false','saved false respected');
check_it($c::option_state($p,'unlisted')['status']==='definition_missing','undefined field flagged');
check_it($c::option_state(null,'booking_enabled')['status']==='page_unavailable','missing page flagged');
$p->storage_type='default';
$GLOBALS['options']['etg-dynamic-pricing-controls']=['booking_enabled'=>'false'];
$d=$c::option_state($p,'booking_enabled');
check_it($d['status']==='explicit'&&$d['value']==='false','default storage reading correct');
echo json_encode(['status'=>'PASS','assertions'=>5,'external_writes'=>false]).PHP_EOL;
