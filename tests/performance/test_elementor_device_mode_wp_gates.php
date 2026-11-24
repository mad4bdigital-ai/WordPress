<?php
/** WordPress-free public/admin/checkout activation fixture. */
$GLOBALS['callbacks']=[];
$GLOBALS['scripts']=[];
$GLOBALS['admin']=false;
$GLOBALS['logged']=false;
$GLOBALS['checkout']=false;
$GLOBALS['option']='1';
function add_action($hook,$cb,$p=10){$GLOBALS['callbacks'][$hook][]=$cb;}
function wp_add_inline_script($handle,$script,$position){$GLOBALS['scripts'][]=[$handle,$script,$position];}
function is_admin(){return $GLOBALS['admin'];}
function wp_doing_ajax(){return false;}
function is_preview(){return false;}
function is_customize_preview(){return false;}
function is_user_logged_in(){return $GLOBALS['logged'];}
function is_feed(){return false;}
function is_404(){return false;}
function is_search(){return false;}
function is_embed(){return false;}
function is_trackback(){return false;}
function is_cart(){return false;}
function is_checkout(){return $GLOBALS['checkout'];}
function is_account_page(){return false;}
function is_wc_endpoint_url(){return false;}
function is_singular($type=null){return !$type;}
function is_post_type_archive(){return false;}
function is_front_page(){return false;}
function is_home(){return false;}
function is_archive(){return false;}
function get_option($k,$d=null){return $GLOBALS['option'];}
define('ABSPATH','/');
$_SERVER['REQUEST_METHOD']='GET';
require $argv[1];
function runCase($name,$admin,$logged,$checkout,$option,$expect) {
 $GLOBALS['scripts']=[];
 $GLOBALS['admin']=$admin;$GLOBALS['logged']=$logged;
 $GLOBALS['checkout']=$checkout;$GLOBALS['option']=$option;
 foreach($GLOBALS['callbacks']['wp_enqueue_scripts']??[] as $fn)$fn();
 $count=count($GLOBALS['scripts']);
 if($count!==$expect)throw new RuntimeException("$name expected $expect got $count");
 if($expect){
  [$handle,$js,$placement]=$GLOBALS['scripts'][0];
  if($handle!=='elementor-frontend'||$placement!=='after'||strpos($js,'function boundedRetry()')===false)
   throw new RuntimeException("$name JS contract");
 }
 echo "PASS $name
";
}
runCase('public-tour',false,false,false,'1',1);
runCase('admin-disabled',true,false,false,'1',0);
runCase('user-disabled',false,true,false,'1',0);
runCase('checkout-disabled',false,false,true,'1',0);
runCase('company-optout',false,false,false,'0',0);
echo "ALL_ELEMENTOR_DEVICE_MODE_PHP_GATES_PASS
";
