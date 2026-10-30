<?php
/**
 * Plugin Name: MAD4B DFSB Native Presentation
 * Description: Read-only native Elementor and shortcode output from published WordPress context. Does not start DFSB SEO publication.
 * Version: 0.2.0
 * Requires PHP: 8.1
 * Text Domain: mad4b-dfsb-native-presentation
 */
namespace MAD4B\DFSB\NativePresentation;

if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/includes/class-shared-context.php';
add_filter('jet-engine/listings/macros-list',array(__NAMESPACE__.'\\SharedContext','jetMacros'),25);
add_action('init',array(__NAMESPACE__.'\\SharedContext','register'),85);

function ready(): bool {
    return class_exists('DynamicFilterSEOBridge\\Context\\ContextValueResolver')
        && class_exists('DynamicFilterSEOBridge\\Context\\PageContextResolver');
}
function context(string $scope='current', int $id=0, string $type=''): array {
    if (!ready()) return array('page_kind'=>'unresolved');
    return \DynamicFilterSEOBridge\Context\ContextValueResolver::context($scope,$id,$type);
}
function value(string $field, array $ctx): string {
    if (!ready()) return '';
    return \DynamicFilterSEOBridge\Context\ContextValueResolver::value($ctx,sanitize_key($field));
}
function image(array $ctx): array {
    if (!ready()) return array('id'=>0,'url'=>'');
    return \DynamicFilterSEOBridge\Context\ContextValueResolver::image($ctx);
}
function render_image(array $ctx): string {
    $media=image($ctx);
    if (empty($media['url'])) return '';
    $alt=value('title',$ctx);
    if (!empty($media['id'])) {
        $out=wp_get_attachment_image((int)$media['id'],'large',false,array('alt'=>$alt,'loading'=>'lazy'));
        if (is_string($out) && $out!=='') return $out;
    }
    return '<img src="'.esc_url($media['url']).'" alt="'.esc_attr($alt).'" loading="lazy" />';
}
function register_shortcodes(): void {
    // Existing names are preserved; overrides change display only, not metadata or SEO.
    add_shortcode('dfsb_context_value',static function($attributes): string {
        $attrs=shortcode_atts(array('field'=>'title'),is_array($attributes)?$attributes:array(),'dfsb_context_value');
        $field=sanitize_key((string)$attrs['field']);
        $out=in_array($field,array('title','description'),true)
            ? SharedContext::field($field) : value($field,context());
        return $field==='image_url' ? esc_url($out) : esc_html($out);
    });
    add_shortcode('dfsb_context_image',static fn($a): string=>render_image(context()));
    add_shortcode('dfsb_context_heading',static function($a): string {
        $ctx=context();
        $slot=class_exists('DynamicFilterSEOBridge\\Context\\PresentationSlotPolicy')
            ? \DynamicFilterSEOBridge\Context\PresentationSlotPolicy::forContext($ctx):array();
        if (($slot['placement']??'')!=='after_listing') return '';
        $title=value('title',$ctx);
        return $title!==''?'<h2 class="dfsb-context-heading">'.esc_html($title).'</h2>':'';
    });
    add_shortcode('dfsb_context_editorial',static function($a): string {
        $ctx=context();
        $slot=class_exists('DynamicFilterSEOBridge\\Context\\PresentationSlotPolicy')
            ? \DynamicFilterSEOBridge\Context\PresentationSlotPolicy::forContext($ctx):array();
        if (($slot['placement']??'')!=='after_listing') return '';
        $description=value('description',$ctx);
        if ($description==='') return '';
        return '<section class="dfsb-context-editorial" aria-label="'.esc_attr(value('title',$ctx)).'"><div class="dfsb-context-editorial__copy">'.esc_html($description).'</div></section>';
    });
}
add_action('init',__NAMESPACE__.'\\register_shortcodes',80);

function register_tags($manager): void {
    if (!ready() || !class_exists('Elementor\\Core\\DynamicTags\\Tag') ||
        !method_exists($manager,'register')) return;

    if (!class_exists(__NAMESPACE__.'\\NativeValueTag',false)) {
        class NativeValueTag extends \Elementor\Core\DynamicTags\Tag {
            public function get_name(){return 'dfsb-context-value';}
            public function get_title(){return 'Context Source Value';}
            public function get_group(){return 'dfsb-context';}
            public function get_categories(){return array('text','url');}
            protected function register_controls(){
                $options=array();
                foreach(\DynamicFilterSEOBridge\Context\ContextValueResolver::fields() as $field)
                    $options[$field]=ucwords(str_replace('_',' ',$field));
                $this->add_control('field',array('label'=>'Source Field',
                    'type'=>\Elementor\Controls_Manager::SELECT,
                    'options'=>$options,'default'=>'title'));
                $this->add_control('preview_scope',array('label'=>'Editor Preview Source',
                    'type'=>\Elementor\Controls_Manager::SELECT,
                    'options'=>array('current'=>'Current Page','post'=>'Published Post / CPT',
                        'taxonomy'=>'Taxonomy Term','archive'=>'CPT Archive',
                        'posts_home'=>'Blog Home','shop'=>'Woo Shop'),'default'=>'current'));
                $this->add_control('preview_id',array('label'=>'Editor Preview Object ID',
                    'type'=>\Elementor\Controls_Manager::NUMBER,'default'=>0));
                $this->add_control('preview_type',array('label'=>'CPT / Taxonomy key',
                    'type'=>\Elementor\Controls_Manager::TEXT,'default'=>''));
            }
            public function get_value(array $options=array()){
                $editor=(is_admin()||wp_doing_ajax())&&current_user_can('manage_options');
                $scope=$editor?sanitize_key((string)$this->get_settings('preview_scope')):'current';
                $id=$editor?absint($this->get_settings('preview_id')):0;
                $type=$editor?sanitize_key((string)$this->get_settings('preview_type')):'';
                $field=(string)$this->get_settings('field');
                if (!$editor && in_array($field,array('title','description'),true))
                    return SharedContext::field($field);
                return value($field,context($scope?:'current',$id,$type));
            }
            public function render(): void {
                $v=(string)$this->get_value();
                echo $this->get_settings('field')==='image_url'?esc_url($v):esc_html($v);
            }
        }
    }
    if (method_exists($manager,'register_group')) {
        $manager->register_group('dfsb-context',array('title'=>'Context Sources'));
    }
    $manager->register(new NativeValueTag());

    if (class_exists('Elementor\\Core\\DynamicTags\\Data_Tag')) {
        if (!class_exists(__NAMESPACE__.'\\NativeImageTag',false)) {
            class NativeImageTag extends \Elementor\Core\DynamicTags\Data_Tag {
                public function get_name(){return 'dfsb-context-image';}
                public function get_title(){return 'Context Source Image';}
                public function get_group(){return 'dfsb-context';}
                public function get_categories(){
                    return array(\Elementor\Modules\DynamicTags\Module::IMAGE_CATEGORY);
                }
                protected function register_controls(){
                    $this->add_control('preview_scope',array('label'=>'Editor Preview Source',
                        'type'=>\Elementor\Controls_Manager::SELECT,
                        'options'=>array('current'=>'Current Page','post'=>'Published Post / CPT',
                            'taxonomy'=>'Taxonomy Term','archive'=>'CPT Archive',
                            'posts_home'=>'Blog Home','shop'=>'Woo Shop'),'default'=>'current'));
                    $this->add_control('preview_id',array('label'=>'Editor Preview Object ID',
                        'type'=>\Elementor\Controls_Manager::NUMBER,'default'=>0));
                    $this->add_control('preview_type',array('label'=>'CPT / Taxonomy key',
                        'type'=>\Elementor\Controls_Manager::TEXT,'default'=>''));
                }
                public function get_value(array $options=array()){
                    $editor=(is_admin()||wp_doing_ajax())&&current_user_can('manage_options');
                    return image(context($editor?sanitize_key((string)$this->get_settings('preview_scope')):'current',
                        $editor?absint($this->get_settings('preview_id')):0,
                        $editor?sanitize_key((string)$this->get_settings('preview_type')):''));
                }
            }
        }
        $manager->register(new NativeImageTag());
    }
}
add_action('elementor/dynamic_tags/register',__NAMESPACE__.'\\register_tags',95);
