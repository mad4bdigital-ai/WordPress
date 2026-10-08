<?php
define('ABSPATH', __DIR__);
require __DIR__.'/../includes/class-mad4b-scp-aci01-recipe-gap.php';
function check($ok,$message){if(!$ok){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
$c='MAD4B_SCP_ACI01_Recipe_Gap';
$s=array('contract'=>'mad4b.aci01.semantic-recipe.v1','status'=>'NEEDS_EVIDENCE',
    'semantic_fingerprint_sha256'=>str_repeat('a',64),
    'mapping'=>array('profile_slug'=>'tour_product','profile_revision'=>5,
        'profile_authority_sha256'=>str_repeat('b',64)));
$d=array('contract'=>'mad4b.aci01.recipe-declaration.v1',
    'profile_slug'=>'tour_product','profile_revision'=>5,
    'profile_authority_sha256'=>str_repeat('b',64),
    'requirements'=>array('availability_source','verified_price_source','cancellation_terms'));
$r=$c::evaluate($s,$d,array());
check($r['status']==='NEEDS_EVIDENCE'&&count($r['missing_requirement_keys'])===3,'missing domain facts');
check($r['authorizing']===false&&$r['mutation_performed']===false,'never authorizes');
check($r['observed_receipt_count']===0,'no receipts');
check($r['candidate_sha256']===$c::evaluate($s,$d,array())['candidate_sha256'],'determinism');
check($c::evaluate($s,null,array())['status']==='NEEDS_EVIDENCE','missing recipe fails conservative');
check(in_array('reviewed_domain_recipe',$c::evaluate($s,null,array())['missing_requirement_keys'],true),'unreviewed marked');
$bad=$d;$bad['profile_revision']=6;
check($c::evaluate($s,$bad,array())['status']==='DENIED','cross revision');
$bad=$d;$bad['profile_authority_sha256']=str_repeat('c',64);
check($c::evaluate($s,$bad,array())['status']==='DENIED','cross authority hash');
$bad=$d;$bad['requirements'][]='availability_source';
check($c::evaluate($s,$bad,array())['status']==='DENIED','duplicate requirements');
$bad=$d;$bad['requirements']=array_fill(0,41,'fact');
check($c::evaluate($s,$bad,array())['status']==='DENIED','bounded');
$receipt=array('requirement'=>'verified_price_source','source_sha256'=>str_repeat('f',64),
    'semantic_sha256'=>str_repeat('a',64));
$partial=$c::evaluate($s,$d,array($receipt));
check(count($partial['missing_requirement_keys'])===2,'partial evidence');
check($partial['source_receipts_independently_certified']===false,'source observation never rights');
check($c::evaluate($s,$d,array($receipt,$receipt))['status']==='DENIED','duplicate receipt');
$receipt['semantic_sha256']=str_repeat('c',64);
check($c::evaluate($s,$d,array($receipt))['status']==='DENIED','wrong semantic');
$receipt['semantic_sha256']=str_repeat('a',64);
$receipt['requirement']='unrelated';
check($c::evaluate($s,$d,array($receipt))['status']==='DENIED','injected requirement');
$bad=$s;$bad['status']='READY';
check($c::evaluate($bad,$d,array())['status']==='DENIED','caller forged semantic readiness');
echo "ACI01_RECIPE_GAP: PASS\n";
