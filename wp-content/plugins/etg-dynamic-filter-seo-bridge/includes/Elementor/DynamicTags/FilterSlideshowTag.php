<?php
namespace ETG\DynamicFilterSEOBridge\Elementor\DynamicTags;

final class FilterSlideshowTag extends \Elementor\Core\DynamicTags\Data_Tag {
    use PreviewContextTrait;

    public function get_name(){ return 'etg-filter-slideshow'; }
    public function get_title(){ return 'ETG Filter Slideshow'; }
    public function get_group(){ return 'etg-dfsb'; }
    public function get_categories(){ return array(\Elementor\Modules\DynamicTags\Module::GALLERY_CATEGORY); }

    protected function register_controls(){
        $roles = DynamicTagRuntime::roleOptions();
        $modes = array(
            'balanced'=>'Balanced Across Active Terms',
            'combined'=>'Combined Media (All Terms)',
            'all_terms'=>'All Active Terms',
            'galleries_only'=>'Gallery Fields Only',
            'primary_images'=>'One Primary Image Per Term',
            'priority'=>'Profile Gallery Priority',
            'role_priority'=>'Combined By Role Priority',
        );
        foreach($roles as$role=>$label){$modes[$role]=$label.' only';}
        $this->add_control('mode',array('label'=>'Slideshow Collection Mode','type'=>\Elementor\Controls_Manager::SELECT,'options'=>$modes,'default'=>'balanced','description'=>'Returns an Elementor-native Gallery value suitable for Slideshow/Carousel controls. Autoplay, transition and timing stay under Elementor.'));
        $this->add_control('limit',array('label'=>'Maximum Slides','type'=>\Elementor\Controls_Manager::NUMBER,'min'=>1,'max'=>30,'default'=>12));
        $this->add_control('minimum',array('label'=>'Minimum Slides','type'=>\Elementor\Controls_Manager::NUMBER,'min'=>1,'max'=>30,'default'=>2,'description'=>'If the selected mode produces fewer images, ETG falls back to Combined Media.'));
        $this->etgRegisterPreviewControl();
    }

    public function get_value(array$options=array()){
        $resolver=DynamicTagRuntime::resolver();if(!$resolver){return array();}
        $limit=max(1,min(30,(int)$this->get_settings('limit')));$minimum=max(1,min($limit,(int)$this->get_settings('minimum')));
        $context=$this->etgPreviewContext();$mode=(string)$this->get_settings('mode');$items=$resolver->gallery($mode,$context,$limit);
        if(count($items)<$minimum&&'combined'!==$mode){$items=$resolver->gallery('combined',$context,$limit);}
        return$items;
    }
}
