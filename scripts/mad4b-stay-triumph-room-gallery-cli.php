<?php
/**
 * MAD4B native JetEngine Room Gallery Extension — exact official Triumph rooms.
 * WP-CLI only. Supports dry-run, apply and rollback (separate owner confirmation).
 * Never changes Featured Image, Unit post status, rates or inventory.
 */
if(!defined('ABSPATH')||!defined('WP_CLI')||!WP_CLI)
    throw new RuntimeException('WP-CLI only');
require_once ABSPATH.'wp-admin/includes/image.php';
require_once ABSPATH.'wp-admin/includes/file.php';
require_once ABSPATH.'wp-admin/includes/media.php';
const MAD4B_ROOM_GALLERY_META='_mad4b_unit_room_gallery_extension_v1';
const MAD4B_ROOM_GALLERY_SOURCE='/tmp/mad4b-triumph-gallery-extra-20261119';
function mad4b_room_gallery_details(int $id,array $items):array {
    $p=get_post($id);
    if(!$p||$p->post_type!=='units'||$p->post_status!=='draft')
        return ['status'=>'not_a_draft_unit'];
    $base=get_post_meta($id,'_mad4b_unit_photo_curation_v1',true);
    $attachment=absint($base['attachment_id']??0);
    $ref=get_post_meta($id,'_mad4b_unit_official_room_photo_reference_v1',true);
    if(!$attachment||!is_array($ref)||!is_array($base)
        ||get_post_thumbnail_id($id)!==$attachment
        ||(string)get_post_meta($id,'unit_gellery',true)!==(string)$attachment)
        return ['status'=>'base_photo_or_gallery_changed'];
    if(get_post_meta($id,MAD4B_ROOM_GALLERY_META,true))
        return ['status'=>'already_extended'];
    if(count($items)>2||!$items)return ['status'=>'invalid_gallery_count'];
    $seen=[];$paths=[];$sources=[];
    foreach($items as $row){
        if($id!==absint($row['unit_id']??0)
            ||$ref['source_room_page']!==($row['source_page']??'')
            ||$ref['room_name']!==($row['room_name']??'')
            ||absint($ref['property_id'])!==absint($row['property_id']??0))
            return ['status'=>'source_page_or_room_mismatch'];
        $host=wp_parse_url((string)($row['image_url']??''),PHP_URL_HOST);
        $path=wp_parse_url((string)($row['image_url']??''),PHP_URL_PATH);
        if($host!=='triumphhotel.com'
            || !preg_match('~^/media/room_images/[a-zA-Z0-9-]+\.jpe?g$~D',(string)$path))
            return ['status'=>'untrusted_room_image'];
        $filename=(string)($row['filename']??'');
        if(!preg_match('/^gallery-room-[0-9]+-[0-9a-f]{12}\.jpg$/D',$filename))
            return ['status'=>'invalid_gallery_filename'];
        $file=realpath(MAD4B_ROOM_GALLERY_SOURCE.'/'.$filename);
        $root=realpath(MAD4B_ROOM_GALLERY_SOURCE);
        if(!$file||!$root||strpos($file,$root.DIRECTORY_SEPARATOR)!==0
            ||filesize($file)>4*1024*1024)
            return ['status'=>'unsafe_gallery_file'];
        $sha=hash_file('sha256',$file);
        if(!hash_equals((string)($row['sha256']??''),$sha)
            ||$sha===$base['source_sha256']||isset($seen[$sha]))
            return ['status'=>'gallery_checksum_duplicate'];
        $img=@getimagesize($file);
        if(!$img||$img[0]<800||$img[1]<480||$img['mime']!=='image/jpeg')
            return ['status'=>'invalid_gallery_dimensions'];
        global $wpdb;
        $already=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta}
             WHERE meta_key=%s AND meta_value=%s",
            '_mad4b_unit_photo_source_sha256_v1',$sha));
        if($already)return ['status'=>'gallery_image_already_imported'];
        $seen[$sha]=1;$paths[]=$file;$sources[]=$row;
    }
    return ['status'=>'eligible','unit'=>$id,'featured'=>$attachment,'paths'=>$paths,'sources'=>$sources];
}
function mad4b_room_gallery_add(array $valid):array {
    $id=$valid['unit'];$base=$valid['featured'];
    $attachments=[];
    foreach($valid['paths'] as $i=>$file){
        $r=$valid['sources'][$i];$sha=$r['sha256'];
        $name='mad4b-unit-'.$id.'-gallery-'.substr($sha,0,12).'.jpg';
        $saved=wp_upload_bits($name,null,file_get_contents($file));
        if(!empty($saved['error'])||empty($saved['file']))break;
        $aid=wp_insert_attachment([
          'post_mime_type'=>'image/jpeg',
          'post_title'=>sanitize_text_field((string)$r['room_name'].' — Gallery'),
          'post_excerpt'=>'Official room gallery: '.$r['source_page'],
          'post_content'=>'Original source: '.$r['image_url'],
          'post_status'=>'inherit'
        ],$saved['file'],$id,true);
        if(is_wp_error($aid)||!$aid){
            @unlink($saved['file']);break;
        }
        $aid=(int)$aid;$attachments[]=$aid;
        $metadata=wp_generate_attachment_metadata($aid,$saved['file']);
        if(!is_array($metadata)||empty($metadata['width'])||empty($metadata['height']))
            break;
        wp_update_attachment_metadata($aid,$metadata);
        update_post_meta($aid,'_wp_attachment_image_alt',
            sanitize_text_field(get_the_title($id).' — Additional room view'));
        update_post_meta($aid,'_mad4b_unit_photo_source_sha256_v1',$sha);
        update_post_meta($aid,'_mad4b_unit_photo_provenance_v1',[
          'schema'=>'mad4b.stay-room-gallery-owner-approval.v1',
          'unit_id'=>$id,'property_id'=>$r['property_id'],
          'original_image_url'=>$r['image_url'],
          'hotel_source_page'=>$r['source_page'],
          'image_sha256'=>$sha,
          'approved_use_basis'=>'user_attested_public_source_use',
          'rights_independently_verified'=>false,
          'original_rights_status'=>'external_source_reference_only_not_licensed_for_copy',
          'recorded_at'=>gmdate('c')
        ]);
    }
    if(count($attachments)!==count($valid['paths'])){
        foreach($attachments as $aid)wp_delete_attachment($aid,true);
        return ['status'=>'attachment_generation_failed_reverted'];
    }
    $new=implode(',',array_merge([$base],$attachments));
    if(!update_post_meta($id,'unit_gellery',$new,(string)$base)){
        foreach($attachments as $aid)wp_delete_attachment($aid,true);
        return ['status'=>'gallery_compare_and_swap_failed_reverted'];
    }
    $rec=['schema'=>'mad4b.stay-gallery-extension.v1',
        'unit_id'=>$id,'featured_unchanged'=>$base,
        'before_gallery'=>(string)$base,'after_gallery'=>$new,
        'added_attachments'=>$attachments,
        'source_sha256'=>array_column($valid['sources'],'sha256'),
        'owner_attested_public_source_use'=>true,'recorded_at'=>gmdate('c')];
    if(!add_post_meta($id,MAD4B_ROOM_GALLERY_META,$rec,true)){
        update_post_meta($id,'unit_gellery',(string)$base,$new);
        foreach($attachments as $aid)wp_delete_attachment($aid,true);
        return ['status'=>'gallery_audit_failed_reverted'];
    }
    return ['status'=>'applied','unit_id'=>$id,'gallery'=>$new,'new_attachments'=>$attachments];
}
function mad4b_room_gallery_revert(int $id,bool $apply):array {
    $r=get_post_meta($id,MAD4B_ROOM_GALLERY_META,true);
    if(!is_array($r)||($r['schema']??'')!=='mad4b.stay-gallery-extension.v1')
        return ['status'=>'no_gallery_extension'];
    if(get_post_status($id)!=='draft'
        ||(int)get_post_thumbnail_id($id)!==(int)($r['featured_unchanged']??0)
        ||(string)get_post_meta($id,'unit_gellery',true)!==($r['after_gallery']??'')
        ||!is_array($r['added_attachments']??null))
        return ['status'=>'gallery_changed_since_apply'];
    global $wpdb;
    foreach($r['added_attachments'] as $aid){
      $aid=(int)$aid;$p=get_post($aid);
      if(!$p||$p->post_type!=='attachment'||(int)$p->post_parent!==$id)
          return ['status'=>'gallery_attachment_changed'];
      $count=(int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta}
          WHERE post_id<>%d AND meta_key=%s AND meta_value LIKE %s",
        $id,'unit_gellery','%'.$aid.'%'));
      if($count)return ['status'=>'gallery_attachment_referenced_elsewhere'];
    }
    if(!$apply)return ['status'=>'rollback_eligible','unit_id'=>$id,
        'gallery'=>$r['after_gallery']];
    if(!update_post_meta($id,'unit_gellery',$r['before_gallery'],$r['after_gallery']))
        return ['status'=>'gallery_rollback_failed'];
    delete_post_meta($id,MAD4B_ROOM_GALLERY_META);
    foreach($r['added_attachments'] as $aid)wp_delete_attachment((int)$aid,true);
    return ['status'=>'rolled_back','unit_id'=>$id,'restored_gallery'=>$r['before_gallery']];
}
$mode=getenv('MAD4B_STAY_GALLERY_MODE')?:'dry-run';
if(!in_array($mode,['dry-run','apply','rollback-preview','rollback'],true))
    WP_CLI::error('Invalid mode');
if(in_array($mode,['apply','rollback'],true)
    &&getenv('MAD4B_STAY_GALLERY_OWNER_APPROVED')!=='1')
    WP_CLI::error('Owner confirmation required');
$ids=array_values(array_unique(array_filter(array_map('intval',
    explode(',',(string)(getenv('MAD4B_STAY_GALLERY_UNIT_IDS')?:''))))));
if(!$ids||count($ids)>6)WP_CLI::error('1–6 exact Unit IDs required');
$manifest=json_decode((string)@file_get_contents(MAD4B_ROOM_GALLERY_SOURCE.'/manifest.json'),true);
if(!is_array($manifest)||!is_array($manifest['records']??null)
    ||count($manifest['records'])!==35)WP_CLI::error('Gallery manifest unavailable');
$byUnit=[];
foreach($manifest['records'] as $r){
    if(($r['status']??'')!=='downloaded')WP_CLI::error('Gallery source not downloaded');
    $byUnit[(int)$r['unit_id']][]=$r;
}
$out=[];
foreach($ids as $id){
    $lock='mad4b_stay_unit_gallery_lock_v1_'.$id;
    if($mode==='rollback'||$mode==='rollback-preview'){
        $out[]=mad4b_room_gallery_revert($id,$mode==='rollback');continue;
    }
    if(!isset($byUnit[$id])){$out[]=['unit'=>$id,'status'=>'manifest_unit_unknown'];continue;}
    if($mode==='apply'&&!add_option($lock,(string)time(),'','no')){
        $out[]=['unit'=>$id,'status'=>'gallery_operation_locked'];continue;
    }
    try{
        $valid=mad4b_room_gallery_details($id,$byUnit[$id]);
        $out[]=($mode==='apply'&&$valid['status']==='eligible')
            ?mad4b_room_gallery_add($valid)
            :['unit_id'=>$id,'status'=>$valid['status']];
    }finally{
        if($mode==='apply')delete_option($lock);
    }
}
WP_CLI::line(wp_json_encode(['mode'=>$mode,'items'=>$out],
    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
