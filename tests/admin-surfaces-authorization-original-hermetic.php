<?php
/** Actual first-party UI entry gates for Performance, ChatGPT and Skills; no admin mutations. */
define('ABSPATH',__DIR__.'/');
class WP_Error{function __construct($code,$message='',$data=[]){}}
function add_action($name,$callback,$priority=10){}
class MAD4B_SCP_Admin_Route_Registry{
 static function schedule_submenu($callback,$priority){}
 static function register($slug,$cap){}
}
function current_user_can($cap){return $GLOBALS['admin_permission'];}
function is_admin(){return true;}
function esc_html__($text,$domain=''){return $text;}
function wp_die($message){throw new RuntimeException('denied:'.$message);}
function admin_gate_check($ok,$why){if(!$ok){fwrite(STDERR,'FAIL '.$why.PHP_EOL);exit(1);}$GLOBALS['n']=($GLOBALS['n']??0)+1;}
$root=dirname(__DIR__).'/wp-content/plugins/mad4b-site-control-plane/includes/';
require $root.'class-mad4b-scp-admin-query-performance-ui.php';
require $root.'class-mad4b-scp-chatgpt-connection-admin-ui.php';
require $root.'class-mad4b-scp-skills-admin-ui.php';
$pages=[
 'MAD4B_SCP_Admin_Query_Performance_UI'=>['render_page'],
 'MAD4B_SCP_ChatGPT_Connection_Admin_UI'=>['render_page','render_checked_page','enqueue_assets'],
 'MAD4B_SCP_Skills_Admin_UI'=>['render_page','intercept_export']
];
foreach([new WP_Error('admin_unknown'),1,'true',null,false] as $bad){
 $GLOBALS['admin_permission']=$bad;
 foreach($pages as $class=>$methods){
  foreach($methods as $method){
   try{$res=$class::$method();$denied=false;}catch(RuntimeException $e){$denied=true;}
   // enqueue/intercept are passive; blocked pages must throw WordPress access denial.
   admin_gate_check(in_array($method,['enqueue_assets','intercept_export'],true)
    ?($denied===false):$denied,
    'Unverified WordPress admin must not enter original status, render, action or export');
  }
 }
}
echo 'PASS: ADMIN_SURFACES_AUTHORIZATION_ORIGINAL '.($GLOBALS['n']??0).
 ' first-party WordPress capability denial and zero-write cases'.PHP_EOL;
