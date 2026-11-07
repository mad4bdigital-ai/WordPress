<?php
/**
 * MAD4B Trust & Support Elementor dynamic tags for existing JetEngine Look & Feel.
 * Text is localized per WPML language, icon sources remain site-owned.
 */
defined('ABSPATH') || exit;

final class MAD4B_Trust_Support_Dynamic {
    private const PAGE='look-and-feel';
    private const LANGS=['en'=>'English','fr'=>'Français','de'=>'Deutsch','es'=>'Español','it'=>'Italiano','ar'=>'العربية'];
    private const HELP=[
        'help_heading'=>'Help section heading',
        'help_community_title'=>'Community card title',
        'help_community_desc'=>'Community card description',
        'help_chat_title'=>'Live chat card title',
        'help_chat_desc'=>'Live chat card description',
    ];
    private const WHY=[
        'why_heading'=>'Why Us section heading',
        'why_1_title'=>'Feature 1 title','why_1_desc'=>'Feature 1 description',
        'why_2_title'=>'Feature 2 title','why_2_desc'=>'Feature 2 description',
        'why_3_title'=>'Feature 3 title','why_3_desc'=>'Feature 3 description',
        'why_4_title'=>'Feature 4 title','why_4_desc'=>'Feature 4 description',
        'why_5_title'=>'Feature 5 title','why_5_desc'=>'Feature 5 description',
        'why_6_title'=>'Feature 6 title','why_6_desc'=>'Feature 6 description',
    ];
    private const ICON_WIDGETS=[
        'e4856eb'=>'why_1_icon','a6e6230'=>'why_2_icon','9568b5c'=>'why_3_icon',
        'eef8ab1'=>'why_4_icon','8667918'=>'why_5_icon','f365531'=>'why_6_icon',
        '411c0c4'=>'help_community_icon','c372c3d'=>'help_chat_icon',
    ];
    public static function names():array {return self::HELP+self::WHY;}
    public static function language():string {
        $lang=apply_filters('wpml_current_language',null);
        $lang=is_string($lang)?$lang:'en';
        return isset(self::LANGS[$lang])?$lang:'en';
    }
    public static function text(string $field):string {
        if(!array_key_exists($field,self::names()))return '';
        $raw=get_option(self::PAGE,[]);
        $value=is_array($raw)?($raw['mad4b_ux_'.$field.'_'.self::language()]??''):'';
        return is_string($value)?$value:'';
    }
    public static function icon_attachment(string $key):int {
        if(!preg_match('/^why_[1-6]_icon$/D',$key))return 0;
        $raw=get_option(self::PAGE,[]);
        $v=is_array($raw)?($raw['mad4b_ux_'.$key]??0):0;
        $id=(int)(is_array($v)?($v['id']??0):$v);
        if($id<1 || get_post_type($id)!=='attachment')return 0;
        if (get_post_mime_type($id) !== 'image/svg+xml') return 0;
        $file=get_attached_file($id);
        if (!is_string($file) || !is_file($file)) return 0;
        return $id;
    }
    public static function option_fields($fields,$page) {
        if(!is_array($fields)||!is_object($page)||($page->slug??'')!==self::PAGE)return $fields;
        $known=[];
        foreach($fields as $f)if(is_array($f)&&isset($f['name']))$known[$f['name']]=true;
        $add=[
            ['name'=>'mad4b_ux_help_tab','title'=>'Need Help · dynamic section','object_type'=>'tab','tab_layout'=>'horizontal'],
            ['name'=>'mad4b_ux_help_info','title'=>'Support links remain in Contact and Help Info: support_group_url / support_chat_url','type'=>'html','object_type'=>'field',
             'html'=>'<p>Only enter titles, descriptions and icons here. Existing support URLs are controlled from Contact and Help Info.</p>'],
        ];
        foreach(self::HELP as $key=>$label) {
            foreach(self::LANGS as $lang=>$name)
                $add[]=['name'=>'mad4b_ux_'.$key.'_'.$lang,'title'=>$label.' — '.$name,'type'=>str_ends_with($key,'_desc')?'textarea':'text','object_type'=>'field'];
        }
        $add[]=['name'=>'mad4b_ux_help_community_icon','title'=>'Community icon · Font Awesome class','type'=>'text','object_type'=>'field','description'=>'For example fas fa-theater-masks'];
        $add[]=['name'=>'mad4b_ux_help_chat_icon','title'=>'Live chat icon · Font Awesome class','type'=>'text','object_type'=>'field','description'=>'For example far fa-comments'];
        $add[]=['name'=>'mad4b_ux_why_tab','title'=>'Why Us · 6 feature cards','object_type'=>'tab','tab_layout'=>'horizontal'];
        foreach(self::WHY as $key=>$label) {
            foreach(self::LANGS as $lang=>$name)
                $add[]=['name'=>'mad4b_ux_'.$key.'_'.$lang,'title'=>$label.' — '.$name,'type'=>str_ends_with($key,'_desc')?'textarea':'text','object_type'=>'field'];
        }
        for($n=1;$n<=6;$n++)$add[]=[
            'name'=>'mad4b_ux_why_'.$n.'_icon','title'=>'Feature '.$n.' icon · Media Library','type'=>'media',
            'object_type'=>'field','value_format'=>'id',
            'allowed_mime_types'=>['image/svg+xml'],
            'description'=>'SVG only, required by the existing Elementor Icon widget. The icon is shared across six languages.',
        ];
        foreach($add as $f)if(!isset($known[$f['name']])){$fields[]=$f;$known[$f['name']]=true;}
        return $fields;
    }
    public static function tag($manager):void {
        if(!class_exists('Elementor\\Core\\DynamicTags\\Tag'))return;
        require_once __DIR__.'/373-mad4b-trust-support-tag.php';
        $manager->register(new MAD4B_Trust_Support_Text_Tag());
    }
    public static function bind_icon($widget):void {
        if(!is_object($widget)||!method_exists($widget,'get_id')||
            !isset(self::ICON_WIDGETS[(string)$widget->get_id()]))return;
        $key=self::ICON_WIDGETS[(string)$widget->get_id()];
        $type=(string)$widget->get_name();
        if(str_starts_with($key,'why_')){
            if($type!=='icon')return;
            $id=self::icon_attachment($key);
            if(!$id)return;
            $url=wp_get_attachment_url($id);
            if(!$url)return;
            $mime=get_post_mime_type($id);
            if($mime!=='image/svg+xml') {
                // Non-SVG media cannot be passed as Elementor Icon control;
                // the original icon remains intact until an SVG is selected.
                return;
            }
            $widget->set_settings('selected_icon',[
                'value'=>['id'=>$id,'url'=>$url],'library'=>'svg'
            ]);
        } elseif($type==='hfe-infocard') {
            $options=get_option(self::PAGE,[]);
            $class=is_array($options)?($options['mad4b_ux_'.$key]??''):'';
            if(!is_string($class)||!preg_match('/^(fas|far|fab) fa-[a-z0-9-]{2,65}$/D',$class))return;
            [$library,$icon]=explode(' ',$class,2);
            $libs=['fas'=>'fa-solid','far'=>'fa-regular','fab'=>'fa-brands'];
            $widget->set_settings('infocard_select_icon',['value'=>$class,'library'=>$libs[$library]]);
        }
    }
    public static function fields_changed($old,$new):bool {
        if(!is_array($old)||!is_array($new))return false;
        foreach(array_unique(array_merge(array_keys($old),array_keys($new))) as $key) {
            if(!is_string($key)||!str_starts_with($key,'mad4b_ux_'))continue;
            if(($old[$key]??null)!==($new[$key]??null))return true;
        }
        return false;
    }
    public static function invalidate_when_saved(string $option,$old,$new):void {
        if($option!==self::PAGE || !self::fields_changed($old,$new))return;
        if(class_exists('Elementor\Plugin',false) && isset(\Elementor\Plugin::$instance->files_manager))
            \Elementor\Plugin::$instance->files_manager->clear_cache();
        do_action('litespeed_purge_all');
    }
    public static function boot():void {
        add_filter('jet-engine/options-pages/raw-fields',[__CLASS__,'option_fields'],96,2);
        add_action('elementor/dynamic_tags/register',[__CLASS__,'tag'],56);
        add_action('elementor/frontend/widget/before_render',[__CLASS__,'bind_icon'],12,1);
        add_action('updated_option',[__CLASS__,'invalidate_when_saved'],25,3);
    }
}
MAD4B_Trust_Support_Dynamic::boot();
