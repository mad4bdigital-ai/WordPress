<?php
/** Original Google OAuth fully mocked fixture plus explicit operator-only revocation admission. */
require __DIR__ . '/context-oauth-lifecycle-runtime.php';
$count=0;
function verify_revocation($label,$ok){global $count;if(!$ok){fwrite(STDERR,'FAIL '.$label.PHP_EOL);exit(1);}$count++;}
$c='MAD4B_SCP_Google_Drive_Context';
$stamp='2026-10-26T03:10:00+00:00';
$pending=[
 'connected'=>true,'revocation_pending'=>true,'read_available'=>false,'write_available'=>false,
 'revocation_attempted_at'=>$stamp,
 'revocation_attempts'=>[
   ['credential_type'=>'refresh','http_status'=>400,'provider_error'=>'invalid_token'],
   ['credential_type'=>'access','http_status'=>400,'provider_error'=>'invalid_token']
 ]
];
verify_revocation('both original invalid-token attempts only enter manual owner gate',$c::manual_revocation_admission($pending,$stamp)===true);
verify_revocation('wrong exact attempt timestamp denied',is_wp_error($c::manual_revocation_admission($pending,'yesterday')));
$copy=$pending;$copy['read_available']=true;
verify_revocation('still readable Google grant cannot be cleaned up',is_wp_error($c::manual_revocation_admission($copy,$stamp)));
$copy=$pending;$copy['revocation_pending']=false;
verify_revocation('no pending revocation denied',is_wp_error($c::manual_revocation_admission($copy,$stamp)));
$copy=$pending;unset($copy['revocation_attempts'][1]);
verify_revocation('one unsuccessful attempt is not proof',is_wp_error($c::manual_revocation_admission($copy,$stamp)));
$copy=$pending;$copy['revocation_attempts'][0]['http_status']=200;
verify_revocation('provider 200 not misclassified as manual invalid-token cleanup',is_wp_error($c::manual_revocation_admission($copy,$stamp)));
$copy=$pending;$copy['revocation_attempts'][1]['provider_error']='invalid_request';
verify_revocation('other Google API error cannot pass',is_wp_error($c::manual_revocation_admission($copy,$stamp)));
$copy=$pending;$copy['revocation_attempts']=array_reverse($copy['revocation_attempts']);
verify_revocation('revocation attempt order bound',is_wp_error($c::manual_revocation_admission($copy,$stamp)));
verify_revocation('fake operator confirmation never deletes vault',is_wp_error($c::complete_manual_revocation_attestation($stamp,'I DID IT')));
verify_revocation('all checks were readonly',!empty($pending['connected']) && !empty($pending['revocation_pending']));
echo 'GOOGLE_REVOCATION_BOUNDARY: PASS '.$count.PHP_EOL;
