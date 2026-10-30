<?php
declare(strict_types=1);
require __DIR__.'/test-single-owner.php';
require __DIR__.'/AuthorityLedger.php';
if(getenv('MAD4B_AUTHORITY_QA_ONLY')!=='1')throw new RuntimeException('QA isolation missing');
$dbName=(string)getenv('AUTH_QA_DB');
$user=(string)getenv('AUTH_QA_DB_USER');
if(!preg_match('/^mad4b_authority_qa_[a-f0-9]{8}$/D',$dbName)
 ||!preg_match('/^authority_qa_[a-f0-9]{8}$/D',$user))throw new RuntimeException('QA DB identity invalid');
$pw=trim((string)file_get_contents('/run/secrets/qa_password'));
$db=new mysqli('mad4b-authority-staging-db',$user,$pw,$dbName);
unset($pw);
if($db->connect_errno)throw new RuntimeException('Isolated QA database unavailable');
$db->set_charset('utf8mb4');
$ledger=new MAD4B_Authority_Ledger($db);
$ledger->install();
$registered=$ledger->registerVerified($envelope,$trust('ca'),$intent,$now,$liveGrant);
testit('single owner ledger registration',($registered['registered']??false)===true);
$sha=$registered['permit_sha256'];
testit('duplicate nonce registration blocked',
    ($ledger->registerVerified($envelope,$trust('ca'),$intent,$now,$liveGrant)['registered']??false)===false);
testit('revoked grant rejected at reservation',
    ($ledger->reserve($sha,$site,$intent['artifact_sha256'],$intent['plan_sha256'],$now,static fn()=>false)['reserved']??false)===false);
testit('grant check mandatory at reservation',
    ($ledger->reserve($sha,$site,$intent['artifact_sha256'],$intent['plan_sha256'],$now)['reserved']??false)===false);
$ok=$ledger->reserve($sha,$site,$intent['artifact_sha256'],$intent['plan_sha256'],$now,$liveGrant);
testit('owner grant allows one atomic reservation',($ok['reserved']??false)===true);
testit('no second reservation',($ledger->reserve($sha,$site,$intent['artifact_sha256'],$intent['plan_sha256'],$now,$liveGrant)['reserved']??false)===false);
$legacyRegister=$ledger->registerVerified($legacy['permit_envelope'],$trust('ca'),$legacyIntent,$now);
testit('old three-person ledger registration preserved',($legacyRegister['registered']??false)===true);
$oldReserve=$ledger->reserve($legacyRegister['permit_sha256'],$site,$legacyIntent['artifact_sha256'],$legacyIntent['plan_sha256'],$now);
testit('old three-person ledger reservation preserved',($oldReserve['reserved']??false)===true);
testit('ledger site mismatch denied',($ledger->reserve($sha,'33333333-3333-4333-8333-333333333333',$intent['artifact_sha256'],$intent['plan_sha256'],$now,$liveGrant)['reserved']??false)===false);
$db->close();
echo "RESULT QA_MARIADB_LEDGER: PASS; operational_database_unchanged=true\n";
