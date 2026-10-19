<?php
require __DIR__.'/fixtures/artifact-test-signing.php';
$c='MAD4B_SCP_Test_Attestation';$automation='MAD4B_SCP_Production_Automation';
foreach(array('local','development','staging','production') as $env){
 $GLOBALS['env']=$env;
 check(!is_wp_error($c::save_trust(array('fixture-test-key'=>$key),$automation::digest($c::trust()))),'enroll exact public test signer in '.$env);
 $certificate=cert($key,$artifact,$private);
 $v=$c::verify($certificate,$artifact);check(!is_wp_error($v)&&$v['valid']&&!$v['authorizing']&&!$v['mutation_performed'],'real RSA certificate accepted non-authorizing in '.$env);
 $wrong=$artifact;$wrong['package_sha256']=str_repeat('f',64);check(is_wp_error($c::verify($certificate,$wrong)),'package drift rejected in '.$env);
 $p=$certificate['payload'];$p['target_environment']='unknown';check(is_wp_error($c::verify(sign_payload($p,$key,$private),$artifact)),'cross-environment certificate rejected in '.$env);
}
$certificate=cert($key,$artifact,$private);
foreach(array('issuer_origin'=>'https://foreign.test','target_site_uuid'=>'33333333-3333-4333-8333-333333333333','source_commit_sha'=>'bad','issued_at'=>time()+100,'expires_at'=>time()-1) as $field=>$value){$p=$certificate['payload'];$p[$field]=$value;check(is_wp_error($c::verify(sign_payload($p,$key,$private),$artifact)),'signed invalid '.$field.' rejected');}
$p=$certificate['payload'];$p['tests']['booking']='FAIL';check(is_wp_error($c::verify(sign_payload($p,$key,$private),$artifact)),'signed failed functional test rejected');
$p=$certificate['payload'];unset($p['tests']['rollback']);check(is_wp_error($c::verify(sign_payload($p,$key,$private),$artifact)),'missing rollback test rejected');
$bad=$certificate;$bad['payload']['tests']['booking']='FAIL';check(is_wp_error($c::verify($bad,$artifact)),'tampered payload rejected');
$bad=$certificate;$bad['signature']['signature_b64url']=str_repeat('A',342);check(is_wp_error($c::verify($bad,$artifact)),'forged RSA signature rejected');
$bad=$certificate;$bad['signature']['kid']='foreign';check(is_wp_error($c::verify($bad,$artifact)),'unknown signer rejected');
$bad=$certificate;unset($bad['signature']['signed_sha256']);check(is_wp_error($c::verify($bad,$artifact)),'malformed signature rejected without warning');
$keys=array('fixture-test-key'=>$key);$keys['fixture-test-key']['enabled']=false;
check(!is_wp_error($c::save_trust($keys,$automation::digest($c::trust())))&&is_wp_error($c::verify($certificate,$artifact)),'key revocation immediate');
$c::save_trust(array('fixture-test-key'=>$key),$automation::digest($c::trust()));
$GLOBALS['revision']=2;check(is_wp_error($c::verify($certificate,$artifact)),'profile change revokes trust binding');$GLOBALS['revision']=1;
$badkey=$key;$badkey['issuer_environment']='development';$c::save_trust(array('fixture-test-key'=>$badkey),$automation::digest($c::trust()));check(is_wp_error($c::verify(cert($badkey,$artifact,$private),$artifact)),'Production refuses Development test issuer');
$GLOBALS['user']=9;check(is_wp_error($c::save_trust(array(),$automation::digest($c::trust()))),'unenrolled admin cannot enroll test trust');$GLOBALS['user']=7;
$badkey=$key;$badkey['public_key']['public_key_pem']='-----BEGIN PRIVATE KEY-----';check(is_wp_error($c::save_trust(array('fixture-test-key'=>$badkey),$automation::digest($c::trust()))),'private keys refused');
echo 'signed-test-attestation: PASS'.PHP_EOL;
