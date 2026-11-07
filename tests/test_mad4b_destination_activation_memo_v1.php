<?php
define('ABSPATH','/');
class WP_Term { public function __construct(public int $parent){} public string $taxonomy='destinations';}
$GLOBALS['terms']=[1=>new WP_Term(0),2=>new WP_Term(1),3=>new WP_Term(2),
 4=>new WP_Term(0),5=>new WP_Term(4),6=>new WP_Term(7),7=>new WP_Term(6)];
$GLOBALS['blocked']=[4=>true];$GLOBALS['term_calls']=0;$GLOBALS['meta_calls']=0;$GLOBALS['admin']=false;
$_SERVER['REQUEST_METHOD']='GET';
function absint($v){return abs((int)$v);}
function is_admin(){return $GLOBALS['admin'];}
function wp_doing_ajax(){return false;}
function is_user_logged_in(){return false;}
function get_current_blog_id(){return 1;}
function get_term($id,$tax){$GLOBALS['term_calls']++;return $GLOBALS['terms'][$id]??false;}
function get_term_meta($id,$key,$single){$GLOBALS['meta_calls']++;return !empty($GLOBALS['blocked'][$id])?'0':'1';}
function add_filter($hook,$cb,$p=10,$args=1){}
function add_action($hook,$cb,$p=10,$args=1){}
function ok($msg,$pass){if(!$pass)throw new RuntimeException('FAIL '.$msg);echo 'PASS '.$msg."\n";}
require getenv('MAD4B_DEST_MEMO_SOURCE');
ok('published descendant of published parents is allowed',destinations_activation_allowed(3));
ok('one traversal visits exactly 3 terms',$GLOBALS['term_calls']===3);
ok('repeated child uses request memo',destinations_activation_allowed(3) && $GLOBALS['term_calls']===3);
ok('cached parent avoids redundant lookups',destinations_activation_allowed(2) && $GLOBALS['term_calls']===3);
ok('blocked root disables child',!destinations_activation_allowed(5));
$before=$GLOBALS['term_calls'];
ok('blocked descendant also memoized',!destinations_activation_allowed(5) && $GLOBALS['term_calls']===$before);
ok('cyclic taxonomy safely rejected',!destinations_activation_allowed(6));
$GLOBALS['admin']=true;
$GLOBALS['terms'][3]->parent=4;
$prev=$GLOBALS['term_calls'];
ok('admin bypasses memoized public result',!destinations_activation_allowed(3) && $GLOBALS['term_calls']>$prev);
$GLOBALS['admin']=false;
$_SERVER['REQUEST_METHOD']='POST';
$GLOBALS['terms'][3]->parent=1;
$prev=$GLOBALS['term_calls'];
ok('write request never uses frontend memo',destinations_activation_allowed(3) && $GLOBALS['term_calls']>$prev);
echo "PASS DESTINATION MEMO REGRESSIONS\n";
