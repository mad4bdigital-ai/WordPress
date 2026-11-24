<?php
$GLOBALS['hooks'] = [];
$GLOBALS['scripts'] = [];
$GLOBALS['admin'] = false;
$GLOBALS['logged'] = false;
$GLOBALS['checkout'] = false;
$GLOBALS['mode'] = 'on';
$GLOBALS['isTour'] = true;
function add_action($hook, $fn, $priority=10) {$GLOBALS['hooks'][$hook][]=$fn;}
function wp_add_inline_script($handle, $script, $position='after') {
    $GLOBALS['scripts'][]=[$handle,$script,$position];
}
function get_option($name,$default=null) {return $GLOBALS['mode'];}
function is_admin(){return $GLOBALS['admin'];}
function wp_doing_ajax(){return false;}
function is_user_logged_in(){return $GLOBALS['logged'];}
function is_preview(){return false;}
function is_customize_preview(){return false;}
function is_singular($type){return $GLOBALS['isTour'] && $type==='tours-and-activities';}
function get_queried_object_id(){return 60623;}
function is_checkout(){return $GLOBALS['checkout'];}
function is_cart(){return false;}
function is_account_page(){return false;}
define('ABSPATH','/');
$_SERVER['REQUEST_METHOD']='GET';
require $argv[1];
function proof($case,$admin,$logged,$checkout,$mode,$get,$expected){
    $GLOBALS['scripts']=[];
    $GLOBALS['admin']=$admin;$GLOBALS['logged']=$logged;
    $GLOBALS['checkout']=$checkout;$GLOBALS['mode']=$mode;
    $_GET=$get;
    foreach($GLOBALS['hooks']['wp_enqueue_scripts']??[] as $fn)$fn();
    $count=count($GLOBALS['scripts']);
    if($count!==$expected)throw new RuntimeException("$case: $count != $expected");
    if($expected){
        [$handle,$js,$placement]=$GLOBALS['scripts'][0];
        if($handle!=='elementor-frontend'||$placement!=='before'
            ||!str_contains($js,'MAD4BUaelOffscreen')
            ||!str_contains($js,'uael-image-gallery.default'))
            throw new RuntimeException("$case inline contract failed");
    }
    echo "PASS $case
";
}
proof('public-tour',false,false,false,'on',[],1);
proof('admin-isolation',true,false,false,'on',[],0);
proof('logged-in-isolation',false,true,false,'on',[],0);
proof('checkout-isolation',false,false,true,'on',[],0);
proof('company-opt-out',false,false,false,'off',[],0);
proof('query-params-fail-closed',false,false,false,'on',['x'=>'1'],0);
echo "ALL_UAEL_OFFSCREEN_PHP_GATES_PASS
";
