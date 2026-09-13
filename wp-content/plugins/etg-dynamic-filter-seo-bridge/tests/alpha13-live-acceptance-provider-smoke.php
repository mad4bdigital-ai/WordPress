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
    public function setup_query(){}
    public function set_filtered_prop($prop,$value){$this->filtered[$prop]=$value;}
    private function slug():string{
        $tax=(array)($this->filtered['tax_query']??array());
        foreach($tax as $clause){if(is_array($clause)&&isset($clause['terms'][0]))return (string)$clause['terms'][0];}
        return '';
    }
    public function get_items_total_count(){return 'cairo'===$this->slug()?3:('luxor'===$this->slug()?2:0);}
    public function get_items(){
        if('cairo'===$this->slug())return array((object)array('ID'=>11),(object)array('ID'=>12),(object)array('ID'=>13));
        if('luxor'===$this->slug())return array((object)array('ID'=>21),(object)array('ID'=>22));
        return array();
    }
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
etg_acceptance_same('PASS',$result['verdict'],'semantic parity passes when direct and AJAX normalize to same state and dataset');
etg_acceptance_same(true,$result['verification']['semantic_parity_verified'],'semantic verification is explicit');
etg_acceptance_same(false,$result['verification']['browser_runtime_parity_verified'],'browser runtime is never self-certified by PHP');
etg_acceptance_same('INCOMPLETE_EVIDENCE',$result['browser_runtime'],'browser evidence remains independent');
etg_acceptance_same(array('browser_runtime_not_observed'),$result['incomplete_evidence'],'browser gap is reported separately from semantic verdict');
etg_acceptance_same(false,$result['authority']['authorizing'],'acceptance creates no authority');
etg_acceptance_same(false,$result['effects']['business_state_mutation'],'acceptance does not mutate business state');
etg_acceptance_same(true,$result['cases'][0]['ids_parity'],'full dataset IDs are compared when complete');
etg_acceptance_same(true,$result['cases'][0]['result_count_parity'],'result count parity is checked');
etg_acceptance_same(true,$result['cases'][0]['seo_non_authority'],'AJAX SEO non-authority is checked');

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

fwrite(STDOUT,"PASS: alpha13 live acceptance provider semantic foundation\n");
