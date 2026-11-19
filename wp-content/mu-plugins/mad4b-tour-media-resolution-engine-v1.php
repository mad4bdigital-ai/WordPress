<?php
/**
 * Plugin Name: MAD4B Tour Media Resolution Engine
 * Description: Evidence-bound decisions for tour image frame problems; uses existing JetEngine/Elementor controls.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Tour_Media_Resolution_Engine_V1 {
    public const CONTRACT='mad4b.tour-media-resolution.v1';
    public const FRAME_META='mad4b_media_frame_focus_v2';
    public const AUTO_OPTION='mad4b_tour_media_resolution_auto_enabled_v1';
    public const RECEIPT_META='_mad4b_tour_media_resolution_receipts_v1';
    private static array $bindings=[];

    public static function hero_bindings():array {
        $template=(int)get_option('mad4b_tour_hero_master_template',24780);
        $cacheKey=$template.':'.(string)get_post_modified_time('U',true,$template);
        if(isset(self::$bindings[$cacheKey]))return self::$bindings[$cacheKey];
        $tree=json_decode((string)get_post_meta($template,'_elementor_data',true),true);
        $found=[];
        $walk=static function($elements)use(&$walk,&$found):void {
            foreach((array)$elements as $el){
                if(!is_array($el))continue;
                $s=(array)($el['settings']??[]);
                $d=(array)($s['__dynamic__']??[]);
                $image=rawurldecode((string)($d['background_image']??''));
                if(preg_match('/"img_field":"(image_vertical|grid_image_[a-zA-Z0-9_]+)"/',$image,$match)){
                    $slot=$match[1];
                    $fit=(string)($d['mad4b_photo_focus_fit']??'');
                    $position=(string)($d['mad4b_photo_focus_position']??'');
                    $found[$slot]=[
                        'fit_bound'=>str_contains($fit,'name="mad4b-jetengine-frame-focus"')
                            &&str_contains(rawurldecode($fit),'"property":"fit"')
                            &&str_contains(rawurldecode($fit),'"slot":"'.$slot.'"'),
                        'position_bound'=>str_contains($position,'name="mad4b-jetengine-frame-focus"')
                            &&str_contains(rawurldecode($position),'"property":"position"')
                            &&str_contains(rawurldecode($position),'"slot":"'.$slot.'"'),
                        'repeat_safe'=>($s['background_repeat']??'')==='no-repeat',
                        'element_id'=>(string)($el['id']??'')
                    ];
                }
                if(!empty($el['elements']))$walk($el['elements']);
            }
        };
        $walk($tree);
        return self::$bindings[$cacheKey]=$found;
    }

    public static function rows(int $tour):array {
        $rows=get_post_meta($tour,self::FRAME_META,true);
        if(!is_array($rows)||!$rows){
            $default=(string)apply_filters('wpml_default_language','');
            $source=$default?(int)apply_filters('wpml_object_id',$tour,'tours-and-activities',false,$default):0;
            if($source>0 && $source!==$tour && get_post_type($source)==='tours-and-activities')
                $rows=get_post_meta($source,self::FRAME_META,true);
        }
        return is_array($rows)?array_slice($rows,0,80):[];
    }

    public static function origin(int $id):array {
        if(!class_exists('MAD4B_Media_Visual_Lineage_V1',false))return ['verified'=>false];
        $origin=MAD4B_Media_Visual_Lineage_V1::origin($id);
        $sha=(string)($origin['origin_sha256']??'');
        return ['verified'=>!empty($origin['verified'])&&preg_match('/^[a-f0-9]{64}$/D',$sha)===1,
            'sha'=>$sha,'root'=>(int)($origin['origin_attachment_id']??0)];
    }

    public static function frame_record(int $tour,string $slot,string $sha):array {
        foreach(self::rows($tour) as $row){
            if(!is_array($row)||($row['slot']??'')!==$slot)continue;
            $fit=(string)($row['fit']??'cover');
            $rowSha=(string)($row['source_sha256']??'');
            $valid=preg_match('/^[a-f0-9]{64}$/D',$rowSha)===1
                &&hash_equals($sha,$rowSha)
                &&is_numeric($row['focus_x']??null)
                &&is_numeric($row['focus_y']??null)
                &&(float)$row['focus_x']>=0&&(float)$row['focus_x']<=100
                &&(float)$row['focus_y']>=0&&(float)$row['focus_y']<=100;
            return ['exists'=>true,'valid'=>$valid,'fit'=>$fit,'row'=>$row];
        }
        return ['exists'=>false,'valid'=>false,'fit'=>'cover','row'=>null];
    }

    public static function verified_contain(int $tour,string $slot,int $attachment):bool {
        $bindings=self::hero_bindings();
        if(empty($bindings[$slot]['fit_bound'])||empty($bindings[$slot]['position_bound'])||empty($bindings[$slot]['repeat_safe']))
            return false;
        $origin=self::origin($attachment);
        if(empty($origin['verified']))return false;
        $record=self::frame_record($tour,$slot,$origin['sha']);
        return !empty($record['valid'])&&$record['fit']==='contain';
    }

    public static function plan(int $tour,string $slot):array {
        $base=['contract'=>self::CONTRACT,'tour_id'=>$tour,'slot'=>$slot,
            'action'=>'review','auto_eligible'=>false,
            'reasons'=>[],'alternatives'=>[],'current'=>[]];
        if(get_post_type($tour)!=='tours-and-activities'||get_post_status($tour)!=='publish'){
            $base['reasons'][]='not_published_tour';return $base;
        }
        if(!preg_match('/^grid_image_[a-zA-Z0-9_]{1,80}$/D',$slot)){
            $base['reasons'][]='not_a_landscape_grid_slot';return $base;
        }
        $url=(string)get_post_meta($tour,$slot,true);
        $id=(int)attachment_url_to_postid($url);
        if(!$id || !str_starts_with($url,'http')){
            $base['reasons'][]='source_attachment_not_resolved';return $base;
        }
        $meta=wp_get_attachment_metadata($id);
        $width=(int)($meta['width']??0);$height=(int)($meta['height']??0);
        if($width<1||$height<1){
            $base['reasons'][]='source_dimensions_missing';return $base;
        }
        $ratio=round($width/$height,4);
        $base['current']=['image_id'=>$id,'width'=>$width,'height'=>$height,'aspect_ratio'=>$ratio,
            'url'=>$url];
        if($ratio>=.98){
            $base['action']='none';$base['reasons'][]='landscape_source_already_suitable';
            return $base;
        }
        $origin=self::origin($id);
        if(empty($origin['verified'])){
            $base['reasons'][]='original_identity_not_verified';return $base;
        }
        $base['current']['origin_sha256']=$origin['sha'];
        $bound=self::hero_bindings();
        $binding=$bound[$slot]??[];
        $frame=self::frame_record($tour,$slot,$origin['sha']);
        // If an editor deliberately switched back to cover AFTER our previous
        // successful contain write, respect that explicit newer decision.
        if(!empty($frame['exists'])&&!empty($frame['valid'])&&$frame['fit']==='cover'){
            // WPML can copy a receipt from EN to ES while the ES frame itself
            // remains unchanged. Such a copied receipt is NOT an ES override.
            // A translated page with no local frame instead inherits the
            // canonical owner's explicit decision.
            $owner=metadata_exists('post',$tour,self::FRAME_META)?$tour:0;
            if(!$owner){
                $owner=(int)apply_filters('wpml_object_id',$tour,'tours-and-activities',false,
                    (string)apply_filters('wpml_default_language',''));
            }
            $receipts=$owner>0?get_post_meta($owner,self::RECEIPT_META,true):[];
            foreach(array_reverse((array)$receipts) as $previous){
                if(!is_array($previous)||($previous['contract']??'')!==self::CONTRACT
                    ||(int)($previous['tour_id']??0)!==$owner)continue;
                if(($previous['slot']??'')===$slot
                    &&($previous['original_sha256']??'')===$origin['sha']){
                    $base['reasons'][]='human_cover_override_after_prior_automatic_contain';
                    return $base;
                }
            }
        }
        if(self::verified_contain($tour,$slot,$id)){
            $base['action']='none';$base['reasons'][]='native_contain_verified_for_original';
            return $base;
        }
        $rootId=$origin['root'];
        if($rootId>0 && $rootId!==$id){
            $rootSize=wp_get_attachment_metadata($rootId);
            $rw=(int)($rootSize['width']??0);$rh=(int)($rootSize['height']??0);
            if($rh>0&&$rw/$rh>=.98&&$rw>=1000&&$rh>=800){
                $base['alternatives'][]=[
                    'action'=>'use_verified_landscape_original','image_id'=>$rootId,
                    'review_required'=>true,'reason'=>'same_origin_with_landscape_geometry'];
            }
        }
        $base['alternatives'][]=[
            'action'=>'set_native_contain','existing_attachment_id'=>$id,
            'review_required'=>false,'preserve_focal_point'=>true,
            'reason'=>'keep_all_original_pixels_without_css_override'];
        $base['alternatives'][]=[
            'action'=>'review_new_image','review_required'=>true,
            'reason'=>'landmark_accuracy_and_rights_require_vision_review'];
        if(empty($binding['fit_bound'])||empty($binding['position_bound'])||empty($binding['repeat_safe'])){
            $base['reasons'][]='elementor_native_dynamic_tag_binding_unverified';
            return $base;
        }
        if(!empty($frame['exists'])&&!$frame['valid']){
            $base['reasons'][]='existing_frame_record_source_or_position_mismatch';
            return $base;
        }
        if(class_exists('MAD4B_Tour_Photo_Quality_V1',false)){
            if(MAD4B_Tour_Photo_Quality_V1::editorial_hold($id)['held']){
                $base['reasons'][]='original_under_editorial_hold';return $base;
            }
            if(MAD4B_Tour_Photo_Quality_V1::provenance($id)['status']==='synthetic_confirmed'){
                $base['reasons'][]='confirmed_synthetic_source';return $base;
            }
        }
        $base['action']='set_native_contain';
        $base['auto_eligible']=true;
        $base['reasons'][]='existing_identity_verified_native_elementor_fit_binding_verified';
        $base['reasoned_choice']='non_destructive_frame_fit_no_image_replacement';
        return $base;
    }

    /**
     * The only automatic write is a SOURCE-SHA-BOUND native Elementor fit.
     * No attachment, gallery, SEO, rights or template mutation is permitted.
     * Company opt-in is required even for authenticated administrative calls.
     */
    public static function apply_contain(int $tour,string $slot):array {
        $plan=self::plan($tour,$slot);
        $out=['contract'=>self::CONTRACT,'tour_id'=>$tour,'slot'=>$slot,
            'action'=>$plan['action'],'status'=>'not_applied','reason'=>null];
        if($plan['action']==='none'){
            $out['status']='no_change_needed';return $out;
        }
        if($plan['action']!=='set_native_contain'||empty($plan['auto_eligible'])){
            $out['reason']=implode(',',(array)$plan['reasons']);return $out;
        }
        if(get_option(self::AUTO_OPTION,false)!==true){
            $out['reason']='company_auto_resolution_not_enabled';return $out;
        }
        $userAuthorized=current_user_can('edit_post',$tour);
        $systemAuthorized=function_exists('wp_doing_cron')&&wp_doing_cron();
        if(!$userAuthorized&&!$systemAuthorized){
            $out['reason']='authenticated_editor_or_approved_site_cron_required';return $out;
        }
        $lock='mad4b_resolution_media_lock_'.$tour;
        if(!add_option($lock,time(),'','no')){
            $since=(int)get_option($lock,0);
            if($since<1||$since>time()-600){
                $out['reason']='concurrent_media_resolution';return $out;
            }
            delete_option($lock);
            if(!add_option($lock,time(),'','no')){
                $out['reason']='concurrent_media_resolution';return $out;
            }
        }
        try{
            // Re-evaluate under the lock. A translated post or its image may
            // have changed while the initial plan was being prepared.
            $now=self::plan($tour,$slot);
            $oldId=(int)($plan['current']['image_id']??0);
            if($now['action']!=='set_native_contain'
                ||(int)($now['current']['image_id']??0)!==$oldId
                ||($now['current']['origin_sha256']??'')!==($plan['current']['origin_sha256']??'')){
                $out['reason']='proposal_stale_or_changed';return $out;
            }
            $original=$now['current'];
            $sha=(string)$original['origin_sha256'];
            $exists=metadata_exists('post',$tour,self::FRAME_META);
            $stored=get_post_meta($tour,self::FRAME_META,true);
            if($exists&&!is_array($stored)){
                $out['reason']='unexpected_non_repeater_frame_metadata';return $out;
            }
            $before=$exists?$stored:[];
            $after=$before;
            $found=false;
            foreach($after as $key=>$row){
                if(!is_array($row)||($row['slot']??'')!==$slot)continue;
                $valid=self::frame_record($tour,$slot,$sha);
                if(empty($valid['valid'])){
                    $out['reason']='frame_record_changed_during_apply';return $out;
                }
                $row['fit']='contain';
                $row['source_sha256']=$sha;
                unset($row['rebind_on_save']);
                $after[$key]=$row;
                $found=true;break;
            }
            if(!$found){
                if(count($after)>=80){
                    $out['reason']='repeater_capacity_exceeded';return $out;
                }
                $after[]=['slot'=>$slot,'focus_x'=>50,'focus_y'=>50,
                    'fit'=>'contain','source_sha256'=>$sha];
            }
            if($after===$before){
                $out['status']='no_change_needed';return $out;
            }
            $saved=$exists
                ?update_post_meta($tour,self::FRAME_META,$after,$before)
                :add_post_meta($tour,self::FRAME_META,$after,true);
            if(!$saved){
                $out['reason']='wordPress_meta_write_rejected';return $out;
            }
            clean_post_cache($tour);
            $ok=self::verified_contain($tour,$slot,$oldId)
                &&(string)get_post_meta($tour,$slot,true)===(string)$original['url'];
            if(!$ok){
                self::restore_frame($tour,$exists,$before,$after);
                $out['reason']='native_binding_or_sha_readback_failed_rolled_back';
                return $out;
            }
            $receipt=[
                'contract'=>self::CONTRACT,
                'tour_id'=>$tour,'slot'=>$slot,'attachment_id'=>$oldId,
                'original_sha256'=>$sha,'action'=>'set_native_contain',
                'previous_frame_rows'=>$before,'previous_metadata_exists'=>$exists,
                'verification'=>'native_dynamic_fit_bound_to_verified_original',
                'created_utc'=>gmdate('c')
            ];
            $history=get_post_meta($tour,self::RECEIPT_META,true);
            $history=is_array($history)?$history:[];
            $history[]= $receipt;
            if(!update_post_meta($tour,self::RECEIPT_META,array_slice($history,-30))){
                self::restore_frame($tour,$exists,$before,$after);
                $out['reason']='receipt_write_failed_rolled_back';
                return $out;
            }
            if(class_exists('MAD4B_Tour_Viewpoint_Discovery_Loop_V1',false))
                MAD4B_Tour_Viewpoint_Discovery_Loop_V1::refresh_one($tour);
            if(class_exists('MAD4B_Tour_Photo_Quality_V1',false))
                MAD4B_Tour_Photo_Quality_V1::scan_one($tour);
            do_action('litespeed_purge_post',$tour);
            $url=get_permalink($tour);
            if($url)do_action('litespeed_purge_url',$url);
            $out['status']='applied_verified';
            $out['attachment_id']=$oldId;
            $out['original_sha256']=$sha;
            $out['frame_fit']='contain';
            $out['image_replaced']=false;
            return $out;
        }finally{
            delete_option($lock);
        }
    }

    private static function restore_frame(int $tour,bool $existed,$before,$written):void {
        if($existed)update_post_meta($tour,self::FRAME_META,$before,$written);
        else delete_post_meta($tour,self::FRAME_META,$written);
        clean_post_cache($tour);
    }



    public const HOOK='mad4b_tour_media_resolution_tick';
    public const SINGLE_HOOK='mad4b_tour_media_resolution_single';
    public const CURSOR_OPTION='mad4b_tour_media_resolution_cursor_v1';
    public const STATUS_OPTION='mad4b_tour_media_resolution_last_batch_v1';

    public static function scan_one(int $tour):array {
        $out=['post_id'=>$tour,'examined'=>0,'resolved'=>0,'review'=>0,
            'changes'=>[],'review_actions'=>[]];
        if(get_option(self::AUTO_OPTION,false)!==true)return $out+['disabled'=>true];
        if(get_post_type($tour)!=='tours-and-activities'||get_post_status($tour)!=='publish')
            return $out;
        foreach(self::hero_bindings() as $slot=>$bound){
            if(!str_starts_with($slot,'grid_image_'))continue;
            $url=(string)get_post_meta($tour,$slot,true);
            $id=(int)attachment_url_to_postid($url);
            if(!$id)continue;
            $meta=wp_get_attachment_metadata($id);
            $w=(int)($meta['width']??0);$h=(int)($meta['height']??0);
            if($h<=0||$w/$h>=.98)continue;
            $out['examined']++;
            $p=self::plan($tour,$slot);
            if($p['action']==='set_native_contain'&&!empty($p['auto_eligible'])){
                $r=self::apply_contain($tour,$slot);
                $out['changes'][]=$r;
                if($r['status']==='applied_verified'||$r['status']==='no_change_needed')$out['resolved']++;
                else $out['review']++;
            }elseif($p['action']==='none'){
                $out['resolved']++;
            }else{
                $out['review']++;
                $out['review_actions'][]=['slot'=>$slot,'action'=>$p['action'],
                    'reasons'=>$p['reasons'],'alternatives'=>$p['alternatives']];
            }
        }
        return $out;
    }

    public static function scan_batch(int $limit=12):array {
        global $wpdb;
        if(get_option(self::AUTO_OPTION,false)!==true)
            return ['state'=>'disabled','contract'=>self::CONTRACT];
        $limit=max(1,min(24,$limit));
        $cursor=(int)get_option(self::CURSOR_OPTION,0);
        $ids=$wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_status=%s AND ID>%d ORDER BY ID LIMIT %d",
            'tours-and-activities','publish',$cursor,$limit
        ));
        if(!is_array($ids)||$wpdb->last_error)
            return ['state'=>'sql_incomplete','cursor'=>$cursor,'contract'=>self::CONTRACT];
        $rows=[];$total=0;
        foreach($ids as $id){
            $result=self::scan_one((int)$id);
            $total+=(int)($result['resolved']??0);
            if(!empty($result['changes'])||!empty($result['review_actions']))
                $rows[$id]=$result;
        }
        $next=count($ids)<$limit?0:(int)end($ids);
        update_option(self::CURSOR_OPTION,$next,false);
        $status=['contract'=>self::CONTRACT,'state'=>$next===0?'full_cycle_complete':'partial_cycle',
            'site'=>home_url(),'tour_count'=>count($ids),'resolved_in_batch'=>$total,
            'cursor'=>$next,'review_or_changed'=>$rows,'finished_utc'=>gmdate('c')];
        update_option(self::STATUS_OPTION,$status,false);
        return $status;
    }

    public static function on_meta_change($meta_id,$post_id,$key,$value):void {
        $id=(int)$post_id;
        if($id<1 || get_option(self::AUTO_OPTION,false)!==true
            || get_post_type($id)!=='tours-and-activities')return;
        $allowed=array_fill_keys(array_keys(self::hero_bindings()),true);
        if(!isset($allowed[$key]) && $key!==self::FRAME_META)return;
        if(!wp_next_scheduled(self::SINGLE_HOOK,[$id]))
            wp_schedule_single_event(time()+60,self::SINGLE_HOOK,[$id]);
    }

    public static function schedule():void {
        if(get_option(self::AUTO_OPTION,false)!==true)return;
        if(!wp_next_scheduled(self::HOOK))
            wp_schedule_event(time()+120,'mad4b_resolution_quarter_hour',self::HOOK);
    }

    public static function cli($args,$assoc):void {
        $verb=(string)($args[0]??'status');
        $id=absint($args[1]??0);
        $slot=(string)($assoc['slot']??'grid_image_3_middle_bottom');
        if($verb==='plan')$result=self::plan($id,$slot);
        elseif($verb==='apply')$result=self::apply_contain($id,$slot);
        elseif($verb==='inspect')$result=self::scan_one($id);
        elseif($verb==='scan')$result=self::scan_batch((int)($assoc['limit']??12));
        elseif($verb==='status')$result=[
            'contract'=>self::CONTRACT,'enabled'=>get_option(self::AUTO_OPTION,false)===true,
            'cursor'=>(int)get_option(self::CURSOR_OPTION,0),
            'last_batch'=>get_option(self::STATUS_OPTION,[]),
            'recurring_scheduled'=>(bool)wp_next_scheduled(self::HOOK)];
        else WP_CLI::error('Usage: wp mad4b media-resolution status|plan|apply|inspect|scan [ID] [--slot=...]');
        WP_CLI::line(wp_json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }


}


add_filter('cron_schedules',static function($schedules){
    $schedules['mad4b_resolution_quarter_hour']=[
        'interval'=>900,'display'=>'MAD4B verified tour image resolution every 15 minutes'];
    return $schedules;
});
add_action('init',[MAD4B_Tour_Media_Resolution_Engine_V1::class,'schedule'],45);
add_action(MAD4B_Tour_Media_Resolution_Engine_V1::HOOK,
    [MAD4B_Tour_Media_Resolution_Engine_V1::class,'scan_batch'],10,0);
add_action(MAD4B_Tour_Media_Resolution_Engine_V1::SINGLE_HOOK,
    [MAD4B_Tour_Media_Resolution_Engine_V1::class,'scan_one'],10,1);
foreach(['added_post_meta','updated_post_meta','deleted_post_meta'] as $hook)
    add_action($hook,[MAD4B_Tour_Media_Resolution_Engine_V1::class,'on_meta_change'],140,4);
if(defined('WP_CLI')&&WP_CLI)
    WP_CLI::add_command('mad4b media-resolution',
        [MAD4B_Tour_Media_Resolution_Engine_V1::class,'cli']);
