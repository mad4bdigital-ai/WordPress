<?php
declare(strict_types=1);

namespace ETG\DynamicFilterSEOBridge\Acceptance {
    final class LiveAcceptanceProvider {
        private $planFixture; private $runFixture;
        public function __construct(array $planFixture,array $runFixture){$this->planFixture=$planFixture;$this->runFixture=$runFixture;}
        public function plan(array $request):array{unset($request);return $this->planFixture;}
        public function run(array $request):array{unset($request);return $this->runFixture;}
    }
}

namespace {
    function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value));}
    function home_url($path='/'){return 'https://staging.egypttourgates.com'.('/'===substr($path,0,1)?$path:'/'.$path);}
    function wp_salt($scheme='auth'){return 'browser-acceptance-test-secret-'.$scheme;}
    function add_filter($hook,$callback,$priority=10,$acceptedArgs=1){unset($priority,$acceptedArgs);$GLOBALS['etg_browser_acceptance_filters'][$hook][]=$callback;return true;}
    function etg_browser_expect($condition,string $message):void{if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
    function etg_browser_same($expected,$actual,string $message):void{if($expected!==$actual){fwrite(STDERR,"FAIL: $message\nEXPECTED ".var_export($expected,true)."\nACTUAL ".var_export($actual,true)."\n");exit(1);}}
    function etg_browser_digest(array $ids):string{return hash('sha256',json_encode(array_values($ids),JSON_UNESCAPED_SLASHES));}
    function etg_browser_challenge_signature(string $profileId,string $planDigest,array $challenge):string{
        $message=implode('|',array(
            'browser_challenge',
            'etg.dfsb.browser-acceptance-challenge.v1',
            'etg-dfsb',
            $profileId,
            $planDigest,
            (string)$challenge['nonce'],
            (string)$challenge['issued_at'],
            (string)$challenge['expires_at'],
        ));
        return hash_hmac('sha256',$message,wp_salt('auth'));
    }

    $root=dirname(__DIR__);
    require_once $root.'/includes/Acceptance/BrowserAcceptanceProvider.php';
    require_once $root.'/includes/Acceptance/BrowserAcceptanceFreshnessGuard.php';
    $planCases=array(
        array('case_id'=>'case-luxor','archive_path'=>'/tours-and-activities/','provider'=>'jet-engine','query_id'=>'tours_query_archive','taxonomy'=>'location_jet','term_id'=>125,'term_slug'=>'luxor'),
        array('case_id'=>'case-day-tours','archive_path'=>'/tours-and-activities/','provider'=>'jet-engine','query_id'=>'tours_query_archive','taxonomy'=>'tour-types_jet','term_id'=>192,'term_slug'=>'day-tours'),
    );
    $semanticPlan=array('contract'=>'etg.dfsb.live-acceptance-plan.v1','state'=>'ready','profile_id'=>'tours','suite'=>'semantic','cases'=>$planCases,'case_count'=>2,'plan_digest'=>str_repeat('a',64),'blocking_reasons'=>array());
    $luxorIds=array(31,32,33);$dayTourIds=array(41,42);
    $semanticRun=array(
        'contract'=>'etg.dfsb.live-acceptance-provider.v1','provider_id'=>'etg-dfsb','profile_id'=>'tours','suite'=>'semantic','plan_digest'=>str_repeat('a',64),
        'verification'=>array('semantic_parity_verified'=>true,'browser_runtime_parity_verified'=>false,'verified_through'=>'live_server_semantic'),
        'cases'=>array(
            array('contract'=>'etg.dfsb.semantic-acceptance-case.v1','case_id'=>'case-luxor','taxonomy'=>'location_jet','term_id'=>125,'term_slug'=>'luxor','provider'=>'jet-engine','query_id'=>'tours_query_archive','provider_total'=>3,'direct_dataset'=>array('ids'=>$luxorIds,'ids_complete'=>true,'proof_complete'=>true,'proof_mode'=>'full_ids','proof_item_count'=>3,'total'=>3,'identity_digest'=>etg_browser_digest($luxorIds),'order_digest'=>etg_browser_digest($luxorIds)),'verdict'=>'PASS'),
            array('contract'=>'etg.dfsb.semantic-acceptance-case.v1','case_id'=>'case-day-tours','taxonomy'=>'tour-types_jet','term_id'=>192,'term_slug'=>'day-tours','provider'=>'jet-engine','query_id'=>'tours_query_archive','provider_total'=>2,'direct_dataset'=>array('ids'=>$dayTourIds,'ids_complete'=>true,'proof_complete'=>true,'proof_mode'=>'full_ids','proof_item_count'=>2,'total'=>2,'identity_digest'=>etg_browser_digest($dayTourIds),'order_digest'=>etg_browser_digest($dayTourIds)),'verdict'=>'PASS'),
        ),
        'blocking_reasons'=>array(),'defect_reasons'=>array(),'infrastructure_failures'=>array(),'classification'=>'NO_CONFIRMED_DEFECT','verdict'=>'PASS',
    );

    $semantic=new \ETG\DynamicFilterSEOBridge\Acceptance\LiveAcceptanceProvider($semanticPlan,$semanticRun);
    $provider=new \ETG\DynamicFilterSEOBridge\Acceptance\BrowserAcceptanceProvider($semantic,static function():array{return array('valid'=>true,'git_sha'=>'4e66cf2cdebc55626e77572ef57a1329bf89c86c','tree_sha'=>'5a646369a63f01efe2ce6dda1f8fc4e15bd07760');});
    $provider->register();
    \ETG\DynamicFilterSEOBridge\Acceptance\BrowserAcceptanceFreshnessGuard::register();
    etg_browser_expect(isset($GLOBALS['etg_browser_acceptance_filters']['mad4b_browser_acceptance_providers']),'central browser acceptance provider hook registered');
    etg_browser_expect(isset($GLOBALS['etg_browser_acceptance_filters']['etg_dfsb_browser_acceptance_provider']),'native browser acceptance provider hook registered');

    $descriptor=$provider->descriptor();
    etg_browser_same('etg.dfsb.browser-acceptance-provider.v2',$descriptor['contract'],'provider contract is versioned');
    etg_browser_same(false,$descriptor['authorizing'],'browser acceptance remains non-authorizing');
    etg_browser_same(true,$descriptor['external_browser_agent_required'],'provider cannot self-certify a browser runtime');
    etg_browser_same(false,$descriptor['arbitrary_javascript_input'],'arbitrary JavaScript remains denied');

    $providers=$provider->registerCentralProvider(array());
    $providers=\ETG\DynamicFilterSEOBridge\Acceptance\BrowserAcceptanceFreshnessGuard::decorateProviders($providers);
    etg_browser_expect(isset($providers['etg-dfsb']),'freshness guard preserves ETG provider registration');
    $guarded=$providers['etg-dfsb'];

    $capabilities=call_user_func($guarded['capabilities_callback']);
    etg_browser_same('mad4b.browser-acceptance-capabilities.v1',$capabilities['contract'],'central capability contract is exposed');
    foreach(array('browser.ajax_round_trip','browser.event_stream','browser.dom_result_count','browser.dataset_id_parity','browser.dataset_digest_parity','browser.order_parity','browser.url_state','browser.seo_non_authority','browser.reset_behavior','browser.performance_baseline')as$capability){
        etg_browser_expect(in_array($capability,$capabilities['capabilities'],true),'capability missing: '.$capability);
    }
    etg_browser_same('etg.dfsb.browser-acceptance-challenge.v1',$capabilities['freshness_challenge']['contract'],'freshness challenge contract is advertised');
    etg_browser_same(900,$capabilities['freshness_challenge']['ttl_seconds'],'freshness challenge TTL is bounded');
    etg_browser_same(false,$capabilities['freshness_challenge']['authorizing'],'freshness challenge creates no authority');
    etg_browser_same(false,$capabilities['freshness_challenge']['persistent_mutation'],'freshness guard remains stateless');

    $plan=call_user_func($guarded['plan_callback'],array('profile_id'=>'tours','suite'=>'browser_runtime'));
    etg_browser_same('ready',$plan['state'],'browser plan is derived only after semantic PASS');
    etg_browser_same(2,$plan['case_count'],'browser cases mirror governed semantic cases');
    etg_browser_same('/tours-and-activities/',$plan['cases'][0]['archive_path'],'browser route is profile governed');
    etg_browser_same(array(31,32,33),$plan['cases'][0]['expected']['ids'],'canonical semantic IDs are embedded in browser plan');
    etg_browser_same(3,$plan['cases'][0]['expected']['result_total'],'canonical semantic total is embedded in browser plan');
    etg_browser_expect(64===strlen($plan['plan_digest']),'browser plan digest is bounded');
    etg_browser_expect(64===strlen($plan['plan_signature']),'browser plan is HMAC bound to this WordPress authority');
    etg_browser_same('external_browser_agent',$plan['execution']['mode'],'WordPress does not pretend to own a browser engine');
    etg_browser_same(false,$plan['authority']['authorizing'],'plan creates no authority');
    etg_browser_same('etg.dfsb.browser-acceptance-challenge.v1',$plan['challenge']['contract'],'ready plan carries freshness challenge');
    etg_browser_expect((bool)preg_match('/^[a-f0-9]{32}$/',$plan['challenge']['nonce']),'challenge nonce is 128-bit hex');
    etg_browser_expect((bool)preg_match('/^[a-f0-9]{64}$/',$plan['challenge']['signature']),'challenge is server HMAC signed');
    etg_browser_expect($plan['challenge']['expires_at']>$plan['challenge']['issued_at'],'challenge expiry follows issue time');
    etg_browser_expect(($plan['challenge']['expires_at']-$plan['challenge']['issued_at'])<=900,'challenge lifetime cannot exceed canonical TTL');

    $missing=call_user_func($guarded['result_callback'],array('profile_id'=>'tours','suite'=>'browser_runtime','plan_digest'=>$plan['plan_digest'],'plan_signature'=>$plan['plan_signature']));
    etg_browser_same('INCOMPLETE_EVIDENCE',$missing['verdict'],'missing external browser evidence remains observation gap');
    etg_browser_same(array('browser_runtime_not_observed'),$missing['incomplete_evidence'],'canonical browser gap is explicit');
    etg_browser_same(false,$missing['verification']['browser_runtime_parity_verified'],'missing evidence cannot self-certify browser parity');

    $stale=$provider->result(array('profile_id'=>'tours','suite'=>'browser_runtime','plan_digest'=>str_repeat('0',64),'plan_signature'=>$plan['plan_signature'],'evidence'=>array('x'=>1)));
    etg_browser_same('BLOCKED',$stale['verdict'],'stale browser plan digest fails closed at canonical provider');
    etg_browser_same('TEST_INFRASTRUCTURE_FAILURE',$stale['classification'],'stale plan digest is an infrastructure failure, not Tours defect');
    etg_browser_expect(in_array('browser_plan_digest_mismatch',$stale['infrastructure_failures'],true),'stale plan reason is explicit');

    $evidence=array(
        'contract'=>'etg.dfsb.browser-acceptance-evidence.v1',
        'plan_digest'=>$plan['plan_digest'],
        'plan_signature'=>$plan['plan_signature'],
        'origin'=>'https://staging.egypttourgates.com/',
        'build_identity'=>$plan['build_identity'],
        'observer'=>array('contract'=>'etg.dfsb.browser-acceptance-observer.v1','javascript_runtime'=>true,'browser_engine'=>'chromium','execution_mode'=>'external_browser_agent'),
        'challenge'=>$plan['challenge'],
        'cases'=>array(),
    );
    foreach($plan['cases']as$case){
        $evidence['cases'][]=array(
            'case_id'=>$case['case_id'],
            'challenge_nonce'=>$plan['challenge']['nonce'],
            'runtime'=>array('javascript_runtime'=>true,'jet_smart_filters_observed'=>true,'filter_group'=>$case['provider'].'/'.$case['query_id']),
            'events'=>array('ajax_filters_updated'=>true,'presentation_updated'=>true,'presentation_reset'=>true),
            'network'=>array('method'=>'POST','endpoint'=>'https://staging.egypttourgates.com/wp-json/etg-dfsb/v1/ajax-presentation','http_status'=>200,'contract'=>'etg.dfsb.ajax-presentation.v1','status'=>'ready','authorizing'=>false,'url_authority'=>false,'seo_mutation'=>false,'provider'=>$case['provider'],'query_id'=>$case['query_id']),
            'performance'=>array('ttfb_ms'=>120.0,'ajax_endpoint_latency_ms'=>75.0,'filter_to_presentation_ms'=>95.0),
            'rendered'=>array('result_count'=>$case['expected']['result_total'],'result_count_authoritative'=>true,'result_count_source'=>'jet_smart_filters_results_count','ids'=>$case['expected']['ids'],'ids_complete'=>true),
            'url_state'=>array('filter_state_observed'=>true,'etg_history_mutation'=>false),
            'seo'=>array('canonical_unchanged'=>true,'robots_unchanged'=>true,'hreflang_unchanged'=>true,'rank_math_unchanged'=>true),
            'reset'=>array('event_observed'=>true,'neutral_state_restored'=>true),
        );
    }

    $missingChallenge=$evidence;unset($missingChallenge['challenge']);
    $missingChallengeResult=call_user_func($guarded['result_callback'],array('profile_id'=>'tours','suite'=>'browser_runtime','plan_digest'=>$plan['plan_digest'],'plan_signature'=>$plan['plan_signature'],'evidence'=>$missingChallenge));
    etg_browser_same('BLOCKED',$missingChallengeResult['verdict'],'observed evidence without freshness challenge fails closed');
    etg_browser_same('TEST_INFRASTRUCTURE_FAILURE',$missingChallengeResult['classification'],'missing freshness challenge is infrastructure failure');
    etg_browser_expect(in_array('browser_challenge_required',$missingChallengeResult['infrastructure_failures'],true),'missing challenge reason is explicit');

    $wrongNonce=$evidence;$wrongNonce['cases'][0]['challenge_nonce']=str_repeat('0',32);
    $wrongNonceResult=call_user_func($guarded['result_callback'],array('profile_id'=>'tours','suite'=>'browser_runtime','plan_digest'=>$plan['plan_digest'],'plan_signature'=>$plan['plan_signature'],'evidence'=>$wrongNonce));
    etg_browser_same('BLOCKED',$wrongNonceResult['verdict'],'case evidence cannot detach from freshness nonce');
    etg_browser_expect(in_array('browser_challenge_nonce_mismatch',$wrongNonceResult['infrastructure_failures'],true),'nonce mismatch reason is explicit');

    $expired=$evidence;
    $expired['challenge']['issued_at']=time()-901;
    $expired['challenge']['expires_at']=time()-1;
    $expired['challenge']['signature']=etg_browser_challenge_signature('tours',$plan['plan_digest'],$expired['challenge']);
    foreach($expired['cases']as&$case){$case['challenge_nonce']=$expired['challenge']['nonce'];}unset($case);
    $expiredResult=call_user_func($guarded['result_callback'],array('profile_id'=>'tours','suite'=>'browser_runtime','plan_digest'=>$plan['plan_digest'],'plan_signature'=>$plan['plan_signature'],'evidence'=>$expired));
    etg_browser_same('BLOCKED',$expiredResult['verdict'],'cryptographically valid but expired evidence challenge cannot certify runtime');
    etg_browser_same('TEST_INFRASTRUCTURE_FAILURE',$expiredResult['classification'],'expired challenge is infrastructure freshness failure');
    etg_browser_expect(in_array('browser_challenge_expired',$expiredResult['infrastructure_failures'],true),'expired challenge reason is explicit');

    $pass=call_user_func($guarded['result_callback'],array('profile_id'=>'tours','suite'=>'browser_runtime','plan_digest'=>$plan['plan_digest'],'plan_signature'=>$plan['plan_signature'],'evidence'=>$evidence));
    etg_browser_same('PASS',$pass['verdict'],'complete fresh live-browser evidence closes browser acceptance');
    etg_browser_same('NO_CONFIRMED_DEFECT',$pass['classification'],'clean browser evidence has no confirmed defect');
    etg_browser_same(true,$pass['verification']['browser_runtime_parity_verified'],'browser parity becomes true only after fresh external evidence');
    etg_browser_same('live_browser_runtime',$pass['verification']['verified_through'],'verification level is promoted only after browser PASS');
    foreach($pass['tests']as$name=>$status)etg_browser_same('PASS',$status,'browser test must pass: '.$name);
    etg_browser_same(false,$pass['receipt_authorizing'],'signed evidence receipt remains non-authorizing');
    etg_browser_expect(64===strlen($pass['evidence_digest']),'browser evidence receives canonical digest');
    etg_browser_expect(64===strlen($pass['receipt_signature']),'browser evidence receives server HMAC receipt');

    $wrongBuild=$evidence;$wrongBuild['build_identity']['git_sha']='stale-build';
    $wrongBuildResult=call_user_func($guarded['result_callback'],array('profile_id'=>'tours','suite'=>'browser_runtime','plan_digest'=>$plan['plan_digest'],'plan_signature'=>$plan['plan_signature'],'evidence'=>$wrongBuild));
    etg_browser_same('BLOCKED',$wrongBuildResult['verdict'],'browser evidence from another build cannot certify this plan');
    etg_browser_expect(in_array('browser_evidence_build_identity_mismatch',$wrongBuildResult['infrastructure_failures'],true),'exact-build evidence binding is enforced');

    $badNetwork=$evidence;$badNetwork['cases'][0]['network']['authorizing']=true;
    $badNetworkResult=call_user_func($guarded['result_callback'],array('profile_id'=>'tours','suite'=>'browser_runtime','plan_digest'=>$plan['plan_digest'],'plan_signature'=>$plan['plan_signature'],'evidence'=>$badNetwork));
    etg_browser_same('BLOCKED',$badNetworkResult['verdict'],'invalid browser transport evidence blocks certification');
    etg_browser_same('TEST_INFRASTRUCTURE_FAILURE',$badNetworkResult['classification'],'invalid round-trip envelope is not mislabeled as product parity defect');

    $diverged=$evidence;$diverged['cases'][0]['rendered']['result_count']=2;$diverged['cases'][0]['rendered']['ids']=array(31,99);
    $divergedResult=call_user_func($guarded['result_callback'],array('profile_id'=>'tours','suite'=>'browser_runtime','plan_digest'=>$plan['plan_digest'],'plan_signature'=>$plan['plan_signature'],'evidence'=>$diverged));
    etg_browser_same('FAIL',$divergedResult['verdict'],'trusted fresh browser DOM divergence is a product defect');
    etg_browser_same('PRODUCT_DEFECT',$divergedResult['classification'],'trusted DOM mismatch is not an infrastructure failure');
    etg_browser_expect(false!==strpos(implode('|',$divergedResult['defect_reasons']),'browser_result_count_divergence'),'result count divergence is surfaced');
    etg_browser_expect(false!==strpos(implode('|',$divergedResult['defect_reasons']),'browser_dataset_identity_divergence'),'dataset identity divergence is surfaced');

    $observerSource=file_get_contents($root.'/assets/js/browser-acceptance-observer.js');
    etg_browser_expect(false!==strpos($observerSource,'function arm(planCase, challenge)'),'observer arm requires explicit plan challenge');
    etg_browser_expect(false!==strpos($observerSource,'invalid_freshness_challenge'),'observer rejects missing/malformed challenge');
    etg_browser_expect(false!==strpos($observerSource,'challenge_nonce: boundedString(currentChallengeNonce, 32)'),'observer snapshot carries bounded challenge nonce');

    echo "Alpha13 browser acceptance provider smoke tests passed.\n";
}
