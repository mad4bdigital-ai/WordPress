<?php
declare(strict_types=1);
mysqli_report(MYSQLI_REPORT_OFF);
if (getenv('AUTH_QA_DB') === 'mad4b_authority_staging' || getenv('MAD4B_AUTHORITY_QA_ONLY') !== '1' || getenv('MAD4B_AUTHORITY_SCOPE') !== 'staging') throw new RuntimeException('QA environment guard');
$dbName=(string)getenv('AUTH_QA_DB');$user=(string)getenv('AUTH_QA_DB_USER');
if(!preg_match('/^mad4b_authority_qa_[a-f0-9]{8}$/D',$dbName)||!preg_match('/^authority_qa_[a-f0-9]{8}$/D',$user))throw new RuntimeException('QA isolation guard');
$pw=trim((string)file_get_contents('/run/secrets/qa_password'));
$db=new mysqli('mad4b-authority-staging-db',$user,$pw,$dbName);
$db2=new mysqli('mad4b-authority-staging-db',$user,$pw,$dbName);unset($pw);
if($db->connect_errno||$db2->connect_errno)throw new RuntimeException('QA DB unavailable');
$db->set_charset('utf8mb4');$db2->set_charset('utf8mb4');
require '/work/src/IndependentAuthority.php';require '/work/src/AuthorityLedger.php';
$tests=[];
$ok=function(string $n,bool $c)use(&$tests):void{if(!$c)throw new RuntimeException('Failed '.$n);$tests[$n]=true;};
$ledger=new MAD4B_Authority_Ledger($db);$ledger2=new MAD4B_Authority_Ledger($db2);$ledger->install();
$now=time();$uuid='22222222-2222-4222-8222-222222222222';$origin='https://qa-independent.example';
$kpid='qa-test-idp';$kpqa='qa-test-runner';$kpca='qa-test-ca';
$generate=function(){ $pair=sodium_crypto_sign_keypair();return [sodium_crypto_sign_secretkey($pair),bin2hex(sodium_crypto_sign_publickey($pair))];};
[$idSec,$idPub]=$generate();[$qaSec,$qaPub]=$generate();[$caSec,$caPub]=$generate();
$sign=static function(array $payload,string $kid,string $secret):array{return ['payload_json'=>MAD4B_Independent_Authority::json($payload),'signature_hex'=>bin2hex(sodium_crypto_sign_detached(MAD4B_Independent_Authority::json($payload),$secret)),'kid'=>$kid];};
$intent=['contract'=>MAD4B_Independent_Authority::INTENT,'operation_id'=>'qa-operation-'.bin2hex(random_bytes(8)),
'action'=>'enable_shared_media','site_uuid'=>$uuid,'environment'=>'staging','origin'=>$origin,
'artifact_sha256'=>str_repeat('a',64),'plan_sha256'=>str_repeat('b',64),'rollback_sha256'=>str_repeat('c',64),
'rights_sha256'=>str_repeat('d',64),'policy_sha256'=>MAD4B_Independent_Authority::activePolicyDigest(),
'created_at'=>$now-5,'expires_at'=>$now+200,'nonce'=>bin2hex(random_bytes(16))];
$ih=MAD4B_Independent_Authority::digest($intent);
$people=[];foreach(['platform_owner','company_owner','security_reviewer'] as $i=>$role){$actor='qa-actor-'.($i+1);$people[]=$sign([
'contract'=>MAD4B_Independent_Authority::IDENTITY,'actor_id'=>$actor,'role'=>$role,'site_uuid'=>$uuid,'environment'=>'staging',
'intent_sha256'=>$ih,'session_sha256'=>hash('sha256',$actor),'authentication_method'=>'webauthn',
'authenticated_at'=>$now-4,'expires_at'=>$now+120],$kpid,$idSec);}
$results=array_fill_keys(array_merge(MAD4B_Independent_Authority::REQUIRED_BASE,MAD4B_Independent_Authority::REQUIRED_MEDIA),true);
$qa=$sign(['contract'=>MAD4B_Independent_Authority::EVIDENCE,'runner_id'=>'qa-test-runner',
'site_uuid'=>$uuid,'environment'=>'staging','intent_sha256'=>$ih,'artifact_sha256'=>$intent['artifact_sha256'],
'plan_sha256'=>$intent['plan_sha256'],'observed_at'=>$now-4,'expires_at'=>$now+120,
'results'=>$results,'evidence_sha256'=>str_repeat('e',64)],$kpqa,$qaSec);
$caSign=fn($v)=>$sign($v,$kpca,$caSec);$trust=[$kpca=>$caPub];
$permit=MAD4B_Independent_Authority::issue($intent,$people,$qa,[$kpid=>$idPub],[$kpqa=>$qaPub],$trust,$caSign,$now);
$ok('test_policy_signed_permit',($permit['valid']??false)===true);
$env=$permit['permit_envelope'];$sha=MAD4B_Independent_Authority::digest($env);
$registered=$ledger->registerVerified($env,$trust,$intent,$now);
$ok('signed_permit_registered',($registered['registered']??false)===true);
$ok('duplicate_nonce_rejected',$ledger2->registerVerified($env,$trust,$intent,$now)['registered']===false);
$ok('registered_event',$ledger->eventCount($sha)===1);
$ok('cross_tenant_denied',$ledger2->reserve($sha,'33333333-3333-4333-8333-333333333333',$intent['artifact_sha256'],$intent['plan_sha256'],$now)['reserved']===false);
$ok('artifact_drift_denied',$ledger2->reserve($sha,$uuid,str_repeat('f',64),$intent['plan_sha256'],$now)['reserved']===false);
$ok('plan_drift_denied',$ledger2->reserve($sha,$uuid,$intent['artifact_sha256'],str_repeat('f',64),$now)['reserved']===false);
$db->begin_transaction();$stmt=$db->prepare('SELECT permit_sha256 FROM mad4b_authority_permits WHERE permit_sha256=? FOR UPDATE');$stmt->bind_param('s',$sha);$stmt->execute();$stmt->get_result()->fetch_assoc();$stmt->close();
$db2->query('SET SESSION innodb_lock_wait_timeout=1');$blocked=false;
try{$ledger2->reserve($sha,$uuid,$intent['artifact_sha256'],$intent['plan_sha256'],$now);}catch(Throwable $e){$blocked=in_array((int)$e->getCode(),[1205,1213],true);}finally{$db->rollback();}
$ok('real_innodb_lock_denied',$blocked);
$res=$ledger->reserve($sha,$uuid,$intent['artifact_sha256'],$intent['plan_sha256'],$now);
$ok('one_reservation_wins',($res['reserved']??false)===true);
$ok('reservation_replay_rejected',$ledger2->reserve($sha,$uuid,$intent['artifact_sha256'],$intent['plan_sha256'],$now)['reserved']===false);
$ok('reserved_cannot_revoke',$ledger->revokeUnreserved($sha,$now)['revoked']===false);
$ok('reservation_audited',$ledger->eventCount($sha)===2);
$cert=['contract'=>MAD4B_Independent_Authority::CERTIFICATE,'issuer'=>'mad4b.local-operational-authority',
'origin'=>$origin,'action'=>'enable_shared_media','permit_sha256'=>$sha,'site_uuid'=>$uuid,'environment'=>'staging',
'artifact_sha256'=>$intent['artifact_sha256'],'runtime_receipt_sha256'=>str_repeat('e',64),
'runtime_sha256'=>str_repeat('f',64),'result'=>'healthy_attested','issued_at'=>$now,'expires_at'=>$now+120];
$unsigned=['payload_json'=>MAD4B_Independent_Authority::json($cert),'signature_hex'=>str_repeat('0',128),'kid'=>$kpca];
$ok('unsigned_completion_denied',$ledger->completeCertified($sha,$res['reservation_token'],$unsigned,$trust,$now)['completed']===false);
$signed=$sign($cert,$kpca,$caSec);
$ok('wrong_reservation_token_denied',$ledger->completeCertified($sha,bin2hex(random_bytes(32)),$signed,$trust,$now)['completed']===false);
$ok('signed_completion_fences_database',($ledger2->completeCertified($sha,$res['reservation_token'],$signed,$trust,$now)['completed']??false)===true);
$ok('completion_replay_denied',$ledger->completeCertified($sha,$res['reservation_token'],$signed,$trust,$now)['completed']===false);
$ok('applied_state_with_three_events',($ledger->inspect($sha)['state']??'')==='applied' && $ledger->eventCount($sha)===3);
$ok('secrets_not_exposed',!isset($ledger->inspect($sha)['nonce'],$ledger->inspect($sha)['reservation_sha256']));
sodium_memzero($idSec);sodium_memzero($qaSec);sodium_memzero($caSec);
$db->close();$db2->close();
echo json_encode(['contract'=>'rev153.ssh-recreated-ledger-mariadb.v1','status'=>'PASS','passed'=>count($tests),'total'=>count($tests),'checks'=>$tests,'test_only'=>true,'real_authority_mutated'=>false,'operational_ca_enrolled'=>false,'deployment_performed'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),"\n";
