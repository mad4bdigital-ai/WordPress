<?php
/**
 * Plugin Name: Company Brand Presentation
 * Description: Native Elementor inheritance and quiet visual roles from the company identity context.
 */
defined('ABSPATH') || exit;
if (!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED) return;

function company_brand_presentation_context(): array {
    if (!function_exists('company_brand_context')) return [];
    $context=company_brand_context();
    return is_array($context) ? $context : [];
}
function company_brand_presentation_font($font,string $fallback): string {
    $font=is_string($font) ? trim($font) : '';
    return $font!=='' && preg_match('/^[\pL\pN ._-]{1,80}$/uD',$font) ? '"'.$font.'",'.$fallback : $fallback;
}
function company_brand_presentation_css(array $context): string {
    if (empty($context['enabled'])) return '';
    $declarations=[];
    $fallbacks=['primary'=>'#102D40','secondary'=>'#204C50','accent'=>'#B48A46','text'=>'#102D40','muted'=>'#64737B','surface'=>'#F6F2E9','border'=>'#DDD9CF','focus'=>'#176B83'];
    foreach($fallbacks as $role=>$fallback) {
        $color=$context['colors'][$role] ?? $fallback;
        $color=is_string($color) && preg_match('/^#[a-f0-9]{6}$/iD',$color) ? $color : $fallback;
        $declarations[]='--company-brand-'.$role.':'.$color;
        if(in_array($role,['primary','secondary','text','accent'],true)) $declarations[]='--e-global-color-'.$role.':'.$color;
    }
    $fonts=is_array($context['fonts']??null)?$context['fonts']:[];
    $heading=company_brand_presentation_font($fonts['heading']??'','Georgia,serif');
    $body=company_brand_presentation_font($fonts['body']??'','Arial,sans-serif');
    $arabic=company_brand_presentation_font($fonts['arabic']??'','Tahoma,Arial,sans-serif');
    $language=(string)apply_filters('wpml_current_language',get_locale());
    if(strpos(strtolower($language),'ar')===0) $heading=$body=$arabic;
    $declarations[]='--company-brand-font-heading:'.$heading;
    $declarations[]='--company-brand-font-body:'.$body;
    $declarations[]='--company-brand-font-arabic:'.$arabic;
    foreach(['primary'=>$heading,'secondary'=>$body,'text'=>$body,'accent'=>$body] as $role=>$font) $declarations[]='--e-global-typography-'.$role.'-font-family:'.$font;
    foreach(['button'=>10,'card'=>18,'input'=>10] as $role=>$fallback) {
        $value=$context['radii'][$role]??$fallback;
        $value=is_numeric($value)?max(0,min(40,(float)$value)):$fallback;
        $declarations[]='--company-brand-radius-'.$role.':'.$value.'px';
    }
    $body_size=is_numeric($context['body_size']??null)?max(14,min(20,(float)$context['body_size'])):16;
    $scale=is_numeric($context['heading_scale']??null)?max(.85,min(1.2,(float)$context['heading_scale'])):1;
    $space=['compact'=>20,'balanced'=>28,'comfortable'=>36][$context['density']??'balanced']??28;
    $declarations[]='--company-brand-body-size:'.$body_size.'px';
    $declarations[]='--company-brand-heading-scale:'.$scale;
    $declarations[]='--company-brand-space:'.$space.'px';
    $css='body.company-brand-active{'.implode(';',$declarations).';}';
    foreach(['heading_regular'=>'normal','heading_italic'=>'italic'] as $key=>$style) {
        $url=esc_url_raw((string)($context['font_urls'][$key]??''));
        $family=$context['font_face_family']??'Playfair Display';
        if(!$url||!is_string($family)||!preg_match('/^[\pL\pN ._-]{1,80}$/uD',$family))continue;
        $format=preg_match('/\.woff2(?:[?].*)?$/i',$url)?'woff2':(preg_match('/\.woff(?:[?].*)?$/i',$url)?'woff':'truetype');
        $css.='@font-face{font-family:"'.$family.'";font-style:'.$style.';font-weight:'.($style==='italic'?'400':'500').';font-display:swap;src:url("'.$url.'") format("'.$format.'");}';
    }
    return $css;
}
add_filter('body_class',static function(array $classes):array {
    $context=company_brand_presentation_context();
    if(empty($context['enabled']))return $classes;
    $classes[]='company-brand-active';
    $classes[]='company-brand-'.sanitize_html_class((string)($context['profile']??'custom'));
    $classes[]='company-brand-density-'.sanitize_html_class((string)($context['density']??'balanced'));
    if(($context['decoration']??'subtle')==='none')$classes[]='company-brand-decoration-off';
    return array_values(array_unique($classes));
});
function company_brand_presentation_enqueue(): void {
    $context=company_brand_presentation_context();
    if(empty($context['enabled']))return;
    $file=WPMU_PLUGIN_DIR.'/assets/company-brand-presentation.css';
    wp_enqueue_style('company-brand-presentation',plugins_url('assets/company-brand-presentation.css',(WPMU_PLUGIN_DIR.'/company-brand-presentation.php')),[],is_file($file)?(string)filemtime($file):'1');
    $css=company_brand_presentation_css($context);
    if($css!=='')wp_add_inline_style('company-brand-presentation',$css);
}
add_action('wp_enqueue_scripts','company_brand_presentation_enqueue',99);
add_action('elementor/preview/enqueue_styles','company_brand_presentation_enqueue',99);

function company_brand_register_role_controls($element): void {
    if(!method_exists($element,'start_controls_section')||!class_exists('\Elementor\Controls_Manager'))return;
    $element->start_controls_section('company_brand_role_section',['label'=>'Company Brand','tab'=>\Elementor\Controls_Manager::TAB_STYLE]);
    $element->add_control('company_brand_role',[
        'label'=>'Visual role',
        'type'=>\Elementor\Controls_Manager::SELECT,
        'options'=>[
            'inherit'=>'Inherit — preserve this element',
            'off'=>'Local style — no brand surface',
            'surface'=>'Light surface',
            'inverse'=>'Dark surface',
            'card'=>'Quiet card',
            'editorial'=>'Editorial introduction',
            'hero'=>'Hero typography'
        ],
        'default'=>'inherit',
        'prefix_class'=>'company-brand-role-',
        'description'=>'Uses JetEngine → Look & Feel → Company Brand. Existing native Elementor styles remain editable. Use an editorial role only on a spacious introduction, away from prices, filters and booking forms.'
    ]);
    $element->end_controls_section();
}
add_action('elementor/element/container/section_background/after_section_end','company_brand_register_role_controls',60);
add_action('elementor/element/common/_section_style/after_section_end','company_brand_register_role_controls',60);

add_action('elementor/dynamic_tags/register',static function($manager): void {
    if(!class_exists('\Elementor\Core\DynamicTags\Data_Tag'))return;
    $manager->register_group('company_brand',['title'=>'Company Brand']);
    if(!class_exists('Company_Brand_Text_Tag',false)) {
        class Company_Brand_Text_Tag extends \Elementor\Core\DynamicTags\Data_Tag {
            public function get_name(){return 'company-brand-text';}
            public function get_title(){return 'Company Brand · Identity';}
            public function get_group(){return 'company_brand';}
            public function get_categories(){return ['text'];}
            protected function register_controls(){
                $this->add_control('field',['label'=>'Identity field','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'name','options'=>['name'=>'Brand name','profile'=>'Profile','palette'=>'Palette','revision'=>'Identity revision']]);
            }
            public function get_value(array $options=[]){
                $key=(string)($this->get_settings('field')?:'name');
                if(!in_array($key,['name','profile','palette','revision'],true))return '';
                return sanitize_text_field((string)(company_brand_presentation_context()[$key]??''));
            }
        }
        class Company_Brand_Color_Tag extends \Elementor\Core\DynamicTags\Data_Tag {
            public function get_name(){return 'company-brand-color';}
            public function get_title(){return 'Company Brand · Color';}
            public function get_group(){return 'company_brand';}
            public function get_categories(){return ['color','text'];}
            protected function register_controls(){
                $this->add_control('role',['label'=>'Color role','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'primary','options'=>['primary'=>'Primary','secondary'=>'Secondary','accent'=>'Accent','text'=>'Text','muted'=>'Muted','surface'=>'Surface','border'=>'Border','focus'=>'Focus']]);
            }
            public function get_value(array $options=[]){
                $role=(string)($this->get_settings('role')?:'primary');
                if(!in_array($role,['primary','secondary','accent','text','muted','surface','border','focus'],true))return '';
                $color=company_brand_presentation_context()['colors'][$role]??'';
                return is_string($color)&&preg_match('/^#[a-f0-9]{6}$/iD',$color)?$color:'';
            }
        }
        class Company_Brand_Media_Tag extends \Elementor\Core\DynamicTags\Data_Tag {
            public function get_name(){return 'company-brand-media';}
            public function get_title(){return 'Company Brand · Artwork';}
            public function get_group(){return 'company_brand';}
            public function get_categories(){return ['image'];}
            protected function register_controls(){
                $this->add_control('role',['label'=>'Artwork role','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'logo_default','options'=>['logo_default'=>'Default logo','logo_inverse'=>'Logo on dark','logo_compact'=>'Compact logo','logo_mobile'=>'Mobile logo','favicon'=>'Site icon','pattern'=>'Decorative pattern']]);
            }
            public function get_value(array $options=[]){
                $role=(string)($this->get_settings('role')?:'logo_default');
                if(!in_array($role,['logo_default','logo_inverse','logo_compact','logo_mobile','favicon','pattern'],true))return ['id'=>0,'url'=>''];
                $context=company_brand_presentation_context();
                return ['id'=>absint($context['media_ids'][$role]??0),'url'=>esc_url_raw((string)($context['media'][$role]??''))];
            }
        }
        class Company_Brand_URL_Tag extends \Elementor\Core\DynamicTags\Data_Tag {
            public function get_name(){return 'company-brand-media-url';}
            public function get_title(){return 'Company Brand · Artwork URL';}
            public function get_group(){return 'company_brand';}
            public function get_categories(){return ['url'];}
            protected function register_controls(){
                $this->add_control('role',['label'=>'Artwork role','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'logo_default','options'=>['logo_default'=>'Default logo','logo_inverse'=>'Logo on dark','logo_compact'=>'Compact logo','logo_mobile'=>'Mobile logo','favicon'=>'Site icon','pattern'=>'Decorative pattern']]);
            }
            public function get_value(array $options=[]){
                $role=(string)($this->get_settings('role')?:'logo_default');
                if(!in_array($role,['logo_default','logo_inverse','logo_compact','logo_mobile','favicon','pattern'],true))return '';
                return esc_url_raw((string)(company_brand_presentation_context()['media'][$role]??''));
            }
        }
    }
    $manager->register(new Company_Brand_Text_Tag());
    $manager->register(new Company_Brand_Color_Tag());
    $manager->register(new Company_Brand_Media_Tag());
    $manager->register(new Company_Brand_URL_Tag());
},70);

/**
 * Native Elementor Global pickers expose company-owned entries. The saved Kit
 * stays intact: these are projections of Look & Feel, not competing settings.
 */
function company_brand_global_color_items($items): array {
    $items=is_array($items)?$items:[];
    $context=company_brand_presentation_context();
    foreach(['primary','secondary','accent','text','muted','surface','border','focus'] as $role) {
        $value=$context['colors'][$role]??'';
        if(!is_string($value)||!preg_match('/^#[a-f0-9]{6}$/iD',$value))continue;
        $id='company-brand-'.$role;
        $items[$id]=['id'=>$id,'title'=>'Company · '.ucfirst($role).' (Look & Feel)','value'=>$value];
        if(!empty($context['enabled'])&&in_array($role,['primary','secondary','accent','text'],true)&&isset($items[$role])&&is_array($items[$role])) {
            $items[$role]['value']=$value;
        }
    }
    return $items;
}
function company_brand_global_typography_items($items): array {
    $items=is_array($items)?$items:[];
    $context=company_brand_presentation_context();
    foreach(['heading','body','arabic'] as $role) {
        $font=$context['fonts'][$role]??'';
        if(!is_string($font)||!preg_match('/^[\pL\pN ._-]{1,80}$/uD',$font))continue;
        $id='company-brand-'.$role;
        $items[$id]=[
            'id'=>$id,
            'title'=>'Company · '.ucfirst($role).' (Look & Feel)',
            'value'=>[
                'typography_typography'=>'custom',
                'typography_font_family'=>$font,
                'typography_font_weight'=>$role==='heading'?'500':'400'
            ]
        ];
    }
    if(!empty($context['enabled'])) {
        $language=(string)apply_filters('wpml_current_language',get_locale());
        $arabic=strpos(strtolower($language),'ar')===0;
        foreach(['primary'=>'heading','secondary'=>'body','text'=>'body','accent'=>'body'] as $id=>$role) {
            $font=$context['fonts'][$arabic?'arabic':$role]??'';
            if(!isset($items[$id])||!is_array($items[$id])||!is_string($font)||!preg_match('/^[\pL\pN ._-]{1,80}$/uD',$font))continue;
            $items[$id]['value']=is_array($items[$id]['value']??null)?$items[$id]['value']:[];
            $items[$id]['value']['typography_font_family']=$font;
        }
    }
    return $items;
}
add_filter('elementor/globals/colors/items','company_brand_global_color_items',50);
add_filter('elementor/globals/typography/items','company_brand_global_typography_items',50);

function company_brand_named_global_css(array $context): string {
    $declarations=[];
    foreach(['primary','secondary','accent','text','muted','surface','border','focus'] as $role) {
        $value=$context['colors'][$role]??'';
        if(is_string($value)&&preg_match('/^#[a-f0-9]{6}$/iD',$value))$declarations[]='--e-global-color-company-brand-'.$role.':'.$value;
    }
    foreach(['heading','body','arabic'] as $role) {
        $font=$context['fonts'][$role]??'';
        if(!is_string($font)||!preg_match('/^[\pL\pN ._-]{1,80}$/uD',$font))continue;
        $declarations[]='--e-global-typography-company-brand-'.$role.'-font-family:"'.$font.'"';
        $declarations[]='--e-global-typography-company-brand-'.$role.'-font-weight:'.($role==='heading'?'500':'400');
    }
    return $declarations?':root{'.implode(';',$declarations).';}':'';
}
add_action('wp_head',static function():void {
    $css=company_brand_named_global_css(company_brand_presentation_context());
    if($css!=='')echo '<style id="company-brand-named-globals">'.$css.'</style>'."\n";
},100);
