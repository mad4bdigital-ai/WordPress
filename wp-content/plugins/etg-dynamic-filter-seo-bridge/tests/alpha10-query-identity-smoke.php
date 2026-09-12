<?php
declare(strict_types=1);

function sanitize_key($key){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$key));}
function expect_same($expected,$actual,string $message):void{if($expected!==$actual){fwrite(STDERR,"FAILED: {$message}\nExpected: ".var_export($expected,true)."\nActual: ".var_export($actual,true)."\n");exit(1);}}
function expect_true($actual,string $message):void{expect_same(true,(bool)$actual,$message);}

$base=dirname(__DIR__);
require_once $base.'/includes/JetEngine/QueryIdentityResolver.php';
require_once $base.'/includes/Runtime/RuntimeQueryBindingResolver.php';

use ETG\DynamicFilterSEOBridge\JetEngine\QueryIdentityResolver;
use ETG\DynamicFilterSEOBridge\Runtime\RuntimeQueryBindingResolver;
use ETG\DynamicFilterSEOBridge\Runtime\RuntimeTopologyDiscoverer;

$make=static function($id,$custom){$q=new stdClass();$q->id=$id;$q->query_id=$custom;return$q;};

$unique=new QueryIdentityResolver(static function()use($make){return array($make(42,'tours_archive'));});
$result=$unique->resolve('tours_archive');
expect_same(true,$result['resolved'],'unique custom Query ID resolves');
expect_same('tours_archive',$result['custom_query_id'],'custom identity is preserved');
expect_same('42',$result['internal_query_id'],'resolved internal ID is evidence only');
expect_same(1,$result['match_count'],'unique mapping has one match');

$missing=new QueryIdentityResolver(static function()use($make){return array($make(42,'other'));});
$result=$missing->resolve('tours_archive');
expect_same(false,$result['resolved'],'missing custom Query ID fails closed');
expect_same('query_identity_not_found',$result['reason'],'missing mapping reason is explicit');

$duplicate=new QueryIdentityResolver(static function()use($make){return array($make(1,'dup'),$make(70,'dup'));});
$result=$duplicate->resolve('dup');
expect_same(false,$result['resolved'],'duplicate custom Query ID fails closed');
expect_same('query_identity_ambiguous',$result['reason'],'duplicate mapping reason is explicit');
expect_same(2,$result['match_count'],'ambiguity reports all exact custom-ID matches');

$numeric=new QueryIdentityResolver(static function()use($make){return array($make(123,'other'),$make(999,'123'));});
$result=$numeric->resolve('123');
expect_same(true,$result['resolved'],'numeric-looking custom Query ID resolves only by custom namespace');
expect_same('999',$result['internal_query_id'],'numeric-looking custom ID never falls back to internal ID');

$invalid=new QueryIdentityResolver(static function(){return'not-an-inventory';});
$result=$invalid->resolve('x');
expect_same(false,$result['resolved'],'invalid inventory fails closed');
expect_same('query_identity_inventory_unavailable',$result['reason'],'invalid inventory reason is explicit');

/*
 * Profile routes can explicitly bridge a JetSmartFilters provider query ID to a
 * different Query Builder custom ID. This mapping must win over a coincidental
 * same-name Query Builder ID and must fail closed when the configured target is
 * absent or ambiguous.
 */
$bindingIdentity=new QueryIdentityResolver(static function()use($make){
    return array($make(10,'surface_query'),$make(20,'explicit_query'),$make(30,'other_query'));
});
$topology=new RuntimeTopologyDiscoverer(static function(){return array();},static function(){return array();});
$bindingResolver=new RuntimeQueryBindingResolver($topology,$bindingIdentity);
$profile=array(
    'id'=>'tours',
    'routes'=>array(
        array('provider'=>'jet-engine','provider_query_id'=>'surface_query','query_builder_query_id'=>'explicit_query'),
    ),
);
$binding=$bindingResolver->resolve('jet-engine','surface_query',$profile);
expect_same(true,$binding['resolved'],'explicit profile Query Builder mapping resolves');
expect_same('explicit_query',$binding['query_builder_custom_query_id'],'explicit profile mapping wins over same-name direct custom ID');
expect_same('20',$binding['query_builder_internal_id'],'explicit mapping resolves through stable custom-ID namespace');
expect_same('profile_explicit_query_builder_query_id',$binding['source'],'explicit mapping source is observable');

$missingExplicitProfile=array(
    'id'=>'tours',
    'routes'=>array(
        array('provider'=>'jet-engine','provider_query_id'=>'surface_query','query_builder_query_id'=>'missing_explicit'),
    ),
);
$binding=$bindingResolver->resolve('jet-engine','surface_query',$missingExplicitProfile);
expect_same(false,$binding['resolved'],'missing explicit Query Builder mapping fails closed');
expect_same('profile_explicit_query_identity_not_found',$binding['reason'],'missing explicit mapping does not fall back to a coincidental direct identity');

$ambiguousExplicitProfile=array(
    'id'=>'tours',
    'routes'=>array(
        array('provider'=>'jet-engine','provider_query_id'=>'surface_query','query_builder_query_id'=>'explicit_query'),
        array('provider'=>'jet-engine','provider_query_id'=>'surface_query','query_builder_query_id'=>'other_query'),
    ),
);
$binding=$bindingResolver->resolve('jet-engine','surface_query',$ambiguousExplicitProfile);
expect_same(false,$binding['resolved'],'conflicting explicit profile mappings fail closed');
expect_same('profile_explicit_query_binding_ambiguous',$binding['reason'],'conflicting explicit mapping reason is explicit');
expect_same(2,$binding['match_count'],'conflicting explicit mappings report unique target count');

$directProfile=array('id'=>'tours','routes'=>array(array('provider'=>'jet-engine','provider_query_id'=>'surface_query')));
$binding=$bindingResolver->resolve('jet-engine','surface_query',$directProfile);
expect_same(true,$binding['resolved'],'same-name direct identity remains backward-compatible when no explicit bridge exists');
expect_same('surface_query',$binding['query_builder_custom_query_id'],'direct custom ID remains the resolved Query Builder identity');
expect_same('provider_query_id_equals_query_builder_custom_id',$binding['source'],'direct compatibility source remains observable');

foreach(array('Runtime/PostTypeObserver.php','SEO/JetEngineResultCountAdapter.php','SEO/PublicationResultCountProbe.php') as $relative){
    $source=file_get_contents($base.'/includes/'.$relative);
    expect_same(false,false!==strpos($source,'get_query_by_id'),'custom route identity consumer must not use get_query_by_id: '.$relative);
    expect_true(false!==strpos($source,'RuntimeQueryBindingResolver'),'Alpha11 consumer must resolve provider namespace through shared runtime binding resolver: '.$relative);
    expect_true(false!==strpos($source,'QueryIdentityResolver'),'Alpha10 exact custom-ID resolver remains the compatibility authority under the binding layer: '.$relative);
}
$bindingSource=file_get_contents($base.'/includes/Runtime/RuntimeQueryBindingResolver.php');
expect_true(false!==strpos($bindingSource,'QueryIdentityResolver'),'runtime binding delegates stable Query Builder identity to Alpha10 resolver');
expect_true(false!==strpos($bindingSource,'profile_explicit_query_builder_query_id'),'runtime binding honors exact profile namespace bridge before compatibility fallback');
expect_true(false!==strpos($bindingSource,'provider_query_id_equals_query_builder_custom_id'),'direct Alpha10 identity behavior remains supported');
expect_true(false!==strpos($bindingSource,'elementor_runtime_topology'),'Alpha11 topology can resolve a distinct provider namespace');

$registrar=file_get_contents($base.'/includes/RankMath/PublicationSitemapRegistrar.php');
expect_true(false!==strpos($registrar,"add_action('update_option_etg_dfsb_settings'"),'publication cache invalidation uses WordPress dynamic update_option hook');
expect_same(false,false!==strpos($registrar,'updated_option_etg_dfsb_settings'),'nonexistent updated_option_<name> hook must not be used');

$endpointCompact=preg_replace('/\s+/','',file_get_contents($base.'/includes/Presentation/AjaxPresentationEndpoint.php'));
expect_true(false!==strpos($endpointCompact,"'presentation_state_complete'=>\$presentationComplete"),'REST presentation completeness remains presentation-specific');
expect_true(false!==strpos($endpointCompact,"'filtered_query_complete'=>\$resultQueryComplete"),'REST filtered query completeness reports result-query completeness rather than presentation completeness');
expect_true(false!==strpos($endpointCompact,"'result_query_complete'=>\$resultQueryComplete"),'backward-compatible result-query completeness field remains correct');

$bootstrap=file_get_contents($base.'/includes/Bootstrap.php');
expect_true(false!==strpos($bootstrap,'$queryIdentityResolver=new QueryIdentityResolver()'),'Bootstrap creates one shared QueryIdentityResolver');
expect_true(false!==strpos($bootstrap,'$queryBindingResolver=new RuntimeQueryBindingResolver($topology,$queryIdentityResolver)'),'Bootstrap layers one shared runtime binding resolver over Alpha10 identity authority');
expect_true(false!==strpos($bootstrap,'new JetEngineResultCountAdapter($queryBindingResolver)'),'request-time result count receives shared binding resolver');
expect_true(false!==strpos($bootstrap,'new PostTypeObserver($queryBindingResolver)'),'Post Type observer receives shared binding resolver');
expect_true(false!==strpos($bootstrap,'new PublicationResultCountProbe($queryBindingResolver)'),'publication probe receives shared binding resolver');

fwrite(STDOUT,"Alpha10 Query Builder custom-ID guarantees and explicit profile bindings retained under Alpha11 topology.\n");
