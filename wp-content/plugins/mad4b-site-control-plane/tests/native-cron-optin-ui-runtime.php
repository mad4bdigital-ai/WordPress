<?php
/** Real renderer and enqueue guard; no network or scheduling. */
define('ABSPATH',__DIR__);
$GLOBALS['admin']=true;$GLOBALS['env']='staging';$GLOBALS['executor']='native_hybrid';
function add_action(...$args){}
function current_user_can($c){return $GLOBALS['admin'];}
function get_option($k,$d=false){return $d;}
function wp_schedule_single_event(...$args){throw new RuntimeException('Read scheduled a job');}
function wp_next_scheduled(...$args){return false;}
function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES);}
function esc_html__($s,$d){return esc_html($s);}
function esc_url($s){return $s;}
function admin_url($s){return 'https://staging.test/wp-admin/'.$s;}
function wp_nonce_field(...$args){echo '<input name="nonce">';}
function submit_button($label){echo '<button>'.esc_html($label).'</button>';}
class WP_Error{private $code;function __construct($c,$m=''){$this->code=$c;}function get_error_code(){return $this->code;}}
class MAD4B_SCP_Environment{static function effective(){return $GLOBALS['env'];}}
class MAD4B_SCP_Browser_Acceptance_Admin_UI{
 static function selection(){return array('executor'=>$GLOBALS['executor']);}
 static function public_selection(){return $GLOBALS['preference'];}
}
require __DIR__.'/../includes/class-mad4b-scp-native-cron-acceptance.php';
function check_cron($ok,$why){if(!$ok)throw new RuntimeException($why);}
function cron_html(){ob_start();MAD4B_SCP_Native_Cron_Acceptance::render();return ob_get_clean();}
$c='MAD4B_SCP_Native_Cron_Acceptance';
foreach(array(array(true,'default_observed',str_repeat('a',32)),array(false,'operator_saved_or_legacy',str_repeat('a',32)),array(true,'operator_saved_or_legacy',''),array(true,'operator_saved_or_legacy','<script>PRIVATE</script>')) as $row){
 $GLOBALS['preference']=array_combine(array('preference_valid','preference_source','configuration_revision'),$row);
 $html=cron_html();
 check_cron(strpos($html,'mad4b_native_cron_smoke_queue')===false,'Invalid consent exposed queue');
 check_cron(strpos($html,'Save operator preference')!==false&&strpos($html,'PRIVATE')===false,'Safe guidance missing');
 check_cron($c::enqueue()->get_error_code()==='mad4b_native_cron_saved_optin_required','Direct request bypassed consent');
}
$GLOBALS['preference']=array('preference_valid'=>true,'preference_source'=>'operator_saved_or_legacy','configuration_revision'=>str_repeat('b',32));
foreach(array('wp_cron','native_hybrid') as $mode){
 $GLOBALS['executor']=$mode;
 check_cron(strpos(cron_html(),'mad4b_native_cron_smoke_queue')!==false,'Saved native consent hid queue');
 check_cron($c::enqueue()->get_error_code()==='mad4b_native_cron_job_unavailable','Valid consent failed guard');
}
$GLOBALS['executor']='auto';check_cron(strpos(cron_html(),'mad4b_native_cron_smoke_queue')===false,'External mode exposed queue');
$GLOBALS['executor']='native_hybrid';$GLOBALS['env']='production';check_cron(strpos(cron_html(),'mad4b_native_cron_smoke_queue')===false,'Production exposed queue');
$GLOBALS['admin']=false;check_cron(cron_html()==='','Non-admin saw controls');
echo "NATIVE_CRON_OPTIN_UI_PASS\n";
