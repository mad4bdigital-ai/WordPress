<?php
/** Elementor Dynamic Tags backed by the existing product activation authority. */
defined('ABSPATH') || exit;
function product_navigation_tag_value(string $key, string $entry='', string $field='label'): string {
    $language=apply_filters('wpml_current_language',null)?:apply_filters('wpml_default_language',null);
    $registry=product_navigation_registry();
    if(isset($registry[$key])) {
        if(!service_channel_allowed($key,'standalone'))return '';
        foreach(product_navigation_sections($language,'header')as$row)if($row['key']===$key){
            if($entry){foreach($row['children']as$child)if($child['key']===$entry)return (string)($child[$field]??'');return '';}
            $value=(string)($row[$field]??'');
            if($field==='label'){$base=get_term_by('slug',apply_filters('product_navigation_menu_slug','main-menu-en'),'nav_menu');$id=$base?apply_filters('wpml_object_id',$base->term_id,'nav_menu',false,$language):null;foreach($id?(wp_get_nav_menu_items($id)?:[]):[]as$item)if($item->type==='post_type_archive'&&$item->object===$registry[$key]['post_type'])return $item->title;}
            return $value;
        }
        return '';
    }
    if($key==='home') {
        $url=apply_filters('wpml_home_url',home_url('/'));
        if($field==='url')return $url;
        $front=apply_filters('wpml_object_id',(int)get_option('page_on_front'),'page',false,$language);
        $base=get_term_by('slug',apply_filters('product_navigation_menu_slug','main-menu-en'),'nav_menu');
        $menu=$base?apply_filters('wpml_object_id',$base->term_id,'nav_menu',false,$language):null;
        foreach($menu?(wp_get_nav_menu_items($menu)?:[]):[]as$item) {
            $front_page=$item->object==='page'&&(int)$item->object_id===(int)$front;
            $home_link=$item->type==='custom'&&untrailingslashit((string)wp_parse_url($item->url,PHP_URL_PATH))===untrailingslashit((string)wp_parse_url($url,PHP_URL_PATH));
            if($front_page||$home_link)return (string)$item->title;
        }
        return $front?(string)get_the_title($front):'';
    }
    if($entry && str_starts_with($key,'term:') && str_starts_with($entry,'term:'))return product_navigation_tag_value($entry,'',$field);
    if(preg_match('/^(term|page):(\d+)$/',$key,$m)){
        if($m[1]==='page'){$owner=apply_filters('product_navigation_page_service',null,(int)$m[2]);if($owner!==null&&!service_channel_allowed($owner,'standalone'))return '';$id=apply_filters('wpml_object_id',(int)$m[2],'page',false,$language);return !$id?'':($field==='url'?get_permalink($id):get_the_title($id));}
        $term=get_term((int)$m[2]);if(!$term||is_wp_error($term))return '';$tax=get_taxonomy($term->taxonomy);$allowed=array_filter($registry,fn($s,$k)=>in_array($s['post_type'],$tax->object_type,true)&&service_channel_allowed($k,'destination_discovery'),ARRAY_FILTER_USE_BOTH);if(!$allowed)return '';
        $id=apply_filters('wpml_object_id',$term->term_id,$term->taxonomy,false,$language);$term=$id?get_term($id):null;if(!$term||is_wp_error($term))return '';
        $visible=function_exists('product_navigation_term_visible')&&array_filter($allowed,fn($service,$key)=>product_navigation_term_visible($key,$term,$language),ARRAY_FILTER_USE_BOTH);
        if(!$visible)return '';
        $url=get_term_link($term);return $field==='url'?(is_wp_error($url)?'':$url):$term->name;
    }
    return '';
}
function product_navigation_tag_markup(string $name,array $settings): string {
    return \Elementor\Plugin::$instance->dynamic_tags->tag_data_to_tag_text(substr(md5($name.wp_json_encode($settings)),0,7),$name,$settings);
}
add_action('elementor/dynamic_tags/register',function($manager){
    $manager->register_group('product-navigation',['title'=>'Product activation & navigation']);
    if(!class_exists('Product_Navigation_Label_Tag',false)){
        class Product_Navigation_Label_Tag extends \Elementor\Core\DynamicTags\Tag {
            public function get_name(){return 'product-navigation-label';}
            public function get_title(){return 'Product navigation label';}
            public function get_group(){return 'product-navigation';}
            public function get_categories(){return [\Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY];}
            protected function register_controls(){
                $this->add_control('key',['label'=>'Navigation key / service','type'=>\Elementor\Controls_Manager::TEXT,'default'=>'tours','description'=>'Registry service, home, term:ID or page:ID.']);
                $this->add_control('entry',['label'=>'Optional generated entry key','type'=>\Elementor\Controls_Manager::TEXT]);
            }
            public function render(){echo esc_html(product_navigation_tag_value((string)$this->get_settings('key'),(string)$this->get_settings('entry'),'label'));}
        }
        class Product_Navigation_Url_Tag extends \Elementor\Core\DynamicTags\Data_Tag {
            public function get_name(){return 'product-navigation-url';}
            public function get_title(){return 'Product navigation canonical URL';}
            public function get_group(){return 'product-navigation';}
            public function get_categories(){return [\Elementor\Modules\DynamicTags\Module::URL_CATEGORY];}
            protected function register_controls(){
                $this->add_control('key',['label'=>'Navigation key / service','type'=>\Elementor\Controls_Manager::TEXT,'default'=>'tours']);
                $this->add_control('entry',['label'=>'Optional generated entry key','type'=>\Elementor\Controls_Manager::TEXT]);
            }
            public function get_value(array $options=[]){return product_navigation_tag_value((string)$this->get_settings('key'),(string)$this->get_settings('entry'),'url');}
        }
        class Product_Service_Policy_Tag extends \Elementor\Core\DynamicTags\Tag {
            public function get_name(){return 'product-service-policy';}
            public function get_title(){return 'Product service allowed (1 / 0)';}
            public function get_group(){return 'product-navigation';}
            public function get_categories(){return [\Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY,\Elementor\Modules\DynamicTags\Module::NUMBER_CATEGORY,\Elementor\Modules\DynamicTags\Module::POST_META_CATEGORY];}
            protected function register_controls(){
                $options=[];foreach(product_navigation_registry()as$key=>$row)$options[$key]=$row['label'];
                $this->add_control('service',['label'=>'Service','type'=>\Elementor\Controls_Manager::SELECT,'options'=>$options,'default'=>'tours']);
                $this->add_control('channel',['label'=>'Channel','type'=>\Elementor\Controls_Manager::SELECT,'options'=>['standalone'=>'Standalone','tour_component'=>'Tour component','destination_discovery'=>'Destination discovery'],'default'=>'standalone']);
            }
            public function render(){echo service_channel_allowed((string)$this->get_settings('service'),(string)$this->get_settings('channel'))?'1':'0';}
        }
    }
    $manager->register(new Product_Navigation_Label_Tag());$manager->register(new Product_Navigation_Url_Tag());$manager->register(new Product_Service_Policy_Tag());
});

// Keep policy binding visible and editable in the native nested repeater.
add_action('elementor/element/mega-menu/section_layout/before_section_end',function($widget){
    $control=$widget->get_controls('menu_items');if(!$control)return;
    $fields=$control['fields'];$fields['product_navigation_key']=['name'=>'product_navigation_key','label'=>'Product activation binding','type'=>\Elementor\Controls_Manager::TEXT,'default'=>'','description'=>'Service registry key (e.g. tours). Leave blank for an ordinary authored item; service bindings are enforced by the shared activation policy.'];
    $widget->update_control('menu_items',['fields'=>$fields]);
});
