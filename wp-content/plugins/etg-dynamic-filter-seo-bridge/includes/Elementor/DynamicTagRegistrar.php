<?php
namespace ETG\DynamicFilterSEOBridge\Elementor;

use ETG\DynamicFilterSEOBridge\Presentation\ContentSlotRegistry;
use ETG\DynamicFilterSEOBridge\Presentation\PresentationResolver;

final class DynamicTagRegistrar {
    private $resolver;private $slots;private $catalogProvider;
    public function __construct(PresentationResolver $resolver,ContentSlotRegistry $slots,callable $catalogProvider){$this->resolver=$resolver;$this->slots=$slots;$this->catalogProvider=$catalogProvider;}
    public function registerHooks():void{add_action('elementor/dynamic_tags/register',array($this,'register'));}
    public function register($manager):void{
        if(!class_exists('\\Elementor\\Core\\DynamicTags\\Tag')){return;}if(method_exists($manager,'register_group')){$manager->register_group('etg-dfsb',array('title'=>'ETG Filter SEO'));}
        foreach(array(array('etg-filter-title','ETG Filter Title','title','text'),array('etg-filter-intro','ETG Filter Intro','intro','text'),array('etg-filter-result-summary','ETG Filter Result Summary','result_summary','text'),array('etg-filter-keyword','ETG Filter Keyword','keyword','text'),array('etg-filter-archive-url','ETG Filter Archive URL','url:archive','url')) as $definition){$tag=new class($this->resolver,$definition) extends \Elementor\Core\DynamicTags\Tag {
                private $resolver;private $definition;
                public function __construct($resolver,$definition){$this->resolver=$resolver;$this->definition=$definition;parent::__construct();}
                public function get_name(){return$this->definition[0];}public function get_title(){return$this->definition[1];}public function get_group(){return'etg-dfsb';}public function get_categories(){return array($this->definition[3]);}
                public function get_value(array $options=array()){return$this->resolver->value($this->definition[2]);}
                public function render(){ $value=$this->get_value();$token=$this->definition[2];if('url'===$this->definition[3]){echo esc_url((string)$value);return;}$open='<span class="etg-dfsb-live-value" data-etg-dfsb-token="'.esc_attr($token).'">';if('intro'===$token){echo $open.wp_kses_post((string)$value).'</span>';}else{echo $open.esc_html((string)$value).'</span>';} }
            };$this->registerTag($manager,$tag);}
        $catalog=call_user_func($this->catalogProvider);$tokenOptions=array();$tokenTypes=array();foreach((array)($catalog['tokens']??array()) as $token=>$meta){$tokenOptions[$token]=(string)($meta['label']??$token).' ['.$token.']';$tokenTypes[$token]=(string)($meta['type']??'text');}
        $inventoryTag=new class($this->resolver,$tokenOptions,$tokenTypes) extends \Elementor\Core\DynamicTags\Tag {
            private $resolver;private $options;private $types;public function __construct($resolver,$options,$types){$this->resolver=$resolver;$this->options=$options;$this->types=$types;parent::__construct();}
            public function get_name(){return'etg-inventory-value';}public function get_title(){return'ETG Inventory Value';}public function get_group(){return'etg-dfsb';}public function get_categories(){return array('text','url');}
            protected function register_controls(){ $this->add_control('token',array('label'=>'Inventory Token','type'=>\Elementor\Controls_Manager::SELECT,'options'=>$this->options,'default'=>'title')); }
            public function get_value(array $options=array()){return$this->resolver->value((string)$this->get_settings('token'));}
            public function render(){ $token=(string)$this->get_settings('token');$value=$this->get_value();$type=(string)($this->types[$token]??'text');if('url'===$type||'image'===$type){echo esc_html((string)$value);return;}$open='<span class="etg-dfsb-live-value" data-etg-dfsb-token="'.esc_attr($token).'">';if('html'===$type){echo $open.wp_kses_post((string)$value).'</span>';}else{echo $open.esc_html((string)$value).'</span>';} }
        };$this->registerTag($manager,$inventoryTag);
        $slotOptions=array();foreach($this->slots->all() as $slot){$slotOptions[$slot['id']]=(string)$slot['label'].' ['.$slot['id'].']';}
        $slotTag=new class($this->resolver,$slotOptions) extends \Elementor\Core\DynamicTags\Tag {
            private $resolver;private $options;public function __construct($resolver,$options){$this->resolver=$resolver;$this->options=$options;parent::__construct();}
            public function get_name(){return'etg-dynamic-content-slot';}public function get_title(){return'ETG Dynamic Content Slot';}public function get_group(){return'etg-dfsb';}public function get_categories(){return array('text','url');}
            protected function register_controls(){ $this->add_control('slot_id',array('label'=>'Content Slot','type'=>\Elementor\Controls_Manager::SELECT,'options'=>$this->options)); }
            public function get_value(array $options=array()){return$this->resolver->slot((string)$this->get_settings('slot_id'));}
            public function render(){ $id=(string)$this->get_settings('slot_id');$value=$this->resolver->slot($id);$type=$this->resolver->slotType($id);if('url'===$type||'image'===$type){echo esc_html((string)$value);return;}$open='<span class="etg-dfsb-live-slot" data-etg-dfsb-slot="'.esc_attr($id).'">';if('html'===$type){echo $open.wp_kses_post($value).'</span>';}else{echo $open.esc_html($value).'</span>';} }
        };$this->registerTag($manager,$slotTag);
    }
    private function registerTag($manager,$tag):void{if(method_exists($manager,'register')){$manager->register($tag);}elseif(method_exists($manager,'register_tag')){$manager->register_tag($tag);}}
}
