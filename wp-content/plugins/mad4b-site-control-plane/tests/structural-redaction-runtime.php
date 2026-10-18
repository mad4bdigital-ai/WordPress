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
// Synthetic credentials only. Generic field names and surrounding prose must
// not evade the same redactor consumed by audit/observability/certification.
foreach ( array( 'ghp_', 'github_pat_', 'gho_', 'ghu_', 'ghs_', 'ghr_', 'sk-', 'xoxb-' ) as $prefix ) {
    $fake = $prefix . str_repeat( 'A', 40 );
    foreach ( array( $fake, 'Upstream rejected ' . $fake . ' during request.' ) as $detail ) {
        $result = MAD4B_SCP_Structural_Redaction::classify( array( 'detail'=>$detail ) );
        $check( '[REDACTED]' === $result['value']['detail'], 'credential format escaped generic metadata: ' . $prefix );
        $check( 'sensitive_redacted' === $result['classification'], 'credential misclassified as public' );
    }
}
foreach ( array( 'Error: Bearer abcdefghijklmnop', 'Invalid JWT eyJabc.def.ghi in response',
    'Key -----BEGIN OPENSSH PRIVATE KEY----- rejected', 'URL https://user:pass@example.invalid/path failed' ) as $detail ) {
    $clean = MAD4B_SCP_Structural_Redaction::redact( array( 'detail'=>$detail ) );
    $check( '[REDACTED]' === $clean['detail'], 'embedded credential escaped redaction' );
}
$safe = array( 'detail'=>'Request failed with HTTP 503; retry after review.', 'count'=>3 );
$check( $safe === MAD4B_SCP_Structural_Redaction::redact( $safe ), 'public operational data should remain readable' );
echo "mad4b.structural-redaction.runtime.v1: PASS\n";
