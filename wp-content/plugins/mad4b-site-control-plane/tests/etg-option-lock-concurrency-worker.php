<?php
/** Genuine cross-process option mutex race test in disposable WordPress + MySQL. */
if (!defined('ABSPATH')) throw new RuntimeException('WordPress is required');
require_once WP_PLUGIN_DIR . '/etg-dynamic-filter-seo-bridge/includes/Runtime/OptionMutationGate.php';
use ETG\DynamicFilterSEOBridge\Runtime\OptionMutationGate as Gate;
$mode=(string)getenv('ETG_LOCK_MODE');
$marker=(string)getenv('ETG_LOCK_MARKER');
if(!preg_match('#^/tmp/[a-zA-Z0-9._-]{5,150}$#D',$marker))throw new RuntimeException('bad marker');
$option='etg_dfsb_dynamic_content_slots';
if($mode==='hold'){
    $lease=Gate::acquire($option);
    if(is_wp_error($lease))throw new RuntimeException('first DB lock failed');
    $slots=new \ETG\DynamicFilterSEOBridge\Presentation\ContentSlotRegistry();
    $first=$slots->save(array('id'=>'ci_mutex_alpha','label'=>'Gate A',
        'template'=>'first','enabled'=>true,'type'=>'text'),$slots->revision());
    if(empty($first['saved']) || !$slots->get('ci_mutex_alpha'))
        throw new RuntimeException('first WordPress option mutation failed');
    file_put_contents($marker.'.lease',wp_json_encode($lease));
    file_put_contents($marker.'.locked','yes');
    $deadline=microtime(true)+20;
    while(!file_exists($marker.'.release') && microtime(true)<$deadline)usleep(100000);
    if(!file_exists($marker.'.release'))throw new RuntimeException('hold timed out; retained lock requires cleanup');
    if(!Gate::release($lease))throw new RuntimeException('initial conditional release failed');
    file_put_contents($marker.'.done','yes');
}elseif($mode==='deny'){
    if(!file_exists($marker.'.locked'))throw new RuntimeException('hold did not start');
    $attempt=Gate::acquire($option);
    if(!is_wp_error($attempt)||$attempt->get_error_code()!=='mad4b_etg_target_locked')
        throw new RuntimeException('second PHP worker acquired DB mutex');
}elseif($mode==='handover'){
    if(!file_exists($marker.'.done'))throw new RuntimeException('first worker incomplete');
    $old=json_decode((string)file_get_contents($marker.'.lease'),true);
    if(!is_array($old))throw new RuntimeException('missing prior ownership evidence');
    $current=Gate::acquire($option);
    if(is_wp_error($current))throw new RuntimeException('second owner cannot acquire after release');
    if(Gate::release($old))throw new RuntimeException('former owner deleted newer lease');
    if(!is_wp_error(Gate::acquire($option)))throw new RuntimeException('former owner unlocked a successor');
    $slots=new \ETG\DynamicFilterSEOBridge\Presentation\ContentSlotRegistry();
    if(!$slots->get('ci_mutex_alpha'))throw new RuntimeException('first worker option write was lost');
    $second=$slots->save(array('id'=>'ci_mutex_beta','label'=>'Gate B',
        'template'=>'second','enabled'=>true,'type'=>'text'),$slots->revision());
    if(empty($second['saved']) || !$slots->get('ci_mutex_alpha')
        || !$slots->get('ci_mutex_beta'))throw new RuntimeException('second option update lost earlier slot');
    if(!$slots->delete('ci_mutex_alpha',$slots->revision())
        || !$slots->delete('ci_mutex_beta',$slots->revision()))
        throw new RuntimeException('source fixture cleanup failed');
    if($slots->get('ci_mutex_alpha') || $slots->get('ci_mutex_beta'))
        throw new RuntimeException('fixture rollback was incomplete');
    if(!Gate::release($current))throw new RuntimeException('successor cannot release');
}else throw new RuntimeException('unknown test phase');
echo "ETG OPTION LOCK ".$mode.": PASS\n";
