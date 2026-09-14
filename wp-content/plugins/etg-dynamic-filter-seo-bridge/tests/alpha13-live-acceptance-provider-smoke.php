<?php
declare(strict_types=1);

function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value));}
function sanitize_title($value){$value=strtolower(trim((string)$value));return trim(preg_replace('/[^a-z0-9_\-]+/','-',$value),'-');}
function wp_json_encode($value){return json_encode($value);}
function add_filter($hook,$callback,$priority=10,$acceptedArgs=1){$GLOBALS['etg_acceptance_filters'][$hook][]=$callback;return true;}
function wp_get_environment_type(){return 'staging';}
function home_url($path='/'){return 'https://staging.egypttourgates.com'.('/'===substr($path,0,1)?$path:'/'.$path);}
function etg_acceptance_expect($condition,string $message):void{if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
function etg_acceptance_same($expected,$actual,string $message):void{if($expected!==$actual){fwrite(STDERR,"FAIL: $message\nEXPECTED ".var_export($expected,true)."\nACTUAL ".var_export($actual,true)."\n");exit(1);}}

$root=dirname(__DIR__);
require_once $root.'/includes/Acceptance/CaseSelector.php';
require_once $root.'/includes/Acceptance/CanonicalStateNormalizer.php';
require_once $root.'/includes/Acceptance/SemanticQueryEvaluator.php';
require_once $root.'/includes/Acceptance/SemanticCaseRunner.php';
require_once $root.'/includes/Acceptance/VerdictReducer.php';
require_once $root.'/includes/Acceptance/LiveAcceptanceProvider.php';

use ETG\DynamicFilterSEOBridge\Acceptance\CaseSelector;
use ETG\DynamicFilterSEOBridge\Acceptance\CanonicalStateNormalizer;
use ETG\DynamicFilterSEOBridge\Acceptance\SemanticQueryEvaluator;
use ETG\DynamicFilterSEOBridge\Acceptance\LiveAcceptanceProvider;

final class ETGAcceptanceQueryStub {
    private $filtered=array();
    private $page=1;
    public function setup_query(){}
    public function set_filtered_prop($prop,$value){if('_page'===$prop){$this->page=max(1,(int)$value);return;}$this->filtered[$prop]=$value;}
    private function slug():string{
        $tax=(array)($this->filtered['tax_query']??array());
        foreach($tax as $clause){if(is_array($clause)&&isset($clause['terms'][0]))return (string)$clause['terms'][0];}
        return '';
    }
    private function allIds():array{
        if('cairo'===$this->slug())return array(11,12,13);
        if('luxor'===$this->slug())return array(21,22);
        if('private-tour'===$this->slug())return range(101,117);
        return array();
    }
    public function get_items_total_count(){return count($this->allIds());}
    public function get_items_per_page(){return 'private-tour'===$this->slug()?10:2;}
    public function get_items(){
        $per=$this->get_items_per_page();$offset=($this->page-1)*$per;
        return array_map(static function($id){return(object)array('ID'=>$id);},array_slice($this->allIds(),$offset,$per));
    }
}

final class ETGAcceptanceHugeQueryStub {
    private $page=1;
    public function setup_query(){}
    public function set_filtered_prop($prop,$value){if('_page'===$prop)$this->page=max(1,(int)$value);}
    public function get_items_total_count(){return 101;}
    public function get_items_per_page(){return 10;}
    public function get_items(){return array_map(static function($id){return(object)array('ID'=>$id);},range((($this->page-1)*10)+1,min($this->page*10,101)));}
}

final class ETGAcceptanceStickyQueryStub {
    public $final_query=array('page'=>1);
    public $current_query=null;
    private $filtered=array();
    public function __construct(){
        $this->current_query=array_map(static function($id){return(object)array('ID'=>$id);},range(101,110));
    }
    public function reset_query(){}
    public function setup_query(){if(null===$this->final_query)$this->final_query=array('page'=>1);}
    public function set_filtered_prop($prop,$value){if('_page'===$prop){$this->final_query['page']=max(1,(int)$value);return;}$this->filtered[$prop]=$value;}
    public function get_items_total_count(){return 17;}
    public function get_items_per_page(){return 10;}
    public function get_current_items_page(){return (int)($this->final_query['page']??1);}
    public function get_items(){
        if(null!==$this->current_query)return $this->current_query;
        $page=(int)($this->final_query['page']??1);$offset=($page-1)*10;
        $this->current_query=array_map(static function($id){return(object)array('ID'=>$id);},array_slice(range(101,117),$offset,10));
        return $this->current_query;
    }
}

final class ETGAcceptanceBrokenPagingQueryStub {
    private $filtered=array();
    public function setup_query(){}
    public function set_filtered_prop($prop,$value){if('_page'===$prop)return;$this->filtered[$prop]=$value;}
    private function slug():string{
        $tax=(array)($this->filtered['tax_query']??array());
        foreach($tax as $clause){if(is_array($clause)&&isset($clause['terms'][0]))return (string)$clause['terms'][0];}
        return '';
    }
    private function allIds():array{
        if('cairo'===$this->slug())return array(11,12,13);
        if('luxor'===$this->slug())return array(21,22);
        if('private-tour'===$this->slug())return range(101,117);
        return array();
    }
    public function get_items_total_count(){return count($this->allIds());}
    public function get_items_per_page(){return 'private-tour'===$this->slug()?10:2;}
    public function get_current_items_page(){return 1;}
    public function get_items(){return array_map(static function($id){return(object)array('ID'=>$id);},array_slice($this->allIds(),0,$this->get_items_per_page()));}
}

$profiles=array(
    'tours'=>array(
        'id'=>'tours','enabled'=>false,'archive_paths'=>array('/tours-and-activities/'),
        'routes'=>array(array('provider'=>'jet-engine','query_id'=>'tours_query_archive','provider_query_id'=>'tours_query_archive')),
        'taxonomy_rules'=>array(
            'location_jet'=>array('role'=>'location','priority'=>10),
            'tour-types_jet'=>array('role'=>'tour_type','priority'=>20),
        ),
        'allowed_taxonomy_sets'=>array('location_jet','tour-types_jet','location_jet+tour-types_jet'),
    ),
);

$selector=new CaseSelector(static function(string $taxonomy,int $limit):array{
    unset($limit);
    if('location_jet'===$taxonomy)return array(
        array('term_id'=>123,'slug'=>'cairo','name'=>'Cairo'),
        array('term_id'=>124,'slug'=>'luxor','name'=>'Luxor'),
    );
    return array(array('term_id'=>77,'slug'=>'private-tour','name'=>'Private Tour'));
});

$normalizer=new CanonicalStateNormalizer();
$bindingProvider=static function(string $provider,string $queryId,array $profile):array{
    unset($profile);
    return array(
        'resolved'=>'jet-engine'===$provider&&'tours_query_archive'===$queryId,
        'reason'=>'verified','query'=>new ETGAcceptanceQueryStub(),
        'provider_query_id'=>$queryId,'query_builder_custom_query_id'=>$queryId,'query_builder_internal_id'=>'5',
        'source'=>'test_fixture','identity_source'=>'custom_query_id',
    );
};
$queryEvaluator=new SemanticQueryEvaluator(null,$bindingProvider);

$directEvaluator=static function(string $uri):array{
    preg_match('#/tax/([^:]+):([^/]+)/#',$uri,$m);
    $taxonomy=$m[1]??'';$slug=$m[2]??'';
    return array(
        'profile_id'=>'tours','provider'=>'jet-engine','query_id'=>'tours_query_archive','archive_path'=>'/tours-and-activities/',
        'filters'=>array($taxonomy=>$slug),'in_scope'=>true,'scope_valid'=>true,'runtime_ready'=>true,'authorizing'=>false,'url_authority'=>true,'ajax_only'=>false,
        'missing_terms'=>array(),'translation_fallback'=>false,'post_type_observation_matches_profile'=>true,
    );
};
$ajaxEvaluator=static function(array $payload):array{
    $clause=$payload['current_query']['tax_query'][0]??array();
    $taxonomy=(string)($clause['taxonomy']??'');$slug=(string)(($clause['terms'][0]??''));
    return array(
        'profile_id'=>'tours','provider'=>$payload['provider'],'query_id'=>$payload['query_id'],'archive_path'=>$payload['archive_path'],
        'filter_values'=>array($taxonomy=>array($slug)),'in_scope'=>true,'scope_valid'=>true,'runtime_ready'=>true,'authorizing'=>false,'url_authority'=>false,'ajax_only'=>true,
        'presentation_state_complete'=>true,'filtered_query_complete'=>true,'missing_terms'=>array(),'translation_fallback'=>false,'post_type_observation_matches_profile'=>true,
        'filtered_query'=>array('tax_query'=>$payload['current_query']['tax_query']),
    );
};

$provider=new LiveAcceptanceProvider(
    static function()use($profiles):array{return $profiles;},$directEvaluator,$ajaxEvaluator,$queryEvaluator,$selector,$normalizer
);
$provider->register();
etg_acceptance_expect(isset($GLOBALS['etg_acceptance_filters']['mad4b_live_acceptance_providers']),'central acceptance provider hook is registered');
$descriptor=$provider->descriptor();
etg_acceptance_same('etg.dfsb.live-acceptance-provider.v1',$descriptor['contract'],'provider contract is versioned');
etg_acceptance_same(false,$descriptor['authorizing'],'provider is non-authorizing');
etg_acceptance_same(false,$descriptor['arbitrary_url_input'],'arbitrary URL input is denied');
etg_acceptance_same(false,$descriptor['verification_levels']['browser_runtime'],'PHP provider does not claim browser verification');

$plan=$provider->plan(array('profile_id'=>'tours','suite'=>'semantic'));
etg_acceptance_same('ready',$plan['state'],'semantic plan is generated from governed profile');
etg_acceptance_same(3,$plan['case_count'],'case selection is deterministic and bounded');
etg_acceptance_same('location_jet',$plan['cases'][0]['taxonomy'],'taxonomy priority controls deterministic first case');
etg_acceptance_same('cairo',$plan['cases'][0]['term_slug'],'stable term ordering is preserved');
etg_acceptance_expect(64===strlen($plan['plan_digest']),'plan carries deterministic digest');

$result=$provider->run(array('profile_id'=>'tours','suite'=>'semantic'));
etg_acceptance_same('PASS',$result['verdict'],'semantic parity passes when direct and AJAX normalize to same state and full bounded datasets');
etg_acceptance_same(true,$result['verification']['semantic_parity_verified'],'semantic verification is explicit');
etg_acceptance_same(false,$result['verification']['browser_runtime_parity_verified'],'browser runtime is never self-certified by PHP');
etg_acceptance_same('INCOMPLETE_EVIDENCE',$result['browser_runtime'],'browser evidence remains independent');
etg_acceptance_same(array('browser_runtime_not_observed'),$result['incomplete_evidence'],'browser gap is reported separately from semantic verdict');
etg_acceptance_same(false,$result['authority']['authorizing'],'acceptance creates no authority');
etg_acceptance_same(false,$result['effects']['business_state_mutation'],'acceptance does not mutate business state');
etg_acceptance_same(true,$result['cases'][0]['ids_parity'],'full dataset IDs are compared when complete');
etg_acceptance_same(true,$result['cases'][0]['result_count_parity'],'result count parity is checked');
etg_acceptance_same(true,$result['cases'][0]['seo_non_authority'],'AJAX SEO non-authority is checked');
etg_acceptance_same(17,$result['cases'][2]['direct_total'],'multi-page semantic case keeps authoritative total');
etg_acceptance_same(true,$result['cases'][2]['direct_dataset']['ids_complete'],'multi-page dataset is collected completely');
etg_acceptance_same('paged_query_items',$result['cases'][2]['direct_dataset']['collection_mode'],'multi-page dataset uses bounded canonical page walk');
etg_acceptance_same(2,$result['cases'][2]['direct_dataset']['page_fetches'],'17 results at page size 10 require exactly two page fetches');
etg_acceptance_same(range(101,117),$result['cases'][2]['direct_dataset']['ids'],'multi-page ID order is preserved');
etg_acceptance_same(17,$result['cases'][2]['direct_dataset']['raw_id_count'],'raw page collection count matches provider total');
etg_acceptance_same(17,$result['cases'][2]['direct_dataset']['unique_id_count'],'ordered unique ID collection is complete');
etg_acceptance_same(false,$result['cases'][2]['direct_dataset']['infrastructure_failure'],'healthy pagination is not mislabeled as infrastructure failure');

$hugeEvaluator=new SemanticQueryEvaluator(null,static function():array{return array('resolved'=>true,'reason'=>'verified','query'=>new ETGAcceptanceHugeQueryStub(),'provider_query_id'=>'tours_query_archive','query_builder_custom_query_id'=>'tours_query_archive','query_builder_internal_id'=>'5','source'=>'test_fixture','identity_source'=>'custom_query_id');});
$huge=$hugeEvaluator->evaluate(array('provider'=>'jet-engine','query_id'=>'tours_query_archive'),$profiles['tours'],array('tax_query'=>array('relation'=>'AND')));
etg_acceptance_same(false,$huge['ids_complete'],'datasets above the raw ID exposure ceiling do not expose a full ID array');
etg_acceptance_same('digest_only',$huge['ids_scope'],'datasets above the raw ID exposure ceiling switch to digest-only proof');
etg_acceptance_same('complete',$huge['ids_reason'],'bounded digest collection remains complete above the raw ID exposure ceiling');
etg_acceptance_same('full_digest',$huge['proof_mode'],'datasets above the raw ID exposure ceiling use canonical digest proof');
etg_acceptance_same(true,$huge['proof_complete'],'digest proof is complete for bounded datasets');
etg_acceptance_same(101,$huge['proof_item_count'],'digest proof covers the full bounded dataset');
etg_acceptance_same(array(),$huge['ids'],'raw IDs are not exposed above the raw ID ceiling');
etg_acceptance_same(11,$huge['page_fetches'],'101 results at page size 10 require eleven bounded page fetches');
etg_acceptance_same(100,$huge['max_ids'],'raw ID exposure ceiling remains bounded');
etg_acceptance_same(5000,$huge['max_digest_ids'],'digest collection ceiling remains bounded');
etg_acceptance_same(100,$huge['max_page_fetches'],'page-walk resource ceiling remains bounded');

$stickyEvaluator=new SemanticQueryEvaluator(null,static function():array{return array('resolved'=>true,'reason'=>'verified','query'=>new ETGAcceptanceStickyQueryStub(),'provider_query_id'=>'tours_query_archive','query_builder_custom_query_id'=>'tours_query_archive','query_builder_internal_id'=>'5','source'=>'test_fixture','identity_source'=>'custom_query_id');});
$sticky=$stickyEvaluator->evaluate(array('provider'=>'jet-engine','query_id'=>'tours_query_archive'),$profiles['tours'],array('tax_query'=>array('relation'=>'AND')));
etg_acceptance_same(true,$sticky['ids_complete'],'pre-evaluated JetEngine runtime state is reset before each semantic page fetch');
etg_acceptance_same(range(101,117),$sticky['ids'],'fresh page state advances instead of cloning stale page-one runtime results');
etg_acceptance_same(17,$sticky['raw_id_count'],'fresh-page collector does not over-collect duplicate page IDs');
etg_acceptance_same(false,$sticky['infrastructure_failure'],'successful runtime-state reset does not trigger infrastructure failure');

$brokenEvaluator=new SemanticQueryEvaluator(null,static function(string $provider,string $queryId,array $profile):array{unset($profile);return array('resolved'=>'jet-engine'===$provider&&'tours_query_archive'===$queryId,'reason'=>'verified','query'=>new ETGAcceptanceBrokenPagingQueryStub(),'provider_query_id'=>$queryId,'query_builder_custom_query_id'=>$queryId,'query_builder_internal_id'=>'5','source'=>'test_fixture','identity_source'=>'custom_query_id');});
$brokenProvider=new LiveAcceptanceProvider(static function()use($profiles):array{return $profiles;},$directEvaluator,$ajaxEvaluator,$brokenEvaluator,$selector,$normalizer);
$broken=$brokenProvider->run(array('profile_id'=>'tours','suite'=>'semantic'));
etg_acceptance_same('BLOCKED',$broken['verdict'],'pagination collector failure blocks semantic certification without blaming Tours product parity');
etg_acceptance_same('TEST_INFRASTRUCTURE_FAILURE',$broken['classification'],'pagination no-advance is classified as test infrastructure failure');
etg_acceptance_same('BLOCKED',$broken['tests']['result_count_parity'],'result-count parity is blocked when dataset collection infrastructure fails');
etg_acceptance_same('BLOCKED',$broken['tests']['dataset_id_parity'],'dataset ID parity is blocked when the collector cannot advance pages');
etg_acceptance_same('paged_query_items_do_not_advance',$broken['cases'][2]['direct_dataset']['pagination_failure'],'page-state no-advance guard is explicit');
etg_acceptance_same(true,$broken['cases'][2]['direct_dataset']['infrastructure_failure'],'dataset evidence exposes infrastructure failure explicitly');
etg_acceptance_same(array(),$broken['defect_reasons'],'collector failure does not manufacture a product defect');

$blocked=$provider->run(array('profile_id'=>'missing','suite'=>'semantic'));
etg_acceptance_same('BLOCKED',$blocked['verdict'],'missing governed profile blocks rather than pretending to fail product parity');
etg_acceptance_expect(in_array('profile_not_found',$blocked['blocking_reasons'],true),'profile block reason is explicit');

$arbitrary=$provider->plan(array('profile_id'=>'tours','suite'=>'semantic','url'=>'https://example.com'));
etg_acceptance_same('blocked',$arbitrary['state'],'arbitrary URL/request fields fail closed');
etg_acceptance_expect(in_array('unsupported_request_fields',$arbitrary['blocking_reasons'],true),'generic debugger inputs are rejected');

$mismatchAjax=static function(array $payload):array{
    $clause=$payload['current_query']['tax_query'][0]??array();
    $taxonomy=(string)($clause['taxonomy']??'');
    return array(
        'profile_id'=>'tours','provider'=>$payload['provider'],'query_id'=>$payload['query_id'],'archive_path'=>$payload['archive_path'],
        'filter_values'=>array($taxonomy=>array('luxor')),'in_scope'=>true,'scope_valid'=>true,'runtime_ready'=>true,'authorizing'=>false,'url_authority'=>false,'ajax_only'=>true,
        'presentation_state_complete'=>true,'filtered_query_complete'=>true,'missing_terms'=>array(),'translation_fallback'=>false,'post_type_observation_matches_profile'=>true,
        'filtered_query'=>array('tax_query'=>array('relation'=>'AND',array('taxonomy'=>$taxonomy,'field'=>'slug','terms'=>array('luxor'),'operator'=>'IN','include_children'=>true))),
    );
};
$mismatchProvider=new LiveAcceptanceProvider(static function()use($profiles):array{return $profiles;},$directEvaluator,$mismatchAjax,$queryEvaluator,$selector,$normalizer);
$mismatch=$mismatchProvider->run(array('profile_id'=>'tours'));
etg_acceptance_same('FAIL',$mismatch['verdict'],'real semantic divergence is classified as product failure');
etg_acceptance_same('PRODUCT_DEFECT',$mismatch['classification'],'semantic mismatch is not mislabeled as missing evidence');

fwrite(STDOUT,"PASS: alpha13 live acceptance provider bounded full-dataset collection and pagination reinitialization\n");
