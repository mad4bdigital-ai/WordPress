<?php
/**
 * Reversible media quarantine for exact, known mismatches on two MAD4B sites.
 * Never deletes attachments or modifies rates, translations or published status.
 */
defined('ABSPATH') && defined('WP_CLI') && WP_CLI || exit(2);
$site = rtrim(home_url('/'), '/');
if (!in_array($site,array('https://tours.dev.mad4b.com','https://wayfatours.com'),true)) {
    WP_CLI::error('site_not_allowlisted');
}
$production=$site==='https://wayfatours.com';
$apply = defined('MAD4B_MEDIA_QUARANTINE_APPLY') && MAD4B_MEDIA_QUARANTINE_APPLY === true;
$rollback = defined('MAD4B_MEDIA_QUARANTINE_ROLLBACK') && MAD4B_MEDIA_QUARANTINE_ROLLBACK === true;
if ($production && ($apply || $rollback) &&
    !(defined('MAD4B_MEDIA_WAYFA_APPROVED') && MAD4B_MEDIA_WAYFA_APPROVED===true)) {
    WP_CLI::error('production_explicit_guard_missing');
}
$key='mad4b_transport_media_quarantine_20261119_v1';
$fields=array('trans_gellery','trans_grid_image_1_middle_upper','trans_grid_image_2',
 'trans_grid_image_3_middle_bottom','trans_grid_image_4','trans_image_vertical');
$knownTrains=$production
 ? array(45202,54470,54552,54553,54554,54555)
 : array(45202,54470,54535,54536,54552,54553,54554,54555,54558,54559,54560,54561);
$targetIds=array_merge(array(39707),$knownTrains);
$backup=get_option($key,false);
if ($rollback) {
    if (!is_array($backup) || !$backup['items']) WP_CLI::error('rollback_backup_absent');
    foreach ($backup['items'] as $id=>$item) {
        foreach ($item['changed'] as $field) {
            $current=get_post_meta((int)$id,$field,true);
            if ($field==='thumbnail') $current=get_post_thumbnail_id((int)$id);
            if ($current && $current!=='') WP_CLI::error('rollback_media_field_changed_'.$id.'_'.$field);
        }
        if (get_post_meta((int)$id,'_mad4b_transport_media_quarantine_v1',true)!=='known_mismatched_media') {
            WP_CLI::error('rollback_marker_changed_'.$id);
        }
    }
    foreach ($backup['items'] as $id=>$item) {
        foreach ($item['changed'] as $field) {
            $prior=$item['previous'][$field];
            if ($field==='thumbnail') {
                if ($prior) set_post_thumbnail((int)$id,(int)$prior);
                else delete_post_thumbnail((int)$id);
            } elseif ($prior['exists']) update_post_meta((int)$id,$field,$prior['value']);
            else delete_post_meta((int)$id,$field);
        }
        if ($item['review_before']['exists'])
            update_post_meta((int)$id,'_mad4b_transport_media_quarantine_v1',$item['review_before']['value']);
        else delete_post_meta((int)$id,'_mad4b_transport_media_quarantine_v1');
    }
    WP_CLI::log(wp_json_encode(array('status'=>'rolled_back','records'=>count($backup['items']))));
    return;
}
$plans=array();
foreach ($targetIds as $id) {
    $p=get_post($id);
    if (!$p || $p->post_type!=='transportations' || $p->post_status!=='publish') {
        WP_CLI::error('unexpected_public_transport_'.$id);
    }
    $type=(int)get_post_meta($id,'trans_type',true);
    $isFadil=$id===39707;
    if (!$isFadil && !in_array($type,array(2419,579,2420),true)) {
        WP_CLI::error('train_type_changed_'.$id);
    }
    $previous=array();$changed=array();
    foreach ($fields as $field) {
        $value=get_post_meta($id,$field,true);
        $previous[$field]=array('exists'=>metadata_exists('post',$id,$field),'value'=>$value);
        if (!is_string($value) || $value==='') continue;
        $looksMismatched=(bool)preg_match('~(?:vip(?:-\d)?\.webp|2372123_0\.jpg)~i',$value);
        if (!$looksMismatched) WP_CLI::error('media_source_drift_'.$id.'_'.$field);
        $changed[]=$field;
    }
    $thumb=(int)get_post_thumbnail_id($id);
    $expectedThumbnail=($production && $isFadil) ? 39701 : ($type===2419 ? ($production?54419:($id===45202||$id===54470?54419:54581)) : 0);
    $clearThumbnail=$expectedThumbnail>0 && $thumb===$expectedThumbnail;
    if ($expectedThumbnail && $thumb && $thumb!==$expectedThumbnail)
        WP_CLI::error('featured_image_changed_'.$id);
    if ($clearThumbnail) {
        $previous['thumbnail']=$thumb;
        $changed[]='thumbnail';
    }
    if ($changed) {
        $plans[$id]=array('title'=>$p->post_title, 'changed'=>$changed,
            'previous'=>$previous,
            'review_before'=>array('exists'=>metadata_exists('post',$id,'_mad4b_transport_media_quarantine_v1'),
            'value'=>get_post_meta($id,'_mad4b_transport_media_quarantine_v1',true)));
    }
}
if (!$apply) { WP_CLI::log(wp_json_encode(array('status'=>'dry_run','site'=>$site,
    'affected'=>count($plans),'items'=>array_map(static fn($x)=>array('title'=>$x['title'],'changed'=>$x['changed']),$plans)))); return; }
if (get_option($key,false)!==false) WP_CLI::error('prior_quarantine_backup_present');
$admin=get_user_by('id',1);
if (!$admin || !user_can($admin,'manage_options')) WP_CLI::error('admin_unavailable');
wp_set_current_user((int)$admin->ID);
if (!add_option($key,array('site'=>$site,'at'=>gmdate('c'),'items'=>$plans),'',false))
    WP_CLI::error('backup_write_failed');
foreach ($plans as $id=>$plan) {
    foreach ($plan['changed'] as $field) {
        if ($field==='thumbnail') delete_post_thumbnail((int)$id);
        else delete_post_meta((int)$id,$field);
    }
    update_post_meta((int)$id,'_mad4b_transport_media_quarantine_v1','known_mismatched_media');
}
foreach ($plans as $id=>$plan) foreach ($plan['changed'] as $field) {
    $value=$field==='thumbnail' ? get_post_thumbnail_id((int)$id) : get_post_meta((int)$id,$field,true);
    if ($value && $value!=='') WP_CLI::error('post_apply_verification_failed_'.$id.'_'.$field);
}
WP_CLI::log(wp_json_encode(array('status'=>'applied','site'=>$site,'affected'=>count($plans),
    'removed_media_relations'=>array_sum(array_map(static fn($p)=>count($p['changed']),$plans)),
    'attachments_deleted'=>0,'rate_mutations'=>0,'rollback_available'=>true)));
