<?php
/**
 * Plugin Name: MAD4B Sitewide Tour Viewpoint Discovery Loop
 * Description: Bounded read-only hero-frame discovery with persistent Content Readiness and configuration-discovery signals.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Tour_Viewpoint_Discovery_Loop_V1 {
    public const CONTRACT='mad4b.tour-viewpoint-sitewide-discovery.v1';
    public const REVISION='20261117-scene-and-fit-v5';
    public const OPTION='mad4b_tour_viewpoint_sitewide_index_v1';
    public const HOOK='mad4b_tour_viewpoint_discovery_tick';
    private const BATCH_CAP=250;

    private static function scene(int $id):string {
        if($id<1 || get_post_type($id)!=='attachment'
           || !class_exists('MAD4B_Tour_Viewpoint_Coverage_V1',false))
            return 'unknown';
        return MAD4B_Tour_Viewpoint_Coverage_V1::scene_from_labels(
            (string)get_the_title($id),(string)get_post_meta($id,'_wp_attachment_image_alt',true));
    }
    private static function museum_headline(int $tour):bool {
        $title=mb_strtolower((string)get_the_title($tour),'UTF-8');
        return (bool)preg_match('/museum|museo|musee|museu|museé|grand egyptian|egipcio|egizio|egyptisch|المتحف|متحف/u',$title);
    }
    private static function original_key(int $id):string {
        // Cheap identity clue; the original photo-quality engine independently
        // verifies file SHA and WPML photographic lineage in full audits.
        $source=(int)get_post_meta($id,'_mad4b_portrait_source_attachment_id',true);
        return 'attachment:'.($source>0?$source:$id);
    }
    public static function inspect(int $tour):array {
        if(get_post_type($tour)!=='tours-and-activities'||get_post_status($tour)!=='publish')
            return ['status'=>'tour_not_published','issues'=>[]];
        $fields=['image_vertical'];
        foreach(array_keys((array)get_post_meta($tour)) as $key)
            if(is_string($key)&&preg_match('/^grid_image_[a-zA-Z0-9_]{1,80}$/D',$key))
                $fields[]=$key;
        $fields=array_values(array_unique($fields));
        $issues=[];$origins=[];$museumInteriors=0;$museumExteriors=0;
        foreach($fields as $slot){
            $src=(string)get_post_meta($tour,$slot,true);
            if($src===''||!str_starts_with($src,'http'))continue;
            $id=(int)attachment_url_to_postid($src);
            if($id<1)continue; // Unresolved sources are owned by existing quality audit.
            $media=wp_get_attachment_metadata($id);
            $w=(int)($media['width']??0);$h=(int)($media['height']??0);
            $ratio=$h>0?round($w/$h,4):null;
            $scene=self::scene($id);
            if($scene==='museum_interior')$museumInteriors++;
            if($scene==='museum_exterior_overview')$museumExteriors++;
            $nativeFit=class_exists('MAD4B_Tour_Media_Resolution_Engine_V1',false)
                && MAD4B_Tour_Media_Resolution_Engine_V1::verified_contain($tour,$slot,$id);
            if(str_starts_with($slot,'grid_image_')&&$ratio!==null&&$ratio<.98&&!$nativeFit){
                $plan=class_exists('MAD4B_Tour_Media_Resolution_Engine_V1',false)
                    ?MAD4B_Tour_Media_Resolution_Engine_V1::plan($tour,$slot):[];
                $issues[]=[
                    'code'=>'media_viewpoint_grid_portrait_cover_risk',
                    'field'=>$slot,'attachment_id'=>$id,'source_ratio'=>$ratio,
                    'reason'=>'portrait_source_for_landscape_grid_cover',
                    'recommended_resolution'=>$plan['action']??'review',
                    'auto_resolution_eligible'=>(bool)($plan['auto_eligible']??false),
                    'resolution_alternatives'=>array_slice((array)($plan['alternatives']??[]),0,4),
                    'resolution_reasons'=>array_slice((array)($plan['reasons']??[]),0,5),
                    'visual_and_rights_not_inferred'=>true
                ];
            }
            $identity=self::original_key($id);
            if(isset($origins[$identity]))
                $issues[]=[
                    'code'=>'media_viewpoint_original_reused_in_hero',
                    'field'=>$slot,'attachment_id'=>$id,
                    'same_original_in'=>$origins[$identity],
                    'reason'=>'exact_or_derivative_attachment_origin_repeated',
                    'scope'=>'same_tour_only'
                ];
            else $origins[$identity]=$slot;
        }
        if(self::museum_headline($tour)){
            if($museumExteriors===0)$issues[]=[
                'code'=>'media_viewpoint_museum_exterior_overview_review',
                'reason'=>'no_wide_exterior_view_in_caption_evidence',
                'visual_verification_still_required'=>true
            ];
            if($museumInteriors>=2)$issues[]=[
                'code'=>'media_viewpoint_museum_interior_scene_repeat',
                'reason'=>'multiple_similar_scene_roles_in_primary_grid',
                'distinct_photographs_may_still_be_valid'=>true
            ];
        }
        return ['status'=>'inspected','tour_id'=>$tour,'issues'=>$issues,
            'inspected_hero_slots'=>count($fields),
            'exterior_overviews_by_caption'=>$museumExteriors,
            'interior_museum_scenes_by_caption'=>$museumInteriors,
            'nonblocking'=>true,'rights_or_visual_truth_not_asserted'=>true];
    }
    private static function raw_index():array {
        $state=get_option(self::OPTION,[]);
        return is_array($state)?$state:[];
    }
    public static function status():array {
        $s=self::raw_index();
        $counts=[];
        foreach((array)($s['tours']??[]) as $issues){
            foreach((array)$issues as $row){
                $code=(string)($row['code']??'');
                if($code!=='')$counts[$code]=($counts[$code]??0)+1;
            }
        }
        ksort($counts);
        return [
            'contract'=>self::CONTRACT,'revision'=>self::REVISION,
            'site'=>home_url(),'complete'=>(bool)($s['complete']??false),
            'revision_matches'=>($s['revision']??'')===self::REVISION,
            'tour_count_at_complete'=>(int)($s['tour_count_at_complete']??0),
            'scanned_tours_with_issues'=>count((array)($s['tours']??[])),
            'issues_by_code'=>$counts,
            'cursor'=>(int)($s['cursor']??0),
            'last_full_scan_utc'=>$s['last_full_scan_utc']??null,
            'pending_batches'=>count((array)($s['pending']??[])),
            'publication_writes'=>false
        ];
    }
    public static function scan_batch(int $limit=self::BATCH_CAP):array {
        global $wpdb;
        $limit=max(1,min(self::BATCH_CAP,$limit));
        $prior=self::raw_index();$revisionMatches=($prior['revision']??'')===self::REVISION;
        $state=$revisionMatches?$prior:[
            'contract'=>self::CONTRACT,'revision'=>self::REVISION,'site'=>home_url(),
            'complete'=>false,'tours'=>(array)($prior['tours']??[]),
            'pending'=>[],'cursor'=>0
        ];
        if(($state['site']??'')!==home_url())throw new RuntimeException('sitewide_media_snapshot_site_mismatch');
        $start=(int)($state['cursor']??0);
        if($start===0 && empty($state['pending'])
            && !empty($state['complete']) && !empty($state['last_full_scan_utc'])
            && strtotime((string)$state['last_full_scan_utc'])>time()-DAY_IN_SECONDS)
            return ['state'=>'fresh_noop','status'=>self::status()];
        $ids=$wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_status=%s AND ID>%d ORDER BY ID LIMIT %d",
            'tours-and-activities','publish',$start,$limit+1
        ));
        if(!is_array($ids)||$wpdb->last_error){
            $GLOBALS['mad4b_configuration_discovery_incomplete']=true;
            return ['state'=>'sql_incomplete','cursor'=>$start,'db_error'=>true];
        }
        $slice=array_slice($ids,0,$limit);
        $pending=(array)($state['pending']??[]);
        foreach($slice as $rawId){
            $id=(int)$rawId;
            $audit=self::inspect($id);
            if(($audit['status']??'')!=='inspected'){
                $GLOBALS['mad4b_configuration_discovery_incomplete']=true;
                return ['state'=>'uninspected_post','post_id'=>$id,'cursor'=>$start];
            }
            $pending[$id]=$audit['issues'];
        }
        $finished=count($ids)<=$limit;
        $state['pending']=$pending;
        $state['cursor']=$slice?(int)end($slice):0;
        $state['last_batch_utc']=gmdate('c');
        $state['complete']=false;
        if($finished){
            $state['tours']=array_filter($pending,static fn($issues)=>is_array($issues)&&count($issues)>0);
            $state['tour_count_at_complete']=count($pending);
            $state['last_full_scan_utc']=gmdate('c');
            $state['pending']=[];$state['cursor']=0;$state['complete']=true;
        }
        update_option(self::OPTION,$state,false);
        return ['state'=>$finished?'full_cycle_complete':'partial_cycle',
            'batch_scanned'=>count($slice),'cursor'=>$state['cursor'],
            'total_in_cycle'=>count($pending),'complete'=>$finished,
            'issues_by_code'=>$finished?self::status()['issues_by_code']:[]];
    }
    public static function refresh_one(int $id):void {
        $state=self::raw_index();
        if(($state['revision']??'')!==self::REVISION || empty($state['complete']))return;
        $inspect=self::inspect($id);
        if(($inspect['status']??'')==='inspected'){
            if($inspect['issues'])$state['tours'][$id]=$inspect['issues'];
            else unset($state['tours'][$id]);
        }else unset($state['tours'][$id]);
        $state['last_single_utc']=gmdate('c');
        update_option(self::OPTION,$state,false);
    }
    public static function schedule():void {
        $existing=wp_get_schedule(self::HOOK);
        if($existing==='mad4b_viewpoint_quarter_hour')return;
        if($existing)wp_clear_scheduled_hook(self::HOOK);
        wp_schedule_event(time()+120,'mad4b_viewpoint_quarter_hour',self::HOOK);
    }
    public static function tick():void {self::scan_batch();}
    private static function visible_issues():array {
        $state=self::raw_index();
        if(($state['revision']??'')!==self::REVISION)return [];
        $out=[];
        foreach((array)($state['tours']??[]) as $id=>$issues){
            if(get_post_type((int)$id)!=='tours-and-activities'||get_post_status((int)$id)!=='publish')continue;
            $groups=[];
            foreach((array)$issues as $row)if(is_array($row)&&!empty($row['code']))$groups[$row['code']][]=$row;
            foreach($groups as $code=>$rows)$out[]=[
                'object_id'=>(int)$id,'code'=>(string)$code,'occurrences'=>count($rows),
                'first_field'=>$rows[0]['field']??null,
                'recommended_resolution'=>$rows[0]['recommended_resolution']??'review',
                'resolution_alternatives'=>$rows[0]['resolution_alternatives']??[],
                'resolution_reasons'=>$rows[0]['resolution_reasons']??[],
                'severity'=>$code==='media_viewpoint_original_reused_in_hero'?'high':'medium'
            ];
        }
        return $out;
    }
    /**
     * Once the full current revision is certified, the sitewide discovery
     * index owns viewpoint alerts; drop stale copies from the older bounded
     * photo-quality scan. Never suppress other media-quality providers or
     * any previous issue while a full scan is incomplete.
     */
    public static function reconcile_legacy_viewpoint_issues(array $incoming,bool $complete):array {
        if(!$complete)return $incoming;
        return array_values(array_filter($incoming,static function($row){
            if(!is_array($row))return true;
            $source=(string)($row['source']??'');
            $code=(string)($row['code']??'');
            return !($source==='mad4b-tour-photo-quality'
                && str_starts_with($code,'media_viewpoint_'));
        }));
    }
    public static function content_issues($incoming,$policy){
        if(!is_array($incoming)||!is_array($policy)
            ||!in_array('tours-and-activities',(array)($policy['post_types']??[]),true))
            return $incoming;
        $state=self::raw_index();
        $currentComplete=($state['revision']??'')===self::REVISION
            && !empty($state['complete']);
        $incoming=self::reconcile_legacy_viewpoint_issues($incoming,$currentComplete);
        $existing=[];
        foreach($incoming as $row)
            if(is_array($row))$existing[($row['object_id']??'').'|'.($row['code']??'')]=true;
        foreach(self::visible_issues() as $item){
            $key=$item['object_id'].'|'.$item['code'];
            if(isset($existing[$key]))continue;
            $existing[$key]=true;
            $incoming[]=[
                'id'=>hash('sha256',home_url().'|viewpoint|'.$key),
                'post_type'=>'tours-and-activities','object_id'=>$item['object_id'],
                'code'=>$item['code'],'source'=>'mad4b-tour-viewpoint-discovery',
                'severity'=>$item['severity'],
                'label'=>'Image viewpoint review: '.$item['occurrences'].' '.str_replace('_',' ',$item['code'])
                    .' on tour '.get_the_title($item['object_id']),
                'scope'=>'sitewide_published_tour_hero',
                'recommended_resolution'=>$item['recommended_resolution'],
                'resolution_alternatives'=>$item['resolution_alternatives'],
                'resolution_reasons'=>$item['resolution_reasons'],
                'asset_editorial_review_only'=>true,'publication_blocked'=>false
            ];
        }
        $state=self::raw_index();
        if(($state['revision']??'')!==self::REVISION||empty($state['complete']))
            $incoming[]=['post_type'=>'tours-and-activities','object_id'=>0,
                'code'=>'media_viewpoint_discovery_scan_incomplete',
                'source'=>'mad4b-tour-viewpoint-discovery','severity'=>'medium',
                'label'=>'Sitewide image viewpoint discovery is still scanning; preserve earlier alerts.'];
        return $incoming;
    }
    public static function configuration_signals($rows):array {
        if(!is_array($rows))return [];
        $state=self::raw_index();
        if(($state['revision']??'')!==self::REVISION||empty($state['complete'])){
            $GLOBALS['mad4b_configuration_discovery_incomplete']=true;
            $rows[]=['provider'=>'tour-viewpoint-media','code'=>'viewpoint_scan_incomplete',
                'scope'=>'site','source'=>'probe','severity'=>'medium',
                'label'=>'Tour photo viewpoint discovery has not completed the current site scan',
                'guidance'=>'Keep previous findings and let the bounded discovery cycle finish'];
        }
        $visible=self::visible_issues();
        if(count($visible)>200){
            $GLOBALS['mad4b_configuration_discovery_incomplete']=true;
            $rows[]=['provider'=>'tour-viewpoint-media','code'=>'viewpoint_discovery_display_overflow',
                'scope'=>'site','source'=>'probe','severity'=>'medium',
                'label'=>'More than 200 sitewide tour viewpoint findings exist; inspect the full Content Readiness index.',
                'guidance'=>'Increase the bounded discovery display or review the completed issue index. No findings have been deleted.'];
        }
        foreach(array_slice($visible,0,200) as $item){
            $rows[]=['provider'=>'tour-viewpoint-media','code'=>$item['code'],
                'scope'=>'tour_'.$item['object_id'],'source'=>'probe',
                'severity'=>$item['severity'],
                'label'=>'Tour '.(int)$item['object_id'].' — '.$item['occurrences'].' image viewpoint review(s): '
                    .str_replace('_',' ',$item['code']),
                'guidance'=>'Recommended action: '.$item['recommended_resolution'].'. Confirm the actual visible landmark and final frame; use a verified landscape original or native contain fit. Never infer image rights from captions.'];
        }
        return $rows;
    }
    public static function cli($args,$assoc):void {
        $mode=(string)($args[0]??'status');
        if($mode==='status')$out=self::status();
        elseif($mode==='scan')$out=self::scan_batch((int)($assoc['limit']??self::BATCH_CAP));
        elseif($mode==='inspect')$out=self::inspect(absint($args[1]??0));
        else WP_CLI::error('Usage: wp mad4b viewpoint-loop status|scan|inspect <post-id>');
        WP_CLI::line(wp_json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
}
add_filter('cron_schedules',static function($schedules){
    $schedules['mad4b_viewpoint_quarter_hour']=['interval'=>900,'display'=>'MAD4B Sitewide Viewpoint Discovery every 15 minutes'];
    return $schedules;
});
add_action('init',[MAD4B_Tour_Viewpoint_Discovery_Loop_V1::class,'schedule'],40);
add_action(MAD4B_Tour_Viewpoint_Discovery_Loop_V1::HOOK,[MAD4B_Tour_Viewpoint_Discovery_Loop_V1::class,'tick'],10,0);
add_action('mad4b_tour_photo_quality_scan_one',[MAD4B_Tour_Viewpoint_Discovery_Loop_V1::class,'refresh_one'],30,1);
add_filter('mad4b/content_readiness/external_issues',[MAD4B_Tour_Viewpoint_Discovery_Loop_V1::class,'content_issues'],27,2);
add_filter('mad4b/configuration_jobs/discover',[MAD4B_Tour_Viewpoint_Discovery_Loop_V1::class,'configuration_signals'],41,1);
if(defined('WP_CLI')&&WP_CLI)
    WP_CLI::add_command('mad4b viewpoint-loop',[MAD4B_Tour_Viewpoint_Discovery_Loop_V1::class,'cli']);
