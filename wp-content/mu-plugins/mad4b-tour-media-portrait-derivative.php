<?php
/**
 * Plugin Name: MAD4B Media Portrait Derivative
 * Description: Source-preserving local portrait crops and WPML Attachment families. Does not update tour fields.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Tour_Media_Portrait_Derivative_V1 {
    const CONTRACT='mad4b.tour-media-portrait-derivative.v1';
    private static function err($code) {return new WP_Error($code,'Media derivative operation blocked.');}
    public static function run(array $m,$create=false) {
        $keys=['contract','site_uuid','post_id','source_attachment_id','source_sha256',
            'crop','localized_alt','review_evidence_sha256'];
        $a=array_keys($m);sort($a);sort($keys);
        if($a!==$keys ||($m['contract']??'')!==self::CONTRACT)
            return self::err('mad4b_portrait_schema_invalid');
        $site=MAD4B_SCP_Site_Profile::status();
        if(empty($site['site_uuid'])||empty($site['origin_match'])
           ||empty($site['environment_match'])||empty($site['deployment_binding_match'])
           ||($m['site_uuid']??'')!==$site['site_uuid'])
            return self::err('mad4b_portrait_site_binding_invalid');
        $pid=absint($m['post_id']);$source=absint($m['source_attachment_id']);
        $plan=MAD4B_Tour_Media_Reconcile_V1::plan($pid,['limit'=>24]);
        if(is_wp_error($plan))return $plan;
        $by_id=[];
        foreach($plan['candidate_catalog']['items'] as $c)$by_id[$c['canonical_attachment_id']]=$c;
        if(!$source||!isset($by_id[$source]))
            return self::err('mad4b_portrait_source_not_tour_local');
        $file=get_attached_file($source);
        if(!is_file((string)$file)||!hash_equals((string)$m['source_sha256'],hash_file('sha256',$file)))
            return self::err('mad4b_portrait_source_hash_invalid');
        $crop=$m['crop'];
        if(!is_array($crop)||array_keys($crop)!==['x','y','width','height'])return self::err('mad4b_portrait_crop_invalid');
        foreach($crop as $n)if(!is_int($n)||$n<0||$n>7000)return self::err('mad4b_portrait_crop_bounds_invalid');
        $info=wp_get_attachment_metadata($source);
        $width=(int)($info['width']??0);$height=(int)($info['height']??0);
        if($crop['width']<600||$crop['height']<800
          ||$crop['width']>=$crop['height']
          ||$crop['x']+$crop['width']>$width
          ||$crop['y']+$crop['height']>$height)
            return self::err('mad4b_portrait_image_size_invalid');
        $family=$plan['wpml_post_family'];
        // WordPress/WPML translation source must be inserted before siblings;
        // the reader returns alphabetically sorted locales (AR before EN).
        $default=(string)apply_filters('wpml_default_language','en');
        if(!isset($family[$default]))
            return self::err('mad4b_portrait_default_locale_missing');
        $family=[$default=>$family[$default]] + $family;
        $alt=$m['localized_alt'];
        if(!is_array($alt))return self::err('mad4b_portrait_locale_alt_invalid');
        $have=array_keys($alt);$need=array_keys($family);sort($have);sort($need);
        if($have!==$need)return self::err('mad4b_portrait_locale_family_incomplete');
        foreach($alt as $lang=>$v)if(!is_string($v)||mb_strlen($v)<30||mb_strlen($v)>190
            ||wp_strip_all_tags($v)!==$v)return self::err('mad4b_portrait_alt_text_invalid');
        $review=(array)MAD4B_SCP_Context_Authority::review_policy();
        if(!preg_match('/^[a-f0-9]{64}$/D',(string)$m['review_evidence_sha256']))
            return self::err('mad4b_portrait_visual_evidence_invalid');
        $hash=hash('sha256',wp_json_encode([$site['site_uuid'],$pid,$source,$m['source_sha256'],$crop]));
        $title=get_the_title($pid).' Portrait Crop From '.$source;
        $up=wp_upload_dir();
        if(!empty($up['error']))return self::err('mad4b_portrait_upload_path_unavailable');
        $basename='mad4b-tour-'.$pid.'-portrait-'.$source.'-'.substr($hash,0,16).'.jpg';
        $dest=trailingslashit($up['path']).$basename;
        $rel=ltrim(str_replace(trailingslashit($up['basedir']),'',$dest),'/');
        $result=['contract'=>self::CONTRACT,'site_uuid'=>$site['site_uuid'],
            'source_post_id'=>$pid,'source_attachment_id'=>$source,
            'source_file_sha256'=>$m['source_sha256'],'crop'=>$crop,
            'estimated_aspect_ratio'=>round($crop['width']/$crop['height'],3),
            'wpml_locales'=>array_keys($family),
            'derivative_relative_path'=>$rel,
            'review_policy'=>$review['mode']??'human_only',
            'source_image_origin_certified'=>false,
            'review_source'=>'visual_observation_without_photo_provenance'];
        if(!$create){$result['status']='dry_run_ready';return $result;}
        if(($review['mode']??'human_only')!=='ai_only')
            return self::err('mad4b_portrait_human_approval_required');
        $lock=MAD4B_SCP_Distributed_Lock::catalog_name('media-portrait:'.$site['site_uuid'].':'.$pid);
        $held=MAD4B_SCP_Distributed_Lock::acquire($lock);
        if(is_wp_error($held))return $held;
        try{
            global $wpdb;
            $existing=$wpdb->get_results($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_mad4b_portrait_derivative_key' AND meta_value=%s ORDER BY post_id LIMIT 20",$hash),ARRAY_A);
            $existing_ids=array_values(array_unique(array_map('intval',array_column($existing?:[],'post_id'))));
            if($existing_ids){
                $default=(string)apply_filters('wpml_default_language','en');
                $source_id=0;
                foreach($existing_ids as $aid){
                    $language=apply_filters('wpml_post_language_details',null,$aid);
                    if(is_array($language)&&($language['language_code']??'')===$default)
                        $source_id=$aid;
                }
                if(!$source_id)return self::err('mad4b_portrait_registered_default_locale_missing');
                $existing_trid=apply_filters('wpml_element_trid',null,$source_id,'post_attachment');
                $translated=MAD4B_WPML_Attachment_Family_Readback_V1::translations($source_id);
                $mapped=[];
                foreach($family as $lang=>$postid){
                    $aid=(int)($translated[$lang]->element_id??0);
                    $linked=$aid?get_attached_file($aid):null;
                    if(!$aid ||!is_file((string)$linked)
                        ||realpath((string)$linked)!==realpath($dest))
                        return self::err('mad4b_portrait_registered_wpml_incomplete');
                    $mapped[$lang]=$aid;
                }
                // The initial six WPML records may have committed before
                // WPML's in-request filter cache refreshed. Reconcile the
                // genuine original records and issue exactly one receipt.
                $receipt_key='mad4b_portrait_reconciled_v1_'.substr($hash,0,40);
                $receipt=get_option($receipt_key,false);
                if(!$receipt){
                    $audit=MAD4B_SCP_Audit::record('mad4b/portrait-derivative-reconciled',[
                        'site_uuid'=>$site['site_uuid'],'source_post_id'=>$pid,
                        'source_attachment_id'=>$source,'derivative_key'=>$hash,
                        'derivative_sha256'=>hash_file('sha256',$dest),
                        'wpml_attachment_ids'=>$mapped,
                        'translation_authority'=>'verified_wpml_icl_translations',
                        'photographic_provenance_certified'=>false,
                        'website_post_media_updated'=>false,
                        'principal'=>'authorized_host_wpcli_no_user_impersonation'
                    ]);
                    if(is_wp_error($audit))
                        return self::err('mad4b_portrait_reconciliation_audit_missing');
                    if(!add_option($receipt_key,['created_at'=>gmdate('c'),
                        'attachment_ids'=>$mapped,'derivative_key'=>$hash],'','no'))
                        return self::err('mad4b_portrait_reconciliation_receipt_conflict');
                }
                return array_merge($result,[
                    'status'=>'already_registered_verified',
                    'derivative_key'=>$hash,'attachment_ids'=>$mapped,
                    'derivative_sha256'=>hash_file('sha256',$dest),
                    'no_new_file'=>true,'wpml_complete'=>true,
                    'audit_receipt_ok'=>true,
                    'tour_meta_updated'=>false
                ]);
            }
            if(file_exists($dest))return self::err('mad4b_portrait_filename_collision');
            $ed=wp_get_image_editor($file);
            if(is_wp_error($ed))return $ed;
            $resize=$ed->crop($crop['x'],$crop['y'],$crop['width'],$crop['height'],
                $crop['width'],$crop['height'],false);
            if(is_wp_error($resize))return $resize;
            $saved=$ed->save($dest,'image/jpeg');
            if(is_wp_error($saved))return $saved;
            @chmod($dest,0644);
            $created=[];
            $trid=null;
            require_once ABSPATH.'wp-admin/includes/image.php';
            foreach($family as $lang=>$postid){
                $translations=$trid && isset($created[$default])
                    ?MAD4B_WPML_Attachment_Family_Readback_V1::translations($created[$default])
                    :[];
                $attachment=isset($translations[$lang])?(int)$translations[$lang]->element_id:0;
                if($attachment){
                    $path=get_attached_file($attachment);
                    if(!is_file((string)$path)||realpath($path)!==realpath($dest))
                        return self::err('mad4b_portrait_wpml_automatic_translation_conflict');
                } else {
                    $attachment=wp_insert_attachment([
                        'post_title'=>$title.' ['.$lang.']',
                        'post_status'=>'inherit',
                        'post_parent'=>$postid,
                        'post_mime_type'=>'image/jpeg',
                        'post_excerpt'=>'',
                        'guid'=>trailingslashit($up['url']).$basename,
                    ],$dest,$postid,true);
                    if(is_wp_error($attachment))return $attachment;
                    $created[$lang]=$attachment;
                }
                if(!$trid){
                    $trid=apply_filters('wpml_element_trid',null,$attachment,'post_attachment');
                }
                do_action('wpml_set_element_language_details',[
                    'element_id'=>$attachment,'element_type'=>'post_attachment',
                    'trid'=>$trid,'language_code'=>$lang,
                    'source_language_code'=>$lang==='en'?null:'en'
                ]);
                if(!$trid)$trid=apply_filters('wpml_element_trid',null,$attachment,'post_attachment');
                update_post_meta($attachment,'_mad4b_portrait_derivative_key',$hash);
                update_post_meta($attachment,'_mad4b_portrait_source_attachment_id',$source);
                update_post_meta($attachment,'_mad4b_original_photography_provenance','unverified');
                update_post_meta($attachment,'_wp_attachment_image_alt',$alt[$lang]);
                if($lang==='en'){
                    $meta=wp_generate_attachment_metadata($attachment,$dest);
                    if(is_array($meta))wp_update_attachment_metadata($attachment,$meta);
                }else{
                    $meta=get_post_meta($created['en']??0,'_wp_attachment_metadata',true);
                    if(is_array($meta))wp_update_attachment_metadata($attachment,$meta);
                }
            }
            $trid=apply_filters('wpml_element_trid',null,(int)($created['en']??0),'post_attachment');
            $translations=MAD4B_WPML_Attachment_Family_Readback_V1::translations((int)($created[$default]??0));
            $localeMap=[];
            foreach($family as $lang=>$postid){
                $id=(int)($translations[$lang]->element_id??0);
                if(!$id ||!is_file((string)get_attached_file($id))
                    ||realpath(get_attached_file($id))!==realpath($dest))
                    return self::err('mad4b_portrait_wpml_readback_failed');
                $localeMap[$lang]=$id;
            }
            $audit=MAD4B_SCP_Audit::record('mad4b/portrait-derivative-created',[
                'site_uuid'=>$site['site_uuid'],'post_id'=>$pid,
                'source_attachment_id'=>$source,'derivative_sha256'=>hash_file('sha256',$dest),
                'crop'=>$crop,'wpml_attachment_ids'=>$localeMap,
                'model_visual_review_sha256'=>$m['review_evidence_sha256'],
                'photo_provenance_verified'=>false,
                'source_files_preserved'=>true,'tour_meta_updated'=>false,
                'principal'=>'authorized_host_wpcli_no_user_impersonation'
            ]);
            return array_merge($result,[
                'status'=>'created_wpml_attachment_family',
                'derivative_sha256'=>hash_file('sha256',$dest),
                'attachment_ids'=>$localeMap,
                'audit_receipt_ok'=>!is_wp_error($audit),'tour_meta_updated'=>false
            ]);
        } finally {
            MAD4B_SCP_Distributed_Lock::release($lock);
        }
    }
    public static function cli($args,$assoc) {
        if(!defined('WP_CLI')||!WP_CLI||get_current_user_id()||$args
           ||array_diff(array_keys($assoc),['create']))
            WP_CLI::error('Host-only no-impersonation WP-CLI and bounded JSON manifest required.');
        $s=stream_get_contents(STDIN,32769);
        $m=json_decode((string)$s,true);
        if(!is_array($m)||strlen((string)$s)>32768)WP_CLI::error('Invalid input');
        $r=self::run($m,isset($assoc['create']));
        if(is_wp_error($r))WP_CLI::error($r->get_error_code());
        WP_CLI::line(wp_json_encode($r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
}
if(defined('WP_CLI')&&WP_CLI&&class_exists('WP_CLI'))
    WP_CLI::add_command('mad4b media-portrait',[MAD4B_Tour_Media_Portrait_Derivative_V1::class,'cli']);

