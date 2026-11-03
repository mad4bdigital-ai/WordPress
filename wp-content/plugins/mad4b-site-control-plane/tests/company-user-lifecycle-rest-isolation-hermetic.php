<?php
/** Hermetic REST identity isolation, no WordPress, network, database or writes. */
define('ABSPATH','/tmp/');
$GLOBALS['f'] = array(); $GLOBALS['actor']=5; $GLOBALS['suspended']=true;
function add_filter($name,$cb,$priority=10,$args=1){$GLOBALS['f'][$name]=array('callback'=>$cb,'priority'=>$priority,'args'=>$args);}
function add_action($name,$cb,$priority=10,$args=1){add_filter($name,$cb,$priority,$args);}
function is_wp_error($x){return $x instanceof WP_Error;}
function get_current_user_id(){return $GLOBALS['actor'];}
function get_user_meta($id,$key,$single=false){return $GLOBALS['suspended'] ? array('previous_roles'=>array('editor')) : false;}
class WP_Error {
  public $code;
  public function __construct($code,$message,$detail=null){$this->code=$code;}
  public function get_error_code(){return $this->code;}
}
class WP_REST_Request {
  private $path;
  public function __construct($method,$path){$this->path=$path;}
  public function get_route(){return $this->path;}
}
$root=getenv('MAD4B_PLUGIN_ROOT') ?: dirname(__DIR__);
require $root.'/includes/class-mad4b-scp-company-user-lifecycle.php';
if(isset($GLOBALS['f']['rest_authentication_errors']))throw new RuntimeException('Forbidden global authentication REST hook');
$scoped=$GLOBALS['f']['rest_pre_dispatch']??null;
if(!$scoped || $scoped['args']!==3)throw new RuntimeException('MAD4B scoped REST hook missing');
$cb=$scoped['callback'];
$wp=$cb(null,null,new WP_REST_Request('GET','/wp/v2/posts'));
if($wp!==null)throw new RuntimeException('Foreign WordPress REST must be unaffected');
$wpml=$cb(null,null,new WP_REST_Request('GET','/wpml/v1/status'));
if($wpml!==null)throw new RuntimeException('WPML REST must be unaffected');
$owned=$cb(null,null,new WP_REST_Request('GET','/mad4b/v1/admin/company-team/users/5'));
if(!($owned instanceof WP_Error) || $owned->get_error_code()!=='company_user_suspended')
 throw new RuntimeException('MAD4B REST suspended staff was not denied');
$owned2=$cb(null,null,new WP_REST_Request('GET','/mad4b-content-readiness/v1/alerts'));
if(!($owned2 instanceof WP_Error))throw new RuntimeException('MAD4B namespace still exposed');
$prior=new WP_Error('prior_denial','Previous authorization rejected');
if($cb($prior,null,new WP_REST_Request('GET','/mad4b/v1/test'))!==$prior)
 throw new RuntimeException('Previous denial overwritten');
$GLOBALS['suspended']=false;
if($cb(null,null,new WP_REST_Request('GET','/mad4b/v1/test'))!==null)
 throw new RuntimeException('Healthy staff must not be blocked');
$GLOBALS['actor']=0;$GLOBALS['suspended']=true;
if($cb(null,null,new WP_REST_Request('GET','/mad4b/v1/test'))!==null)
 throw new RuntimeException('Anonymous must remain governed by route permissions');
echo 'MAD4B_COMPANY_REST_SCOPE: PASS'.PHP_EOL;
