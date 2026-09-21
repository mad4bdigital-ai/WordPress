<?php
namespace ETG\DynamicFilterSEOBridge\Acceptance;

final class BrowserAcceptanceProvider {
    const CONTRACT = 'etg.dfsb.browser-acceptance-provider.v2';
    const PROVIDER_ID = 'etg-dfsb';
    const CAPABILITIES_CONTRACT = 'mad4b.browser-acceptance-capabilities.v1';
    const PLAN_CONTRACT = 'mad4b.browser-acceptance-plan.v1';
    const RESULT_CONTRACT = 'mad4b.browser-acceptance-result.v1';
    const EVIDENCE_CONTRACT = 'etg.dfsb.browser-acceptance-evidence.v1';
    const OBSERVER_CONTRACT = 'etg.dfsb.browser-acceptance-observer.v1';
    const CASE_CONTRACT = 'etg.dfsb.browser-acceptance-case.v2';
    const MAX_CASES = 8;
    const MAX_IDS = 100;
    const MAX_DIGEST_IDS = 5000;
    const MAX_EVIDENCE_BYTES = 2097152;

    private $semanticProvider;
    private $buildIdentityProvider;

    public function __construct(LiveAcceptanceProvider $semanticProvider, callable $buildIdentityProvider) {
        $this->semanticProvider = $semanticProvider;
        $this->buildIdentityProvider = $buildIdentityProvider;
    }

    public function register(): void {
        if (!function_exists('add_filter')) return;
        add_filter('mad4b_browser_acceptance_providers', array($this, 'registerCentralProvider'), 10, 1);
        add_filter('etg_dfsb_browser_acceptance_provider', array($this, 'exposeNativeProvider'), 10, 1);
    }

    public function registerCentralProvider($providers): array {
        $providers = is_array($providers) ? $providers : array();
        $providers[self::PROVIDER_ID] = array(
            'provider_id'=>self::PROVIDER_ID,
            'contract'=>self::CONTRACT,
            'read_only'=>true,
            'authorizing'=>false,
            'execution_mode'=>'external_browser_agent',
            'transport_owned_by_provider'=>false,
            'descriptor_callback'=>array($this,'descriptor'),
            'capabilities_callback'=>array($this,'capabilities'),
            'plan_callback'=>array($this,'plan'),
            'result_callback'=>array($this,'result'),
        );
        return $providers;
    }

    public function exposeNativeProvider($provider=null) { unset($provider); return $this; }

    public function descriptor(): array {
        return array(
            'contract'=>self::CONTRACT,
            'provider_id'=>self::PROVIDER_ID,
            'read_only'=>true,
            'authorizing'=>false,
            'execution_mode'=>'external_browser_agent',
            'supported_execution_modes'=>$this->supportedExecutionModes(),
            'transport_owned_by_provider'=>false,
            'browser_engine_owned_by_provider'=>false,
            'external_browser_agent_required'=>true,
            'arbitrary_url_input'=>false,
            'arbitrary_javascript_input'=>false,
            'business_state_mutation'=>false,
            'profile_mutation'=>false,
            'seo_mutation'=>false,
            'production_activation'=>false,
            'max_cases'=>self::MAX_CASES,
            'max_ids'=>self::MAX_IDS,
            'max_digest_ids'=>self::MAX_DIGEST_IDS,
            'max_evidence_bytes'=>self::MAX_EVIDENCE_BYTES,
        );
    }

    public function capabilities(): array {
        return array(
            'contract'=>self::CAPABILITIES_CONTRACT,
            'provider_contract'=>self::CONTRACT,
            'provider_id'=>self::PROVIDER_ID,
            'authorizing'=>false,
            'read_only'=>true,
            'execution_mode'=>'external_browser_agent',
            'supported_execution_modes'=>$this->supportedExecutionModes(),
            'external_browser_agent_required'=>true,
            'transport_authentication_required'=>true,
            'plan_signature'=>'wordpress_auth_salt_hmac_sha256',
            'evidence_receipt_signature'=>'wordpress_auth_salt_hmac_sha256',
            'capabilities'=>array(
                'browser.ajax_round_trip',
                'browser.event_stream',
                'browser.dom_result_count',
                'browser.dataset_id_parity',
                'browser.dataset_digest_parity',
                'browser.order_parity',
                'browser.url_state',
                'browser.seo_non_authority',
                'browser.reset_behavior',
                'browser.performance_baseline',
            ),
            'required_events'=>array('ajaxFilters/updated','etg-dfsb/ajax-presentation-updated','etg-dfsb/ajax-presentation-reset'),
            'required_network'=>array('POST /wp-json/etg-dfsb/v1/ajax-presentation'),
            'required_head_state'=>array('canonical','robots','hreflang','rank_math'),
        );
    }

    public function plan(array $request): array {
        $validation = $this->validatePlanRequest($request);
        if ($validation['blocking_reasons']) return $this->blockedPlan($validation['profile_id'], $validation['blocking_reasons']);

        $profileId = $validation['profile_id'];
        $semanticPlan = $this->semanticProvider->plan(array('profile_id'=>$profileId,'suite'=>'semantic'));
        if ('ready' !== (string)($semanticPlan['state'] ?? '')) return $this->blockedPlan($profileId, array_merge(array('semantic_acceptance_plan_not_ready'), (array)($semanticPlan['blocking_reasons'] ?? array())));
        $semantic = $this->semanticProvider->run(array('profile_id'=>$profileId,'suite'=>'semantic'));
        if ('PASS' !== (string)($semantic['verdict'] ?? '') || empty($semantic['verification']['semantic_parity_verified'])) {
            $reasons = array_merge(array('semantic_acceptance_not_ready'), (array)($semantic['blocking_reasons'] ?? array()), (array)($semantic['defect_reasons'] ?? array()), (array)($semantic['infrastructure_failures'] ?? array()));
            return $this->blockedPlan($profileId, $reasons);
        }

        $identity = $this->buildIdentity();
        if (empty($identity['valid']) || empty($identity['git_sha']) || empty($identity['tree_sha'])) return $this->blockedPlan($profileId, array('exact_build_identity_unavailable'));
        $origin = $this->origin();
        if ('' === $origin) return $this->blockedPlan($profileId, array('origin_unavailable'));

        $cases = array();
        foreach ((array)($semantic['cases'] ?? array()) as $case) {
            if (count($cases) >= self::MAX_CASES) break;
            if ('PASS' !== (string)($case['verdict'] ?? '')) continue;
            $dataset = (array)($case['direct_dataset'] ?? array());
            if (empty($dataset['proof_complete'])) return $this->blockedPlan($profileId, array('semantic_dataset_proof_incomplete'));
            $proofMode = (string)($dataset['proof_mode'] ?? '');
            if (!in_array($proofMode, array('full_ids','full_digest'), true)) return $this->blockedPlan($profileId, array('semantic_dataset_proof_mode_invalid'));
            $total = isset($case['provider_total']) ? (int)$case['provider_total'] : (int)($dataset['total'] ?? 0);
            $proofCount = (int)($dataset['proof_item_count'] ?? 0);
            if ($total < 0 || $total > self::MAX_DIGEST_IDS || $total !== $proofCount) return $this->blockedPlan($profileId, array('semantic_dataset_total_mismatch'));
            $identityDigest = strtolower((string)($dataset['identity_digest'] ?? ''));
            $orderDigest = strtolower((string)($dataset['order_digest'] ?? ''));
            if (!$this->validDigest($identityDigest) || !$this->validDigest($orderDigest)) return $this->blockedPlan($profileId, array('semantic_dataset_digest_invalid'));
            $ids = 'full_ids' === $proofMode ? $this->normalizeIds((array)($dataset['ids'] ?? array()), self::MAX_IDS) : array();
            if ('full_ids' === $proofMode && $total !== count($ids)) return $this->blockedPlan($profileId, array('semantic_dataset_ids_incomplete'));

            $cases[] = array(
                'case_id'=>(string)($case['case_id'] ?? ''),
                'archive_path'=>$this->caseArchivePath($semanticPlan, $case),
                'provider'=>(string)($case['provider'] ?? ''),
                'query_id'=>(string)($case['query_id'] ?? ''),
                'taxonomy'=>(string)($case['taxonomy'] ?? ''),
                'term_id'=>(int)($case['term_id'] ?? 0),
                'term_slug'=>(string)($case['term_slug'] ?? ''),
                'expected'=>array(
                    'result_total'=>$total,
                    'proof_mode'=>$proofMode,
                    'proof_item_count'=>$proofCount,
                    'ids'=>$ids,
                    'identity_digest'=>$identityDigest,
                    'order_digest'=>$orderDigest,
                    'order_sensitive'=>true,
                ),
            );
        }
        if (!$cases) return $this->blockedPlan($profileId, array('browser_cases_unavailable'));

        $core = array(
            'provider_id'=>self::PROVIDER_ID,
            'profile_id'=>$profileId,
            'suite'=>'browser_runtime',
            'origin'=>$origin,
            'build_identity'=>array('git_sha'=>(string)$identity['git_sha'],'tree_sha'=>(string)$identity['tree_sha']),
            'semantic_plan_digest'=>(string)($semantic['plan_digest'] ?? ''),
            'cases'=>$cases,
            'case_count'=>count($cases),
            'authority'=>$this->authority(),
            'execution'=>array(
                'mode'=>'external_browser_agent',
                'supported_modes'=>$this->supportedExecutionModes(),
                'arbitrary_url_input'=>false,
                'arbitrary_javascript_input'=>false,
                'observer_contract'=>self::OBSERVER_CONTRACT,
                'evidence_contract'=>self::EVIDENCE_CONTRACT,
                'ajax_endpoint_path'=>'/wp-json/etg-dfsb/v1/ajax-presentation',
                'max_evidence_bytes'=>self::MAX_EVIDENCE_BYTES,
            ),
        );
        $digest = hash('sha256', $this->canonicalJson($core));
        $signature = $this->sign('plan|'.$digest);
        if ('' === $signature) return $this->blockedPlan($profileId, array('browser_plan_signing_unavailable'));

        return array_merge(array('contract'=>self::PLAN_CONTRACT,'provider_contract'=>self::CONTRACT,'state'=>'ready'), $core, array(
            'plan_digest'=>$digest,
            'plan_signature'=>$signature,
            'blocking_reasons'=>array(),
        ));
    }

    public function result(array $request): array {
        $profileId = $this->cleanKey($request['profile_id'] ?? '');
        $unknown = array_diff(array_keys($request), array('profile_id','suite','plan_digest','plan_signature','evidence'));
        if ($unknown) return $this->resultBlocked($profileId, array('unsupported_result_fields'), array());
        if ('' === $profileId) return $this->resultBlocked('', array('profile_id_required'), array());
        $suite = $this->cleanKey($request['suite'] ?? 'browser_runtime');
        if ('browser_runtime' !== $suite && 'browser' !== $suite) return $this->resultBlocked($profileId, array('unsupported_suite'), array());

        $plan = $this->plan(array('profile_id'=>$profileId,'suite'=>'browser_runtime'));
        if ('ready' !== (string)($plan['state'] ?? '')) return $this->resultBlocked($profileId, (array)($plan['blocking_reasons'] ?? array('browser_plan_blocked')), array());

        $digest = (string)($request['plan_digest'] ?? '');
        $signature = (string)($request['plan_signature'] ?? '');
        if ('' === $digest || !hash_equals((string)$plan['plan_digest'], $digest)) return $this->resultInfrastructure($profileId, $plan, array('browser_plan_digest_mismatch'));
        if ('' === $signature || !hash_equals((string)$plan['plan_signature'], $signature) || !$this->verifySignature('plan|'.$digest, $signature)) return $this->resultInfrastructure($profileId, $plan, array('browser_plan_signature_invalid'));

        $evidence = $request['evidence'] ?? null;
        if (!is_array($evidence) || !$evidence) return $this->resultIncomplete($profileId, $plan, array('browser_runtime_not_observed'));
        $encodedEvidence = $this->canonicalJson($evidence);
        if (strlen($encodedEvidence) > self::MAX_EVIDENCE_BYTES) return $this->resultInfrastructure($profileId, $plan, array('browser_evidence_size_limit_exceeded'));
        $envelopeReasons = $this->validateEvidenceEnvelope($evidence, $plan);
        if ($envelopeReasons) return $this->resultInfrastructure($profileId, $plan, $envelopeReasons);

        $evidenceCases = array();
        foreach ((array)($evidence['cases'] ?? array()) as $case) {
            if (!is_array($case)) continue;
            $caseId = (string)($case['case_id'] ?? '');
            if ('' !== $caseId) $evidenceCases[$caseId] = $case;
        }
        $caseResults = array(); $infra = array(); $defects = array(); $incomplete = array();
        foreach ((array)$plan['cases'] as $expected) {
            $caseId = (string)$expected['case_id'];
            if (!isset($evidenceCases[$caseId])) {
                $incomplete[] = $caseId.':browser_case_evidence_missing';
                $caseResults[] = $this->caseIncomplete($expected, array('browser_case_evidence_missing'));
                continue;
            }
            $evaluated = $this->evaluateCase($expected, $evidenceCases[$caseId]);
            $caseResults[] = $evaluated;
            foreach ((array)$evaluated['infrastructure_failures'] as $reason) $infra[] = $caseId.':'.$reason;
            foreach ((array)$evaluated['defect_reasons'] as $reason) $defects[] = $caseId.':'.$reason;
            foreach ((array)$evaluated['incomplete_evidence'] as $reason) $incomplete[] = $caseId.':'.$reason;
        }
        if (count($evidenceCases) > count((array)$plan['cases'])) $infra[] = 'browser_case_set_mismatch';
        $infra = array_values(array_unique($infra));
        $defects = array_values(array_unique($defects));
        $incomplete = array_values(array_unique($incomplete));
        $tests = $this->reduceTests($caseResults);

        if ($infra) { $verdict='BLOCKED'; $classification='TEST_INFRASTRUCTURE_FAILURE'; }
        elseif ($defects) { $verdict='FAIL'; $classification='PRODUCT_DEFECT'; }
        elseif ($incomplete) { $verdict='INCOMPLETE_EVIDENCE'; $classification='OBSERVATION_GAP'; }
        else { $verdict='PASS'; $classification='NO_CONFIRMED_DEFECT'; }

        $evidenceDigest = hash('sha256', $this->canonicalJson($this->canonicalEvidence($evidence)));
        $receiptSignature = $this->sign('receipt|'.$plan['plan_digest'].'|'.$evidenceDigest.'|'.$verdict);
        return array(
            'contract'=>self::RESULT_CONTRACT,
            'provider_contract'=>self::CONTRACT,
            'provider_id'=>self::PROVIDER_ID,
            'profile_id'=>$profileId,
            'suite'=>'browser_runtime',
            'plan_digest'=>(string)$plan['plan_digest'],
            'evidence_digest'=>$evidenceDigest,
            'receipt_signature'=>$receiptSignature,
            'receipt_authorizing'=>false,
            'verification'=>array(
                'semantic_parity_verified'=>true,
                'browser_runtime_parity_verified'=>'PASS'===$verdict,
                'verified_through'=>'PASS'===$verdict?'live_browser_runtime':'live_server_semantic',
            ),
            'tests'=>$tests,
            'cases'=>$caseResults,
            'case_count'=>count($caseResults),
            'authority'=>$this->authority(),
            'blocking_reasons'=>array(),
            'incomplete_evidence'=>$incomplete,
            'defect_reasons'=>$defects,
            'infrastructure_failures'=>$infra,
            'classification'=>$classification,
            'verdict'=>$verdict,
            'browser_runtime'=>'PASS'===$verdict?'PASS':('FAIL'===$verdict?'FAIL':('INCOMPLETE_EVIDENCE'===$verdict?'INCOMPLETE_EVIDENCE':'BLOCKED')),
        );
    }

    private function evaluateCase(array $expected, array $evidence): array {
        $infra=array(); $defects=array(); $incomplete=array(); $tests=array();
        if ((string)($evidence['case_id'] ?? '') !== (string)$expected['case_id']) $infra[]='case_id_mismatch';
        $runtime=(array)($evidence['runtime'] ?? array());
        $group=(string)($runtime['filter_group'] ?? '');
        $expectedGroup=(string)$expected['provider'].'/'.(string)$expected['query_id'];
        if (empty($runtime['javascript_runtime']) || empty($runtime['jet_smart_filters_observed']) || $group !== $expectedGroup) $infra[]='browser_runtime_binding_unobserved';

        $events=(array)($evidence['events'] ?? array());
        $eventPass=!empty($events['ajax_filters_updated'])&&!empty($events['presentation_updated'])&&!empty($events['presentation_reset']);
        $tests['browser_event_stream']=$eventPass?'PASS':'BLOCKED';
        if(!$eventPass)$infra[]='browser_event_stream_incomplete';

        $network=(array)($evidence['network'] ?? array());
        $networkPass='POST'===strtoupper((string)($network['method']??''))
            &&$this->endpointMatches((string)($network['endpoint']??''))
            &&(int)($network['http_status']??0)>=200&&(int)($network['http_status']??0)<300
            &&'etg.dfsb.ajax-presentation.v1'===(string)($network['contract']??'')
            &&'ready'===(string)($network['status']??'')
            &&empty($network['authorizing'])&&empty($network['url_authority'])&&empty($network['seo_mutation'])
            &&(string)($network['provider']??'')===(string)$expected['provider']
            &&(string)($network['query_id']??'')===(string)$expected['query_id'];
        $tests['browser_ajax_round_trip']=$networkPass?'PASS':'BLOCKED';
        if(!$networkPass)$infra[]='browser_ajax_round_trip_invalid';

        $performance=(array)($evidence['performance']??array());
        $ttfb=isset($performance['ttfb_ms'])&&is_numeric($performance['ttfb_ms'])?(float)$performance['ttfb_ms']:-1;
        $ajaxLatency=isset($performance['ajax_endpoint_latency_ms'])&&is_numeric($performance['ajax_endpoint_latency_ms'])?(float)$performance['ajax_endpoint_latency_ms']:(isset($network['latency_ms'])&&is_numeric($network['latency_ms'])?(float)$network['latency_ms']:-1);
        $presentationLatency=isset($performance['filter_to_presentation_ms'])&&is_numeric($performance['filter_to_presentation_ms'])?(float)$performance['filter_to_presentation_ms']:-1;
        $performancePass=$ttfb>=0&&$ajaxLatency>=0&&$presentationLatency>=0;
        $tests['browser_performance_baseline']=$performancePass?'PASS':'INCOMPLETE_EVIDENCE';
        if(!$performancePass)$incomplete[]='browser_performance_baseline_missing';

        $rendered=(array)($evidence['rendered'] ?? array());
        $expectedProof=(array)$expected['expected'];
        $expectedTotal=(int)($expectedProof['result_total']??0);
        $proofMode=(string)($expectedProof['proof_mode']??'full_ids');
        $actualTotal=isset($rendered['result_count'])&&is_numeric($rendered['result_count'])?(int)$rendered['result_count']:-1;
        $countAuthoritative=!empty($rendered['result_count_authoritative']);
        if(!$countAuthoritative){
            $tests['browser_dom_result_count']='INCOMPLETE_EVIDENCE';
            $incomplete[]='browser_result_count_not_authoritative';
        } elseif($actualTotal!==$expectedTotal){
            $tests['browser_dom_result_count']='FAIL';
            $defects[]='browser_result_count_divergence';
        } else $tests['browser_dom_result_count']='PASS';

        $actualIds=$this->normalizeIds((array)($rendered['ids']??array()), self::MAX_IDS);
        $expectedIds=$this->normalizeIds((array)($expectedProof['ids']??array()), self::MAX_IDS);
        if('full_ids'===$proofMode){
            $idsComplete=!empty($rendered['ids_complete']) || ($countAuthoritative && $actualTotal>=0 && $actualTotal<=self::MAX_IDS && count($actualIds)===$actualTotal);
            if(!$idsComplete){
                $tests['browser_dataset_id_parity']='INCOMPLETE_EVIDENCE';
                $tests['browser_order_parity']='INCOMPLETE_EVIDENCE';
                $incomplete[]='browser_dataset_ids_partial';
            } else {
                $identityActual=$actualIds; $identityExpected=$expectedIds;
                sort($identityActual,SORT_NUMERIC); sort($identityExpected,SORT_NUMERIC);
                $idsPass=$identityActual===$identityExpected;
                $orderPass=$actualIds===$expectedIds;
                $tests['browser_dataset_id_parity']=$idsPass?'PASS':'FAIL';
                $tests['browser_order_parity']=$orderPass?'PASS':'FAIL';
                if(!$idsPass)$defects[]='browser_dataset_identity_divergence';
                if(!$orderPass)$defects[]='browser_dataset_order_divergence';
            }
        } else {
            $digestAuthoritative=!empty($rendered['digest_authoritative']);
            $actualProofCount=isset($rendered['proof_item_count'])&&is_numeric($rendered['proof_item_count'])?(int)$rendered['proof_item_count']:-1;
            $actualIdentity=strtolower((string)($rendered['identity_digest']??''));
            $actualOrder=strtolower((string)($rendered['order_digest']??''));
            if(!$digestAuthoritative || $actualProofCount!==$expectedTotal || !$this->validDigest($actualIdentity) || !$this->validDigest($actualOrder)){
                $tests['browser_dataset_id_parity']='INCOMPLETE_EVIDENCE';
                $tests['browser_order_parity']='INCOMPLETE_EVIDENCE';
                $incomplete[]='browser_dataset_digest_not_authoritative';
            } else {
                $idsPass=hash_equals((string)$expectedProof['identity_digest'],$actualIdentity);
                $orderPass=hash_equals((string)$expectedProof['order_digest'],$actualOrder);
                $tests['browser_dataset_id_parity']=$idsPass?'PASS':'FAIL';
                $tests['browser_order_parity']=$orderPass?'PASS':'FAIL';
                if(!$idsPass)$defects[]='browser_dataset_identity_divergence';
                if(!$orderPass)$defects[]='browser_dataset_order_divergence';
            }
        }

        $url=(array)($evidence['url_state']??array());
        $urlPass=!empty($url['filter_state_observed'])&&empty($url['etg_history_mutation']);
        $tests['browser_url_state']=$urlPass?'PASS':'FAIL';
        if(!$urlPass)$defects[]='browser_url_state_violation';

        $seo=(array)($evidence['seo']??array());
        $seoPass=!empty($seo['canonical_unchanged'])&&!empty($seo['robots_unchanged'])&&!empty($seo['hreflang_unchanged'])&&!empty($seo['rank_math_unchanged'])&&empty($network['seo_mutation'])&&empty($network['url_authority']);
        $tests['browser_seo_non_authority']=$seoPass?'PASS':'FAIL';
        if(!$seoPass)$defects[]='browser_seo_non_authority_violation';

        $reset=(array)($evidence['reset']??array());
        $resetPass=!empty($reset['event_observed'])&&!empty($reset['neutral_state_restored']);
        $tests['browser_reset_behavior']=$resetPass?'PASS':'FAIL';
        if(!$resetPass)$defects[]='browser_reset_behavior_divergence';

        $infra=array_values(array_unique($infra)); $defects=array_values(array_unique($defects)); $incomplete=array_values(array_unique($incomplete));
        if($infra){$verdict='BLOCKED';$classification='TEST_INFRASTRUCTURE_FAILURE';}
        elseif($defects){$verdict='FAIL';$classification='PRODUCT_DEFECT';}
        elseif($incomplete){$verdict='INCOMPLETE_EVIDENCE';$classification='OBSERVATION_GAP';}
        else{$verdict='PASS';$classification='NO_CONFIRMED_DEFECT';}
        return array(
            'contract'=>self::CASE_CONTRACT,
            'case_id'=>(string)$expected['case_id'],
            'provider'=>(string)$expected['provider'],
            'query_id'=>(string)$expected['query_id'],
            'taxonomy'=>(string)$expected['taxonomy'],
            'term_id'=>(int)$expected['term_id'],
            'term_slug'=>(string)$expected['term_slug'],
            'expected_total'=>$expectedTotal,
            'expected_proof_mode'=>$proofMode,
            'expected_ids'=>$expectedIds,
            'expected_identity_digest'=>(string)($expectedProof['identity_digest']??''),
            'expected_order_digest'=>(string)($expectedProof['order_digest']??''),
            'observed_total'=>$actualTotal,
            'result_count_authoritative'=>$countAuthoritative,
            'result_count_source'=>(string)($rendered['result_count_source']??''),
            'performance'=>array('ttfb_ms'=>$ttfb,'ajax_endpoint_latency_ms'=>$ajaxLatency,'filter_to_presentation_ms'=>$presentationLatency),
            'observed_ids'=>$actualIds,
            'tests'=>$tests,
            'blocking_reasons'=>array(),
            'incomplete_evidence'=>$incomplete,
            'defect_reasons'=>$defects,
            'infrastructure_failures'=>$infra,
            'classification'=>$classification,
            'verdict'=>$verdict,
        );
    }

    private function validateEvidenceEnvelope(array $evidence,array $plan):array{
        $reasons=array();
        $allowed=array('contract','plan_digest','plan_signature','origin','build_identity','observer','cases','receipt_signature');
        if(array_diff(array_keys($evidence),$allowed))$reasons[]='browser_evidence_unknown_fields';
        if(self::EVIDENCE_CONTRACT!==(string)($evidence['contract']??''))$reasons[]='browser_evidence_contract_invalid';
        if((string)$plan['plan_digest']!==(string)($evidence['plan_digest']??''))$reasons[]='browser_evidence_plan_digest_mismatch';
        if((string)$plan['plan_signature']!==(string)($evidence['plan_signature']??''))$reasons[]='browser_evidence_plan_signature_mismatch';
        if((string)$plan['origin']!==$this->normalizeOrigin((string)($evidence['origin']??'')))$reasons[]='browser_evidence_origin_mismatch';
        $identity=(array)($evidence['build_identity']??array());$expected=(array)$plan['build_identity'];
        if((string)($expected['git_sha']??'')!==(string)($identity['git_sha']??'')||(string)($expected['tree_sha']??'')!==(string)($identity['tree_sha']??''))$reasons[]='browser_evidence_build_identity_mismatch';
        $observer=(array)($evidence['observer']??array());
        $mode=(string)($observer['execution_mode']??'external_browser_agent');
        if(self::OBSERVER_CONTRACT!==(string)($observer['contract']??'')||empty($observer['javascript_runtime'])||''===trim((string)($observer['browser_engine']??'')))$reasons[]='browser_observer_identity_invalid';
        if(!in_array($mode,$this->supportedExecutionModes(),true))$reasons[]='browser_observer_execution_mode_invalid';
        $cases=(array)($evidence['cases']??array());if(count($cases)>self::MAX_CASES)$reasons[]='browser_evidence_case_limit_exceeded';
        return array_values(array_unique($reasons));
    }

    private function validatePlanRequest(array $request):array{
        $unknown=array_diff(array_keys($request),array('profile_id','suite'));
        $profileId=$this->cleanKey($request['profile_id']??'');$suite=$this->cleanKey($request['suite']??'browser_runtime');$reasons=array();
        if($unknown)$reasons[]='unsupported_request_fields';if(''===$profileId)$reasons[]='profile_id_required';if('browser_runtime'!==$suite&&'browser'!==$suite)$reasons[]='unsupported_suite';
        return array('profile_id'=>$profileId,'blocking_reasons'=>$reasons);
    }

    private function blockedPlan(string $profileId,array $reasons):array{
        return array('contract'=>self::PLAN_CONTRACT,'provider_contract'=>self::CONTRACT,'state'=>'blocked','provider_id'=>self::PROVIDER_ID,'profile_id'=>$profileId,'suite'=>'browser_runtime','authorizing'=>false,'read_only'=>true,'cases'=>array(),'case_count'=>0,'blocking_reasons'=>array_values(array_unique(array_filter($reasons))));
    }
    private function resultBlocked(string $profileId,array $reasons,array $infra):array{
        return array('contract'=>self::RESULT_CONTRACT,'provider_contract'=>self::CONTRACT,'provider_id'=>self::PROVIDER_ID,'profile_id'=>$profileId,'suite'=>'browser_runtime','verification'=>array('semantic_parity_verified'=>false,'browser_runtime_parity_verified'=>false,'verified_through'=>'none'),'tests'=>array(),'cases'=>array(),'case_count'=>0,'authority'=>$this->authority(),'blocking_reasons'=>array_values(array_unique($reasons)),'incomplete_evidence'=>array(),'defect_reasons'=>array(),'infrastructure_failures'=>array_values(array_unique($infra)),'classification'=>$infra?'TEST_INFRASTRUCTURE_FAILURE':'ENVIRONMENT_OR_PROVIDER_BLOCK','verdict'=>'BLOCKED','browser_runtime'=>'BLOCKED');
    }
    private function resultInfrastructure(string $profileId,array $plan,array $reasons):array{
        return array('contract'=>self::RESULT_CONTRACT,'provider_contract'=>self::CONTRACT,'provider_id'=>self::PROVIDER_ID,'profile_id'=>$profileId,'suite'=>'browser_runtime','plan_digest'=>(string)($plan['plan_digest']??''),'verification'=>array('semantic_parity_verified'=>true,'browser_runtime_parity_verified'=>false,'verified_through'=>'live_server_semantic'),'tests'=>array(),'cases'=>array(),'case_count'=>0,'authority'=>$this->authority(),'blocking_reasons'=>array(),'incomplete_evidence'=>array(),'defect_reasons'=>array(),'infrastructure_failures'=>array_values(array_unique($reasons)),'classification'=>'TEST_INFRASTRUCTURE_FAILURE','verdict'=>'BLOCKED','browser_runtime'=>'BLOCKED');
    }
    private function resultIncomplete(string $profileId,array $plan,array $reasons):array{
        return array('contract'=>self::RESULT_CONTRACT,'provider_contract'=>self::CONTRACT,'provider_id'=>self::PROVIDER_ID,'profile_id'=>$profileId,'suite'=>'browser_runtime','plan_digest'=>(string)$plan['plan_digest'],'verification'=>array('semantic_parity_verified'=>true,'browser_runtime_parity_verified'=>false,'verified_through'=>'live_server_semantic'),'tests'=>array(),'cases'=>array(),'case_count'=>0,'authority'=>$this->authority(),'blocking_reasons'=>array(),'incomplete_evidence'=>array_values(array_unique($reasons)),'defect_reasons'=>array(),'infrastructure_failures'=>array(),'classification'=>'OBSERVATION_GAP','verdict'=>'INCOMPLETE_EVIDENCE','browser_runtime'=>'INCOMPLETE_EVIDENCE');
    }
    private function caseIncomplete(array $expected,array $reasons):array{
        return array('contract'=>self::CASE_CONTRACT,'case_id'=>(string)$expected['case_id'],'provider'=>(string)$expected['provider'],'query_id'=>(string)$expected['query_id'],'taxonomy'=>(string)$expected['taxonomy'],'term_id'=>(int)$expected['term_id'],'term_slug'=>(string)$expected['term_slug'],'tests'=>array(),'blocking_reasons'=>array(),'incomplete_evidence'=>array_values(array_unique($reasons)),'defect_reasons'=>array(),'infrastructure_failures'=>array(),'classification'=>'OBSERVATION_GAP','verdict'=>'INCOMPLETE_EVIDENCE');
    }

    private function reduceTests(array $cases):array{
        $keys=array('browser_ajax_round_trip','browser_event_stream','browser_dom_result_count','browser_dataset_id_parity','browser_order_parity','browser_url_state','browser_seo_non_authority','browser_reset_behavior','browser_performance_baseline');$out=array();
        foreach($keys as$key){$status='PASS';foreach($cases as$case){$caseStatus=(string)($case['tests'][$key]??'INCOMPLETE_EVIDENCE');if('BLOCKED'===$caseStatus){$status='BLOCKED';break;}if('FAIL'===$caseStatus){$status='FAIL';continue;}if('INCOMPLETE_EVIDENCE'===$caseStatus&&'PASS'===$status)$status='INCOMPLETE_EVIDENCE';}$out[$key]=$status;}return$out;
    }
    private function buildIdentity():array{try{$identity=call_user_func($this->buildIdentityProvider);}catch(\Throwable$e){return array('valid'=>false);}return is_array($identity)?$identity:array('valid'=>false);}
    private function origin():string{return function_exists('home_url')?$this->normalizeOrigin((string)home_url('/')):'';}
    private function normalizeOrigin(string $origin):string{$origin=trim($origin);if(''===$origin)return'';return rtrim($origin,'/').'/';}
    private function caseArchivePath(array $semanticPlan,array $case):string{if(isset($case['archive_path']))return(string)$case['archive_path'];foreach((array)($semanticPlan['cases']??array())as$candidate){if((string)($candidate['case_id']??'')===(string)($case['case_id']??'')&&isset($candidate['archive_path']))return(string)$candidate['archive_path'];}return'/';}
    private function normalizeIds(array $ids,int $limit):array{$out=array();foreach(array_slice($ids,0,$limit)as$id){$id=(int)$id;if($id>0&&!in_array($id,$out,true))$out[]=$id;}return$out;}
    private function endpointMatches(string $endpoint):bool{$endpoint=trim($endpoint);if(''===$endpoint)return false;$path=parse_url($endpoint,PHP_URL_PATH);if(!is_string($path)||''===$path)$path=$endpoint;return'/wp-json/etg-dfsb/v1/ajax-presentation'===rtrim($path,'/');}
    private function authority():array{return array('authorizing'=>false,'persistent_mutation'=>false,'profile_mutation'=>false,'seo_publication'=>false,'production_activation'=>false,'browser_state_transient_only'=>true);}
    private function supportedExecutionModes():array{return array('external_browser_agent','local_interactive_browser','self_hosted_browser_agent','managed_browser_agent');}
    private function validDigest(string $digest):bool{return 1===preg_match('/^[a-f0-9]{64}$/',$digest);}
    private function sign(string $message):string{if(!function_exists('wp_salt'))return'';$secret=(string)wp_salt('auth');if(''===$secret)return'';return hash_hmac('sha256',$message,$secret);}
    private function verifySignature(string $message,string $signature):bool{$expected=$this->sign($message);return''!==$expected&&''!==$signature&&hash_equals($expected,$signature);}
    private function canonicalEvidence(array $evidence):array{$allowed=array('contract','plan_digest','plan_signature','origin','build_identity','observer','cases');$copy=array();foreach($allowed as$key){if(array_key_exists($key,$evidence))$copy[$key]=$evidence[$key];}return$copy;}
    private function canonicalJson($value):string{$encoded=json_encode($this->canonicalize($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);return is_string($encoded)?$encoded:'';}
    private function canonicalize($value){if(!is_array($value))return$value;if($this->isList($value))return array_map(array($this,'canonicalize'),$value);ksort($value,SORT_STRING);foreach($value as$key=>$item)$value[$key]=$this->canonicalize($item);return$value;}
    private function isList(array $value):bool{$index=0;foreach($value as$key=>$unused){if($key!==$index++)return false;}return true;}
    private function cleanKey($value):string{if(function_exists('sanitize_key'))return sanitize_key((string)$value);return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value))?:'';}
}
