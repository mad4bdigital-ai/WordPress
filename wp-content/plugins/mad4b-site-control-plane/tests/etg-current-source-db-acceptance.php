<?php
/** Real disposable WordPress + MySQL acceptance of the *current PR source*, not the pinned legacy ETG fixture. */
if (!defined('ABSPATH') || !defined('ETG_DFSB_DIR') || 'production'===wp_get_environment_type())
    throw new RuntimeException('Non-production installed ETG WordPress runtime required');
require_once ETG_DFSB_DIR . 'includes/Runtime/OptionMutationGate.php';
require_once ETG_DFSB_DIR . 'includes/Presentation/ContentSlotRegistry.php';
use ETG\DynamicFilterSEOBridge\Runtime\OptionMutationGate as Gate;
use ETG\DynamicFilterSEOBridge\Presentation\ContentSlotRegistry as Slots;
$option=Slots::OPTION_NAME;
$slots=new Slots();
$id='ci_governed_candidate';
$before=$slots->get($id);
if($before)throw new RuntimeException('Disposable fixture already exists; refusing overwrite');
$lease=Gate::acquire($option);
if(is_wp_error($lease))throw new RuntimeException('Candidate option lock acquisition failed');
try {
    $contender=Gate::acquire($option);
    if(!is_wp_error($contender)||'mad4b_etg_target_locked'!==$contender->get_error_code())
        throw new RuntimeException('Second local contender was incorrectly admitted');
    $revision=$slots->revision();
    $saved=$slots->save(array('id'=>$id,'label'=>'CI current source',
        'enabled'=>true,'type'=>'text','template'=>'candidate-first'),$revision);
    if(empty($saved['saved'])||!$slots->get($id))
        throw new RuntimeException('Native candidate slot save/readback failed');
    $stale=$slots->save(array('id'=>$id,'label'=>'CI stale','template'=>'stale'),$revision);
    if(!empty($stale['saved'])||'stale_revision'!==($stale['reason']??''))
        throw new RuntimeException('Stale candidate option revision was accepted');
    if(!$slots->delete($id,$slots->revision())||$slots->get($id))
        throw new RuntimeException('Native candidate slot restore/readback failed');
} finally {
    if(!Gate::release($lease))
        throw new RuntimeException('Candidate owner-token atomic SQL lock release failed');
}
echo "ETG CURRENT SOURCE REAL DB SLOT CAS + MUTEX + RESTORE: PASS (disposable WP only)\n";

// Exercise the new native storage service on actual WordPress/MySQL. This does
// not certify or grant the separate MCP writer or the front-end runtime Provider.
if (!class_exists('\\ETG\\DynamicFilterSEOBridge\\IndexFlow\\RouteDocumentStore'))
    throw new RuntimeException('Current IndexFlow storage service is missing');
$store = new \ETG\DynamicFilterSEOBridge\IndexFlow\RouteDocumentStore();
$original = $store->snapshot();
if ($original['exists']) throw new RuntimeException('Disposable route registry must start absent');
$lease = Gate::acquire(\ETG\DynamicFilterSEOBridge\IndexFlow\RouteDocumentStore::OPTION);
if (is_wp_error($lease)) throw new RuntimeException('Route registry mutex unavailable');
try {
    $document = array('version'=>1,'profiles'=>array(),'routes'=>array());
    $plan = $store->plan($document);
    $store->apply($document,$plan);
    $first = $store->snapshot();
    wp_cache_set(\ETG\DynamicFilterSEOBridge\IndexFlow\RouteDocumentStore::OPTION,array('stale'=>'cache'),'options');
    if ($store->snapshot() !== $first) throw new RuntimeException('Route snapshot trusted stale WordPress option cache');
    $changed = $document;
    $changed['profiles']['ci'] = array('strategy'=>'native','provider'=>'jet-engine','query_id'=>'CI_Exact',
        'bases'=>array('en'=>'/ci-route-test/'),'dimensions'=>array(array('name'=>'location','taxonomy'=>'category','required'=>true)));
    $store->apply($changed,$store->plan($changed));
    $after = $store->snapshot();
    $denied = false;
    try { $store->apply($document,$plan); } catch (InvalidArgumentException $error) { $denied = true; }
    if (!$denied) throw new RuntimeException('Old route plan accepted after real DB mutation');
    $store->restore($first,\ETG\DynamicFilterSEOBridge\IndexFlow\RouteDocumentStore::digest($after));
    if ($store->snapshot() !== $first) throw new RuntimeException('Route undo failed to restore exact initial document');
    $store->restore($original,\ETG\DynamicFilterSEOBridge\IndexFlow\RouteDocumentStore::digest($first));
    if ($store->snapshot() !== $original) throw new RuntimeException('Route undo failed to restore original absence');
} finally {
    if (!Gate::release($lease)) throw new RuntimeException('Route registry atomic lease release failed');
}
echo "INDEXFLOW CURRENT SOURCE REAL DB SNAPSHOT + APPLY + STALE CACHE/PLAN DENIAL + UNDO: PASS (native storage only; MCP/runtime certification absent)\n";
