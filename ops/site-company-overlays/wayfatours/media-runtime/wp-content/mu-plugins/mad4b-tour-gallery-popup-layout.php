<?php
/**
 * Plugin Name: MAD4B Tour Gallery Popup Layout
 * Description: Responsive, scroll-safe Elementor/UAEL tour gallery dialog; does not alter gallery data or other popups.
 * Version: 1.4.1
 */
defined('ABSPATH') || exit;
add_action('wp_enqueue_scripts', static function (): void {
    if (!is_singular('tours-and-activities')) return;
    wp_register_style('mad4b-tour-gallery-popup-layout', false, [], '1.0.0');
    wp_enqueue_style('mad4b-tour-gallery-popup-layout');
    wp_add_inline_style('mad4b-tour-gallery-popup-layout', <<<'CSS'
/* Target only Elementor popups containing the UAEL tour photo gallery. */
body.single-tours-and-activities .elementor-popup-modal:has(.elementor-widget-uael-image-gallery) .dialog-widget-content {
    width: min(1100px, calc(100vw - 32px)) !important;
    max-width: calc(100vw - 32px) !important;
    max-height: calc(100dvh - 32px) !important;
    border-radius: 16px !important;
    overflow: hidden !important;
    box-sizing: border-box;
}
body.single-tours-and-activities .elementor-popup-modal:has(.elementor-widget-uael-image-gallery) .dialog-message {
    width: 100% !important;
    max-height: calc(100dvh - 40px) !important;
    min-height: 0 !important;
    overflow-x: hidden !important;
    overflow-y: auto !important;
    padding: 18px 20px !important;
    box-sizing: border-box !important;
    overscroll-behavior: contain;
}
body.single-tours-and-activities .elementor-popup-modal:has(.elementor-widget-uael-image-gallery) .dialog-close-button {
    position: absolute !important;
    z-index: 100 !important;
    top: 10px !important;
    right: 12px !important;
    margin: 0 !important;
    background: rgba(255,255,255,.95) !important;
    border-radius: 50%;
    padding: 8px;
}
body.single-tours-and-activities .elementor-popup-modal:has(.elementor-widget-uael-image-gallery) .elementor-widget-uael-image-gallery,
body.single-tours-and-activities .elementor-popup-modal:has(.elementor-widget-uael-image-gallery) .uael-img-gallery-wrap {
    width: 100% !important;
    max-width: 100% !important;
}
@media (max-width: 767px) {
    body.single-tours-and-activities .elementor-popup-modal:has(.elementor-widget-uael-image-gallery) .dialog-widget-content {
        width: calc(100vw - 16px) !important;
        max-width: calc(100vw - 16px) !important;
        max-height: calc(100dvh - 16px) !important;
    }
    body.single-tours-and-activities .elementor-popup-modal:has(.elementor-widget-uael-image-gallery) .dialog-message {
        padding: 14px 10px !important;
        max-height: calc(100dvh - 24px) !important;
    }
}
CSS
    );
}, 55);

add_action('wp_enqueue_scripts', static function (): void {
    if (!is_singular('tours-and-activities')) return;
    // Keep the Elementor button as the single popup authority. This asset
    // only makes the surrounding background-image photo frames actionable.
    $relative='mad4b-tour-gallery-popup-layout/frames.js';
    $path=__DIR__.'/'.$relative;
    if (!is_readable($path) || is_link($path)) return;
    wp_enqueue_script(
        'mad4b-tour-gallery-frame-opener',
        plugins_url($relative, __FILE__),
        ['elementor-frontend'],
        (string)filemtime($path),
        true
    );
    // Position and cropping are owned solely by Elementor native Background
    // controls on the Tour Hero container. No post-meta-to-CSS override here.
    wp_add_inline_style('mad4b-tour-gallery-popup-layout',
        'body.single-tours-and-activities .mad4b-gallery-frame-opener {'.
        'cursor:pointer;}'.
        'body.single-tours-and-activities .mad4b-gallery-frame-opener:focus-visible {'.
        'outline:3px solid var(--e-global-color-accent,#b88942);'.
        'outline-offset:-4px;}'
    );
}, 58);
/**
 * JetEngine Photo Frame Focus → Elementor's native dynamic Style controls.
 * No CSS/JS substitution: Elementor's own selector compiler owns position/fit.
 * Bound to the actual original image SHA; changing the image invalidates focus.
 */
final class MAD4B_JetEngine_Elementor_Frame_Focus_V2 {
    const META='mad4b_media_frame_focus_v2';
    private static array $sourceMemo=[];
    private static array $resolvedMemo=[];

    public static function slots():array {
        if(class_exists('MAD4B_Tour_Layout_Control_V1',false))
            return MAD4B_Tour_Layout_Control_V1::photo_slots();
        return [];
    }
    private static function current_tour():int {
        $id=(int)get_queried_object_id();
        if($id>0&&get_post_type($id)==='tours-and-activities')return $id;
        $post=get_post();
        return $post && $post->post_type==='tours-and-activities'?(int)$post->ID:0;
    }
    private static function original_sha(int $tour,string $slot):string {
        $cacheKey=$tour.':'.$slot;
        if(array_key_exists($cacheKey,self::$sourceMemo))return self::$sourceMemo[$cacheKey];
        $url=(string)get_post_meta($tour,$slot,true);
        if($url===''||!class_exists('MAD4B_Gallery_Resolution_Engine_V1',false)
            ||!class_exists('MAD4B_Media_Visual_Lineage_V1',false))return self::$sourceMemo[$cacheKey]='';
        $p=MAD4B_Gallery_Resolution_Engine_V1::inspect($url,$tour);
        $id=(int)($p['render_value']??0);
        if($id<1)return self::$sourceMemo[$cacheKey]='';
        $o=MAD4B_Media_Visual_Lineage_V1::origin($id);
        return self::$sourceMemo[$cacheKey]=!empty($o['verified'])?(string)($o['origin_sha256']??''):'';
    }
    private static function rows(int $tour):array {
        $rows=get_post_meta($tour,self::META,true);
        if(!is_array($rows)||!$rows){
            $default=(string)apply_filters('wpml_default_language','');
            $source=$default!==''?(int)apply_filters('wpml_object_id',$tour,'tours-and-activities',false,$default):0;
            if($source>0&&$source!==$tour&&get_post_type($source)==='tours-and-activities')
                $rows=get_post_meta($source,self::META,true);
        }
        return is_array($rows)?array_slice($rows,0,80):[];
    }
    private static function frame(int $tour,string $slot):array {
        $key=$tour.':'.$slot;
        if(isset(self::$resolvedMemo[$key]))return self::$resolvedMemo[$key];
        $fallback=['position'=>'','fit'=>'','source'=>'elementor_default'];
        if(!$tour||!in_array($slot,self::slots(),true))return self::$resolvedMemo[$key]=$fallback;
        $sha=self::original_sha($tour,$slot);
        if(!preg_match('/^[a-f0-9]{64}$/D',$sha))return self::$resolvedMemo[$key]=$fallback;
        foreach(self::rows($tour) as $row){
            if(!is_array($row)||($row['slot']??'')!==$slot)continue;
            $rowSha=(string)($row['source_sha256']??'');
            if(!preg_match('/^[a-f0-9]{64}$/D',$rowSha)||!hash_equals($sha,$rowSha))continue;
            $x=$row['focus_x']??null;$y=$row['focus_y']??null;
            if(!is_numeric($x)||!is_numeric($y)||$x<0||$x>100||$y<0||$y>100)continue;
            $fit=in_array(($row['fit']??''),['cover','contain'],true)?$row['fit']:'cover';
            $out=['position'=>(float)$x.'% '.(float)$y.'%','fit'=>$fit,
                'source'=>'jetengine_verified_frame_record','source_sha256'=>$sha];
            return self::$resolvedMemo[$key]=$out;
        }
        return self::$resolvedMemo[$key]=$fallback;
    }
    public static function value(string $slot,string $property):string {
        $tour=self::current_tour();
        if(!in_array($property,['position','fit'],true))return '';
        return self::frame($tour,$slot)[$property]??'';
    }
    public static function stamp($meta_id,$post_id,$key,$value):void {
        static $updating=false;
        if($updating||$key!==self::META||get_post_type((int)$post_id)!=='tours-and-activities'||!is_array($value))return;
        $known=array_fill_keys(self::slots(),true);
        if(!$known)return;
        $clean=[];$seen=[];
        foreach(array_slice($value,0,80) as $row){
            if(!is_array($row))continue;
            $slot=(string)($row['slot']??'');
            if(!isset($known[$slot])||isset($seen[$slot]))continue;
            $x=$row['focus_x']??50;$y=$row['focus_y']??50;
            if(!is_numeric($x)||!is_numeric($y)||$x<0||$x>100||$y<0||$y>100)continue;
            $sha=self::original_sha((int)$post_id,$slot);
            $old=(string)($row['source_sha256']??'');
            $validOld=(bool)preg_match('/^[a-f0-9]{64}$/D',$old);
            if(!preg_match('/^[a-f0-9]{64}$/D',$sha)&&!$validOld)continue;
            if($validOld&&!empty($row['rebind_on_save']))$old='';
            $sha=$old?:$sha;
            $seen[$slot]=true;
            $aspect=$row['frame_aspect_ratio']??null;
            // Optional width/height ratio is descriptive and MUST NOT be
            // coerced from empty text to zero. It cannot override crop policy.
            $aspect=is_numeric($aspect) && $aspect>=0.25 && $aspect<=5
                ?round((float)$aspect,3):null;
            $entry=['slot'=>$slot,'focus_x'=>min(100,max(0,(float)$x)),
                'focus_y'=>min(100,max(0,(float)$y)),
                'fit'=>in_array(($row['fit']??''),['cover','contain'],true)?$row['fit']:'cover',
                'source_sha256'=>$sha];
            if($aspect!==null)$entry['frame_aspect_ratio']=$aspect;
            $clean[]=$entry;
        }
        if($clean===$value)return;
        $updating=true;
        try{update_post_meta((int)$post_id,self::META,$clean);}
        finally{$updating=false;}
    }
    public static function invalidate_after_change($meta_id,$post_id,$key,$value):void {
        static $seen=[];
        $id=(int)$post_id;
        if($key!==self::META||$id<1||get_post_type($id)!=='tours-and-activities'||isset($seen[$id]))return;
        $seen[$id]=true;
        $ids=[$id];
        $trid=apply_filters('wpml_element_trid',null,$id,'post_tours-and-activities');
        if($trid){
            $family=apply_filters('wpml_get_element_translations',null,$trid,'post_tours-and-activities');
            foreach(array_slice((array)$family,0,12) as $entry){
                $target=(int)($entry->element_id??0);
                if($target>0&&get_post_type($target)==='tours-and-activities')$ids[]=$target;
            }
        }
        foreach(array_unique($ids) as $tour){
            wp_cache_delete($tour,'post_meta');
            clean_post_cache($tour);
            if(class_exists('MAD4B_Page_Dependency_Cache',false))
                MAD4B_Page_Dependency_Cache::invalidate(['post:'.$tour],'photo_focus_changed');
        }
    }
    public static function add_elementor_controls($element):void {
        if(!is_object($element)||!method_exists($element,'start_controls_section')||!class_exists('Elementor\Controls_Manager'))return;
        $element->start_controls_section('mad4b_tour_frame_focus_style',[
            'label'=>'MAD4B · JetEngine Photo Focus','tab'=>\Elementor\Controls_Manager::TAB_STYLE]);
        $element->add_control('mad4b_photo_focus_position',[
            'label'=>'Native image position · dynamic JetEngine','type'=>\Elementor\Controls_Manager::TEXT,
            'default'=>'','dynamic'=>['active'=>true],
            // Elementor's native Background selector includes :not(...),
            // which is one specificity level above a plain {{WRAPPER}} tag.
            // Match its exact cascade instead of runtime CSS overrides.
            'selectors'=>[
                '{{WRAPPER}}:not(.elementor-motion-effects-element-type-background)'=>'background-position: {{VALUE}};',
                '{{WRAPPER}} > .elementor-motion-effects-container > .elementor-motion-effects-layer'=>'background-position: {{VALUE}};',
            ],
            'description'=>'Bind MAD4B Photo Frame Focus dynamic tag to this image Container. No runtime CSS/JS override.']);
        $element->add_control('mad4b_photo_focus_fit',[
            'label'=>'Native image fit · dynamic JetEngine','type'=>\Elementor\Controls_Manager::TEXT,
            'default'=>'','dynamic'=>['active'=>true],
            'selectors'=>[
                '{{WRAPPER}}:not(.elementor-motion-effects-element-type-background)'=>'background-size: {{VALUE}};',
                '{{WRAPPER}} > .elementor-motion-effects-container > .elementor-motion-effects-layer'=>'background-size: {{VALUE}};',
            ],
            'description'=>'Optional cover / contain per image and tour.']);
        $element->end_controls_section();
    }
    public static function register_tag($manager):void {
        if(!class_exists('Elementor\Core\DynamicTags\Tag'))return;
        require_once __DIR__.'/mad4b-tour-gallery-popup-layout/frame-focus-tag.php';
        $manager->register_group('mad4b-jetengine-media',['title'=>'MAD4B · JetEngine Media']);
        $manager->register(new MAD4B_JetEngine_Photo_Focus_Dynamic_Tag());
    }
}
add_action('elementor/dynamic_tags/register',[MAD4B_JetEngine_Elementor_Frame_Focus_V2::class,'register_tag'],30);
add_action('elementor/element/container/section_background/after_section_end',
    [MAD4B_JetEngine_Elementor_Frame_Focus_V2::class,'add_elementor_controls'],60,1);
foreach(['added_post_meta','updated_post_meta'] as $event)
    add_action($event,[MAD4B_JetEngine_Elementor_Frame_Focus_V2::class,'stamp'],90,4);
foreach(['added_post_meta','updated_post_meta','deleted_post_meta'] as $event)
    add_action($event,[MAD4B_JetEngine_Elementor_Frame_Focus_V2::class,'invalidate_after_change'],120,4);
