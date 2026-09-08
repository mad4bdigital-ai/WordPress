<?php
namespace ETG\DynamicFilterSEOBridge\Elementor;

use ETG\DynamicFilterSEOBridge\Elementor\DynamicTags\ContentSlotTag;
use ETG\DynamicFilterSEOBridge\Elementor\DynamicTags\ContentSlotImageTag;
use ETG\DynamicFilterSEOBridge\Elementor\DynamicTags\ContentSlotGalleryTag;
use ETG\DynamicFilterSEOBridge\Elementor\DynamicTags\DynamicTagRuntime;
use ETG\DynamicFilterSEOBridge\Elementor\DynamicTags\FilterArchiveUrlTag;
use ETG\DynamicFilterSEOBridge\Elementor\DynamicTags\FilterCurrentUrlTag;
use ETG\DynamicFilterSEOBridge\Elementor\DynamicTags\FilterGalleryTag;
use ETG\DynamicFilterSEOBridge\Elementor\DynamicTags\FilterSlideshowTag;
use ETG\DynamicFilterSEOBridge\Elementor\DynamicTags\FilterImageTag;
use ETG\DynamicFilterSEOBridge\Elementor\DynamicTags\FilterImageUrlTag;
use ETG\DynamicFilterSEOBridge\Elementor\DynamicTags\FilterIntroTag;
use ETG\DynamicFilterSEOBridge\Elementor\DynamicTags\FilterKeywordTag;
use ETG\DynamicFilterSEOBridge\Elementor\DynamicTags\FilterResultSummaryTag;
use ETG\DynamicFilterSEOBridge\Elementor\DynamicTags\FilterTitleTag;
use ETG\DynamicFilterSEOBridge\Elementor\DynamicTags\InventoryValueTag;
use ETG\DynamicFilterSEOBridge\Elementor\DynamicTags\TermFieldTag;
use ETG\DynamicFilterSEOBridge\Elementor\DynamicTags\TermMetaTag;
use ETG\DynamicFilterSEOBridge\Elementor\DynamicTags\TermSectionTag;
use ETG\DynamicFilterSEOBridge\Presentation\ContentSlotRegistry;
use ETG\DynamicFilterSEOBridge\Presentation\PresentationResolver;

final class DynamicTagRegistrar {
    private $resolver;private $slots;private $catalogProvider;private $previewContextProvider;
    public function __construct(PresentationResolver$resolver,ContentSlotRegistry$slots,callable$catalogProvider,callable$previewContextProvider=null){$this->resolver=$resolver;$this->slots=$slots;$this->catalogProvider=$catalogProvider;$this->previewContextProvider=$previewContextProvider;}
    public function registerHooks():void{add_action('elementor/dynamic_tags/register',array($this,'register'));}
    public function register($manager):void{
        try{
        if(!class_exists('\\Elementor\\Core\\DynamicTags\\Tag')){return;}
        DynamicTagRuntime::configure($this->resolver,$this->slots,$this->catalogProvider,$this->previewContextProvider);
        // LiveBindingTrait owns the emitted data-etg-dfsb-token and data-etg-dfsb-slot
        // attributes. Registrar's contract is to keep every live-capable named tag
        // connected to that trait/runtime without constructor dependency drift.
        if(method_exists($manager,'register_group')){$manager->register_group('etg-dfsb',array('title'=>'ETG Filter SEO'));}
        $classes=array(FilterTitleTag::class,FilterIntroTag::class,FilterResultSummaryTag::class,FilterKeywordTag::class,FilterArchiveUrlTag::class,FilterCurrentUrlTag::class,InventoryValueTag::class,ContentSlotTag::class,TermFieldTag::class,TermMetaTag::class,TermSectionTag::class);
        $mediaAvailable=class_exists('\\Elementor\\Core\\DynamicTags\\Data_Tag')&&class_exists('\\Elementor\\Modules\\DynamicTags\\Module')&&$this->dataTagCompatible();
        if($mediaAvailable){$classes[]=FilterImageTag::class;$classes[]=FilterImageUrlTag::class;$classes[]=FilterGalleryTag::class;$classes[]=FilterSlideshowTag::class;$classes[]=ContentSlotImageTag::class;$classes[]=ContentSlotGalleryTag::class;}
        foreach($classes as$class){if(!class_exists($class)){continue;}$this->registerTagClass($manager,$class);}
        }catch(\Throwable$e){\ETG\DynamicFilterSEOBridge\Runtime\BootGuard::recordThrowable('elementor_dynamic_tags',$e);}
    }
    private function dataTagCompatible():bool{
        try{$class='Elementor\\Core\\DynamicTags\\Data_Tag';if(!method_exists($class,'get_value')){return true;}$method=new \ReflectionMethod($class,'get_value');if($method->hasReturnType()){return false;}if($method->getNumberOfRequiredParameters()>0||$method->getNumberOfParameters()>1){return false;}$params=$method->getParameters();if($params&&$params[0]->hasType()&&(string)$params[0]->getType()!=='array'){return false;}return true;}catch(\Throwable$e){return false;}
    }
    private function registerTagClass($manager,string$class):void{if(method_exists($manager,'register')){$manager->register(new$class());return;}if(method_exists($manager,'register_tag')){$manager->register_tag($class);}}
}
