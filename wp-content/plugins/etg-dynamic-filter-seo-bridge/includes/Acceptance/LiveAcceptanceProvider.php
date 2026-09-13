<?php
namespace ETG\DynamicFilterSEOBridge\Acceptance;

final class LiveAcceptanceProvider {
    const CONTRACT='etg.dfsb.live-acceptance-provider.v1';
    const PROVIDER_ID='etg-dfsb';
    const MAX_ROUTES=4;
    const MAX_CASES=8;

    private $profilesProvider;
    private $caseSelector;
    private $normalizer;
    private $caseRunner;
    private $reducer;

    public function __construct(callable $profilesProvider,callable $directEvaluator,callable $ajaxEvaluator,SemanticQueryEvaluator $queryEvaluator,CaseSelector $caseSelector=null,CanonicalStateNormalizer $normalizer=null,VerdictReducer $reducer=null){
        $this->profilesProvider=$profilesProvider;
        $this->caseSelector=$caseSelector?:new CaseSelector();
        $this->normalizer=$normalizer?:new CanonicalStateNormalizer();
        $this->caseRunner=new SemanticCaseRunner($directEvaluator,$ajaxEvaluator,$queryEvaluator,$this->normalizer);
        $this->reducer=$reducer?:new VerdictReducer();
    }

    public function register():void{
        if(!function_exists('add_filter'))return;
        add_filter('mad4b_live_acceptance_providers',array($this,'registerCentralProvider'),10,1);
        add_filter('etg_dfsb_live_acceptance_provider',array($this,'exposeNativeProvider'),10,1);
    }

    public function registerCentralProvider($providers):array{
        $providers=is_array($providers)?$providers:array();
        $providers[self::PROVIDER_ID]=array('provider_id'=>self::PROVIDER_ID,'contract'=>self::CONTRACT,'read_only'=>true,'authorizing'=>false,'profile_mutation'=>false,'transport_owned_by_provider'=>false,
            'descriptor_callback'=>array($this,'descriptor'),'capabilities_callback'=>array($this,'capabilities'),'plan_callback'=>array($this,'plan'),'run_callback'=>array($this,'run'));
        return$providers;
    }
    public function exposeNativeProvider($provider=null){unset($provider);return$this;}

    public function descriptor():array{
        return array('contract'=>self::CONTRACT,'provider_id'=>self::PROVIDER_ID,'authorizing'=>false,'read_only'=>true,'profile_mutation'=>false,'transport_owned_by_provider'=>false,
            'arbitrary_url_input'=>false,'arbitrary_query_input'=>false,'arbitrary_taxonomy_input'=>false,'arbitrary_http_input'=>false,'suites'=>array('semantic'),
            'verification_levels'=>array('semantic_server_side'=>true,'browser_runtime'=>false),'effects'=>$this->effects(),
            'limits'=>array('max_routes'=>self::MAX_ROUTES,'max_cases'=>self::MAX_CASES,'max_ids'=>SemanticQueryEvaluator::MAX_IDS));
    }

    public function capabilities():array{
        return array('contract'=>'etg.dfsb.live-acceptance-capabilities.v1','provider_id'=>self::PROVIDER_ID,'authorizing'=>false,
            'capabilities'=>array('semantic.profile_resolution','semantic.provider_binding','semantic.query_binding','semantic.direct_ajax_state_parity','semantic.result_count_parity','semantic.dataset_id_parity','semantic.seo_non_authority'),
            'external_observer_required'=>array('browser.ajax_round_trip','browser.event_stream','browser.dom_result_count'));
    }

    public function plan(array$request):array{
        $check=$this->validateRequest($request);
        if($check['blocking_reasons'])return$this->planEnvelope('blocked',array(),array(),$check['blocking_reasons']);
        $profileId=$check['profile_id'];$profiles=$this->profiles();
        if(null===$profiles)return$this->planEnvelope('blocked',array(),array(),array('profile_registry_unavailable'));
        $profile=isset($profiles[$profileId])&&is_array($profiles[$profileId])?$profiles[$profileId]:array();
        if(!$profile)return$this->planEnvelope('blocked',array(),array(),array('profile_not_found'));
        $routes=$this->routes($profile);if(!$routes)return$this->planEnvelope('blocked',$profile,array(),array('profile_routes_unavailable'));
        $selection=$this->caseSelector->select($profile);$candidates=(array)($selection['candidates']??array());
        if(!$candidates)return$this->planEnvelope('blocked',$profile,$routes,(array)($selection['blocking_reasons']??array('acceptance_cases_unavailable')));
        $cases=array();
        foreach($routes as$route){foreach($candidates as$candidate){if(count($cases)>=self::MAX_CASES)break 2;
            $case=array('profile_id'=>$profileId,'provider'=>(string)($route['provider']??''),'query_id'=>(string)(($route['provider_query_id']??'')?:($route['query_id']??'')),
                'archive_path'=>$this->archivePath($profile),'taxonomy'=>(string)($candidate['taxonomy']??''),'term_id'=>(int)($candidate['term_id']??0),'term_slug'=>(string)($candidate['term_slug']??''),
                'selection_rank'=>(int)($candidate['selection_rank']??0),'selection_reason'=>(string)($candidate['selection_reason']??''));
            $case['case_id']=substr(hash('sha256',json_encode($case)),0,24);$cases[]=$case;
        }}
        if(!$cases)return$this->planEnvelope('blocked',$profile,$routes,array('acceptance_cases_unavailable'));
        $plan=$this->planEnvelope('ready',$profile,$routes,array());$plan['cases']=$cases;$plan['case_count']=count($cases);
        $plan['plan_digest']=hash('sha256',json_encode(array('profile_id'=>$profileId,'suite'=>'semantic','cases'=>$cases)));return$plan;
    }

    public function run(array$request):array{
        $plan=$this->plan($request);if('ready'!==(string)($plan['state']??''))return$this->runBlocked($request,$plan);
        $profiles=$this->profiles();$profileId=(string)($plan['profile_id']??'');$profile=is_array($profiles)&&isset($profiles[$profileId])&&is_array($profiles[$profileId])?$profiles[$profileId]:array();
        if(!$profile)return$this->runBlocked($request,$this->planEnvelope('blocked',array(),array(),array('profile_not_found')));
        $results=array();foreach((array)($plan['cases']??array())as$case)$results[]=$this->caseRunner->run($profile,$case);
        $reduced=$this->reducer->reduce($results);
        return array('contract'=>self::CONTRACT,'provider_id'=>self::PROVIDER_ID,'environment'=>function_exists('wp_get_environment_type')?(string)wp_get_environment_type():'','origin'=>function_exists('home_url')?(string)home_url('/'):'',
            'profile_id'=>$profileId,'suite'=>'semantic','plan_digest'=>(string)($plan['plan_digest']??''),
            'verification'=>array('semantic_parity_verified'=>'PASS'===$reduced['verdict'],'browser_runtime_parity_verified'=>false,'verified_through'=>'live_server_semantic'),
            'tests'=>$reduced['tests'],'cases'=>$results,'case_count'=>count($results),'authority'=>$this->authority(),'effects'=>$this->effects(),
            'blocking_reasons'=>$reduced['blocking_reasons'],'incomplete_evidence'=>array_values(array_unique(array_merge($reduced['incomplete_evidence'],array('browser_runtime_not_observed')))),
            'defect_reasons'=>$reduced['defect_reasons'],'infrastructure_failures'=>array_values((array)($reduced['infrastructure_failures']??array())),
            'classification'=>$reduced['classification'],'verdict'=>$reduced['verdict'],'browser_runtime'=>'INCOMPLETE_EVIDENCE');
    }

    private function runBlocked(array$request,array$plan):array{
        return array('contract'=>self::CONTRACT,'provider_id'=>self::PROVIDER_ID,'profile_id'=>$this->cleanKey($request['profile_id']??''),'suite'=>'semantic',
            'verification'=>array('semantic_parity_verified'=>false,'browser_runtime_parity_verified'=>false,'verified_through'=>'none'),'tests'=>array('profile_resolution'=>'BLOCKED'),'cases'=>array(),'case_count'=>0,
            'authority'=>$this->authority(),'effects'=>$this->effects(),'blocking_reasons'=>array_values(array_unique((array)($plan['blocking_reasons']??array('acceptance_plan_blocked')))),
            'incomplete_evidence'=>array('browser_runtime_not_observed'),'defect_reasons'=>array(),'infrastructure_failures'=>array(),
            'classification'=>'ENVIRONMENT_OR_PROVIDER_BLOCK','verdict'=>'BLOCKED','browser_runtime'=>'INCOMPLETE_EVIDENCE');
    }

    private function routes(array$profile):array{
        $routes=array();foreach((array)($profile['routes']??array())as$route){if(!is_array($route))continue;$provider=$this->cleanKey($route['provider']??'');$queryId=$this->cleanIdentifier(($route['provider_query_id']??'')?:($route['query_id']??''));if(''===$provider||''===$queryId)continue;$routes[]=array('provider'=>$provider,'query_id'=>$queryId,'provider_query_id'=>$queryId);}
        usort($routes,static function(array$a,array$b):int{return strcmp($a['provider'].'|'.$a['query_id'],$b['provider'].'|'.$b['query_id']);});return array_slice($routes,0,self::MAX_ROUTES);
    }
    private function profiles(){try{$profiles=call_user_func($this->profilesProvider);}catch(\Throwable$e){return null;}return is_array($profiles)?$profiles:null;}
    private function validateRequest(array$request):array{$unknown=array_diff(array_keys($request),array('profile_id','suite'));$profileId=$this->cleanKey($request['profile_id']??'');$suite=$this->cleanKey($request['suite']??'semantic');$reasons=array();if($unknown)$reasons[]='unsupported_request_fields';if(''===$profileId)$reasons[]='profile_id_required';if('semantic'!==$suite&&'full_semantic'!==$suite)$reasons[]='unsupported_suite';return array('profile_id'=>$profileId,'suite'=>'semantic','blocking_reasons'=>$reasons);}
    private function planEnvelope(string$state,array$profile,array$routes,array$reasons):array{return array('contract'=>'etg.dfsb.live-acceptance-plan.v1','provider_id'=>self::PROVIDER_ID,'state'=>$state,'profile_id'=>(string)($profile['id']??''),'suite'=>'semantic','routes'=>$routes,'cases'=>array(),'case_count'=>0,'authorizing'=>false,'read_only'=>true,'blocking_reasons'=>array_values(array_unique(array_filter($reasons))));}
    private function authority():array{return array('authorizing'=>false,'persistent_mutation'=>false,'profile_mutation'=>false,'seo_publication'=>false,'production_activation'=>false);}
    private function effects():array{return array('business_state_mutation'=>false,'authority_mutation'=>false,'seo_mutation'=>false,'profile_mutation'=>false,'observational_persistence'=>false);}
    private function archivePath(array$profile):string{$paths=array_values(array_filter(array_map('strval',(array)($profile['archive_paths']??array()))));sort($paths,SORT_STRING);return$paths?$paths[0]:'';}
    private function cleanKey($value):string{if(function_exists('sanitize_key'))return sanitize_key((string)$value);return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value))?:'';}
    private function cleanIdentifier($value):string{$value=strtolower(trim((string)$value));return preg_match('/^[a-z0-9._\-]{1,80}$/',$value)?$value:'';}
}
