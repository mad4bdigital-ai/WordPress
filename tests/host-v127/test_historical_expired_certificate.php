<?php
/**
 * Host v127 synthetic Ed25519 historical proof acceptance.
 * No real owner key, WordPress, SQL, Original318, Root or outbound request.
 * The independently pinned real public key is replaced in a COPY IN MEMORY
 * of the verifier for the sole purpose of synthetic negative-fixture testing.
 */
declare(strict_types=1);
define('ABSPATH', __DIR__.'/');
if (!extension_loaded('sodium'))throw new RuntimeException('SODIUM_REQUIRED');
$options=[];
class MAD4B_Central_VPS_Broker_V1 { const PUBKEY='test_broker_pub'; }
function get_option($key,$default=false){global $options;return $options[$key]??$default;}
function wp_json_encode($input,$flags=0){return json_encode($input,$flags|JSON_THROW_ON_ERROR);}
$independentKeypair=sodium_crypto_sign_seed_keypair(random_bytes(32));
$brokerKeypair=sodium_crypto_sign_seed_keypair(random_bytes(32));
$independentPublic=sodium_crypto_sign_publickey($independentKeypair);
$brokerPublic=sodium_crypto_sign_publickey($brokerKeypair);
$options[MAD4B_Central_VPS_Broker_V1::PUBKEY]=base64_encode($brokerPublic);
$root=dirname(__DIR__,2);
$file=$root.'/deploy/host-authority/review/v127/signed-final-certificate.php';
$source=file_get_contents($file);
$existing="const INDEPENDENT_PUB_B64='4jwcLgOUf2d/j2uDYbVysCnkSMJW64cXnhMPaeix11Y=';";
$replacement="const INDEPENDENT_PUB_B64='".base64_encode($independentPublic)."';";
if(substr_count($source,$existing)!==1)throw new RuntimeException('REAL_PIN_HAS_CHANGED');
$testOnly=str_replace($existing,$replacement,$source);
eval('?>'.$testOnly);
$cls='MAD4B_Host_Authority_Signed_Certificate_V123';
$now=time();
$sha=hash('sha256','synthetic-original318-anchor-message');
$uuid='123e4567-e89b-42d3-a456-426614174001';
$nonce=str_repeat('a',32);
$job=$cls::JOB;
$ledger=[
 'contract'=>$cls::LEDGER,'company'=>'all-royal-egypt','environment'=>'staging',
 'site_uuid'=>$uuid,'target_host_id'=>'hosting-2','ssh_principal'=>'u123456789',
 'revision'=>1,'grant_revision'=>0,'anchor_nonce'=>$nonce,'state'=>'ACTIVE',
 'event_id'=>$job,'previous_event_sha256'=>str_repeat('0',64),
 'issued_epoch'=>$now-600,'expires_epoch'=>$now-555];
function sign_row(array $row,array $fields,string $domain,string $keypair):array{
 $body=[];
 foreach($fields as $key)$body[$key]=$row[$key];
 ksort($body,SORT_STRING);
 $msg=$domain."\n".json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
 $row['signature']=base64_encode(sodium_crypto_sign_detached($msg,sodium_crypto_sign_secretkey($keypair)));
 return $row;
}
function signed_digest(array $row,array $fields,string $domain):string{
 $body=[];
 foreach($fields as $key)$body[$key]=$row[$key];
 ksort($body,SORT_STRING);
 return hash('sha256',$domain."\n".json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
}
$ledger=sign_row($ledger,$cls::LEDGER_FIELDS,'MAD4B_ORIGINAL_HOST_LEDGER_V1',$brokerKeypair);
$ledgerSha=signed_digest($ledger,$cls::LEDGER_FIELDS,'MAD4B_ORIGINAL_HOST_LEDGER_V1');
$cp=['contract'=>$cls::CHECKPOINT,'company'=>'all-royal-egypt','environment'=>'staging',
 'site_uuid'=>$uuid,'revision'=>1,'grant_revision'=>0,'anchor_nonce'=>$nonce,
 'state'=>'ACTIVE','event_sha256'=>$ledgerSha,
 'issued_epoch'=>$now-590,'expires_epoch'=>$now-545];
$cp=sign_row($cp,$cls::CHECKPOINT_FIELDS,'MAD4B_ORIGINAL_HOST_CHECKPOINT_V1',$independentKeypair);
$cpSha=signed_digest($cp,$cls::CHECKPOINT_FIELDS,'MAD4B_ORIGINAL_HOST_CHECKPOINT_V1');
$receipt=['contract'=>$cls::RECEIPT,'job_id'=>$job,'operation_id'=>$cls::ISSUANCE,
 'company'=>'all-royal-egypt','environment'=>'staging','site_uuid'=>$uuid,
 'revision'=>1,'anchor_nonce'=>$nonce,'state'=>'ACTIVE','event_sha256'=>$ledgerSha,
 'checkpoint_sha256'=>$cpSha,'wp_signed_message_sha256'=>$sha,
 'issued_epoch'=>$now-590];
$receipt=sign_row($receipt,$cls::RECEIPT_FIELDS,'MAD4B_ORIGINAL313_HOST_FINAL_RECEIPT_V1',$independentKeypair);
$bundle=['contract'=>$cls::CONTRACT,'job_id'=>$job,'revision_event'=>$ledger,'checkpoint'=>$cp,'receipt'=>$receipt];
$options[$cls::OPTION]=$bundle;
$expired=[
 'state'=>'EXPIRED','revision'=>1,'readback_verified'=>true,
 'current_anchor_signature_verified'=>true,
 'signed_anchor_active_in_option'=>false,'prior_grants_ineligible'=>true,
 'write_authorized'=>false,'current_anchor_eligible'=>false,
 'anchor_nonce'=>$nonce,'signed_anchor_message_sha256'=>$sha];
$pass=0;
function check($cond,$desc){global $pass;if(!$cond)throw new RuntimeException('V127_TEST_FAILED:'.$desc);++$pass;}
function denied(array $anchor,string $desc){global $cls;check($cls::verified_summary($anchor)===null,$desc);}
$summary=$cls::verified_summary($expired);
check(is_array($summary)&&$summary['verified']===true,'EXPIRED_HISTORICAL_SIGNED_PROOF_ACCEPTED');
check($summary['state']==='EXPIRED'&&$summary['revision']===1,'EXPIRED_STATE_NOT_ACTIVE');
check($summary['execution_authority_granted']===false,'NO_EXECUTION_AUTHORITY');
check($summary['anchor_currently_unexpired']===false,'NO_VALID_LEASE');
check($summary['expires_epoch']===$ledger['expires_epoch'],'SIGNED_EXPIRY_PRESENT');
check($summary['job_id']===$job,'ORIGINAL_JOB_MATCH');
$active=$expired;
$active['state']='ACTIVE';$active['signed_anchor_active_in_option']=true;
$active['prior_grants_ineligible']=false;
denied($active,'HISTORICAL_EXPIRED_LEDGER_CANNOT_BE_ACTIVE');
$freshLedger=$ledger;$freshLedger['issued_epoch']=$now-4;$freshLedger['expires_epoch']=$now+41;
unset($freshLedger['signature']);
$freshLedger=sign_row($freshLedger,$cls::LEDGER_FIELDS,'MAD4B_ORIGINAL_HOST_LEDGER_V1',$brokerKeypair);
$freshEventSha=signed_digest($freshLedger,$cls::LEDGER_FIELDS,'MAD4B_ORIGINAL_HOST_LEDGER_V1');
$freshCp=$cp;$freshCp['event_sha256']=$freshEventSha;
$freshCp['issued_epoch']=$now-3;$freshCp['expires_epoch']=$now+42;
unset($freshCp['signature']);
$freshCp=sign_row($freshCp,$cls::CHECKPOINT_FIELDS,'MAD4B_ORIGINAL_HOST_CHECKPOINT_V1',$independentKeypair);
$freshReceipt=$receipt;
$freshReceipt['event_sha256']=$freshEventSha;
$freshReceipt['checkpoint_sha256']=signed_digest($freshCp,$cls::CHECKPOINT_FIELDS,'MAD4B_ORIGINAL_HOST_CHECKPOINT_V1');
$freshReceipt['issued_epoch']=$now-3;
unset($freshReceipt['signature']);
$freshReceipt=sign_row($freshReceipt,$cls::RECEIPT_FIELDS,'MAD4B_ORIGINAL313_HOST_FINAL_RECEIPT_V1',$independentKeypair);
$options[$cls::OPTION]=['contract'=>$cls::CONTRACT,'job_id'=>$job,'revision_event'=>$freshLedger,'checkpoint'=>$freshCp,'receipt'=>$freshReceipt];
$historicalFromActive=$cls::verified_summary($active);
check(is_array($historicalFromActive)&&$historicalFromActive['state']==='ACTIVE'&&$historicalFromActive['anchor_currently_unexpired']===true,'ACTIVE_STATUS_PRESERVED');
check($historicalFromActive['execution_authority_granted']===false,'NO_GENERIC_ACTIVE_WRITE_GRANT');
$options[$cls::OPTION]=$bundle;
$x=$expired;$x['state']='REVOKED';denied($x,'REVOKED_REJECTED');
$x=$expired;$x['state']='MISSING_OR_INVALID';denied($x,'UNKNOWN_REJECTED');
$x=$expired;$x['current_anchor_signature_verified']=false;denied($x,'UNSIGNED_ANCHOR_DENIED');
$x=$expired;$x['prior_grants_ineligible']=false;denied($x,'PREVIOUS_GRANTS_INVALIDATION_REQUIRED');
$x=$expired;$x['signed_anchor_active_in_option']=true;denied($x,'EXPIRED_CANNOT_CLAIM_ACTIVE_OPTION');
$x=$expired;$x['write_authorized']=true;denied($x,'EXPIRED_NO_WRITE');
$x=$expired;$x['current_anchor_eligible']=true;denied($x,'EXPIRED_NO_ELIGIBLE_ANCHOR');
$x=$expired;$x['anchor_nonce']=str_repeat('f',32);denied($x,'CROSS_ANCHOR_NONCE_DENIED');
$x=$expired;$x['signed_anchor_message_sha256']=hash('sha256','forged');denied($x,'SIGNED_ANCHOR_HASH_DENIED');
$x=$active;$x['signed_anchor_active_in_option']=false;denied($x,'ACTIVE_MUST_HAVE_ACTIVE_OPTION');
foreach(['revision_event'=>'BROKER_LEDGER_SIGNATURE_TAMPER','checkpoint'=>'INDEPENDENT_CHECKPOINT_SIGNATURE_TAMPER','receipt'=>'INDEPENDENT_FINAL_RECEIPT_SIGNATURE_TAMPER'] as $part=>$reason){
 $bad=$bundle;$bad[$part]['signature']=base64_encode(random_bytes(64));
 $options[$cls::OPTION]=$bad;denied($expired,$reason);
}
$options[$cls::OPTION]=$bundle;
$bad=$bundle;$bad['receipt']['job_id']=str_repeat('0',32);$options[$cls::OPTION]=$bad;denied($expired,'WRONG_OWNER_JOB');
$options[$cls::OPTION]=$bundle;
$bad=$bundle;$bad['revision_event']['target_host_id']='hosting-3';$options[$cls::OPTION]=$bad;denied($expired,'WRONG_HOST');
$options[$cls::OPTION]=$bundle;
echo json_encode(['result'=>'PASS','checks'=>$pass,'expired_certificate_historical_only'=>true,
 'signature_forgery_rejected'=>true,'user_host_write_authority'=>false,
 'persisted_option_mutation'=>false],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),PHP_EOL;
