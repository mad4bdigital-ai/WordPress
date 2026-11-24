<?php
/** Exact deployed document-kernel behavior contract. No real WordPress I/O. */
if (!defined('ABSPATH')) define('ABSPATH','/tmp/mad4b-readonly-fixture/');
if (!defined('MAD4B_SCP_DIR')) define('MAD4B_SCP_DIR',dirname(__DIR__).'/');
require_once MAD4B_SCP_DIR.'includes/class-mad4b-scp-public-document-kernel.php';
$assertions=0;
$check=static function($passed,$label) use(&$assertions) {
 $assertions++;
 if (!$passed){fwrite(STDERR,"FAIL $label\n");exit(1);}
 echo "PASS $label\n";
};
$server=['REQUEST_METHOD'=>'GET','HTTPS'=>'on','SERVER_PORT'=>'443','HTTP_HOST'=>'example.com',
         'QUERY_STRING'=>'','REQUEST_URI'=>'/egypt-tour-packages/example-tour/'];
$eligible=static fn($s,$get=[],$post=[],$cookies=[],$environment='production',$mode='on',
                    $prefixes=['egypt-tour-packages'],$host='example.com',$admin=false,$cli=false,
                    $ajax=false,$cron=false) => MAD4B_SCP_Public_Document_Kernel::classify(
                        $s,$get,$post,$cookies,$environment,$mode,$prefixes,$host,$admin,$cli,$ajax,$cron);
$check(!empty($eligible($server)['eligible']),'valid canonical public singular GET eligible for further site checks');
$check(empty($eligible($server,[],[],[],'production','off')['eligible']),'off mode denied');
$check(empty($eligible($server,[],[],[],'production','canary')['eligible']),'production canary denied');
$check(empty($eligible($server,[],[],[],'local','on')['eligible']),'unrecognized environment denied');
foreach (['POST','PUT','DELETE'] as $method){$s=$server;$s['REQUEST_METHOD']=$method;$check(empty($eligible($s)['eligible']),"$method denied");}
$check(empty($eligible($server,['q'=>'a'])['eligible']),'GET query denied');
$check(empty($eligible($server,[],['x'=>'1'])['eligible']),'POST body denied');
$check(empty($eligible($server,[],[],['market'=>'eg'])['eligible']),'market cookie denied');
foreach (['HTTP_AUTHORIZATION'=>'Bearer testing','HTTP_X_WP_NONCE'=>'anything',
          'HTTP_COOKIE'=>'name=x','HTTP_X_GEO_COUNTRY'=>'EG',
          'HTTP_X_MAD4B_TRUST_CONTEXT'=>'fake','HTTP_SEC_PURPOSE'=>'prefetch'] as $key=>$value){
 $s=$server;$s[$key]=$value;$check(empty($eligible($s)['eligible']),"$key denied");
}
$s=$server;$s['HTTP_HOST']='another.example';$check(empty($eligible($s)['eligible']),'foreign hostname denied');
$s=$server;$s['HTTPS']='off';$s['SERVER_PORT']='80';$check(empty($eligible($s)['eligible']),'non-HTTPS denied');
$s=$server;$s['REQUEST_URI']='/wp-admin/';$check(empty($eligible($s)['eligible']),'admin route denied');
$s=$server;$s['REQUEST_URI']='/egypt-tour-packages/example-tour/?x=1';$check(empty($eligible($s)['eligible']),'query URI denied');
$s=$server;$s['REQUEST_URI']='//egypt-tour-packages/example-tour/';$check(empty($eligible($s)['eligible']),'double-slash URI denied');
$check(empty($eligible($server,[],[],[],'production','on',['other'])['eligible']),'unregistered prefix denied');
$check(empty($eligible($server,[],[],[],'production','on',[])['eligible']),'missing site prefix denied');
foreach ([8,9,10,11] as $index){
 $args=[$server,[],[],[],'production','on',['egypt-tour-packages'],'example.com',false,false,false,false];
 $args[$index]=true;
 $check(empty(MAD4B_SCP_Public_Document_Kernel::classify(...$args)['eligible']),"private execution flag index $index denied");
}
$check(empty($eligible($server,[],[],[],'staging','canary')['eligible']),'staging canary absent signed canary header denied');
$s=$server;$s['HTTP_X_MAD4B_SCP_PUBLIC_CANARY']='v1';
$check(!empty($eligible($s,[],[],[],'staging','canary')['eligible']),'strict staging canary recognized');
$check(empty($eligible($s,[],[],[],'production','canary')['eligible']),'same canary not allowed in production');
function get_option($name,$default){return $default;}
$_SERVER = $server;$_GET=[];$_POST=[];$_COOKIE=[];$_FILES=[];
$check(MAD4B_SCP_Public_Document_Kernel::try_boot()===false,'default WordPress state cannot activate reduced bootstrap');
echo 'PUBLIC_DOCUMENT_KERNEL_LIVE_PARITY_PASS '.$assertions."\n";
