<?php
namespace ETG\DynamicFilterSEOBridge\Elementor\DynamicTags;

final class ContentSlotImageTag extends \Elementor\Core\DynamicTags\Data_Tag {
    use PreviewContextTrait;

    public function get_name(){return'etg-content-slot-image';}
    public function get_title(){return'ETG Content Slot Image';}
    public function get_group(){return'etg-dfsb';}
    public function get_categories(){return array(\Elementor\Modules\DynamicTags\Module::IMAGE_CATEGORY);}

    protected function register_controls(){
        $options=array(''=>'— Select Image Slot —');
        $slots=DynamicTagRuntime::slots();if($slots){foreach($slots->all()as$slot){if('image'!==(string)($slot['type']??'')){continue;}$id=sanitize_key((string)($slot['id']??''));if($id){$options[$id]=(string)($slot['label']??$id).' ['.$id.']';}}}
        $this->add_control('slot_id',array('label'=>'Image Content Slot','type'=>\Elementor\Controls_Manager::SELECT,'options'=>$options,'default'=>'','description'=>'Uses the slot source/fallback chain, including taxonomy meta, listing meta, repeater, Query Builder and related posts.'));
        $this->etgRegisterPreviewControl();
    }

    public function get_value(array $options=array()){
        $id=sanitize_key((string)$this->get_settings('slot_id'));if(''===$id){return array('id'=>0,'url'=>'');}$resolver=DynamicTagRuntime::resolver();return$resolver?$resolver->slotImage($id,$this->etgPreviewContext()):array('id'=>0,'url'=>'');
    }
}
