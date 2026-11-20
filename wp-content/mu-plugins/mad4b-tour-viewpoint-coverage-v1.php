<?php
/**
 * Plugin Name: MAD4B Landmark Viewpoint Coverage
 * Description: Read-only scene diversity and frame-fit guidance for tour media proposals.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

/**
 * Text cues are routing hints, not visual verification or a license grant.
 * Never changes published images on a visitor page.
 */
final class MAD4B_Tour_Viewpoint_Coverage_V1 {
    public const CONTRACT = 'mad4b.tour-viewpoint-coverage.v1';

    public static function scene_from_labels(string $title,string $alt):string {
        $raw=mb_strtolower(html_entity_decode($title.' '.$alt,ENT_QUOTES|ENT_HTML5,'UTF-8'),'UTF-8');
        $text=function_exists('remove_accents')?remove_accents($raw):$raw;
        $museum=(bool)preg_match('/museum|museo|musee|museu|gem[_ @-]|grand egyptian|egipcio|egyptische|egizio|متحف|المتحف/u',$text);
        if($museum){
            $wide=(bool)preg_match('/elevated view|aerial|bird.s.eye|seen from above|view from above|building and gardens|building and grounds|panoram|wide view|whole building|full exterior|vista elevada|panoramica|luftaufnahme|vue aerienne|vista aerea|منظر شامل|لقطة واسعة/u',$text);
            $facade=(bool)preg_match('/facade|fassade|façade|واجهة|frontage|wall detail/u',$text);
            if($wide)return 'museum_exterior_overview';
            // Explicit tight framing overrides a generic entrance mention.
            // This preserves the original GEM facade close-up regression.
            if($facade && preg_match('/angled|close.?up|cropp|zoom|partial|fragment|section of|wall detail|فصيل|مقربة/u',$text))
                return 'museum_exterior_detail';
            // A documented entrance plaza or full arrival viewpoint shows
            // the landmark even when its description also says facade.
            if(preg_match('/entrance|entry plaza|access plaza|entradas?|plaza de acceso|entree|eingang|ingresso|piazza d.ingresso|مدخل|بوابة المتحف/u',$text))
                return 'museum_exterior_overview';
            if($facade)return 'museum_exterior_detail';
            if(preg_match('/staircase|stairs|stair|statue|sculpture|exhibit|gallery|hall|interior|inside|escalinata|escalier|treppe|statua|escalera|busto|قاعات|بهو|درج|تمثال/u',$text))
                return 'museum_interior';
            return 'museum_unclassified';
        }
        if(preg_match('/sphinx|esfinge|sfinge|سفنكس|ابو الهول|أبو الهول/u',$text))return 'sphinx';
        if(preg_match('/pyramid|piramid|pyramide|اهرام|أهرام/u',$text))return 'pyramids';
        return 'unknown';
    }
    private static function museum_prominence(int $tour):int {
        $pattern='/museum|museo|musee|museu|grand egyptian|egipcio|egizio|egyptisch|المتحف|متحف/u';
        $title=mb_strtolower((string)get_the_title($tour),'UTF-8');
        // The tour headline establishes the primary landmark. A passing
        // reference in a multi-day description must not dominate hero media.
        if(preg_match($pattern,$title))return 2;
        $description=mb_strtolower((string)get_post_meta($tour,'_description',true),'UTF-8');
        return preg_match($pattern,mb_substr($description,0,1000))?1:0;
    }
    private static function image(int $id):array {
        if($id<1||get_post_type($id)!=='attachment')return ['id'=>$id,'scene'=>'unknown','ratio'=>null];
        $m=wp_get_attachment_metadata($id);
        $w=(int)($m['width']??0);$h=(int)($m['height']??0);
        $ratio=($w>0&&$h>0)?round($w/$h,4):null;
        $scene=self::scene_from_labels((string)get_the_title($id),
            (string)get_post_meta($id,'_wp_attachment_image_alt',true));
        return ['id'=>$id,'scene'=>$scene,'ratio'=>$ratio,
            'evidence'=>'attachment_title_alt_heuristic_only_not_visual_proof'];
    }
    public static function eligibility($allowed,$tour,$slot,$entry,$id) {
        if(!$allowed||!is_string($slot)||!str_starts_with($slot,'grid_image_'))return $allowed;
        $media=self::image((int)$id);
        // An unknown file remains reviewable. Do not silently declare it safe.
        // These are landscape cover cells; portrait sources often hide landmarks.
        $nativeFit=class_exists('MAD4B_Tour_Media_Resolution_Engine_V1',false)
            && MAD4B_Tour_Media_Resolution_Engine_V1::verified_contain((int)$tour,$slot,(int)$id);
        if($media['ratio']!==null && $media['ratio']<0.98
            && empty($entry['explicit_verified_contain_fit'])&&!$nativeFit)return false;
        return $allowed;
    }
    public static function rank($score,$tour,$slot,$entry,$id,$selected) {
        if(!is_numeric($score))return $score;
        if(!is_string($slot)||!str_starts_with($slot,'grid_image_'))return $score;
        $media=self::image((int)$id);
        if($media['ratio']!==null){
            if($media['ratio']>=1.33)$score+=18;
            elseif($media['ratio']>=0.98)$score+=5;
        }
        $prominence=self::museum_prominence((int)$tour);
        if(!$prominence)return $score;
        $seenOverview=0;$seenInterior=0;
        foreach((array)$selected as $prev) {
            $scene=self::image((int)($prev['id']??0))['scene'];
            if($scene==='museum_exterior_overview')$seenOverview++;
            if($scene==='museum_interior')$seenInterior++;
        }
        if($media['scene']==='museum_exterior_overview')$score+=$seenOverview?7:($prominence>=2?75:20);
        elseif($media['scene']==='museum_exterior_detail')$score-=28;
        elseif($media['scene']==='museum_interior' && $seenInterior)$score-=43;
        return $score;
    }
    /** Audit on the existing bounded tour-quality scan, not the public request path. */
    public static function audit($result,$tour) {
        if(!is_array($result)||get_post_type((int)$tour)!=='tours-and-activities')return $result;
        $all=(array)get_post_meta((int)$tour);
        $slots=['image_vertical'];
        foreach(array_keys($all) as $key)
            if(is_string($key)&&preg_match('/^grid_image_[a-zA-Z0-9_]{1,80}$/D',$key))$slots[]=$key;
        $overview=0;$interiors=0;$issues=(array)($result['issues']??[]);
        foreach(array_unique($slots) as $slot){
            $raw=(string)get_post_meta((int)$tour,$slot,true);
            if(!$raw||!str_starts_with($raw,'http'))continue;
            $id=(int)attachment_url_to_postid($raw);
            if(!$id)continue;
            $info=self::image($id);
            if($info['scene']==='museum_exterior_overview')$overview++;
            if($info['scene']==='museum_interior')$interiors++;
            $nativeFit=class_exists('MAD4B_Tour_Media_Resolution_Engine_V1',false)
                && MAD4B_Tour_Media_Resolution_Engine_V1::verified_contain((int)$tour,$slot,$id);
            if(str_starts_with($slot,'grid_image_') && $info['ratio']!==null
                && $info['ratio']<0.98 && !$nativeFit)
                $issues[]=['code'=>'media_viewpoint_grid_portrait_cover_risk','severity'=>'medium',
                    'field'=>$slot,'attachment_id'=>$id,'image_ratio'=>$info['ratio'],
                    'reason'=>'landscape_grid_rejects_portrait_in_new_proposals',
                    'existing_published_media_not_hidden'=>true];
        }
        if(self::museum_prominence((int)$tour)>=2){
            if(!$overview)$issues[]=['code'=>'media_viewpoint_museum_exterior_overview_review','severity'=>'medium',
                'reason'=>'no_exterior_overview_caption_evidence_in_visible_slots',
                'photo_subject_must_still_be_visually_verified'=>true];
            if($interiors>=2)$issues[]=['code'=>'media_viewpoint_museum_interior_scene_repeat','severity'=>'medium',
                'reason'=>'multiple_indoor_museum_images_in_hero_slots',
                'not_a_claim_of_identical_photographs'=>true];
        }
        $result['issues']=$issues;
        $result['issue_count']=count($issues);
        $result['viewpoint_coverage']=['contract'=>self::CONTRACT,
            'visible_exterior_overviews_by_caption'=>$overview,
            'visible_museum_interiors_by_caption'=>$interiors,
            'source'=>'attachment_caption_heuristics_no_visual_or_rights_certification'];
        return $result;
    }
    public static function plan($result,$tour) {
        if(!is_array($result))return $result;
        $selected=[];
        foreach((array)($result['proposed_hero_by_field']??[]) as $slot=>$candidate) {
            $id=(int)($candidate['id']??0);
            if($id)$selected[$slot]=self::image($id);
        }
        $result['viewpoint_coverage']=['contract'=>self::CONTRACT,
            'role'=>'nonbinding_media_selection_advice',
            'proposed_visible_slots'=>$selected,
            'text_cues_not_visual_or_rights_proof'=>true,
            'viewer_and_existing_authored_media_unchanged'=>true];
        return $result;
    }
}
if(function_exists('add_filter')){
    add_filter('mad4b/tour_photo_quality/hero_candidate_eligible',
        ['MAD4B_Tour_Viewpoint_Coverage_V1','eligibility'],10,5);
    add_filter('mad4b/tour_photo_quality/hero_candidate_score',
        ['MAD4B_Tour_Viewpoint_Coverage_V1','rank'],10,7);
    add_filter('mad4b/tour_photo_quality/audit',
        ['MAD4B_Tour_Viewpoint_Coverage_V1','audit'],10,2);
    add_filter('mad4b/tour_photo_quality/selection_plan',
        ['MAD4B_Tour_Viewpoint_Coverage_V1','plan'],10,2);
}
