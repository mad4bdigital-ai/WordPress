<?php
define('ABSPATH',__DIR__.'/');
class WP_Error{}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v));}
require dirname(__DIR__).'/includes/class-mad4b-scp-structural-redaction.php';

$fail=static function($m,$v=null){fwrite(STDERR,'FAIL structural-redaction-runtime: '.$m.(null===$v?'':' '.json_encode($v)).PHP_EOL);exit(1);};
$check=static function($c,$m,$v=null)use($fail){if(!$c)$fail($m,$v);};

$payload=array(
 'metadata'=>array(
   'clientCredentialMaterial'=>'super-secret',
   'X-Webhook-Auth-Secret'=>'hook-secret',
   'nested'=>(object)array(
     'normal'=>'ok',
     'odd.Token.Value'=>'eyJabc.def.ghi',
     'private_material'=>"-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----",
     'url'=>'https://user:pass@example.invalid/path'
   )
 ),
 'callback_return'=>array('body'=>'safe','response_body'=>'must-hide'),
 'provider_error'=>array('detail'=>'safe','Authorization'=>'Bearer abcdefghijklmnop'),
 'list'=>array(array('apiKey'=>'abc'),array('note'=>str_repeat('x',4096)))
);
$result=MAD4B_SCP_Structural_Redaction::classify($payload,'provider_error');
$clean=$result['value'];
$check('sensitive_redacted'===$result['classification'],'sensitive payload not classified',$result);
$check('[REDACTED]'===$clean['metadata']['clientCredentialMaterial'],'camelCase credential key escaped redaction',$clean);
$check('[REDACTED]'===$clean['metadata']['X-Webhook-Auth-Secret'],'unexpected webhook secret key escaped redaction',$clean);
$check('[REDACTED]'===$clean['metadata']['nested']['odd.Token.Value'],'JWT-like nested value escaped redaction',$clean);
$check('[REDACTED]'===$clean['metadata']['nested']['private_material'],'private key value escaped redaction',$clean);
$check('[REDACTED]'===$clean['metadata']['nested']['url'],'URL credentials escaped redaction',$clean);
$check('[REDACTED]'===$clean['callback_return']['response_body'],'callback response_body escaped redaction',$clean);
$check('[REDACTED]'===$clean['provider_error']['Authorization'],'provider Authorization escaped redaction',$clean);
$check(false!==strpos($clean['list'][1]['note'],'[TRUNCATED]'),'oversized metadata string was not bounded',$clean);
$check($result['stats']['redacted']>=7&&$result['stats']['truncated']>=1,'redaction stats did not capture adversarial cases',$result['stats']);

$deep=array();$cursor=&$deep;for($i=0;$i<12;$i++){$cursor['level_'.$i]=array();$cursor=&$cursor['level_'.$i];}
$bounded=MAD4B_SCP_Structural_Redaction::classify($deep,'fuzz');
$check($bounded['stats']['truncated']>0,'deep nested structure was not bounded',$bounded);
echo "mad4b.structural-redaction.runtime.v1: PASS\n";
