<?php
/**
 * Reversible staging quarantine of two expired/mismatched CCT-migrated rates.
 * Never rewrites historical Mode/Type values or legacy CCT source rows.
 */
if (!defined('ABSPATH')||!defined('WP_CLI')||!WP_CLI) exit(2);
$site=rtrim(home_url('/'),'/');
$staging=($site==='https://tours.dev.mad4b.com');
$wayfa=($site==='https://wayfatours.com' && defined('MAD4B_TRANSPORT_WAYFA_PRODUCTION') &&
    MAD4B_TRANSPORT_WAYFA_PRODUCTION===true);
if (!$staging && !$wayfa) WP_CLI::error('site_not_allowed_or_production_guard_missing');
$cases=array(66570=>1,66571=>2);
$option='mad4b_transport_legacy_quarantine_backup_v1_20261119';
$original=get_option($option,false);
if (defined('MAD4B_TRANSPORT_LEGACY_ROLLBACK')&&MAD4B_TRANSPORT_LEGACY_ROLLBACK) {
    if (!is_array($original)||count($original['items']??array())!==2) WP_CLI::error('rollback_backup_missing');
    foreach ($original['items'] as $id=>$prior) {
        if (get_post_type($id)!=='transport-rates'||get_post_status($id)!=='draft'||
            get_post_meta($id,'_mad4b_transport_quarantine_reason',true)!=='legacy_rate_mode_type_mismatch_expired')
            WP_CLI::error('rollback_conflict_'.$id);
    }
    foreach ($original['items'] as $id=>$prior) {
        $change=wp_update_post(array('ID'=>(int)$id,'post_status'=>$prior['status']),true);
        if (is_wp_error($change)) WP_CLI::error('rollback_post_failed_'.$id);
        foreach (array('stop_sale','_mad4b_transport_quarantine_reason',
            '_mad4b_transport_quarantine_at') as $meta) {
            if ($prior['meta'][$meta]['exists'])
                update_post_meta((int)$id,$meta,$prior['meta'][$meta]['value']);
            else delete_post_meta((int)$id,$meta);
        }
    }
    WP_CLI::log(wp_json_encode(array('status'=>'rolled_back','rates'=>array_keys($original['items']))));return;
}
$before=array();
foreach ($cases as $postId=>$sourceCctId) {
    $post=get_post($postId);
    if (!$post||$post->post_type!=='transport-rates'||$post->post_status!=='publish')
        WP_CLI::error('not_expected_published_rate_'.$postId);
    $sourceId=(int)get_post_meta($postId,'source_cct_id',true);
    $related=(int)get_post_meta($postId,'source_post_id',true);
    $mode=(int)get_post_meta($postId,'trans_mode_term_id',true);
    $type=(int)get_post_meta($postId,'trans_type_term_id',true);
    if ($sourceId!==$sourceCctId||$related!==39707||$mode!==595||$type!==598)
        WP_CLI::error('legacy_mapping_has_changed_'.$postId);
    $parentMode=(int)get_post_meta($related,'trans_mode',true);
    $parentType=(int)get_post_meta($related,'trans_type',true);
    if ($parentMode===$mode||$parentType===$type) WP_CLI::error('mismatch_already_resolved_'.$postId);
    $date=(string)get_post_meta($postId,'valid_to',true);
    if (!$date||$date>=wp_date('Y-m-d')) WP_CLI::error('rate_not_expired_'.$postId);
    $meta=array();
    foreach(array('stop_sale','_mad4b_transport_quarantine_reason','_mad4b_transport_quarantine_at') as $key){
        $meta[$key]=array('exists'=>metadata_exists('post',$postId,$key),
            'value'=>get_post_meta($postId,$key,true));
    }
    $before[$postId]=array('status'=>$post->post_status,'cct_id'=>$sourceCctId,
        'end_date'=>$date,'mode'=>$mode,'type'=>$type,
        'parent_mode'=>$parentMode,'parent_type'=>$parentType,'meta'=>$meta);
}
if (!(defined('MAD4B_TRANSPORT_LEGACY_APPLY')&&MAD4B_TRANSPORT_LEGACY_APPLY)){
    WP_CLI::log(wp_json_encode(array('status'=>'dry_run','items'=>$before)));return;
}
if ($original!==false) WP_CLI::error('existing_quarantine_backup');
$admin=get_user_by('id',1);
if (!$admin||!user_can($admin,'edit_others_posts')) WP_CLI::error('admin_required');
wp_set_current_user($admin->ID);
if (!add_option($option,array('items'=>$before,'at'=>gmdate('c')),'',false))
    WP_CLI::error('backup_write_failed');
foreach ($cases as $id=>$cctId) {
    $result=wp_update_post(array('ID'=>$id,'post_status'=>'draft'),true);
    if (is_wp_error($result)) WP_CLI::error('quarantine_draft_failed_'.$id);
    update_post_meta($id,'stop_sale','true');
    update_post_meta($id,'_mad4b_transport_quarantine_reason','legacy_rate_mode_type_mismatch_expired');
    update_post_meta($id,'_mad4b_transport_quarantine_at',gmdate('c'));
}
foreach ($cases as $id=>$cctId) {
    if(get_post_status($id)!=='draft'||get_post_meta($id,'stop_sale',true)!=='true')
        WP_CLI::error('quarantine_verification_failed_'.$id);
}
WP_CLI::log(wp_json_encode(array('status'=>'quarantined','count'=>count($cases),
    'unchanged_source_cct'=>true,'source_price_changed'=>false,'checkout_authorized'=>false)));
