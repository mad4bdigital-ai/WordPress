<?php
/**
 * MAD4B Stay Unit Photo Curation — owner-approved third-party source references.
 * Run via WP-CLI eval-file only, never as a web request or background hook.
 *
 * Permission note: user approved use of publicly accessible images for this site.
 * Public access is not a public-domain license. Preserve original unverified
 * rights status, source URL, image hash and confirmation provenance. Never
 * claim the hotel granted a license.
 *
 * Read-only default:
 *  MAD4B_STAY_MEDIA_MODE=dry-run wp eval-file SCRIPT
 * Explicit owner-directed apply:
 *  MAD4B_STAY_MEDIA_MODE=apply MAD4B_STAY_MEDIA_USER_APPROVED=1
 *  MAD4B_STAY_MEDIA_UNIT_IDS=70861,70862 wp eval-file SCRIPT
 *
 * Only drafts, explicit curated room-photo source and matching JetEngine relation.
 */
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('WP CLI only');
}
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

final class MAD4B_Unit_Photo_Curation_CLI {
    private const HOSTS=['cache.marriott.com','m.ahstatic.com','www.ahstatic.com',
        'media.iceportal.com','pyramidsparkresort.com','triumphhotel.com'];
    private static function error(string $code):array {
        return ['status'=>'skipped','reason'=>$code];
    }
    private static function existing_hash(string $sha):int {
        global $wpdb;
        $ids=$wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
              WHERE meta_key=%s AND meta_value=%s ORDER BY post_id DESC LIMIT 3",
            '_mad4b_unit_photo_source_sha256_v1',$sha));
        foreach($ids as $id){
            if(get_post_type((int)$id)==='attachment')return (int)$id;
        }
        return 0;
    }
    private static function validate(array $row,string $folder,array &$seen):array {
        $unit=filter_var($row['unit_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $property=filter_var($row['property_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!$unit || !$property ||get_post_type($unit)!=='units'
            ||get_post_status($unit)!=='draft' ||get_post_type($property)!=='properties')
            return self::error('unit_or_property_unavailable');
        $title=html_entity_decode((string)get_the_title($unit),ENT_QUOTES,'UTF-8');
        $parentTitle=html_entity_decode((string)get_the_title($property),ENT_QUOTES,'UTF-8');
        if(preg_match('/\b(?:QA\s+ONLY|fixture|demo|test)\b/i',$title.' '.$parentTitle))
            return self::error('qa_or_test_excluded');
        global $wpdb;
        $rel=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}jet_rel_default
             WHERE rel_id=%d AND parent_object_id=%d AND child_object_id=%d",
            223,$property,$unit));
        if($rel!==1)return self::error('original_relation_mismatch');
        if(get_post_thumbnail_id($unit) || get_post_meta($unit,'unit_gellery',true))
            return self::error('existing_unit_media_preserved');
        if(get_post_meta($unit,'_mad4b_unit_photo_curation_v1',true))
            return self::error('already_curated_preserved');
        $ref=get_post_meta($unit,'_mad4b_unit_official_room_photo_reference_v1',true);
        if(!is_array($ref)||empty($ref['image_url'])||!is_string($ref['image_url']))
            return self::error('original_reference_missing');
        if((string)$ref['image_url'] !== (string)($row['image_url']??'')
            ||(int)($ref['property_id']??0)!==$property
            ||(string)($ref['room_name']??'')!==(string)($row['room_name']??'')
            ||(string)($ref['source_room_page']??'')!==(string)($row['source']??''))
            return self::error('reference_changed_reverify');
        if(($ref['rights_status']??'')!=='external_source_reference_only_not_licensed_for_copy')
            return self::error('unexpected_source_rights_status');
        $imageUrl=(string)$ref['image_url'];
        $page=(string)$ref['source_room_page'];
        if(wp_parse_url($imageUrl,PHP_URL_SCHEME)!=='https'
            || !in_array(strtolower((string)wp_parse_url($imageUrl,PHP_URL_HOST)),self::HOSTS,true)
            ||wp_parse_url($page,PHP_URL_SCHEME)!=='https')
            return self::error('untrusted_external_source');
        $name=(string)($row['file']??'');
        if(!preg_match('/^self-room-[0-9]+-[0-9a-f]{12}\.(?:jpg|webp|png)$/D',$name))
            return self::error('invalid_local_filename');
        $file=realpath($folder.'/'.$name);
        $root=realpath($folder);
        if(!$file || !$root || strpos($file,$root.DIRECTORY_SEPARATOR)!==0
            ||!is_file($file) || filesize($file)>6*1024*1024)
            return self::error('invalid_local_file');
        $sha=hash_file('sha256',$file);
        if(!hash_equals((string)($row['sha256']??''),$sha))
            return self::error('photo_file_checksum_mismatch');
        if(isset($seen[$sha]))return self::error('duplicate_photo_bytes_across_units');
        $seen[$sha]=$unit;
        $previous=self::existing_hash($sha);
        if($previous)return self::error('image_already_imported_attachment_'.$previous);
        $m=getimagesize($file);
        if(!$m||$m[0]<800||$m[1]<480)return self::error('insufficient_image_dimensions');
        $mime=(string)($m['mime']??'');
        if(!in_array($mime,['image/jpeg','image/webp','image/png'],true)
           ||$mime!==(string)($row['mime']??''))
            return self::error('unexpected_image_mime');
        return ['status'=>'eligible','unit_id'=>$unit,'property_id'=>$property,
            'file'=>$file,'sha256'=>$sha,'mime'=>$mime,'size'=>[$m[0],$m[1]],
            'ref'=>$ref,'unit_title'=>$title,'parent_title'=>$parentTitle];
    }
    private static function apply(array $candidate):array {
        $unit=$candidate['unit_id'];
        $property=$candidate['property_id'];
        $before=['thumbnail'=>(int)get_post_thumbnail_id($unit),
            'gallery'=>(string)get_post_meta($unit,'unit_gellery',true)];
        $source=$candidate['ref'];
        $ext=pathinfo($candidate['file'],PATHINFO_EXTENSION);
        $name='mad4b-unit-'.$unit.'-room-'.substr($candidate['sha256'],0,12).'.'.$ext;
        $file=wp_upload_bits($name,null,file_get_contents($candidate['file']));
        if(!empty($file['error'])||empty($file['file']))return self::error('wp_upload_failed');
        $attachment=wp_insert_attachment([
            'post_mime_type'=>$candidate['mime'],
            'post_title'=>$candidate['parent_title'].' — '.($source['room_name']??$candidate['unit_title']),
            'post_excerpt'=>'Room photograph. Original hotel image source: '.$source['source_room_page'],
            'post_content'=>'Source: '.(string)$source['image_url'],
            'post_status'=>'inherit'
        ],$file['file'],$unit,true);
        if(is_wp_error($attachment) || !$attachment){
            @unlink($file['file']);return self::error('attachment_insert_failed');
        }
        $attachment=(int)$attachment;
        $generated=wp_generate_attachment_metadata($attachment,$file['file']);
        if(!is_array($generated)||empty($generated['width'])||empty($generated['height'])){
            wp_delete_attachment($attachment,true);
            return self::error('attachment_metadata_failed');
        }
        wp_update_attachment_metadata($attachment,$generated);
        $alt=$candidate['parent_title'].' — '.(string)($source['room_name']??'Room');
        update_post_meta($attachment,'_wp_attachment_image_alt',sanitize_text_field($alt));
        // Historical source flag remains untouched. Owner permission is a
        // separate assertion, NOT an independent license verification.
        $provenance=[
            'schema'=>'mad4b.stay-room-photo-owner-approval.v1',
            'approved_use_basis'=>'user_attested_public_source_use',
            'rights_independently_verified'=>false,
            'original_rights_status'=>(string)$source['rights_status'],
            'hotel_source_page'=>(string)$source['source_room_page'],
            'original_image_url'=>(string)$source['image_url'],
            'image_sha256'=>$candidate['sha256'],
            'unit_id'=>$unit,'property_id'=>$property,
            'recorded_at'=>gmdate('c')
        ];
        update_post_meta($attachment,'_mad4b_unit_photo_provenance_v1',$provenance);
        update_post_meta($attachment,'_mad4b_unit_photo_source_sha256_v1',$candidate['sha256']);
        $featured=update_post_meta($unit,'_thumbnail_id',$attachment,$before['thumbnail']
            ?:'' );
        $gallery=update_post_meta($unit,'unit_gellery',(string)$attachment,
            $before['gallery']);
        if(!$featured || !$gallery){
            if($featured){
                if($before['thumbnail'])update_post_meta($unit,'_thumbnail_id',$before['thumbnail']);
                else delete_post_meta($unit,'_thumbnail_id',$attachment);
            }
            if($gallery){
                if($before['gallery']!=='')update_post_meta($unit,'unit_gellery',$before['gallery']);
                else delete_post_meta($unit,'unit_gellery',(string)$attachment);
            }
            wp_delete_attachment($attachment,true);
            return self::error('unit_media_write_failed_reverted');
        }
        update_post_meta($unit,'_mad4b_unit_photo_curation_v1',[
            'schema'=>'mad4b.stay-room-photo-curation.v1',
            'attachment_id'=>$attachment,
            'source_sha256'=>$candidate['sha256'],
            'rights_verified'=>false,'approval'=>'owner_attested_public_use',
            'before'=>$before,'applied_at'=>gmdate('c')
        ]);
        return ['status'=>'applied','unit_id'=>$unit,'attachment_id'=>$attachment,
            'dimensions'=>$candidate['size'],'before'=>$before,
            'source_sha256'=>$candidate['sha256']];
    }
    public static function run():void {
        $mode=getenv('MAD4B_STAY_MEDIA_MODE')?:'dry-run';
        if(!in_array($mode,['dry-run','apply'],true))WP_CLI::error('Unknown photo mode');
        if($mode==='apply' && getenv('MAD4B_STAY_MEDIA_USER_APPROVED')!=='1')
            WP_CLI::error('Explicit owner image-use acknowledgment required');
        if(!function_exists('abk_entity_map'))WP_CLI::error('Native accommodation relation unavailable');
        $map=abk_entity_map();
        if(is_wp_error($map)||(int)($map['relation_id']??0)!==223)
            WP_CLI::error('Unexpected relation map');
        $folder='/tmp/mad4b-stay-room-photo-approval-revalidated';
        $path=$folder.'/manifest.json';
        $manifest=json_decode((string)@file_get_contents($path),true);
        if(!is_array($manifest)||!is_array($manifest['records']??null))
            WP_CLI::error('Curated media manifest unavailable');
        $allow=array_values(array_filter(array_map('intval',
            explode(',',(string)(getenv('MAD4B_STAY_MEDIA_UNIT_IDS')?:'')))));
        if($mode==='apply' && !$allow)WP_CLI::error('Explicit bounded unit list required');
        if(count($allow)>20)WP_CLI::error('Unit batch limit is 20');
        $allow=array_fill_keys($allow,true);
        $seen=[];$out=[];$stats=['eligible'=>0,'applied'=>0,'skipped'=>0];
        $rows=array_slice($manifest['records'],0,120);
        foreach($rows as $row){
            $id=(int)($row['unit_id']??0);
            if($mode==='apply' && !isset($allow[$id]))continue;
            $v=self::validate((array)$row,$folder,$seen);
            if($v['status']!=='eligible'){$stats['skipped']++;$out[]=['unit_id'=>$id]+$v;continue;}
            $stats['eligible']++;
            if($mode==='apply'){
                // WP options use a unique DB index. Lock before the last
                // readback to stop concurrent WP-CLI jobs from both importing.
                $lock='mad4b_stay_unit_photo_curation_lock_v1_'.$id;
                if(!add_option($lock,(string)time(),'','no')){
                    $result=self::error('unit_import_locked_by_another_job');
                }else{
                    try{
                        $secondSeen=[];
                        $fresh=self::validate((array)$row,$folder,$secondSeen);
                        $result=($fresh['status']==='eligible')
                            ?self::apply($fresh):$fresh;
                    }finally{
                        delete_option($lock);
                    }
                }
            }else{
                $result=['status'=>'eligible','unit_id'=>$id,
                    'size'=>$v['size'],'property_id'=>$v['property_id']];
            }
            if($result['status']==='applied')$stats['applied']++;
            else if($mode==='apply')$stats['skipped']++;
            $out[]=$result;
            if($mode==='apply' && $result['status']==='applied'){
                file_put_contents('/tmp/mad4b-stay-photo-apply-log-20261119.jsonl',
                    wp_json_encode($result,JSON_UNESCAPED_SLASHES)."\n",FILE_APPEND|LOCK_EX);
            }
        }
        WP_CLI::line(wp_json_encode(['mode'=>$mode,'stats'=>$stats,'items'=>$out],
            JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
}
MAD4B_Unit_Photo_Curation_CLI::run();
