<?php
/** Host v112: exact Host Authority GET fastpath only; no WordPress mutations. */
declare(strict_types=1);
error_reporting(E_ALL);
define('ABSPATH','/source');
$GLOBALS['is_admin']=true;
$GLOBALS['ajax']=false;
function is_admin():bool{return $GLOBALS['is_admin'];}
function wp_doing_ajax():bool{return $GLOBALS['ajax'];}
$path='/source/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-servers.php';
$source=file_get_contents($path);
if(!is_string($source))throw new RuntimeException('SERVER_SOURCE_MISSING');
$start=strpos($source,'private static function host_authority_admin_get_without_mcp():bool');
$stop=$start!==false?strpos($source,"
	}
",$start):false;
if($start===false||$stop===false)throw new RuntimeException('FASTPATH_METHOD_NOT_FOUND');
$method=substr($source,$start,$stop-$start+4);
eval('final class V112OwnerGetFixture {' . $method .
 ' public static function status():bool{return self::host_authority_admin_get_without_mcp();}}');
$checks=0;
function check($ok,$label){global $checks;$checks++;if(!$ok)throw new RuntimeException($label);}
$_GET['page']='mad4b-company-host-authority';
$_SERVER['REQUEST_METHOD']='GET';
check(V112OwnerGetFixture::status()===true,'company owner GET fastpath');
$_GET['page']='mad4b-host-authority';
check(V112OwnerGetFixture::status()===true,'control plane owner GET fastpath');
$_GET['page']='dynamic-pricing-controls';
check(V112OwnerGetFixture::status()===false,'pricing admin not excluded');
$_GET['page']='mad4b-company-host-authority';
$_SERVER['REQUEST_METHOD']='POST';
check(V112OwnerGetFixture::status()===false,'POST owner action keeps catalog');
$_SERVER['REQUEST_METHOD']='GET';
$GLOBALS['ajax']=true;
check(V112OwnerGetFixture::status()===false,'AJAX keeps catalog');
$GLOBALS['ajax']=false;
$GLOBALS['is_admin']=false;
check(V112OwnerGetFixture::status()===false,'public route keeps catalog');
$GLOBALS['is_admin']=true;
unset($_GET['page']);
check(V112OwnerGetFixture::status()===false,'nonpage request keeps catalog');
$_GET['page']=['mad4b-company-host-authority'];
check(V112OwnerGetFixture::status()===false,'array query param rejected');
check(str_contains($source,"'mad4b-read' === \$server_id && self::host_authority_admin_get_without_mcp()"),'scoped to read server');
check(str_contains($source,"defined( 'REST_REQUEST' ) && REST_REQUEST"),'REST excluded');
echo json_encode(['state'=>'PASS','checks'=>$checks,'owner_get_only'=>true,'new_authorities'=>0,'anchor_issued'=>false]).PHP_EOL;
