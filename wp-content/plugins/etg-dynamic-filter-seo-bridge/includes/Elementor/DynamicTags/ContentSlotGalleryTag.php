<?php
namespace ETG\DynamicFilterSEOBridge\Elementor\DynamicTags;

final class ContentSlotGalleryTag extends \Elementor\Core\DynamicTags\Data_Tag {
    use PreviewContextTrait;

    public function get_name(){return'etg-content-slot-gallery';}
    public function get_title(){return'ETG Content Slot Gallery';}
    public function get_group(){return'etg-dfsb';}
    public function get_categories(){return array(\Elementor\Modules\DynamicTags\Module::GALLERY_CATEGORY);}

    protected function register_controls(){
        $options=array(''=>'— Select Gallery Slot —');
        $slots=DynamicTagRuntime::slots();if($slots){foreach($slots->all()as$slot){if('gallery'!==(string)($slot['type']??'')){continue;}$id=sanitize_key((string)($slot['id']??''));if($id){$options[$id]=(string)($slot['label']??$id).' ['.$id.']';}}}
        $this->add_control('slot_id',array('label'=>'Gallery Content Slot','type'=>\Elementor\Controls_Manager::SELECT,'options'=>$options,'default'=>'','description'=>'Produces an Elementor-native gallery from the configured source/fallback chain.'));
        $this->add_control('limit',array('label'=>'Maximum Images','type'=>\Elementor\Controls_Manager::NUMBER,'min'=>1,'max'=>30,'default'=>9));
        $this->etgRegisterPreviewControl();
    }

    public function get_value(array $options=array()){
        $id=sanitize_key((string)$this->get_settings('slot_id'));if(''===$id){return array();}$resolver=DynamicTagRuntime::resolver();return$resolver?$resolver->slotGallery($id,$this->etgPreviewContext(),(int)$this->get_settings('limit')):array();
    }
}
