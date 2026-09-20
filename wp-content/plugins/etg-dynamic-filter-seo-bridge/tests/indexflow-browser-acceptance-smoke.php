<?php
namespace ETG\DynamicFilterSEOBridge\Acceptance {
    class CanonicalStateNormalizer {}
    class SemanticQueryEvaluator {}
    class CaseSelector {}
    class VerdictReducer {}
}
namespace {
require dirname(__DIR__) . '/includes/Acceptance/LiveAcceptanceProvider.php';
require dirname(__DIR__) . '/includes/Acceptance/BrowserAcceptanceProvider.php';
use ETG\DynamicFilterSEOBridge\Acceptance\LiveAcceptanceProvider;
use ETG\DynamicFilterSEOBridge\Acceptance\BrowserAcceptanceProvider;

function need($cond,$msg){if(!$cond){fwrite(STDERR,"FAIL: $msg\n");exit(1);}}
$live=(new ReflectionClass(LiveAcceptanceProvider::class))->newInstanceWithoutConstructor();
$provider=new BrowserAcceptanceProvider($live, static fn()=>['valid'=>true,'git_sha'=>'g','tree_sha'=>'t']);
$rc=new ReflectionClass($provider);
$eval=$rc->getMethod('evaluateCase');$eval->setAccessible(true);
$validate=$rc->getMethod('validateEvidenceEnvelope');$validate->setAccessible(true);
$ids=range(1,50);$identity=$ids;sort($identity,SORT_NUMERIC);
$dig=fn($v)=>hash('sha256',json_encode(array_values($v),JSON_UNESCAPED_SLASHES));
$expected=[
 'case_id'=>'c','provider'=>'jet-engine','query_id'=>'q','taxonomy'=>'tax','term_id'=>1,'term_slug'=>'x',
 'expected'=>['result_total'=>50,'proof_mode'=>'full_ids','proof_item_count'=>50,'ids'=>$ids,'identity_digest'=>$dig($identity),'order_digest'=>$dig($ids)]
];
$base=[
 'case_id'=>'c',
 'runtime'=>['javascript_runtime'=>true,'jet_smart_filters_observed'=>true,'filter_group'=>'jet-engine/q'],
 'events'=>['ajax_filters_updated'=>true,'presentation_updated'=>true,'presentation_reset'=>true],
 'network'=>['method'=>'POST','endpoint'=>'/wp-json/etg-dfsb/v1/ajax-presentation','http_status'=>200,'contract'=>'etg.dfsb.ajax-presentation.v1','status'=>'ready','authorizing'=>false,'url_authority'=>false,'seo_mutation'=>false,'provider'=>'jet-engine','query_id'=>'q'],
 'rendered'=>['ids'=>$ids,'ids_complete'=>true,'result_count'=>50,'result_count_authoritative'=>true,'result_count_source'=>'jet_smart_filters_results_count'],
 'url_state'=>['filter_state_observed'=>true,'etg_history_mutation'=>false],
 'seo'=>['canonical_unchanged'=>true,'robots_unchanged'=>true,'hreflang_unchanged'=>true,'rank_math_unchanged'=>true],
 'reset'=>['event_observed'=>true,'neutral_state_restored'=>true],
];
$r=$eval->invoke($provider,$expected,$base);need($r['verdict']==='PASS','full ids pass');
$e=$base;$e['rendered']['result_count_authoritative']=false;$e['rendered']['ids_complete']=false;
$r=$eval->invoke($provider,$expected,$e);need($r['verdict']==='INCOMPLETE_EVIDENCE'&&$r['classification']==='OBSERVATION_GAP','non-authoritative count observation gap');
$e=$base;$e['rendered']['result_count']=49;
$r=$eval->invoke($provider,$expected,$e);need($r['verdict']==='FAIL'&&in_array('browser_result_count_divergence',$r['defect_reasons'],true),'trusted count mismatch defect');

$ids250=range(1,250);$identity250=$ids250;sort($identity250,SORT_NUMERIC);
$expectedDigest=$expected;$expectedDigest['expected']=['result_total'=>250,'proof_mode'=>'full_digest','proof_item_count'=>250,'ids'=>[],'identity_digest'=>$dig($identity250),'order_digest'=>$dig($ids250)];
$e=$base;$e['rendered']=['ids'=>array_slice($ids250,0,100),'result_count'=>250,'result_count_authoritative'=>true,'result_count_source'=>'jet_smart_filters_results_count'];
$r=$eval->invoke($provider,$expectedDigest,$e);need($r['verdict']==='INCOMPLETE_EVIDENCE','missing digest observation gap');
$e['rendered']['digest_authoritative']=true;$e['rendered']['proof_item_count']=250;$e['rendered']['identity_digest']=$dig($identity250);$e['rendered']['order_digest']=$dig($ids250);
$r=$eval->invoke($provider,$expectedDigest,$e);need($r['verdict']==='PASS','digest pass');
$e['rendered']['order_digest']=str_repeat('a',64);
$r=$eval->invoke($provider,$expectedDigest,$e);need($r['verdict']==='FAIL'&&in_array('browser_dataset_order_divergence',$r['defect_reasons'],true),'trusted digest mismatch defect');

$plan=['plan_digest'=>'p','plan_signature'=>'s','origin'=>'https://example.test/','build_identity'=>['git_sha'=>'g','tree_sha'=>'t']];
$env=['contract'=>BrowserAcceptanceProvider::EVIDENCE_CONTRACT,'plan_digest'=>'p','plan_signature'=>'s','origin'=>'https://example.test/','build_identity'=>['git_sha'=>'g','tree_sha'=>'t'],'observer'=>['contract'=>BrowserAcceptanceProvider::OBSERVER_CONTRACT,'javascript_runtime'=>true,'browser_engine'=>'chromium','execution_mode'=>'managed_browser_agent'],'cases'=>[],'unexpected'=>1];
$reasons=$validate->invoke($provider,$env,$plan);need(in_array('browser_evidence_unknown_fields',$reasons,true),'unknown envelope rejected');
unset($env['unexpected']);$reasons=$validate->invoke($provider,$env,$plan);need(!$reasons,'managed mode accepted');

echo "BROWSER_ACCEPTANCE_MATRIX=PASS\n";
}
