<?php
declare(strict_types=1);
/**
 * Run with genuine immutable signed Root bundle mounted as /proof.json.
 * All negative tests change READ-ONLY in-memory copies only.
 * No WP, Broker or Original313 secret keys, and no WordPress writes.
 */
define('ABSPATH','/tmp/');
class MAD4B_Central_VPS_Broker_V1 { const PUBKEY='test_original_broker_pub'; }
$GLOBALS['v123_opts']=array();
function get_option($name,$default=false) {
    return $GLOBALS['v123_opts'][$name]??$default;
}
function wp_json_encode($value,$options=0) {
    return json_encode($value,$options|JSON_THROW_ON_ERROR);
}
require dirname(__DIR__,2).'/wp-content/mu-plugins/mad4b-host-authority-owner-ui/signed-final-certificate.php';
$c='MAD4B_Host_Authority_Signed_Certificate_V123';
$assert=static function(bool $ok,string $reason):void {
    if(!$ok)throw new RuntimeException($reason);
};
$empty=array('state'=>'ACTIVE','revision'=>1,
    'readback_verified'=>true);
$assert($c::verified_summary($empty)===null,'EMPTY_CERT_MUST_FAIL_CLOSED');
$invalid=array('contract'=>$c::CONTRACT,'job_id'=>$c::JOB,
    'revision_event'=>array(),'checkpoint'=>array(),'receipt'=>array());
$GLOBALS['v123_opts'][$c::OPTION]=$invalid;
$assert($c::verified_summary($empty)===null,
    'UNSIGNED_CERT_MUST_FAIL_CLOSED');
$checks=2;
if(isset($argv[1])&&is_file($argv[1])) {
    $live=json_decode((string)file_get_contents($argv[1]),true,
        512,JSON_THROW_ON_ERROR);
    $broker=base64_decode((string)file_get_contents($argv[2]),true);
    $assert(is_array($live)&&is_string($broker)&&strlen($broker)===32,
        'LIVE_SIGNED_BUNDLE_REQUIRED');
    $GLOBALS['v123_opts'][MAD4B_Central_VPS_Broker_V1::PUBKEY]=base64_encode($broker);
    $GLOBALS['v123_opts'][$c::OPTION]=$live;
    $receipt=$live['receipt'];
    $anchor=array(
        'state'=>'ACTIVE','revision'=>1,'readback_verified'=>true,
        'anchor_nonce'=>$receipt['anchor_nonce'],
        'signed_anchor_message_sha256'=>$receipt['wp_signed_message_sha256']
    );
    $read=$c::verified_summary($anchor);
    $assert(is_array($read)&&$read['verified']===true
        &&$read['job_id']===$c::JOB
        &&$read['revision']===1
        &&$read['issued_epoch']===$receipt['issued_epoch'],
        'ORIGINAL_ROOT_THREE_SIGNATURES_NOT_VERIFIED');
    ++$checks;
    foreach(array('revision_event','checkpoint','receipt') as $key) {
        $bad=$live;
        $bad[$key]['signature']=base64_encode(str_repeat(chr(0),64));
        $GLOBALS['v123_opts'][$c::OPTION]=$bad;
        $assert($c::verified_summary($anchor)===null,
            'FORGED_'.$key.'_SIGNATURE_ACCEPTED');
        ++$checks;
    }
    foreach(array(
        array('receipt','job_id',str_repeat('f',32)),
        array('receipt','checkpoint_sha256',str_repeat('f',64)),
        array('checkpoint','event_sha256',str_repeat('f',64)),
        array('revision_event','target_host_id','hosting-3'),
        array('receipt','revision',2),
        array('receipt','environment','production')
    ) as $change) {
        $bad=$live;
        $bad[$change[0]][$change[1]]=$change[2];
        $GLOBALS['v123_opts'][$c::OPTION]=$bad;
        $assert($c::verified_summary($anchor)===null,
            'FORGED_OR_WRONG_SCOPE_CERTIFICATE_ACCEPTED_'.$change[1]);
        ++$checks;
    }
    $GLOBALS['v123_opts'][$c::OPTION]=$live;
    $badAnchor=$anchor;$badAnchor['anchor_nonce']=str_repeat('f',32);
    $assert($c::verified_summary($badAnchor)===null,
        'DIFFERENT_LIVE_ANCHOR_NONCE_ACCEPTED');
    ++$checks;
    $badAnchor=$anchor;
    $badAnchor['signed_anchor_message_sha256']=str_repeat('f',64);
    $assert($c::verified_summary($badAnchor)===null,
        'DIFFERENT_WORDPRESS_ANCHOR_SIGNATURE_ACCEPTED');
    ++$checks;
    $badAnchor=$anchor;$badAnchor['readback_verified']=false;
    $assert($c::verified_summary($badAnchor)===null,
        'UNVERIFIED_WORDPRESS_READBACK_ACCEPTED');
    ++$checks;
    $badAnchor=$anchor;$badAnchor['revision']=0;
    $assert($c::verified_summary($badAnchor)===null,
        'REVISION_ZERO_CERTIFIED');
    ++$checks;
    $GLOBALS['v123_opts'][MAD4B_Central_VPS_Broker_V1::PUBKEY]=
        base64_encode(str_repeat(chr(1),32));
    $assert($c::verified_summary($anchor)===null,
        'UNREGISTERED_ORIGINAL_BROKER_PUB_ACCEPTED');
    ++$checks;
}
echo json_encode(array('state'=>'PASS','checks'=>$checks,
    'original_live_signed_rows'=>(isset($live)?'verified':'not supplied'),
    'wp_writes'=>0),JSON_THROW_ON_ERROR),PHP_EOL;
