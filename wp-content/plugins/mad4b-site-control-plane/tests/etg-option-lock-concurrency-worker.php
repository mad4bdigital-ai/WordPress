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
    $stored=get_option($option,array());
    if(!is_array($stored))throw new RuntimeException('shared option state is not an array');
    $stored['ci_mutex_alpha']=array('id'=>'ci_mutex_alpha','template'=>'first','enabled'=>true);
    update_option($option,$stored,false);
    $readback=get_option($option,array());
    if(!is_array($readback)||!isset($readback['ci_mutex_alpha']))
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
    $stored=get_option($option,array());
    if(!is_array($stored)||!isset($stored['ci_mutex_alpha']))
        throw new RuntimeException('first worker option write was lost');
    $stored['ci_mutex_beta']=array('id'=>'ci_mutex_beta','template'=>'second','enabled'=>true);
    update_option($option,$stored,false);
    $readback=get_option($option,array());
    if(!isset($readback['ci_mutex_alpha'],$readback['ci_mutex_beta']))
        throw new RuntimeException('second option update lost earlier write');
    unset($readback['ci_mutex_alpha'],$readback['ci_mutex_beta']);
    update_option($option,$readback,false);
    $after=get_option($option,array());
    if(isset($after['ci_mutex_alpha'])||isset($after['ci_mutex_beta']))
        throw new RuntimeException('fixture rollback was incomplete');
    if(!Gate::release($current))throw new RuntimeException('successor cannot release');
}else throw new RuntimeException('unknown test phase');
echo "ETG OPTION LOCK ".$mode.": PASS\n";
