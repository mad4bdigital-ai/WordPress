<?php
/**
 * Reversal of the 2026-11-19 owner-approved Unit photo batch only.
 * No action by default; requires explicit bounded Unit IDs + rollback flag.
 * Never overwrites later editorial edits.
 */
if(!defined('ABSPATH')||!defined('WP_CLI')||!WP_CLI)
    throw new RuntimeException('WP CLI only');
$ids=array_values(array_unique(array_filter(array_map('intval',
    explode(',',(string)(getenv('MAD4B_STAY_MEDIA_UNIT_IDS')?:''))))));
if(!$ids||count($ids)>20)WP_CLI::error('1–20 explicit Unit IDs required');
$apply=(getenv('MAD4B_STAY_MEDIA_ROLLBACK')==='1'
    && getenv('MAD4B_STAY_MEDIA_ROLLBACK_OWNER_CONFIRMED')==='1');
$out=[];
foreach($ids as $id){
    $c=get_post_meta($id,'_mad4b_unit_photo_curation_v1',true);
    if(!is_array($c)||($c['schema']??'')!=='mad4b.stay-room-photo-curation.v1'){
        $out[]=['unit'=>$id,'status'=>'not_curated'];continue;
    }
    $aid=(int)($c['attachment_id']??0);
    $prev=(array)($c['before']??[]);
    $image=get_post($aid);
    $prov=get_post_meta($aid,'_mad4b_unit_photo_provenance_v1',true);
    $valid=$image && $image->post_type==='attachment' &&
        (int)$image->post_parent===$id &&
        is_array($prov) && (int)($prov['unit_id']??0)===$id &&
        ($prov['image_sha256']??'')===($c['source_sha256']??'') &&
        (int)get_post_thumbnail_id($id)===$aid &&
        (string)get_post_meta($id,'unit_gellery',true)===(string)$aid &&
        get_post_status($id)==='draft' &&
        empty($prev['thumbnail']) && ($prev['gallery']??'')==='';
    if(!$valid){$out[]=['unit'=>$id,'status'=>'changed_since_import_rollback_blocked'];continue;}
    if(!$apply){$out[]=['unit'=>$id,'status'=>'reversible','attachment'=>$aid];continue;}
    global $wpdb;
    $used=(int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta}
         WHERE post_id<>%d AND (meta_key=%s OR meta_key=%s)
            AND meta_value LIKE %s",
        $id,'_thumbnail_id','unit_gellery','%'.$aid.'%'));
    if($used){$out[]=['unit'=>$id,'status'=>'attachment_shared_rollback_blocked'];continue;}
    delete_post_meta($id,'_thumbnail_id',$aid);
    delete_post_meta($id,'unit_gellery',(string)$aid);
    delete_post_meta($id,'_mad4b_unit_photo_curation_v1');
    $removed=wp_delete_attachment($aid,true);
    if(!$removed)WP_CLI::warning('Media removal failed, investigate Unit '.$id);
    $out[]=['unit'=>$id,'status'=>$removed?'rolled_back':'media_delete_error'];
}
WP_CLI::line(wp_json_encode(['mode'=>$apply?'rollback':'dry_run',
    'items'=>$out],JSON_UNESCAPED_SLASHES));
