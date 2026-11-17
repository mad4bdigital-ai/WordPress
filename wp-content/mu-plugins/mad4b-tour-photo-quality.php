<?php
/**
 * Plugin Name: MAD4B Tour Photo Selection Quality
 * Description: Per-tour photo originality, provenance and visual overlap gates, ranked criteria passes and bounded Content Readiness notifications. Distinct tours may share images.
 * Version: 1.0.0
 */
defined('ABSPATH')||exit;
require_once __DIR__.'/mad4b-tour-photo-quality/weighted-coverage.php';
final class MAD4B_Tour_Photo_Quality_V1 {
    use MAD4B_Tour_Photo_Weighted_Coverage_Trait;
    const CONTRACT='mad4b.tour-photo-quality.v1';
    const OPTION='mad4b_tour_photo_quality_alerts_v1';
    const CURSOR='mad4b_tour_photo_quality_scan_cursor_v1';
    private static array $hashes=[];
    private static array $origins=[];
    private static function fail(string $code):array{return ['ok'=>false,'reason'=>$code];}
    private static function origin(int $id):array{
        if(array_key_exists($id,self::$origins))return self::$origins[$id];
        if(!class_exists('MAD4B_Media_Visual_Lineage_V1',false))return self::$origins[$id]=['verified'=>false,'reason'=>'lineage_module_missing'];
        return self::$origins[$id]=MAD4B_Media_Visual_Lineage_V1::origin($id);
    }
    /** Image-identity review holds are not claims of AI generation or rights.
     * A photo may be reconsidered only through a new evidence-bound review.
     */
    public static function editorial_hold(int $id):array {
        $o=self::origin($id);
        $sha=(string)($o['origin_sha256']??'');
        if(empty($o['verified'])||!preg_match('/^[a-f0-9]{64}$/D',$sha))
            return ['held'=>false,'reason'=>'original_identity_unverified'];
        $stored=get_option('mad4b_media_editorial_holds_v1',[]);
        $row=is_array($stored)?($stored[$sha]??null):null;
        $valid=is_array($row)&&($row['contract']??'')==='mad4b.media-editorial-hold.v1'
            &&($row['origin_sha256']??'')===$sha
            &&($row['status']??'')==='hold_for_visual_review'
            &&preg_match('/^[a-f0-9]{64}$/D',(string)($row['contact_sheet_sha256']??''))
            &&is_string($row['reason']??null)&&mb_strlen($row['reason'])>=20;
        return ['held'=>$valid,'origin_sha256'=>$sha,
            'reason'=>$valid?$row['reason']:'not_held',
            'not_a_synthetic_or_rights_determination'=>true];
    }
    /** Only explicit positive origin evidence proves synthetic media. Missing labels are UNKNOWN, never "real" or "AI". */
    public static function provenance(int $id):array{
        $o=self::origin($id);
        if(empty($o['verified']))return ['status'=>'unresolved','reason'=>$o['reason']??'origin_missing'];
        $root=(int)$o['origin_attachment_id'];
        $ids=array_unique([$root,$id]);
        $binary=['_mad4b_ai_generated','_ai_generated','_mad4b_is_ai_generated','_generated_by_ai','_mad4b_synthetic_image'];
        $textual=['_mad4b_media_origin_type','_mad4b_image_origin','_mad4b_media_generation_type','_mad4b_content_origin','_mad4b_source_type'];
        $known=['ai_generated','generated_ai','synthetic','generative_ai','midjourney','stable_diffusion','dall-e','dalle','flux_generated','comfyui'];
        foreach($ids as $aid){
            // Explicit generator-export filename survives image conversion and
            // is strong, reviewable positive evidence. Never treat generic
            // terms such as 'AI' or a random "v0" suffix as proof.
            $file=basename((string)get_attached_file($aid));
            if(preg_match('/^(?:ChatGPT[-_ ]Image[-_ ]|DALL[-_ ]?E[-_ ]|Midjourney[-_ ]|Stable[-_ ]Diffusion[-_ ])/i',$file))
                return ['status'=>'synthetic_confirmed','evidence'=>'explicit_generator_export_filename',
                    'attachment_id'=>$aid,'filename'=>$file];
            foreach($binary as $key){$v=get_post_meta($aid,$key,true);
                if($v===true||$v===1||in_array(strtolower(trim((string)$v)),['1','true','yes','ai','synthetic'],true))
                    return ['status'=>'synthetic_confirmed','evidence'=>'metadata_flag','attachment_id'=>$aid,'meta_key'=>$key];
            }
            foreach($textual as $key){$v=strtolower(trim((string)get_post_meta($aid,$key,true)));
                if(in_array($v,$known,true))return ['status'=>'synthetic_confirmed','evidence'=>'declared_media_origin','attachment_id'=>$aid,'meta_key'=>$key];
            }
            $m=wp_get_attachment_metadata($aid);
            $software=strtolower((string)($m['image_meta']['software']??''));
            if($software!==''&&preg_match('/midjourney|stable diffusion|comfyui|automatic1111|dall.?e|leonardo\.ai|openai image generator/i',$software))
                return ['status'=>'synthetic_confirmed','evidence'=>'image_software_generator_tag','attachment_id'=>$aid];
        }
        $verified=(string)get_post_meta($root,'_mad4b_photo_authenticity_verified',true);
        return $verified==='verified_photographic_origin'
            ?['status'=>'photography_editorially_verified','evidence'=>'editorial_provenance_attestation','origin_attachment_id'=>$root]
            :['status'=>'origin_unverified','evidence'=>'no_conclusive_provenance_metadata','origin_attachment_id'=>$root];
    }
    private static function dhash(int $id):?string {
        $o=self::origin($id);$root=(int)($o['origin_attachment_id']??0);
        if(!$root)return null;
        if(array_key_exists($root,self::$hashes))return self::$hashes[$root];
        if(!function_exists('imagecreatefromstring'))return self::$hashes[$root]=null;
        $f=get_attached_file($root);
        if(!$f||!is_file($f)||filesize($f)>12000000)return self::$hashes[$root]=null;
        $dims=@getimagesize($f);if(!$dims||$dims[0]*$dims[1]>16000000)return self::$hashes[$root]=null;
        // Share expensive perceptual hashing between hourly passes. A changed
        // original file or lineage digest creates a different cache key.
        $key='dhash_'.hash('sha256',$root.'|'.(string)($o['origin_sha256']??'').'|'.filesize($f).'|'.filemtime($f));
        $cached=wp_cache_get($key,'mad4b_tour_photo_quality');
        if(is_string($cached)&&preg_match('/^[01]{64}$/D',$cached))
            return self::$hashes[$root]=$cached;
        $bytes=@file_get_contents($f);$im=$bytes!==false?@imagecreatefromstring($bytes):false;
        if(!$im)return self::$hashes[$root]=null;
        $thumb=imagecreatetruecolor(9,8);imagecopyresampled($thumb,$im,0,0,0,0,9,8,imagesx($im),imagesy($im));imagedestroy($im);
        $bits='';
        for($y=0;$y<8;$y++)for($x=0;$x<8;$x++){
            $p=imagecolorat($thumb,$x,$y);$q=imagecolorat($thumb,$x+1,$y);
            $g1=((($p>>16)&255)*299+(($p>>8)&255)*587+($p&255)*114);
            $g2=((($q>>16)&255)*299+(($q>>8)&255)*587+($q&255)*114);
            $bits.=($g1>$g2)?'1':'0';
        }
        imagedestroy($thumb);
        wp_cache_set($key,$bits,'mad4b_tour_photo_quality',DAY_IN_SECONDS);
        return self::$hashes[$root]=$bits;
    }
    private static function distance(string $a,string $b):int{$d=0;for($i=0;$i<64;$i++)if($a[$i]!==$b[$i])$d++;return $d;}
    private static function image_ids(string $csv,int $post_id):array {
        $parts=array_values(array_filter(array_map('trim',explode(',',$csv)),'strlen'));
        if(!$parts||count($parts)>500)return ['ids'=>[],'unresolved'=>count($parts)];
        $r=MAD4B_Gallery_Resolution_Engine_V1::inspect(implode(',',$parts),$post_id);
        if(empty($r['valid_source']))return ['ids'=>[],'unresolved'=>count($parts)];
        return ['ids'=>array_map('intval',explode(',',(string)$r['render_value'])),'unresolved'=>(int)$r['unresolved']];
    }
    /** Hard-stop: synthetic originals, repeated photographic origins in hero OR newly selected gallery. */
    public static function preflight(int $tour,array $gallery,array $grid,int $vertical):array {
        if(get_post_type($tour)!=='tours-and-activities')return self::fail('tour_missing');
        $hero=array_merge($vertical>0?[$vertical]:[],$grid);
        $seen=[];$hero_origins=[];$gallery_origins=[];
        foreach($hero as $id){
            if(!is_int($id)||$id<=0)return self::fail('invalid_hero_attachment');
            $o=self::origin($id);if(empty($o['verified']))return self::fail('hero_origin_unverified');
            $sha=$o['origin_sha256'];if(isset($seen[$sha]))return self::fail('hero_original_repeated');
            if(self::provenance($id)['status']==='synthetic_confirmed')return self::fail('hero_ai_generated_prohibited');
            if(self::editorial_hold($id)['held'])return self::fail('hero_original_editorial_hold');
            $seen[$sha]=true;$hero_origins[]=$sha;
        }
        $seen=[];
        foreach($gallery as $id){
            if(!is_int($id)||$id<=0)return self::fail('invalid_gallery_attachment');
            $o=self::origin($id);if(empty($o['verified']))return self::fail('gallery_origin_unverified');
            $sha=$o['origin_sha256'];if(isset($seen[$sha]))return self::fail('gallery_original_repeated');
            if(self::provenance($id)['status']==='synthetic_confirmed')return self::fail('gallery_ai_generated_prohibited');
            if(self::editorial_hold($id)['held'])return self::fail('gallery_original_editorial_hold');
            $seen[$sha]=true;$gallery_origins[]=$sha;
        }
        return ['ok'=>true,'hero_origins'=>count($hero_origins),'gallery_origins'=>count($gallery_origins),
            'cross_tour_reuse_allowed'=>true,'semantic_and_rights_review_still_required'=>true];
    }
    /** Unreviewed bulk-import evidence; never mutates gallery or blocks individual shared photos. */
    public static function bulk_reuse_evidence(int $tour,string $raw):array {
        static $memo=[];
        $items=array_values(array_filter(array_map('trim',explode(',',$raw)),'strlen'));
        if(count($items)<30)return ['untrusted'=>false,'reason'=>'not_bulk'];
        $src=(string)wp_parse_url($items[0],PHP_URL_HOST);
        $home=(string)wp_parse_url(home_url('/'),PHP_URL_HOST);
        if(!$src || $src===$home)return ['untrusted'=>false,'reason'=>'local_gallery'];
        $sha=hash('sha256',$raw);
        if(!array_key_exists($sha,$memo)){
            global $wpdb;
            $count=$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
                  JOIN {$wpdb->postmeta} m ON m.post_id=p.ID
                 WHERE p.post_type=%s AND p.post_status=%s AND m.meta_key=%s
                   AND SHA2(m.meta_value,256)=%s",
                'tours-and-activities','publish','gallery',$sha
            ));
            $memo[$sha]=($count===null || $wpdb->last_error)?null:(int)$count;
        }
        return ['untrusted'=>$memo[$sha]===null || $memo[$sha]>=12,
            'reason'=>$memo[$sha]===null?'catalog_unavailable':'exact_bulk_foreign_gallery_reuse',
            'same_gallery_published_posts'=>$memo[$sha],
            'gallery_items'=>count($items),'gallery_sha256'=>$sha];
    }
    /** Enumerate current native Tour image slots instead of fixed widget IDs.
     * Future JetEngine grid fields become eligible with no code deployment.
     */
    private static function hero_fields(int $tour):array {
        $fields=['image_vertical'];
        foreach(array_keys((array)get_post_meta($tour)) as $field){
            if(is_string($field)&&preg_match('/^grid_image_[a-zA-Z0-9_]{1,80}$/D',$field))
                $fields[]=$field;
        }
        $fields=array_values(array_unique($fields));
        $head=array_shift($fields);
        sort($fields,SORT_NATURAL);
        $fields=array_merge([$head],$fields);
        $registered=apply_filters('mad4b/tour_photo_quality/hero_fields',$fields,$tour);
        if(!is_array($registered))return $fields;
        $allowed=array_fill_keys(array_keys((array)get_post_meta($tour)),true);
        $result=[];
        foreach(array_slice($registered,0,80) as $name)
            if(is_string($name)&&isset($allowed[$name])
                &&preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{1,90}$/D',$name))
                $result[$name]=true;
        return $result?array_keys($result):$fields;
    }
    private static function focal_points(int $tour):array {
        // Only a focus bound to the current verified ORIGINAL photo counts.
        $v2=get_post_meta($tour,'mad4b_media_frame_focus_v2',true);
        if(is_array($v2)&&$v2){
            $out=[];
            foreach(array_slice($v2,0,80) as $row){
                if(!is_array($row))continue;
                $slot=(string)($row['slot']??'');
                $old=(string)($row['source_sha256']??'');
                $x=$row['focus_x']??null;$y=$row['focus_y']??null;
                if(!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{2,90}$/D',$slot)
                    ||!preg_match('/^[a-f0-9]{64}$/D',$old)
                    ||!is_numeric($x)||!is_numeric($y)||$x<0||$x>100||$y<0||$y>100)continue;
                $ids=self::image_ids((string)get_post_meta($tour,$slot,true),$tour);
                $o=self::origin((int)($ids['ids'][0]??0));
                if(empty($o['verified'])||!hash_equals($old,(string)($o['origin_sha256']??'')))continue;
                $out[$slot]=['x'=>(float)$x,'y'=>(float)$y];
            }
            return $out;
        }
        $raw=get_post_meta($tour,'_mad4b_media_focal_points_v1',true);
        if(!is_array($raw))return [];
        $out=[];
        foreach(array_slice($raw,0,80,true) as $key=>$point){
            if(!is_string($key)||!is_array($point)||array_keys($point)!==['x','y'])continue;
            if((!is_int($point['x'])&&!is_float($point['x']))
                ||(!is_int($point['y'])&&!is_float($point['y']))
                ||$point['x']<0||$point['x']>100||$point['y']<0||$point['y']>100)continue;
            $out[$key]=$point;
        }
        return $out;
    }
    /** Conservative frame-cover crop budget. Pure geometry, no photo claims.
     * For a cover image, the visible fraction of source pixels equals the
     * smaller ratio of its image/frame aspect ratios. CSS focal coordinates
     * cannot recover the cropped area; a manual focus is not visual approval.
     */
    /** Pure size admission rule, testable independently of WP metadata.
     * A portrait cannot be silently reassigned to a wide Elementor grid.
     */
    public static function same_original_grid_reframe_geometry(int $width,int $height):bool {
        return $width>=1000 && $height>=800 && $height>0 && $width/$height>=0.98;
    }
    public static function cover_crop_geometry(int $width,int $height,float $frameRatio):array {
        if($width<1||$height<1||!is_finite($frameRatio)||$frameRatio<0.25||$frameRatio>5)
            return ['status'=>'invalid_geometry'];
        $imageRatio=$width/$height;
        $visible=min(1.0,$imageRatio/$frameRatio,$frameRatio/$imageRatio);
        return ['status'=>'ok','source_aspect_ratio'=>round($imageRatio,4),
            'frame_aspect_ratio'=>round($frameRatio,4),
            'visible_source_fraction'=>round($visible,4),
            'estimated_cropped_fraction'=>round(1-$visible,4),
            'cropped_axis'=>$imageRatio<$frameRatio?'vertical':($imageRatio>$frameRatio?'horizontal':'none')];
    }
    private static function native_frame_crop_config(int $tour,string $slot,string $sourceSha):array {
        $rows=get_post_meta($tour,'mad4b_media_frame_focus_v2',true);
        if(!is_array($rows)||!$rows){
            $default=(string)apply_filters('wpml_default_language','');
            $canonical=$default!==''?(int)apply_filters('wpml_object_id',$tour,'tours-and-activities',false,$default):0;
            if($canonical>0&&$canonical!==$tour&&get_post_type($canonical)==='tours-and-activities')
                $rows=get_post_meta($canonical,'mad4b_media_frame_focus_v2',true);
        }
        foreach(array_slice((array)$rows,0,80) as $row){
            if(!is_array($row)||($row['slot']??'')!==$slot)continue;
            if(!preg_match('/^[a-f0-9]{64}$/D',(string)($row['source_sha256']??''))
               ||!hash_equals($sourceSha,(string)$row['source_sha256']))continue;
            $aspect=$row['frame_aspect_ratio']??null;
            return ['fit'=>($row['fit']??'')==='contain'?'contain':'cover',
                'frame_aspect_ratio'=>is_numeric($aspect)&&$aspect>=0.25&&$aspect<=5?(float)$aspect:null,
                'source_sha256'=>$sourceSha,'focus_is_current'=>true];
        }
        return ['fit'=>'cover','frame_aspect_ratio'=>null,'focus_is_current'=>false];
    }
    public static function audit(int $tour):array {
        if(get_post_type($tour)!=='tours-and-activities')return self::fail('tour_missing');
        $fields=self::hero_fields($tour);
        $focal=self::focal_points($tour);
        $media_policy=class_exists('MAD4B_Media_Review_Hierarchy_V1',false)
            ?MAD4B_Media_Review_Hierarchy_V1::effective():[];
        // Company policy may add reviews, never make synthetic/duplicate
        // originals acceptable or create a publishing authorization.
        $review_rules=apply_filters('mad4b/tour_photo_quality/review_rules',[
            'cropping'=>true,'per_image_subject'=>true],$tour,$media_policy);
        if(!is_array($review_rules))$review_rules=[];
        $review_crop=($review_rules['cropping']??true)!==false;
        $review_subject=($review_rules['per_image_subject']??true)!==false;
        $issues=[];$hero=[];$seen=[];
        foreach($fields as $field){
            $url=(string)get_post_meta($tour,$field,true);
            if($url===''){ $issues[]=['code'=>'media_hero_slot_empty','severity'=>'high','field'=>$field];continue;}
            $ids=self::image_ids($url,$tour);$id=(int)($ids['ids'][0]??0);$o=self::origin($id);
            if(empty($o['verified'])){$issues[]=['code'=>'media_hero_source_unresolved','severity'=>'high','field'=>$field];continue;}
            $sha=$o['origin_sha256'];
            if(isset($seen[$sha]))$issues[]=['code'=>'media_hero_original_duplicate','severity'=>'high','field'=>$field,'duplicate_of'=>$seen[$sha]];
            else $seen[$sha]=$field;
            $p=self::provenance($id);
            if($p['status']==='synthetic_confirmed')$issues[]=['code'=>'media_synthetic_source_prohibited','severity'=>'high','field'=>$field,'evidence'=>$p['evidence']];
            if($p['status']==='origin_unverified')$issues[]=['code'=>'media_origin_provenance_unverified','severity'=>'low','field'=>$field];
            // A portrait in a landscape hero cell can hide the actual subject.
            // A focal point is evidence of editorial framing, NOT evidence of
            // geographic accuracy, photographic provenance or license rights.
            $size=wp_get_attachment_metadata($id);
            if($review_crop && (int)($size['width']??0)>0 && (int)($size['height']??0)>0){
                $frame=self::native_frame_crop_config($tour,$field,(string)$sha);
                $isLandscapeSlot=str_starts_with($field,'grid_image_');
                $nativeAspect=$frame['frame_aspect_ratio'];
                $ratio=$nativeAspect??($isLandscapeSlot?1.0:null);
                if($frame['fit']==='cover'&&$ratio!==null){
                    $geometry=self::cover_crop_geometry((int)$size['width'],(int)$size['height'],(float)$ratio);
                    // With no measured Elementor ratio, 1:1 is only a
                    // conservative lower bound for a grid landscape frame.
                    // Risk detected at that lower bound remains real for the
                    // wider actual Elementor container, without inventing
                    // a claimed viewport measurement.
                    if(($geometry['status']??'')==='ok'
                       && $geometry['visible_source_fraction']<0.70
                       && ($nativeAspect!==null || $geometry['cropped_axis']==='vertical')){
                        $issues[]=['code'=>'media_hero_crop_geometry_review_pending',
                            'severity'=>'medium','field'=>$field,
                            'attachment_id'=>$id,'origin_sha256'=>$sha,
                            'width'=>(int)$size['width'],'height'=>(int)$size['height'],
                            'source_aspect_ratio'=>$geometry['source_aspect_ratio'],
                            'frame_ratio_basis'=>$nativeAspect!==null?'jetengine_explicit_frame_ratio':'landscape_grid_lower_bound_1_to_1',
                            'reference_frame_aspect_ratio'=>$ratio,
                            'minimum_estimated_crop_fraction'=>$geometry['estimated_cropped_fraction'],
                            'crop_axis'=>$geometry['cropped_axis'],
                            'current_focal_meta_exists'=>isset($focal[$field]),
                            'focal_cannot_restore_pixels_lost_to_cover'=>true,
                            'recommended_action'=>'review_landscape_source_or_approved_native_contain_layout',
                            'photographic_source_or_rights_not_auto_verified'=>true];
                    }
                }
                if($isLandscapeSlot && $size['width']/$size['height']<0.9 && !isset($focal[$field]))
                    $issues[]=['code'=>'media_hero_focal_review_pending',
                        'severity'=>'medium','field'=>$field,
                        'width'=>(int)$size['width'],'height'=>(int)$size['height'],
                        'visual_subject_not_verified_by_dimensions'=>true];
            }
            if(self::editorial_hold($id)['held'])
                $issues[]=['code'=>'media_visual_editorial_hold','severity'=>'medium',
                    'field'=>$field,'attachment_id'=>$id,
                    'reason'=>'source_bound_review_requires_a_new_editorial_decision'];
            $hero[]=['field'=>$field,'id'=>$id,'sha'=>$sha,'dhash'=>self::dhash($id)];
        }
        // Title focus is a separate quality dimension from shared destinations.
        $headline=self::headline_context($tour);
        if(($headline['profile']??'none')!=='none'){
            $focused=[];$in_first_three=0;
            foreach($hero as $index=>$picture){
                if(!self::headline_photo_hint((int)$picture['id'],$headline))continue;
                $focused[]=$picture['field'];if($index<3)$in_first_three++;
            }
            if(count($focused)<(int)$headline['min_hero']||$in_first_three===0)
                $issues[]=['code'=>'media_hero_headline_focus_gap','severity'=>'medium',
                    'profile'=>$headline['profile'],'mixed_package'=>$headline['mixed'],
                    'focus_slots'=>$focused,'minimum_focus_slots'=>$headline['min_hero'],
                    'minimum_early_focus'=>1,
                    'filename_cue_is_not_visual_or_rights_certification'=>true];
        }
        // Accommodation photography must follow published Tour Rates, not
        // an independently guessed Tour -> Property relation.
        if(($headline['profile']??'none')!=='none'){
            $rateContext=self::coverage_context($tour);
            $stay=self::coverage_rate_property_sources($tour,$rateContext);
            $receipt=$stay['report']??[];
            $missing=(array)($receipt['options_missing_media']??[]);
            if($missing)$issues[]=['code'=>'media_rate_property_photo_coverage_gap',
                'severity'=>'medium','rate_property_options'=>(int)($receipt['rate_property_options']??0),
                'properties_without_subject_supported_images'=>count($missing),
                'property_ids'=>array_slice(array_map('intval',array_column($missing,'property_id')),0,20),
                'source'=>'native_tour_rate_accommodation_assignment',
                'property_is_option_not_guaranteed_vessel'=>true];
        }
        // The original Tour association can prove which files were reviewed
        // together, but its generic contact-sheet note is not per-image proof.
        $association=get_post_meta($tour,'_mad4b_tour_media_association_v1',true);
        $associationPost=$tour;
        // The signed association belongs to the default-language canonical
        // tour and explicitly binds its WPML family. Spanish does not need an
        // independently forged duplicate. Reuse ONLY after native provider
        // verifies all attachments, language members and file SHA identities.
        if($review_subject
           &&(!is_array($association)||($association['contract']??'')!=='mad4b.tour-media-association.v1')
           &&class_exists('MAD4B_Tour_Media_Association_V1',false)){
            $primary=(int)apply_filters('wpml_object_id',$tour,'tours-and-activities',false,
                (string)apply_filters('wpml_default_language','en'));
            if($primary>0&&$primary!==$tour&&get_post_status($primary)==='publish'){
                $trid=apply_filters('wpml_element_trid',null,$primary,'post_tours-and-activities');
                $translations=$trid?apply_filters('wpml_get_element_translations',[],
                    $trid,'post_tours-and-activities'):[];
                $family=[];
                foreach(array_slice((array)$translations,0,12,true) as $language=>$translation){
                    $pid=(int)($translation->element_id??0);
                    if($pid>0&&get_post_type($pid)==='tours-and-activities'
                       &&get_post_status($pid)==='publish')$family[(string)$language]=$pid;
                }
                ksort($family);
                if(($family['en']??0)===$primary && in_array($tour,$family,true)
                   &&count(MAD4B_Tour_Media_Association_V1::verified_ids($primary,$family))>=4){
                    $candidate=get_post_meta($primary,'_mad4b_tour_media_association_v1',true);
                    if(is_array($candidate)
                       &&($candidate['contract']??'')==='mad4b.tour-media-association.v1'
                       &&($candidate['canonical_post_id']??0)===$primary
                       &&($candidate['wpml_family']??[])===$family){
                        $association=$candidate;
                        $associationPost=$primary;
                    }
                }
            }
        }
        if(!is_array($association)||($association['contract']??'')!=='mad4b.tour-media-association.v1'){
            if($review_subject)$issues[]=['code'=>'media_hero_subject_review_pending',
                'severity'=>'medium','reason'=>'original_tour_association_unavailable',
                'never_assume_subject_from_file_name'=>true];
        }elseif($review_subject){
            $approved_origins=[];
            foreach((array)($association['items']??[]) as $item)
                if(preg_match('/^[a-f0-9]{64}$/D',(string)($item['sha256']??'')))
                    $approved_origins[(string)$item['sha256']]=true;
            foreach($hero as $photo)
                if(!isset($approved_origins[$photo['sha']]))
                    $issues[]=['code'=>'media_hero_subject_review_pending',
                        'severity'=>'medium','field'=>$photo['field'],
                        'reason'=>'photo_not_in_original_review_association'];
            $subject=get_post_meta($associationPost,'_mad4b_media_subject_evidence_v1',true);
            $site=class_exists('MAD4B_SCP_Site_Profile',false)
                ?(string)MAD4B_SCP_Site_Profile::site_uuid():'';
            $bound=is_array($subject)
                &&($subject['contract']??'')==='mad4b.media-subject-evidence.v1'
                &&$site!==''&&($subject['site_uuid']??'')===$site
                &&is_array($subject['slots']??null);
            if(!$bound){
                $issues[]=['code'=>'media_hero_subject_review_pending',
                    'severity'=>'medium','reason'=>'per_photo_subject_evidence_absent_or_unbound',
                    'generic_tour_review_is_not_per_image_subject_proof'=>true];
            }else{
                // An editable contract name alone cannot certify a photo.
                // Bind each editorial subject note to the exact original
                // image SHA and the independent visual review evidence.
                foreach($hero as $photo){
                    $slot=$subject['slots'][$photo['field']]??null;
                    $valid=is_array($slot)
                        &&($slot['origin_sha256']??'')===$photo['sha']
                        &&is_string($slot['subject']??null)
                        &&mb_strlen(trim($slot['subject']))>=8
                        &&in_array($slot['review_type']??'', ['human_editor','ai_editorial_review'],true)
                        &&preg_match('/^[a-f0-9]{64}$/D',(string)($slot['visual_review_sha256']??''))
                        &&preg_match('/^20[0-9]{2}-[01][0-9]-[0-3][0-9]T/',(string)($slot['reviewed_at']??''));
                    if(!$valid)$issues[]=['code'=>'media_hero_subject_review_pending',
                        'severity'=>'medium','field'=>$photo['field'],
                        'reason'=>'missing_or_stale_origin_bound_subject_review'];
                }
            }
        }
        $featured=(int)get_post_thumbnail_id($tour);
        if($featured>0){
            $featured_origin=self::origin($featured);
            if(empty($featured_origin['verified']))
                $issues[]=['code'=>'media_featured_original_unresolved','severity'=>'high'];
            $featured_provenance=self::provenance($featured);
            if(self::editorial_hold($featured)['held'])
                $issues[]=['code'=>'media_visual_editorial_hold','severity'=>'medium',
                    'scope'=>'featured','attachment_id'=>$featured,
                    'reason'=>'featured_original_requires_subject_and_geography_review'];
            if($featured_provenance['status']==='synthetic_confirmed')
                $issues[]=['code'=>'media_synthetic_source_prohibited','severity'=>'high','scope'=>'featured',
                    'evidence'=>$featured_provenance['evidence']];
        }
        $gallery_raw=(string)get_post_meta($tour,'gallery',true);
        $bulk=self::bulk_reuse_evidence($tour,$gallery_raw);
        if($bulk['untrusted'])$issues[]=['code'=>'media_gallery_mass_reuse_requires_reselection',
            'severity'=>'high','same_gallery_published_posts'=>$bulk['same_gallery_published_posts'],
            'gallery_items'=>$bulk['gallery_items'],'reason'=>$bulk['reason'],
            'editorial_relevance_unverified'=>true,'original_gallery_preserved'=>true];
        $gallery=self::image_ids($gallery_raw,$tour);
        if(count($gallery['ids'])===0)$issues[]=['code'=>'media_gallery_missing','severity'=>'medium'];
        if(($headline['profile']??'none')!=='none'){
            $expected=$headline['mixed']?8:12;
            if(count($gallery['ids'])<$expected)
                $issues[]=['code'=>'media_gallery_headline_depth_gap','severity'=>'low',
                    'current'=>count($gallery['ids']),'editorial_target'=>$expected,
                    'source_media_required_to_be_locally_resolved_and_rights_reviewed'=>true];
        }

        if($gallery['unresolved'])$issues[]=['code'=>'media_gallery_unresolved','severity'=>'high','count'=>$gallery['unresolved']];
        $gseen=[];$grows=[];
        foreach($gallery['ids'] as $n=>$id){
            $o=self::origin((int)$id);
            if(empty($o['verified'])){$issues[]=['code'=>'media_gallery_original_unverified','severity'=>'high','index'=>$n];continue;}
            $sha=$o['origin_sha256'];
            if(isset($gseen[$sha]))$issues[]=['code'=>'media_gallery_original_duplicate','severity'=>'high','index'=>$n,'duplicate_of'=>$gseen[$sha]];
            else $gseen[$sha]=$n;
            $p=self::provenance((int)$id);
            if($p['status']==='synthetic_confirmed')$issues[]=['code'=>'media_synthetic_source_prohibited','severity'=>'high','gallery_index'=>$n,'evidence'=>$p['evidence']];
            if(self::editorial_hold((int)$id)['held'])
                $issues[]=['code'=>'media_visual_editorial_hold','severity'=>'medium',
                    'gallery_index'=>$n,'attachment_id'=>(int)$id,
                    'reason'=>'source_bound_review_requires_a_new_editorial_decision'];
            $grows[]=['index'=>$n,'sha'=>$sha,'dhash'=>self::dhash((int)$id)];
        }
        // Similar composition is a REVIEW signal, never automatic evidence of duplication or AI.
        foreach([['hero',$hero],['gallery',$grows]] as [$scope,$rows]){
            $alerts=0;
            for($a=0;$a<count($rows);$a++){
                if($alerts>=5)break;
                for($b=$a+1;$b<count($rows);$b++){
                    if($rows[$a]['sha']===$rows[$b]['sha'])continue;
                    if($rows[$a]['dhash']===null||$rows[$b]['dhash']===null)continue;
                    $dist=self::distance($rows[$a]['dhash'],$rows[$b]['dhash']);
                    if($dist<=3){$issues[]=['code'=>'media_near_duplicate_review','severity'=>'medium','scope'=>$scope,
                        'item_a'=>$rows[$a]['field']??$rows[$a]['index'],'item_b'=>$rows[$b]['field']??$rows[$b]['index'],
                        'dhash_distance'=>$dist,'not_automatic_proof'=>true];$alerts++;break;}
                }
            }
        }
        // Coverage is evaluated from actual itinerary days, highlights and
        // destination media (not a fixed gallery image-count threshold).
        if(class_exists('MAD4B_Tour_Story_Gallery_V1',false)){
            $story=MAD4B_Tour_Story_Gallery_V1::build($tour);
            foreach((array)($story['geography_review']??[]) as $conflict){
                $issues[]=['code'=>'media_itinerary_geography_conflict','severity'=>'high',
                  'primary_destination'=>$conflict['primary_destination']??'',
                  'conflicting_excursion'=>$conflict['conflicting_optional_excursion']??'',
                  'needs_supplier_confirmation'=>true];
            }
            foreach((array)($story['coverage_gaps']??[]) as $gap){
                $issues[]=['code'=>'media_story_coverage_gap','severity'=>'medium',
                    'destination'=>sanitize_text_field((string)($gap['section']??'')),
                    'missing_photo_count'=>absint($gap['missing']??0),
                    'itinerary_days'=>array_slice(array_map('intval',(array)($gap['days']??[])),0,30),
                    'has_destination_context'=>true];
            }
        }
        return apply_filters('mad4b/tour_photo_quality/audit',['contract'=>self::CONTRACT,'post_id'=>$tour,'hero_slots'=>count($hero),'gallery_items'=>count($gallery['ids']),
            'issues'=>$issues,'issue_count'=>count($issues),'source_image_identity_verified'=>true,
            'cross_tour_image_reuse_allowed'=>true,'ai_detection_complete'=>false,
            'ai_limitations'=>'Positive generator tags are conclusive for blocking; absence of metadata does not prove photographic origin'],$tour);
    }
    /** Company-extendable title-first story profile, never a publication grant. */
    private static function headline_context(int $tour):array {
        $defaults=['nile_cruise'=>[
            'taxonomy'=>'tour-types','term'=>'nile-cruises',
            'title_phrases'=>['nile cruise','crucero por el nilo','nilkreuzfahrt',
                'croisière sur le nil','crociera sul nilo','كروز النيل'],
            'subject_hints'=>['cruise-ship','nile-boat','sun-boat','sundeck','fayan-deck'],
            'subject_primary'=>['ship','boat','deck','sundeck','vessel'],
            'subject_context'=>['nile','cruise','river','fayan'],
            'min_hero'=>2,'mixed_package_min_hero'=>1]];
        $stored=get_option('mad4b_media_story_profiles_v1',[]);
        if(is_array($stored))foreach($stored as $key=>$config)
            if(isset($defaults[$key])&&is_array($config))
                $defaults[$key]=array_replace($defaults[$key],$config);
        $rules=apply_filters('mad4b/tour_photo_quality/headline_profiles',$defaults,$tour);
        if(!is_array($rules))$rules=$defaults;
        $title=mb_strtolower(html_entity_decode((string)get_the_title($tour),ENT_QUOTES,'UTF-8'),'UTF-8');
        foreach($rules as $profile=>$r){
            if(!is_array($r))continue;
            $tax=(string)($r['taxonomy']??'');$term=(string)($r['term']??'');
            $termMatch=$tax&&$term&&taxonomy_exists($tax)&&has_term($term,$tax,$tour);
            $titleMatch=false;
            foreach(array_slice((array)($r['title_phrases']??[]),0,24) as $phrase)
                if(is_string($phrase)&&mb_strlen($phrase)>3&&mb_stripos($title,mb_strtolower($phrase,'UTF-8'))!==false){$titleMatch=true;break;}
            if(!$termMatch&&!$titleMatch)continue;
            $mixed=taxonomy_exists('tour-types')&&has_term('packages','tour-types',$tour);
            $min=(int)($r[$mixed?'mixed_package_min_hero':'min_hero']??1);
            return ['profile'=>(string)$profile,'rules'=>$r,'mixed'=>$mixed,
                'min_hero'=>max(1,min(4,$min)),'evidence'=>'title_or_taxonomy_not_image_subject'];
        }
        return ['profile'=>'none','min_hero'=>0];
    }
    private static function headline_photo_hint(int $id,array $context):bool {
        if($id<1||($context['profile']??'none')==='none')return false;
        $origin=self::origin($id);
        if(!empty($origin['verified']))$id=(int)($origin['origin_attachment_id']??$id);
        $file=(string)get_post_meta($id,'_wp_attached_file',true);
        if($file==='')return false;
        $raw=mb_strtolower(pathinfo(wp_basename($file),PATHINFO_FILENAME),'UTF-8');
        $words=preg_split('/[^\p{L}\p{N}]+/u',$raw);
        $tokens=array_fill_keys((array)$words,true);
        $primary=false;$contextual=false;
        foreach(array_slice((array)($context['rules']['subject_primary']??[]),0,24) as $word)
            if(is_string($word)&&isset($tokens[$word])){$primary=true;break;}
        foreach(array_slice((array)($context['rules']['subject_context']??[]),0,24) as $word)
            if(is_string($word)&&isset($tokens[$word])){$contextual=true;break;}
        return $primary&&$contextual;
    }
    /** Bounded reuse of company-local originals, including shared tour subjects.
     * Never assumes copyright or an itinerary visit from an attachment title.
     */
    private static function headline_local_pool(int $tour,array $context):array {
        if(($context['profile']??'none')==='none')return [];
        $hints=[];
        foreach(array_slice((array)($context['rules']['subject_hints']??[]),0,12) as $term)
            if(is_string($term)&&preg_match('/^[a-z0-9-]{3,30}$/D',$term))$hints[]=$term;
        if(!$hints)return [];
        global $wpdb;
        $where=implode(' OR ',array_fill(0,count($hints),'p.post_name LIKE %s'));
        $args=array_map(static fn($v)=>'%'.$wpdb->esc_like($v).'%', $hints);
        $args[]=(string)apply_filters('wpml_default_language','en');$args[]=70;
        $sql=$wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->prefix}icl_translations tr
             ON tr.element_id=p.ID AND tr.element_type='post_attachment'
            WHERE p.post_type='attachment' AND p.post_mime_type LIKE 'image/%%'
             AND ($where) AND (tr.element_id IS NULL OR tr.language_code=%s)
            ORDER BY p.ID DESC LIMIT %d",...$args);
        $ids=$wpdb->get_col($sql);
        if(!is_array($ids)||$wpdb->last_error!=='')return [];
        $up=wp_get_upload_dir();$root=realpath((string)($up['basedir']??''));
        if(!$root)return [];
        $entries=[];$seen=[];
        foreach($ids as $raw){
            $id=(int)$raw;$o=self::origin($id);
            if(empty($o['verified'])||self::provenance($id)['status']==='synthetic_confirmed')continue;
            $sha=(string)($o['origin_sha256']??'');
            if($sha===''||isset($seen[$sha])||!self::headline_photo_hint($id,$context))continue;
            $file=get_attached_file($id);$real=$file?realpath($file):false;
            if(!$real||!str_starts_with($real,$root.DIRECTORY_SEPARATOR)||!is_file($real))continue;
            $meta=wp_get_attachment_metadata($id);$w=(int)($meta['width']??0);$h=(int)($meta['height']??0);
            if($w<640||$h<450)continue;
            $ratio=$w/$h;$seen[$sha]=true;
            $entries[]=['canonical_attachment_id'=>$id,
                'source_evidence'=>'company_local_shared_subject_pool_review_only',
                'aspect_ratio'=>$ratio,'role_scores'=>[
                    'vertical'=>($ratio<=.95&&$h>=780)?115:0,
                    'grid'=>100,'gallery'=>100],
                'headline_subject_hint'=>true,'activity_stem_matches_in_label'=>[],
                'editorial_visual_verified'=>false,'media_rights_verified'=>false,
                'auto_apply_authorized'=>false];
        }
        return array_slice($entries,0,40);
    }
    /** A parent attachment link is not subject proof: only positive subject
     * signals can enter the shortlist, and none authorizes publication.
     * A verified original already used as the vertical is retained for review.
     */
    private static function candidate_subject_signal(int $tour,array $entry,string $role,int $id):array {
        if(!empty($entry['editorial_visual_verified']))
            return ['eligible'=>true,'basis'=>'explicit_editorial_visual_review','still_needs_rights_review'=>true];
        $matches=(array)($entry['activity_stem_matches_in_label']??[]);
        if(count($matches)>0)
            return ['eligible'=>true,'basis'=>'activity_terms_in_attachment_label_only','still_needs_visual_and_rights_review'=>true];
        if($role==='vertical'){
            $current=self::image_ids((string)get_post_meta($tour,'image_vertical',true),$tour);
            $old=(int)($current['ids'][0]??0);
            $before=self::origin($old);$proposed=self::origin($id);
            if(!empty($before['verified'])&&!empty($proposed['verified'])
                &&hash_equals((string)$before['origin_sha256'],(string)$proposed['origin_sha256']))
                return ['eligible'=>true,'basis'=>'existing_vertical_original_retained','still_needs_visual_and_rights_review'=>true];
        }
        return ['eligible'=>false,'basis'=>'no_positive_tour_subject_signal'];
    }
    /** Iterative, non-mutating candidate selection with progressively wider source evidence. */
    public static function selection_loop(int $tour):array {
        if(!class_exists('MAD4B_Tour_Media_Reconcile_V1',false))return self::fail('media_reconcile_missing');
        $plan=MAD4B_Tour_Media_Reconcile_V1::plan($tour,['limit'=>24]);
        if(is_wp_error($plan))return self::fail($plan->get_error_code());
        $items=$plan['candidate_catalog']['items']??[];
        $headline=self::headline_context($tour);
        $shared=self::headline_local_pool($tour,$headline);
        $items=array_merge($items,$shared);
        $weighted=self::coverage_candidates($tour);
        foreach($weighted['items'] as $candidate){
            $id=(int)$candidate['id'];$meta=wp_get_attachment_metadata($id);
            $w=(int)($meta['width']??0);$h=(int)($meta['height']??0);
            $ratio=$h?($w/$h):3;
            $items[]=['canonical_attachment_id'=>$id,
                'source_evidence'=>'weighted_registered_context_review_only',
                'aspect_ratio'=>$ratio,'role_scores'=>['vertical'=>($ratio<=.95?75:0),
                    'grid'=>min(100,(int)$candidate['gallery_score']),'gallery'=>min(100,(int)$candidate['gallery_score'])],
                'coverage_score'=>(float)$candidate['gallery_score'],
                'hero_score'=>(float)$candidate['hero_score'],
                'hero_title_anchor'=>(bool)$candidate['hero_title_anchor'],
                'coverage_owner'=>$candidate['coverage_owner'],
                'weighted_review_context'=>true,'auto_apply_authorized'=>false,
                'editorial_visual_verified'=>false,'media_rights_verified'=>false];
        }
        // Approved-source catalogs can extend a shortlist when five distinct
        // images are not present. These are only editorial PROPOSALS.
        $catalog=get_post_meta($tour,'_mad4b_gallery_source_catalog_v1',true);
        if(is_array($catalog)&&($catalog['contract']??'')==='mad4b.tour-gallery-source.v1'){
            foreach(array_slice($catalog['items']??[],0,150) as $item){
                $id=(int)($item['attachment_id']??0);
                if(!$id||get_post_type($id)!=='attachment')continue;
                $file=get_attached_file($id);$dimension=$file?@getimagesize($file):false;
                if(!$dimension)continue;
                $items[]=['canonical_attachment_id'=>$id,
                    'source_evidence'=>'staged_source_gallery_review_only',
                    'aspect_ratio'=>$dimension[1]?($dimension[0]/$dimension[1]):3,
                    'role_scores'=>['vertical'=>($dimension[1]>=$dimension[0]?60:0),
                        'grid'=>65,'gallery'=>65]];
            }
        }
        $selected=[];$origins=[];$passes=[];$held_subject=[];$held_crop=[];
        $slot_fields=self::hero_fields($tour);
        $roles=array_map(static fn($field)=>$field==='image_vertical'?'vertical':'grid',$slot_fields);
        $slot_total=count($roles);
        foreach(['company_local_shared_subject_pool_review_only','weighted_registered_context_review_only','signed_tour_media_association','wordpress_parent_post','exact_tour_title_prefix',
                 'staged_source_gallery_review_only','existing_verified_tour_candidate'] as $pass){
            $added=[];
            foreach($roles as $slot=>$role){
                if(isset($selected[$slot]))continue;
                if($pass==='company_local_shared_subject_pool_review_only'
                   &&count($origins)>=(int)($headline['min_hero']??0))continue;
                $best=null;$best_score=-INF;
                foreach($items as $entry){
                    $id=(int)($entry['canonical_attachment_id']??0);if(!$id||!empty($entry['is_ai_generated']))continue;
                    $evidence=(string)($entry['source_evidence']??'');
                    if($pass!=='existing_verified_tour_candidate'&&$evidence!==$pass)continue;
                    $o=self::origin($id);if(empty($o['verified'])||isset($origins[$o['origin_sha256']]))continue;
                    if(self::provenance($id)['status']==='synthetic_confirmed')continue;
                    if(self::editorial_hold($id)['held'])continue;
                    if($role==='vertical'&&(float)($entry['aspect_ratio']??2)>1.0)continue;
                    // Selection and rendering share one crop budget. For Grid
                    // cover, no focused X/Y can restore large portions of
                    // a portrait source lost to a landscape frame.
                    // Without a real Elementor frame aspect we use the 1:1
                    // lower bound, not a fabricated 3:2 measurement.
                    $entryRatio=(float)($entry['aspect_ratio']??0);
                    if($role==='grid' && $entryRatio>0 && $entryRatio<0.70){
                        $held_crop[$id]=['id'=>$id,'origin_sha256'=>$o['origin_sha256'],
                            'source_aspect_ratio'=>round($entryRatio,4),
                            'minimum_cropped_fraction'=>round(1-$entryRatio,4),
                            'reason'=>'portrait_cover_crop_excess_for_grid',
                            'no_automatic_rights_or_visual_approval'=>true];
                        continue;
                    }
                    if(!apply_filters('mad4b/tour_photo_quality/hero_candidate_eligible',true,$tour,$slot_fields[$slot]??'',$entry,$id))continue;
                    $focus=self::headline_photo_hint($id,$headline);
                    if($pass==='company_local_shared_subject_pool_review_only'&&!$focus)continue;
                    if($pass==='weighted_registered_context_review_only'
                        &&((float)($entry['hero_score']??0)<25
                            ||empty($entry['hero_title_anchor'])))continue;
                    $subject=self::candidate_subject_signal($tour,$entry,$role,$id);
                    if($focus&&$pass==='company_local_shared_subject_pool_review_only')
                        $subject=['eligible'=>true,'basis'=>'local_filename_headline_hint_only'];
                    if($pass==='weighted_registered_context_review_only')
                        $subject=['eligible'=>true,'basis'=>'registered_media_and_weighted_context_review_only'];
                    if(empty($subject['eligible'])){
                        $held_subject[$id]=['id'=>$id,'reason'=>$subject['basis']];
                        continue;
                    }
                    $score=(float)($entry['role_scores'][$role]??0);
                    if($focus)$score+=($slot<3?80:45);
                    $score+=(float)($entry['hero_score']??0);
                    $score=apply_filters('mad4b/tour_photo_quality/hero_candidate_score',$score,$tour,$slot_fields[$slot]??'',$entry,$id,array_values($selected));
                    if($score>$best_score){$best=['id'=>$id,'sha'=>$o['origin_sha256'],'evidence'=>$evidence,
                        'provenance'=>self::provenance($id)['status'],
                        'subject_signal'=>$subject['basis'],'headline_subject_hint'=>$focus,
                        'needs_visual_and_rights_review'=>true];$best_score=$score;}
                }
                if($best){$selected[$slot]=$best;$origins[$best['sha']]=true;$added[]=$slot;}
            }
            $passes[]=['criterion_pass'=>$pass,'selected_hero_slots'=>$added,'remaining_slots'=>$slot_total-count($selected)];
            if(count($selected)===$slot_total)break;
        }
        ksort($selected);
        // Gallery loop: first keep every existing non-synthetic origin; then
        // extend from the exact tour association, metadata and staged source
        // catalog without changing or truncating actual WordPress meta.
        $gallery_raw=(string)get_post_meta($tour,'gallery',true);
        $bulk=self::bulk_reuse_evidence($tour,$gallery_raw);
        $existing=self::image_ids($gallery_raw,$tour);
        $gallery_proposal=[];$gallery_sha=[];$gallery_dhash=[];$rejected=['synthetic'=>0,'exact_duplicate'=>0,'near_duplicate'=>0,'unverified'=>0,'subject_unsubstantiated'=>0];
        foreach(($bulk['untrusted']?[]:$existing['ids']) as $id){
            if(self::editorial_hold((int)$id)['held']){
                $rejected['editorial_hold']=($rejected['editorial_hold']??0)+1;continue;
            }
            $o=self::origin((int)$id);
            if(empty($o['verified'])){$rejected['unverified']++;continue;}
            if(self::provenance((int)$id)['status']==='synthetic_confirmed'){$rejected['synthetic']++;continue;}
            if(isset($gallery_sha[$o['origin_sha256']])){$rejected['exact_duplicate']++;continue;}
            $gallery_sha[$o['origin_sha256']]=true;
            $gallery_proposal[]=['id'=>(int)$id,'source'=>'existing_gallery_preserved'];
            $gallery_dhash[]=self::dhash((int)$id);
        }
        // Gallery growth is proportional to registered chapters, bounded by
        // company budget. Previously selected originals are never removed.
        $chapterCounts=(array)($weighted['diagnostics']['coverage_source_counts']??[]);
        $chapters=array_sum(array_map('intval',$chapterCounts));
        $maxAdd=max(1,(int)($weighted['context']['policy']['budget']['gallery_additions']??48));
        $growth=min($maxAdd,max(8,$chapters*4));
        $galleryTarget=count($gallery_proposal)+$growth;
        $gallery_passes=[];
        foreach(['signed_tour_media_association','wordpress_parent_post','exact_tour_title_prefix',
                 'staged_source_gallery_review_only','company_local_shared_subject_pool_review_only','weighted_registered_context_review_only'] as $pass){
            $accepted=0;
            foreach($items as $entry){
                if(count($gallery_proposal)>=$galleryTarget)break;
                if(($entry['source_evidence']??'')!==$pass)continue;
                $id=(int)($entry['canonical_attachment_id']??0);
                $subject=self::candidate_subject_signal($tour,$entry,'gallery',$id);
                if($pass==='company_local_shared_subject_pool_review_only'
                   &&self::headline_photo_hint($id,$headline))$subject=['eligible'=>true];
                if($pass==='weighted_registered_context_review_only')
                    $subject=['eligible'=>true,'basis'=>'registered_media_source_review_only'];
                if(empty($subject['eligible'])){
                    $rejected['subject_unsubstantiated']++;continue;
                }
                $o=self::origin($id);
                if(empty($o['verified'])){$rejected['unverified']++;continue;}
                if(self::provenance($id)['status']==='synthetic_confirmed'){$rejected['synthetic']++;continue;}
                if(self::editorial_hold($id)['held']){$rejected['editorial_hold']=($rejected['editorial_hold']??0)+1;continue;}
                if(isset($gallery_sha[$o['origin_sha256']])){$rejected['exact_duplicate']++;continue;}
                $dh=self::dhash($id);$too_similar=false;
                if($dh!==null)foreach($gallery_dhash as $prev)if($prev!==null && self::distance($dh,$prev)<=3){$too_similar=true;break;}
                if($too_similar){$rejected['near_duplicate']++;continue;}
                $gallery_sha[$o['origin_sha256']]=true;$gallery_dhash[]=$dh;
                $gallery_proposal[]=['id'=>$id,'source'=>$pass,'needs_editorial_review'=>true];$accepted++;
            }
            $gallery_passes[]=['criterion_pass'=>$pass,'new_candidates'=>$accepted,'total_proposed'=>count($gallery_proposal)];
        }
        $proposed_by_field=[];
        foreach($selected as $index=>$candidate)if(isset($slot_fields[$index]))$proposed_by_field[$slot_fields[$index]]=$candidate;
        return apply_filters('mad4b/tour_photo_quality/selection_plan',['contract'=>self::CONTRACT,'post_id'=>$tour,'mode'=>'proposal_only_no_automatic_writes',
            'hero_ready'=>count($selected)===$slot_total,'hero_target_slots'=>$slot_fields,
            'headline_profile'=>$headline['profile'],'headline_min_subject_slots'=>$headline['min_hero'],
            'shared_company_local_candidates'=>count($shared),
            'weighted_coverage_policy'=>$weighted['context']['policy'],
            'weighted_coverage_diagnostics'=>$weighted['diagnostics'],
            'cross_tour_image_reuse_allowed'=>true,
            'visual_and_rights_review_required'=>true,'ready_to_auto_apply'=>false,
            'withheld_subject_candidates'=>count($held_subject),
            'withheld_subject_candidate_examples'=>array_slice(array_values($held_subject),0,10),
            'withheld_severe_grid_crop_candidates'=>count($held_crop),
            'withheld_severe_grid_crop_examples'=>array_slice(array_values($held_crop),0,10),
            'grid_crop_budget_uses_conservative_landscape_lower_bound'=>true,
            'distinct_photo_origins'=>count($origins),'passes'=>$passes,
            'proposed_hero_slots'=>$selected,'proposed_hero_by_field'=>$proposed_by_field,'proposed_gallery_count'=>count($gallery_proposal),
            'proposed_gallery_items'=>$gallery_proposal,'gallery_passes'=>$gallery_passes,
            'gallery_growth_budget'=>$maxAdd,'gallery_growth_target'=>$galleryTarget,
            'registered_story_chapters'=>$chapters,
            'gallery_rejected'=>$rejected,'gallery_existing_images_not_deleted'=>true,
            'current_bulk_gallery_held_for_review'=>$bulk['untrusted'],
            'bulk_gallery_evidence'=>$bulk,
            'gallery_proposal_excludes_unreviewed_shared_set'=>$bulk['untrusted'],
            'gallery_policy'=>'Same original photo may appear in several tours when their itineraries overlap; inside one tour, unique originals and no confirmed synthetic media',
            'pending'=>'visual subject accuracy, licensing and source provenance editorial review'],$tour);
    }
    public static function state_sha(int $tour):string{
        $keys=array_merge(['gallery'],self::hero_fields($tour),['_thumbnail_id','_mad4b_media_focal_points_v1','mad4b_media_frame_focus_v2','_mad4b_media_subject_evidence_v1','_mad4b_tour_media_association_v1']);
        $parts=[];foreach($keys as $key){
            $v=get_post_meta($tour,$key,true);
            $parts[$key]=is_scalar($v)?(string)$v:wp_json_encode($v,JSON_UNESCAPED_SLASHES);
        }
        return hash('sha256',wp_json_encode($parts,JSON_UNESCAPED_SLASHES));
    }
    public static function issues($issues,$policy){
        if(!is_array($issues))return $issues;
        $state=get_option(self::OPTION,[]);
        $allowed=['media_gallery_mass_reuse_requires_reselection'=>'high',
                  'media_synthetic_source_prohibited'=>'high',
            'media_hero_original_duplicate'=>'high','media_gallery_original_duplicate'=>'high',
            'media_hero_slot_empty'=>'high','media_hero_source_unresolved'=>'high',
            'media_gallery_unresolved'=>'high','media_featured_original_unresolved'=>'high',
            'media_gallery_original_unverified'=>'high',
            'media_gallery_missing'=>'medium','media_near_duplicate_review'=>'medium',
            'media_hero_focal_review_pending'=>'medium',
            'media_hero_crop_geometry_review_pending'=>'medium',
            'media_hero_subject_review_pending'=>'medium',
            'media_visual_editorial_hold'=>'medium',
            'media_rate_property_photo_coverage_gap'=>'medium',
            'media_story_coverage_gap'=>'medium',
            'media_itinerary_geography_conflict'=>'high',
            'media_origin_provenance_unverified'=>'low',
                'media_viewpoint_grid_portrait_cover_risk'=>'medium',
                'media_viewpoint_museum_exterior_overview_review'=>'medium',
                'media_viewpoint_museum_interior_scene_repeat'=>'medium'];
        foreach(($state['tours']??[]) as $tour=>$rows){
            $tour=(int)$tour;
            if(!$tour||get_post_type($tour)!=='tours-and-activities'||get_post_status($tour)!=='publish')continue;
            $codes=[];
            foreach($rows as $row){
                $code=(string)($row['code']??'');
                if(!isset($allowed[$code]))continue;
                if(!isset($codes[$code]))$codes[$code]=0;
                $codes[$code]++;
            }
            foreach($codes as $code=>$events){
                if(in_array($code,['media_itinerary_geography_conflict','media_story_coverage_gap','media_gallery_mass_reuse_requires_reselection'],true)){
                    $default=(string)apply_filters('wpml_default_language','en');
                    $canonical=(int)apply_filters('wpml_object_id',$tour,'tours-and-activities',false,$default);
                    if($canonical>0&&$canonical!==$tour)continue;
                }
                $severity=$allowed[$code];
                $gaps=[];
                if($code==='media_story_coverage_gap')foreach($rows as $r){
                    if(($r['code']??'')===$code)$gaps[]=sanitize_text_field((string)($r['destination']??''));
                }
                $label=$gaps?'Tour photo coverage missing for '.implode(', ',array_slice($gaps,0,6)):
                    'Tour media: '.str_replace('_',' ',$code).' ('.(int)$events.' occurrence(s))';
                $issues[]=['source'=>'mad4b-tour-photo-quality','code'=>$code,
                    'id'=>hash('sha256',get_current_blog_id().'|tours-and-activities|'.$tour.'|'.$code),
                    'object_id'=>$tour,'post_type'=>'tours-and-activities','severity'=>$severity,
                    'label'=>mb_substr($label,0,230),
                    'source_evidence'=>['contract'=>self::CONTRACT,'post_id'=>$tour,
                        'site_uuid'=>class_exists('MAD4B_SCP_Site_Profile',false)
                            ?(string)MAD4B_SCP_Site_Profile::site_uuid():'',
                        'state_sha256'=>self::state_sha($tour),'events_count'=>(int)$events]];
            }
        }
        return $issues;
    }
    /** Live, demand-driven review backlog. One verified ORIGINAL per unit of work,
     * regardless of how many tours reuse it. The hero weight is derived from
     * dynamically discovered Elementor/JetEngine fields, not a static slot map.
     * TourRate property options remain options, never guaranteed vessels.
     * This does not classify, publish, download or authorize any image.
     */
    public static function review_queue():array {
        $ids=get_posts(['post_type'=>'tours-and-activities','post_status'=>'publish',
            'posts_per_page'=>1001,'fields'=>'ids','orderby'=>'ID','order'=>'ASC']);
        if(!is_array($ids)||count($ids)>1000)return ['status'=>'bounded_catalog_overflow',
            'complete'=>false,'pages_seen'=>is_array($ids)?count($ids):0];
        $originals=[];$properties=[];$frameCrop=[];$unmapped=0;$usageCount=0;$affected=[];
        foreach($ids as $rawId){
            $tour=(int)$rawId;$audit=self::audit($tour);
            $slots=self::hero_fields($tour);$slotRank=array_flip($slots);
            $rateLinks=null;
            foreach((array)($audit['issues']??[]) as $issue){
                $code=(string)($issue['code']??'');
                if($code==='media_origin_provenance_unverified'){
                    $field=(string)($issue['field']??'');
                    if(!isset($slotRank[$field])){$unmapped++;continue;}
                    $source=MAD4B_Gallery_Resolution_Engine_V1::inspect(
                        (string)get_post_meta($tour,$field,true),$tour);
                    $id=(int)($source['render_value']??0);
                    $origin=$id?self::origin($id):[];
                    $root=(int)($origin['origin_attachment_id']??0);
                    $sha=(string)($origin['origin_sha256']??'');
                    if(empty($origin['verified'])||!preg_match('/^[a-f0-9]{64}$/D',$sha)){
                        $unmapped++;continue;
                    }
                    if(!isset($originals[$sha])){
                        $m=wp_get_attachment_metadata($root);
                        $originals[$sha]=['origin_sha256'=>$sha,
                          'root_attachment_id'=>$root,'root_attachment_title'=>sanitize_text_field((string)get_the_title($root)),
                          'representative_attachment_id'=>$id,
                          'photo_uses'=>0,'affected_tour_ids'=>[],'source_count'=>1,
                          'slot_priority_score'=>0,
                          'exif_camera_claim_present'=>!empty($m['image_meta']['camera']),
                          'verified_import_source_declared'=>
                            get_post_meta($root,'_mad4b_verified_source_sha256',true)!=='',
                          'photographic_origin_status'=>'origin_unverified',
                          'decision'=>'needs_original_photographic_authenticity_evidence',
                          'neither_ai_nor_photo_assumed'=>true,
                          'media_rights_not_automatically_granted'=>true];
                    }
                    $originals[$sha]['photo_uses']++;
                    $originals[$sha]['slot_priority_score']+=max(1,count($slots)-(int)$slotRank[$field]);
                    if(!in_array($tour,$originals[$sha]['affected_tour_ids'],true)
                       &&count($originals[$sha]['affected_tour_ids'])<60)
                        $originals[$sha]['affected_tour_ids'][]=$tour;
                    $usageCount++;$affected[$tour]=true;
                }elseif($code==='media_hero_crop_geometry_review_pending'){
                    $field=(string)($issue['field']??'');
                    if(!isset($slotRank[$field]))continue;
                    $current=(int)($issue['attachment_id']??0);
                    $o=$current?self::origin($current):[];
                    $root=(int)($o['origin_attachment_id']??0);
                    $sha=(string)($o['origin_sha256']??'');
                    if(empty($o['verified'])||!preg_match('/^[a-f0-9]{64}$/D',$sha))continue;
                    $m=wp_get_attachment_metadata($root);
                    $rootWidth=(int)($m['width']??0);$rootHeight=(int)($m['height']??0);
                    $rootRatio=$rootHeight>0?$rootWidth/$rootHeight:0.0;
                    // The original source was already reviewed for this tour:
                    // do not mistake an unrelated attachment with a matching
                    // subject label for a reviewed original. WPML translations
                    // derive authority from the original canonical family.
                    $default=(string)apply_filters('wpml_default_language','en');
                    $canonical=(int)apply_filters('wpml_object_id',$tour,
                        'tours-and-activities',false,$default);
                    $signed=$canonical>0?get_post_meta($canonical,
                        '_mad4b_tour_media_association_v1',true):null;
                    $signedRoot=false;
                    if(is_array($signed)
                       &&($signed['contract']??'')==='mad4b.tour-media-association.v1'
                       &&(int)($signed['canonical_post_id']??0)===$canonical
                       &&in_array($tour,array_map('intval',(array)($signed['wpml_family']??[])),true)){
                        foreach(array_slice((array)($signed['items']??[]),0,100) as $source){
                            if((int)($source['attachment_id']??0)===$root
                               &&hash_equals($sha,(string)($source['sha256']??''))){
                                $signedRoot=true;break;
                            }
                        }
                    }
                    $rootUrl=wp_get_attachment_url($root);
                    $imageFile=get_attached_file($root);
                    $exactFile=$signedRoot && is_string($imageFile)&&is_file($imageFile)
                        &&hash_equals($sha,(string)hash_file('sha256',$imageFile));
                    $eligible=$exactFile && self::same_original_grid_reframe_geometry($rootWidth,$rootHeight)
                        &&is_string($rootUrl)
                        &&$rootUrl!==get_post_meta($tour,$field,true)
                        &&attachment_url_to_postid($rootUrl)===$root;
                    $frameCrop[]=[
                        'post_id'=>$tour,'canonical_post_id'=>$canonical,
                        'slot'=>$field,'current_attachment_id'=>$current,
                        'root_attachment_id'=>$root,'origin_sha256'=>$sha,
                        'minimum_crop_fraction'=>$issue['minimum_estimated_crop_fraction']??null,
                        'reference_frame_aspect_ratio'=>$issue['reference_frame_aspect_ratio']??null,
                        'reference_frame_basis'=>$issue['frame_ratio_basis']??'unknown',
                        'root_dimensions'=>[$rootWidth,$rootHeight],
                        'current_focal_meta_exists'=>(bool)($issue['current_focal_meta_exists']??false),
                        'source_is_in_signed_tour_review'=>$signedRoot,
                        'original_hash_matches_disk'=>(bool)$exactFile,
                        'safe_same_original_reframe_candidate'=>$eligible,
                        'review_state'=>$eligible?'review_same_original_reframe':
                            'needs_editorially_reviewed_landscape_source',
                        'next_step'=>$eligible?'preview_current_and_original_in_actual_frame':
                            'obtain_subject_accurate_rights_verified_landscape_image',
                        'copyright_or_photography_authenticity_auto_approved'=>false,
                        'auto_media_write_authorized'=>false,
                    ];
                }elseif($code==='media_rate_property_photo_coverage_gap'){
                    if($rateLinks===null){$rateLinks=function_exists('shared_product_assignment_legacy_links')
                        ?shared_product_assignment_legacy_links($tour):[];
                        if(is_wp_error($rateLinks)||!is_array($rateLinks))$rateLinks=[];}
                    foreach(array_slice(array_map('intval',(array)($issue['property_ids']??[])),0,80) as $pid){
                        if($pid<1||get_post_type($pid)!=='properties'||get_post_status($pid)!=='publish')continue;
                        if(!isset($properties[$pid]))$properties[$pid]=[
                          'property_id'=>$pid,'property_title'=>get_the_title($pid),
                          'affected_tour_ids'=>[],'source_tour_rate_ids'=>[],
                          'source_contract'=>'native_tour_rate_property_assignment',
                          'missing_supplier_specific_photos'=>true,
                          'photo_assignment_not_guaranteed_stay'=>true,
                          'no_unreviewed_generic_photo_substitution'=>true];
                        if(!in_array($tour,$properties[$pid]['affected_tour_ids'],true))
                            $properties[$pid]['affected_tour_ids'][]=$tour;
                        foreach(array_slice((array)($rateLinks[$pid]['source_rate_ids']??[]),0,80) as $r){
                            $rid=(int)$r;
                            if($rid>0&&!in_array($rid,$properties[$pid]['source_tour_rate_ids'],true))
                                $properties[$pid]['source_tour_rate_ids'][]=$rid;
                        }
                    }
                }
            }
        }
        $sourceRows=array_values($originals);usort($sourceRows,static function($a,$b){
            $x=$a['photo_uses']*100+$a['slot_priority_score'];
            $y=$b['photo_uses']*100+$b['slot_priority_score'];
            return $y<=>$x ?: $a['root_attachment_id']<=>$b['root_attachment_id'];
        });
        $propertyRows=array_values($properties);usort($propertyRows,static function($a,$b){
            $c=count($b['affected_tour_ids'])<=>count($a['affected_tour_ids']);
            return $c ?: ($a['property_id']<=>$b['property_id']);
        });
        return ['contract'=>'mad4b.tour-photo-quality-dynamic-review-queue.v1',
            'status'=>'complete','site'=>home_url(),'created_utc'=>gmdate('c'),
            'published_tours_scanned'=>count($ids),'unverified_photo_use_count'=>$usageCount,
            'unique_original_photos_requiring_evidence'=>count($sourceRows),
            'tour_pages_affected'=>count($affected),'unmapped_hero_uses'=>$unmapped,
            'properties_requiring_supplier_photos'=>count($propertyRows),
            'original_source_review'=>$sourceRows,'tour_rate_property_photo_review'=>$propertyRows,
            'frame_crop_review'=>$frameCrop,
            'frame_crop_review_count'=>count($frameCrop),
            'frame_crop_requires_new_landscape_source_count'=>count(array_filter($frameCrop,
                static fn($v)=>!$v['safe_same_original_reframe_candidate'])),
            'frame_crop_same_original_candidates_count'=>count(array_filter($frameCrop,
                static fn($v)=>$v['safe_same_original_reframe_candidate'])),
            'cross_tour_image_reuse_not_a_violation'=>true,
            'dynamic_hero_field_priority'=>true,'dynamic_tour_rate_relations'=>true,
            'executed_writes'=>false,'auto_authenticity_or_rights_grants'=>false];
    }
    public static function scan_batch(int $limit=12):array {
        global $wpdb;
        $cursor=(int)get_option(self::CURSOR,0);
        $limit=max(1,min(50,$limit));
        $ids=$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_status=%s AND ID>%d ORDER BY ID LIMIT %d",
            'tours-and-activities','publish',$cursor,$limit));
        $state=get_option(self::OPTION,['tours'=>[]]);$state['tours']=$state['tours']??[];
        $run=[];
        foreach($ids as $id){$id=(int)$id;$r=self::audit($id);$state['tours'][$id]=$r['issues']??[];
            $run[]=['id'=>$id,'issues'=>count($state['tours'][$id])];}
        $next=$ids?(int)end($ids):0;
        update_option(self::CURSOR,$next,false);
        if(!$ids){$state['last_full_scan_utc']=gmdate('c');}
        $state['last_batch_utc']=gmdate('c');update_option(self::OPTION,$state,false);
        return ['contract'=>self::CONTRACT,'scanned'=>count($run),'cursor'=>$next,'cycle_complete'=>!$ids,'tours'=>$run];
    }
    public static function scan_one(int $id):array {
        if(get_post_type($id)!=='tours-and-activities')return self::fail('tour_missing');
        $r=self::audit($id);
        $state=get_option(self::OPTION,['tours'=>[]]);$state['tours']=$state['tours']??[];
        $state['tours'][$id]=$r['issues']??[];
        $state['last_single_utc']=gmdate('c');update_option(self::OPTION,$state,false);
        return ['contract'=>self::CONTRACT,'post_id'=>$id,'issues'=>count($state['tours'][$id]),
            'alerts_persisted'=>true,'status'=>'scanned'];
    }
    public static function scheduled_one(int $id):void {self::scan_one($id);}
    /** Workload-based schedule, with company-controlled CPU ceiling and freshness target.
     * A larger catalog changes cadence and batch size; no fixed tour list.
     */
    public static function scan_policy():array {
        $count=(int)(wp_count_posts('tours-and-activities')->publish??0);
        $hours=apply_filters('mad4b/tour_photo_quality/target_full_scan_hours',24,$count);
        $hours=is_numeric($hours)?max(6,min(168,(int)$hours)):24;
        $cap=apply_filters('mad4b/tour_photo_quality/max_scan_batch',50,$count);
        $cap=is_numeric($cap)?max(1,min(50,(int)$cap)):50;
        $window=$hours*HOUR_IN_SECONDS;
        $interval=300;
        foreach([3600,900,300] as $candidate){
            if((int)ceil($count*$candidate/max(1,$window))<=$cap){$interval=$candidate;break;}
        }
        $runs=max(1,(int)floor($window/$interval));
        $required=max(1,(int)ceil($count/$runs));
        $batch=min($cap,max(1,$required));
        $batch=max(1,min($cap,max(min(12,$cap),$batch)));
        $capacity=$batch*$runs;
        $schedule=match($interval){3600=>'mad4b_photo_quality_hourly',900=>'mad4b_photo_quality_quarter_hour',default=>'mad4b_photo_quality_five_minutes'};
        return ['contract'=>self::CONTRACT,'published_tours'=>$count,'target_full_scan_hours'=>$hours,
            'cron_schedule'=>$schedule,'cron_interval_seconds'=>$interval,'batch_limit'=>$batch,
            'capacity_per_target_window'=>$capacity,'capacity_sufficient'=>$capacity>=$count,
            'estimated_full_scan_hours'=>$count?round(ceil($count/$batch)*$interval/3600,2):0,
            'company_policy_filters_supported'=>true];
    }
    public static function ensure_scan_schedule():void {
        $policy=self::scan_policy();$hook='mad4b_tour_photo_quality_scan';
        $existing=wp_get_schedule($hook);
        if($existing===$policy['cron_schedule'])return;
        if($existing)wp_clear_scheduled_hook($hook);
        wp_schedule_event(time()+180,$policy['cron_schedule'],$hook);
    }
    public static function scheduled():void {
        $policy=self::scan_policy();
        $requested=apply_filters('mad4b/tour_photo_quality/scan_batch_limit',
            $policy['batch_limit'],$policy['published_tours']);
        $limit=is_numeric($requested)?(int)$requested:$policy['batch_limit'];
        $limit=max(1,min(50,$policy['batch_limit'],$limit));
        self::scan_batch($limit);
    }
    public static function cli($args,$assoc):void{
        $mode=(string)($args[0]??'audit');$id=absint($args[1]??0);
        switch($mode){
            case 'audit':$r=self::audit($id);break;
            case 'plan':$r=self::selection_loop($id);break;
            case 'scan':$r=self::scan_batch(absint($assoc['limit']??12));break;
            case 'scan-one':$r=self::scan_one($id);break;
            case 'policy':$r=self::scan_policy();break;
            case 'coverage-page':$r=self::coverage_page($id,absint($assoc['page']??0));break;
            case 'review-queue':$r=self::review_queue();break;
            default:WP_CLI::error('Usage: wp mad4b tour-photo-quality audit|plan <post-id> OR scan [--limit=12]');
        }
        WP_CLI::line(wp_json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    }
}
add_filter('mad4b/content_readiness/external_issues',[MAD4B_Tour_Photo_Quality_V1::class,'issues'],26,2);
add_action('mad4b_tour_photo_quality_scan',[MAD4B_Tour_Photo_Quality_V1::class,'scheduled']);
add_action('mad4b_tour_photo_quality_scan_one',[MAD4B_Tour_Photo_Quality_V1::class,'scheduled_one']);
$mad4b_photo_quality_meta_changed=static function($meta_id,$post_id,$key,$value){
    if(!in_array((string)$key,['gallery','image_vertical','_thumbnail_id',
        '_mad4b_media_focal_points_v1','mad4b_media_frame_focus_v2','_mad4b_media_subject_evidence_v1',
        '_mad4b_tour_media_association_v1'],true)
        && !preg_match('/^grid_image_[a-zA-Z0-9_]{1,80}$/D',(string)$key))return;
    if(get_post_type((int)$post_id)!=='tours-and-activities')return;
    if(!wp_next_scheduled('mad4b_tour_photo_quality_scan_one',[(int)$post_id]))
        wp_schedule_single_event(time()+20,'mad4b_tour_photo_quality_scan_one',[(int)$post_id]);
};
foreach(['added_post_meta','updated_post_meta','deleted_post_meta'] as $meta_hook)
    add_action($meta_hook,$mad4b_photo_quality_meta_changed,30,4);
add_filter('cron_schedules',static function($schedules){
    $schedules['mad4b_photo_quality_hourly']=['interval'=>3600,'display'=>'MAD4B Photo Quality hourly'];
    $schedules['mad4b_photo_quality_quarter_hour']=['interval'=>900,'display'=>'MAD4B Photo Quality 15 min'];
    $schedules['mad4b_photo_quality_five_minutes']=['interval'=>300,'display'=>'MAD4B Photo Quality 5 min'];
    return $schedules;
});
add_action('admin_init',[MAD4B_Tour_Photo_Quality_V1::class,'ensure_scan_schedule']);
add_action('transition_post_status',static function($new,$old,$post){
    if(!$post instanceof WP_Post || $post->post_type!=='tours-and-activities'
        || $new===$old || ($old!=='publish'&&$new!=='publish'))return;
    MAD4B_Tour_Photo_Quality_V1::ensure_scan_schedule();
    if($new==='publish'){
        if(!wp_next_scheduled('mad4b_tour_photo_quality_scan_one',[(int)$post->ID]))
            wp_schedule_single_event(time()+20,'mad4b_tour_photo_quality_scan_one',[(int)$post->ID]);
    }else{
        // Remove retired tours from the live alert index, without deleting
        // their media or editorial evidence.
        $state=get_option(MAD4B_Tour_Photo_Quality_V1::OPTION,[]);
        if(is_array($state)&&isset($state['tours'][$post->ID])){
            unset($state['tours'][$post->ID]);
            update_option(MAD4B_Tour_Photo_Quality_V1::OPTION,$state,false);
        }
    }
},30,3);

if(defined('WP_CLI')&&WP_CLI)WP_CLI::add_command('mad4b tour-photo-quality',[MAD4B_Tour_Photo_Quality_V1::class,'cli']);
