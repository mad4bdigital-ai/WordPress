<?php
namespace ETG\DynamicFilterSEOBridge\Acceptance;

final class BrowserAcceptanceProvider {
    const CONTRACT = 'etg.dfsb.browser-acceptance-provider.v1';
    const PROVIDER_ID = 'etg-dfsb';
    const CAPABILITIES_CONTRACT = 'mad4b.browser-acceptance-capabilities.v1';
    const PLAN_CONTRACT = 'mad4b.browser-acceptance-plan.v1';
    const RESULT_CONTRACT = 'mad4b.browser-acceptance-result.v1';
    const EVIDENCE_CONTRACT = 'etg.dfsb.browser-acceptance-evidence.v1';
    const OBSERVER_CONTRACT = 'etg.dfsb.browser-acceptance-observer.v1';
    const MAX_CASES = 8;
    const MAX_IDS = 100;

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
            'external_browser_agent_required'=>true,
            'transport_authentication_required'=>true,
            'plan_signature'=>'wordpress_auth_salt_hmac_sha256',
            'evidence_receipt_signature'=>'wordpress_auth_salt_hmac_sha256',
            'capabilities'=>array(
                'browser.ajax_round_trip',
                'browser.event_stream',
                'browser.dom_result_count',
                'browser.dataset_id_parity',
                'browser.order_parity',
                'browser.url_state',
                'browser.seo_non_authority',
                'browser.reset_behavior',
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
            if (empty($dataset['ids_complete'])) return $this->blockedPlan($profileId, array('semantic_dataset_ids_incomplete'));
            $ids = $this->normalizeIds((array)($dataset['ids'] ?? array()));
            $total = isset($case['provider_total']) ? (int)$case['provider_total'] : (int)($dataset['total'] ?? 0);
            if ($total !== count($ids)) return $this->blockedPlan($profileId, array('semantic_dataset_total_mismatch'));
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
                    'ids'=>$ids,
                    'ids_digest'=>hash('sha256', json_encode($ids)),
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
                'arbitrary_url_input'=>false,
                'arbitrary_javascript_input'=>false,
                'observer_contract'=>self::OBSERVER_CONTRACT,
                'evidence_contract'=>self::EVIDENCE_CONTRACT,
                'ajax_endpoint_path'=>'/wp-json/etg-dfsb/v1/ajax-presentation',
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
        $envelopeReasons = $this->validateEvidenceEnvelope($evidence, $plan);
        if ($envelopeReasons) return $this->resultInfrastructure($profileId, $plan, $envelopeReasons);

        $evidenceCases = array();
        foreach ((array)($evidence['cases'] ?? array()) as $case) {
            if (!is_array($case)) continue;
            $caseId = (string)($case['case_id'] ?? '');
            if ('' !== $caseId) $evidenceCases[$caseId] = $case;
        }
        $caseResults = array(); $infra = array(); $defects = array();
        foreach ((array)$plan['cases'] as $expected) {
            $caseId = (string)$expected['case_id'];
            if (!isset($evidenceCases[$caseId])) {
                $infra[] = $caseId.':browser_case_evidence_missing';
                $caseResults[] = $this->caseBlocked($expected, array('browser_case_evidence_missing'));
                continue;
            }
            $evaluated = $this->evaluateCase($expected, $evidenceCases[$caseId]);
            $caseResults[] = $evaluated;
            foreach ((array)$evaluated['infrastructure_failures'] as $reason) $infra[] = $caseId.':'.$reason;
            foreach ((array)$evaluated['defect_reasons'] as $reason) $defects[] = $caseId.':'.$reason;
        }
        if (count($evidenceCases) !== count((array)$plan['cases'])) $infra[] = 'browser_case_set_mismatch';
        $infra = array_values(array_unique($infra)); $defects = array_values(array_unique($defects));
        $tests = $this->reduceTests($caseResults);

        if ($infra) { $verdict='BLOCKED'; $classification='TEST_INFRASTRUCTURE_FAILURE'; }
        elseif ($defects) { $verdict='FAIL'; $classification='PRODUCT_DEFECT'; }
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
            'incomplete_evidence'=>array(),
            'defect_reasons'=>$defects,
            'infrastructure_failures'=>$infra,
            'classification'=>$classification,
            'verdict'=>$verdict,
            'browser_runtime'=>'PASS'===$verdict?'PASS':('FAIL'===$verdict?'FAIL':'BLOCKED'),
        );
    }

    private function evaluateCase(array $expected, array $evidence): array {
        $infra=array(); $defects=array(); $tests=array();
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

        $rendered=(array)($evidence['rendered'] ?? array());
        $actualIds=$this->normalizeIds((array)($rendered['ids']??array()));
        $expectedIds=$this->normalizeIds((array)$expected['expected']['ids']);
        $countPass=(int)($rendered['result_count']??-1)===(int)$expected['expected']['result_total'];
        $identityActual=$actualIds;$identityExpected=$expectedIds;sort($identityActual,SORT_NUMERIC);sort($identityExpected,SORT_NUMERIC);
        $idsPass=$identityActual===$identityExpected;$orderPass=$actualIds===$expectedIds;
        $tests['browser_dom_result_count']=$countPass?'PASS':'FAIL';
        $tests['browser_dataset_id_parity']=$idsPass?'PASS':'FAIL';
        $tests['browser_order_parity']=$orderPass?'PASS':'FAIL';
        if(!$countPass)$defects[]='browser_result_count_divergence';
        if(!$idsPass)$defects[]='browser_dataset_identity_divergence';
        if(!$orderPass)$defects[]='browser_dataset_order_divergence';

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

        $infra=array_values(array_unique($infra));$defects=array_values(array_unique($defects));
        if($infra){$verdict='BLOCKED';$classification='TEST_INFRASTRUCTURE_FAILURE';}
        elseif($defects){$verdict='FAIL';$classification='PRODUCT_DEFECT';}
        else{$verdict='PASS';$classification='NO_CONFIRMED_DEFECT';}
        return array(
            'contract'=>'etg.dfsb.browser-acceptance-case.v1',
            'case_id'=>(string)$expected['case_id'],
            'provider'=>(string)$expected['provider'],
            'query_id'=>(string)$expected['query_id'],
            'taxonomy'=>(string)$expected['taxonomy'],
            'term_id'=>(int)$expected['term_id'],
            'term_slug'=>(string)$expected['term_slug'],
            'expected_total'=>(int)$expected['expected']['result_total'],
            'expected_ids'=>$expectedIds,
            'observed_total'=>(int)($rendered['result_count']??-1),
            'observed_ids'=>$actualIds,
            'tests'=>$tests,
            'blocking_reasons'=>array(),
            'incomplete_evidence'=>array(),
            'defect_reasons'=>$defects,
            'infrastructure_failures'=>$infra,
            'classification'=>$classification,
            'verdict'=>$verdict,
        );
    }

    private function validateEvidenceEnvelope(array $evidence,array $plan):array{
        $reasons=array();
        if(self::EVIDENCE_CONTRACT!==(string)($evidence['contract']??''))$reasons[]='browser_evidence_contract_invalid';
        if((string)$plan['plan_digest']!==(string)($evidence['plan_digest']??''))$reasons[]='browser_evidence_plan_digest_mismatch';
        if((string)$plan['plan_signature']!==(string)($evidence['plan_signature']??''))$reasons[]='browser_evidence_plan_signature_mismatch';
        if((string)$plan['origin']!==$this->normalizeOrigin((string)($evidence['origin']??'')))$reasons[]='browser_evidence_origin_mismatch';
        $identity=(array)($evidence['build_identity']??array());$expected=(array)$plan['build_identity'];
        if((string)($expected['git_sha']??'')!==(string)($identity['git_sha']??'')||(string)($expected['tree_sha']??'')!==(string)($identity['tree_sha']??''))$reasons[]='browser_evidence_build_identity_mismatch';
        $observer=(array)($evidence['observer']??array());
        if(self::OBSERVER_CONTRACT!==(string)($observer['contract']??'')||empty($observer['javascript_runtime'])||''===trim((string)($observer['browser_engine']??'')))$reasons[]='browser_observer_identity_invalid';
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
    private function caseBlocked(array $expected,array $reasons):array{
        return array('contract'=>'etg.dfsb.browser-acceptance-case.v1','case_id'=>(string)$expected['case_id'],'provider'=>(string)$expected['provider'],'query_id'=>(string)$expected['query_id'],'taxonomy'=>(string)$expected['taxonomy'],'term_id'=>(int)$expected['term_id'],'term_slug'=>(string)$expected['term_slug'],'tests'=>array(),'blocking_reasons'=>array(),'incomplete_evidence'=>array(),'defect_reasons'=>array(),'infrastructure_failures'=>array_values(array_unique($reasons)),'classification'=>'TEST_INFRASTRUCTURE_FAILURE','verdict'=>'BLOCKED');
    }

    private function reduceTests(array $cases):array{
        $keys=array('browser_ajax_round_trip','browser_event_stream','browser_dom_result_count','browser_dataset_id_parity','browser_order_parity','browser_url_state','browser_seo_non_authority','browser_reset_behavior');$out=array();
        foreach($keys as$key){$status='PASS';foreach($cases as$case){$caseStatus=(string)($case['tests'][$key]??'BLOCKED');if('BLOCKED'===$caseStatus){$status='BLOCKED';break;}if('FAIL'===$caseStatus)$status='FAIL';}$out[$key]=$status;}return$out;
    }
    private function buildIdentity():array{try{$identity=call_user_func($this->buildIdentityProvider);}catch(\Throwable$e){return array('valid'=>false);}return is_array($identity)?$identity:array('valid'=>false);}
    private function origin():string{return function_exists('home_url')?$this->normalizeOrigin((string)home_url('/')):'';}
    private function normalizeOrigin(string $origin):string{$origin=trim($origin);if(''===$origin)return'';return rtrim($origin,'/').'/';}
    private function caseArchivePath(array $semanticPlan,array $case):string{if(isset($case['archive_path']))return(string)$case['archive_path'];foreach((array)($semanticPlan['cases']??array())as$candidate){if((string)($candidate['case_id']??'')===(string)($case['case_id']??'')&&isset($candidate['archive_path']))return(string)$candidate['archive_path'];}return'/';}
    private function normalizeIds(array $ids):array{$out=array();foreach(array_slice($ids,0,self::MAX_IDS)as$id){$id=(int)$id;if($id>0&&!in_array($id,$out,true))$out[]=$id;}return$out;}
    private function endpointMatches(string $endpoint):bool{$endpoint=trim($endpoint);if(''===$endpoint)return false;$path=parse_url($endpoint,PHP_URL_PATH);if(!is_string($path)||''===$path)$path=$endpoint;return'/wp-json/etg-dfsb/v1/ajax-presentation'===rtrim($path,'/');}
    private function authority():array{return array('authorizing'=>false,'persistent_mutation'=>false,'profile_mutation'=>false,'seo_publication'=>false,'production_activation'=>false,'browser_state_transient_only'=>true);}
    private function sign(string $message):string{if(!function_exists('wp_salt'))return'';$secret=(string)wp_salt('auth');if(''===$secret)return'';return hash_hmac('sha256',$message,$secret);}
    private function verifySignature(string $message,string $signature):bool{$expected=$this->sign($message);return''!==$expected&&''!==$signature&&hash_equals($expected,$signature);}
    private function canonicalEvidence(array $evidence):array{$copy=$evidence;unset($copy['receipt_signature']);return$copy;}
    private function canonicalJson($value):string{return json_encode($this->canonicalize($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
    private function canonicalize($value){if(!is_array($value))return$value;if($this->isList($value))return array_map(array($this,'canonicalize'),$value);ksort($value,SORT_STRING);foreach($value as$key=>$item)$value[$key]=$this->canonicalize($item);return$value;}
    private function isList(array $value):bool{$index=0;foreach($value as$key=>$unused){if($key!==$index++)return false;}return true;}
    private function cleanKey($value):string{if(function_exists('sanitize_key'))return sanitize_key((string)$value);return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value))?:'';}
}
