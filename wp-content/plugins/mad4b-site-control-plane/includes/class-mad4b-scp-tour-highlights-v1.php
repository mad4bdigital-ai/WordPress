<?php
/**
 * Tour Highlights v1 — small native JetEngine consumer and editor-time suggestions.
 * Deliberately no AI/network calls, no runtime term discovery and no cross-locale copying.
 */
defined('ABSPATH') || exit;

if (!class_exists('MAD4B_Tour_Highlights_V1',false)) {
final class MAD4B_Tour_Highlights_V1 {
    private static bool $syncing=false;
    private static array $relatedGroupStack=[];

    public static function register():void {
        add_filter('jet-engine/listings/allowed-callbacks',[__CLASS__,'callbacks']);
        add_filter('elementor/frontend/container/should_render',[__CLASS__,'section_visible'],10,2);
        add_action('elementor/frontend/container/before_render',[__CLASS__,'decorate_container'],10,1);
        // Track actual native JetEngine destination groups, not Elementor element IDs.
        add_action('elementor/frontend/container/before_render',[__CLASS__,'enter_native_group'],5,1);
        add_action('elementor/frontend/container/after_render',[__CLASS__,'leave_native_group'],5,1);
        add_action('elementor/frontend/widget/before_render',[__CLASS__,'localize_native_grid'],5,1);
        // JetEngine writes classic post meta on generic save_post priority 10.
        // The post-type-specific hook runs earlier; use generic save_post at 190.
        add_action('save_post',[__CLASS__,'on_save'],190,3);
        // REST field updates may arrive after save_post; finalize after REST insertion.
        add_action('rest_after_insert_tours-and-activities',[__CLASS__,'on_rest_save'],190,3);
    }

    public static function callbacks($callbacks) {
        if (!is_array($callbacks)) return $callbacks;
        $callbacks['tour_highlights_render_approved'] = 'Tour Highlights — verified and curated';
        return $callbacks;
    }

    private static function context_id():int {
        $id=0;
        if(function_exists('jet_engine') && isset(jet_engine()->listings->data)
          && method_exists(jet_engine()->listings->data,'get_current_object_id')) {
            $id=(int)jet_engine()->listings->data->get_current_object_id();
        }
        if (!$id || get_post_type($id)!=='tours-and-activities') $id=(int)get_queried_object_id();
        if (!$id || get_post_type($id)!=='tours-and-activities') $id=(int)get_the_ID();
        return $id>0 && get_post_type($id)==='tours-and-activities' ? $id:0;
    }

    public static function mode(int $post):string {
        $mode=(string)get_post_meta($post,'tour_highlights_mode',true);
        if(!in_array($mode,['assisted','auto','manual','hide','inherit'],true))$mode='inherit';
        if($mode==='inherit') {
            $lf=get_option('look-and-feel',[]);
            $mode=is_array($lf)?(string)($lf['tour_highlights_default_mode']??'manual'):'manual';
        }
        return in_array($mode,['assisted','auto','manual','hide'],true)?$mode:'manual';
    }

    public static function get_tour_id():int {
        return self::context_id();
    }

    public static function display_pattern(int $post):string {
        $v=(string)get_post_meta($post,'tour_highlights_display_pattern',true);
        if($v==='' || $v==='inherit'){
            $company=get_option('look-and-feel',[]);
            $v=is_array($company)?(string)($company['tour_highlights_default_pattern']??'explorer'):'explorer';
        }
        return in_array($v,['explorer','split','story'],true)?$v:'explorer';
    }

    public static function related_places(int $post):array {
        // Request-local cache only; every new request validates current taxonomy
        // and company activation policy instead of reusing stale front-end facts.
        static $cache=[];
        if(array_key_exists($post,$cache))return $cache[$post];
        $cache[$post]=[];
        if($post<=0 || get_post_type($post)!=='tours-and-activities'
            || !class_exists('MAD4B_Company_Term_Eligibility_V1',false))return [];
        $terms=wp_get_object_terms($post,'destinations',['fields'=>'all']);
        if(!is_array($terms)||is_wp_error($terms))return [];
        $eligibleTypes=['place_attraction','city','zone_area','state_governorate','country'];
        foreach($terms as $term) {
            if(!$term instanceof WP_Term)continue;
            $kind=(string)get_term_meta($term->term_id,'destination_type',true);
            if(!in_array($kind,$eligibleTypes,true))continue;
            $decision=MAD4B_Company_Term_Eligibility_V1::decide(
                $term,'tours-and-activities','destination_discovery',get_post_status($post)==='publish'
            );
            if(empty($decision['allowed']))continue;
            $url=get_term_link($term,'destinations');
            if(is_wp_error($url)||!is_string($url)||$url==='')continue;
            $cache[$post][]=['id'=>$term->term_id,'parent'=>(int)$term->parent,'label'=>$term->name,'url'=>$url,'kind'=>$kind];
        }
        return $cache[$post];
    }

    public static function has_highlights(int $post):bool {
        $mode=self::mode($post);
        if($mode==='hide')return false;
        if($mode==='auto')return (bool)self::approved_items($post);
        if($mode==='assisted' && self::approved_items($post))return true;
        return trim(wp_strip_all_tags((string)get_post_meta($post,'tour_highlights_content',true)))!=='';
    }

    private static function is_discovery_container($element):bool {
        if(!is_object($element) || !method_exists($element,'get_id'))return false;
        $css=method_exists($element,'get_settings')?(string)$element->get_settings('css_classes'):'';
        if(str_contains(' '.trim($css).' ',' tour-journey-discovery-editor '))return true;
        // Compatibility with already published Elementor documents.
        return $element->get_id()==='d90c3d1';
    }

    public static function decorate_container($element):void {
        if(!self::is_discovery_container($element))return;
        $post=self::context_id();
        if($post<=0)return;
        $element->add_render_attribute('_wrapper','class','tour-journey-discovery');
        $element->add_render_attribute('_wrapper','class','tour-journey--'.self::display_pattern($post));
        $element->add_render_attribute('_wrapper','data-tour-journey-mode',self::display_pattern($post));
        // Existing taxonomy is the sole source of the Split hierarchy.
        // The payload contains only eligible terms linked to this Tour.
        $placeTree=array_map(static fn($place)=>[
            'id'=>(int)$place['id'],
            'parent'=>(int)($place['parent']??0),
            'name'=>(string)$place['label'],
            'kind'=>(string)$place['kind'],
            'url'=>(string)$place['url'],
        ],self::related_places($post));
        $encoded=wp_json_encode($placeTree,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
        if(is_string($encoded)){
            $element->add_render_attribute('_wrapper','data-tour-destinations',$encoded);
        }
        // Destinations remain available when Tour Highlights are hidden or
        // awaiting review; never suppress the whole combined discovery section.
        $has=self::has_highlights($post);
        $element->add_render_attribute('_wrapper','data-tour-journey-has-highlights',$has?'1':'0');
        if(!$has)$element->add_render_attribute('_wrapper','class','tour-journey--places-only');
    }

    public static function enter_native_group($element):void {
        $inside=!empty(self::$relatedGroupStack) && (bool)end(self::$relatedGroupStack);
        $css=(is_object($element)&&method_exists($element,'get_settings'))
          ?(string)$element->get_settings('css_classes'):'';
        if(str_contains(' '.trim($css).' ',' tour-place-group '))$inside=true;
        self::$relatedGroupStack[]=$inside;
    }

    public static function leave_native_group($element):void {
        if(self::$relatedGroupStack)array_pop(self::$relatedGroupStack);
    }

    public static function localize_native_grid($widget):void {
        if(empty(self::$relatedGroupStack) || !end(self::$relatedGroupStack)
            || !is_object($widget)
            || !method_exists($widget,'get_name')
            || $widget->get_name()!=='jet-listing-grid'
            || !method_exists($widget,'get_settings')
            || !method_exists($widget,'set_settings'))return;

        $lang=(string)apply_filters('wpml_current_language',null);
        if($lang==='')return;
        $original=(int)$widget->get_settings('lisitng_id');
        if($original<=0)return;
        $localized=(int)apply_filters('wpml_object_id',$original,'jet-engine',false,$lang);
        if($localized<=0||$localized===$original
          ||get_post_type($localized)!=='jet-engine'
          ||get_post_status($localized)!=='publish')return;
        $widget->set_settings('lisitng_id',(string)$localized);
    }

    private static function source_valid(int $post,array $item):bool {
        $type=(string)($item['tour_highlight_source_type']??'');
        $id=(int)($item['tour_highlight_source_id']??0);
        if($type==='legacy'||$type==='custom')return true; // explicitly editorial-approved
        if($id<=0)return false;
        if($type==='destination') {
            $term=get_term($id,'destinations');
            if(!$term instanceof WP_Term || !has_term($id,'destinations',$post))return false;
            if(!class_exists('MAD4B_Company_Term_Eligibility_V1',false))return false;
            $decision=MAD4B_Company_Term_Eligibility_V1::decide($term,'tours-and-activities','destination_discovery',true);
            return !empty($decision['allowed']);
        }
        if($type==='program') {
            $term=get_term($id,'programs');
            if(!$term instanceof WP_Term)return false;
            $linked=array_map('intval',(array)get_post_meta($post,'related_program_terms',true));
            if(!in_array($id,$linked,true) && !has_term($id,'programs',$post))return false;
            $it=get_term_meta($id,'program_itinerary',true);
            return (is_array($it)&&count($it)>0)||(is_string($it)&&trim(wp_strip_all_tags($it))!=='');
        }
        if($type==='property')return (int)get_post_meta($post,'tour_cruise_property_id',true)===$id
            && get_post_type($id)==='properties' && get_post_status($id)==='publish';
        // Service promises must be checked against an exact service+tour contract,
        // never inferred from marketing copy or a free-text 'includes' repeater.
        return false;
    }

    public static function approved_items(int $post):array {
        if((string)get_post_meta($post,'tour_highlights_review_status',true)!=='approved')return [];
        $items=get_post_meta($post,'tour_highlights_items',true);
        if(!is_array($items))return [];
        $out=[];
        foreach($items as $item) {
            if(!is_array($item) || ($item['tour_highlight_status']??'')!=='approved')continue;
            if(!self::source_valid($post,$item))continue;
            $title=trim(wp_strip_all_tags((string)($item['tour_highlight_title']??'')));
            if($title==='')continue;
            $out[]=['title'=>$title,'description'=>(string)($item['tour_highlight_description']??'')];
            if(count($out)>=15)break;
        }
        return $out;
    }

    public static function render($fallback=''):string {
        $post=self::context_id();
        if(!$post)return is_string($fallback)?$fallback:'';
        $mode=self::mode($post);
        if($mode==='hide')return '';
        $original=is_string($fallback)?$fallback:(string)get_post_meta($post,'tour_highlights_content',true);
        if($mode==='manual')return $original;
        $items=self::approved_items($post);
        if(!$items)return $mode==='assisted'?$original:'';
        $out='<ul class="tour-highlights-curated" role="list">';
        foreach($items as $item) {
            $desc=trim(wp_strip_all_tags($item['description']));
            $out.='<li><strong>'.esc_html($item['title']).'</strong>';
            if($desc!=='')$out.='<span> '.esc_html($desc).'</span>';
            $out.='</li>';
        }
        return $out.'</ul>';
    }

    public static function section_visible($visible,$element) {
        if(!$visible || !self::is_discovery_container($element))return $visible;
        // Keep the container selectable in Elementor's editor, matching JetEngine's native behavior.
        if(class_exists(\Elementor\Plugin::class,false) && \Elementor\Plugin::$instance->editor->is_edit_mode())return $visible;
        $post=self::context_id();
        if(!$post)return $visible;
        return $visible && (self::has_highlights($post) || (bool)self::related_places($post));
    }

    public static function suggest(int $post):array {
        if($post<=0||get_post_type($post)!=='tours-and-activities')return [];
        $new=[];$existing=get_post_meta($post,'tour_highlights_items',true);
        $seen=[];
        foreach(is_array($existing)?$existing:[] as $item) {
            if(!is_array($item))continue;
            $seen[(string)($item['tour_highlight_source_type']??'').':'.(string)($item['tour_highlight_source_id']??'')]=true;
        }
        $terms=wp_get_object_terms($post,'destinations',['fields'=>'all']);
        if(!is_wp_error($terms)) {
            $order=['place_attraction'=>0,'city'=>1,'zone_area'=>2];
            usort($terms,static function($a,$b)use($order) {
                return ($order[get_term_meta($a->term_id,'destination_type',true)]??99)
                     <=>($order[get_term_meta($b->term_id,'destination_type',true)]??99)
                     ?:strcasecmp($a->name,$b->name);
            });
            foreach($terms as $term) {
                $kind=(string)get_term_meta($term->term_id,'destination_type',true);
                if(!isset($order[$kind]))continue;
                if(!class_exists('MAD4B_Company_Term_Eligibility_V1',false))continue;
                $elig=MAD4B_Company_Term_Eligibility_V1::decide($term,'tours-and-activities','destination_discovery',get_post_status($post)==='publish');
                if(empty($elig['allowed']))continue;
                $key='destination:'.$term->term_id;
                if(isset($seen[$key]))continue;
                $new[]=self::item($term->name,'destination',$term->term_id);
                $seen[$key]=true;
                if(count($new)>=8)break;
            }
        }
        $programs=array_map('intval',(array)get_post_meta($post,'related_program_terms',true));
        foreach($programs as $id) {
            if($id<=0 || isset($seen['program:'.$id]))continue;
            $term=get_term($id,'programs');
            if(!$term instanceof WP_Term)continue;
            $candidate=self::item($term->name,'program',$id);
            if(!self::source_valid($post,$candidate))continue;
            $new[]=$candidate;$seen['program:'.$id]=true;
            if(count($new)>=12)break;
        }
        $vessel=(int)get_post_meta($post,'tour_cruise_property_id',true);
        if($vessel>0&&!isset($seen['property:'.$vessel])) {
            $candidate=self::item(get_the_title($vessel),'property',$vessel);
            if(self::source_valid($post,$candidate))$new[]=$candidate;
        }
        return $new;
    }

    private static function item(string $title,string $type,int $id):array {
        return [
            'tour_highlight_title'=>mb_substr(wp_strip_all_tags($title),0,120,'UTF-8'),
            'tour_highlight_description'=>'',
            'tour_highlight_source_type'=>$type,
            'tour_highlight_source_id'=>(string)$id,
            'tour_highlight_status'=>'needs_review',
        ];
    }

    public static function sync(int $post,bool $write=false):array {
        $candidates=self::suggest($post);
        $old=get_post_meta($post,'tour_highlights_items',true);
        $old=is_array($old)?$old:[];
        $result=['post_id'=>$post,'mode'=>self::mode($post),'existing'=>count($old),'suggested'=>count($candidates),'written'=>false,'requires_approval'=>true,'source_types'=>array_count_values(array_column($candidates,'tour_highlight_source_type'))];
        if(!$write||!$candidates)return $result;
        if(self::$syncing)return $result;
        self::$syncing=true;
        $new=array_merge($old,$candidates);
        $ok=update_post_meta($post,'tour_highlights_items',wp_slash($new),$old);
        self::$syncing=false;
        if(!$ok)throw new RuntimeException('TOUR_HIGHLIGHTS_META_CAS_CONFLICT');
        $result['written']=true;
        $result['readback_count']=count((array)get_post_meta($post,'tour_highlights_items',true));
        return $result;
    }

    public static function on_save(int $post,$postObj,$update):void {
        if(get_post_type($post)!=='tours-and-activities' || self::$syncing || wp_is_post_revision($post)||wp_is_post_autosave($post))return;
        $mode=self::mode($post);
        if(!in_array($mode,['assisted','auto'],true))return;
        self::sync($post,true); // Editor-time only; never during frontend render.
    }

    public static function on_rest_save($post,$request,$creating):void {
        if($post instanceof WP_Post)self::on_save((int)$post->ID,$post,!$creating);
    }
}
MAD4B_Tour_Highlights_V1::register();
}

if (!function_exists('tour_highlights_render_approved')) {
    function tour_highlights_render_approved($value) {
        return class_exists('MAD4B_Tour_Highlights_Presentation_V1',false)
            ? MAD4B_Tour_Highlights_Presentation_V1::render(MAD4B_Tour_Highlights_V1::render($value))
            : MAD4B_Tour_Highlights_V1::render($value);
    }
}

// Native JetEngine presentation adapter. Loaded from the same shared plugin as the resolver.
require_once __DIR__ . '/class-mad4b-scp-tour-highlights-presentation-v1.php';
