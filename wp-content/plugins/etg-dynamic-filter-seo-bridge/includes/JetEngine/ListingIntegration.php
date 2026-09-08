<?php
namespace ETG\DynamicFilterSEOBridge\JetEngine;

require_once dirname(__DIR__) . '/Identifiers/FieldKey.php';

use ETG\DynamicFilterSEOBridge\Identifiers\FieldKey;
use ETG\DynamicFilterSEOBridge\Presentation\PresentationResolver;

final class ListingIntegration {
    const MAX_MACRO_TERMS=20;

    private $presentation;
    public function __construct(PresentationResolver $presentation){$this->presentation=$presentation;}

    public function register():void{
        add_action('jet-engine/register-macros',array($this,'registerMacros'));
        add_filter('jet-engine/listings/dynamic-repeater/pre-get-saved',array($this,'repeaterRows'),999999,2);
        add_filter('jet-engine/listings/allowed-context-list',array($this,'allowedContexts'));
        add_filter('jet-engine/listings/data/object-by-context/etg-filter-context',array($this,'filterContextObject'));
    }

    public function registerMacros():void{
        if(!class_exists('\\Jet_Engine_Base_Macros')){return;}
        $slotMacro=new class extends \Jet_Engine_Base_Macros {
            public $resolver;public function macros_tag(){return'etg_content_slot';}public function macros_name(){return'ETG Content Slot';}
            public function macros_args(){return array('slot_id'=>array('label'=>'Content Slot ID','type'=>'text','default'=>''));}
            public function macros_callback($args=array()){if(!$this->resolver){return'';}$id=sanitize_key((string)($args['slot_id']??''));return$id?$this->resolver->slot($id):'';}
        };$slotMacro->resolver=$this->presentation;

        $valueMacro=new class extends \Jet_Engine_Base_Macros {
            public $resolver;public function macros_tag(){return'etg_filter_value';}public function macros_name(){return'ETG Filter Value';}
            public function macros_args(){return array('token'=>array('label'=>'ETG Token','type'=>'text','default'=>'title'));}
            public function macros_callback($args=array()){if(!$this->resolver){return'';}$token=trim((string)($args['token']??''));if(''===$token||!preg_match('/^[A-Za-z0-9_:\-.]+$/',$token)){return'';}$value=$this->resolver->value($token);return is_scalar($value)?(string)$value:'';}
        };$valueMacro->resolver=$this->presentation;

        $termIdMacro=new class extends \Jet_Engine_Base_Macros {
            public $resolver;public function macros_tag(){return'etg_filter_term_id';}public function macros_name(){return'ETG Selected Term ID';}
            public function macros_args(){return array('role'=>array('label'=>'Taxonomy Role','type'=>'text','default'=>'location'));}
            public function macros_callback($args=array()){if(!$this->resolver){return'';}$role=sanitize_key((string)($args['role']??''));$c=$this->resolver->context();$term=(array)($c['terms'][$role]??array());return$role?(string)(int)($term['term_id']??0):'';}
        };$termIdMacro->resolver=$this->presentation;

        $termSlugMacro=new class extends \Jet_Engine_Base_Macros {
            public $resolver;public function macros_tag(){return'etg_filter_term_slug';}public function macros_name(){return'ETG Selected Term Slug';}
            public function macros_args(){return array('role'=>array('label'=>'Taxonomy Role','type'=>'text','default'=>'location'));}
            public function macros_callback($args=array()){if(!$this->resolver){return'';}$role=sanitize_key((string)($args['role']??''));$c=$this->resolver->context();$term=(array)($c['terms'][$role]??array());return$role&&is_scalar($term['slug']??null)?(string)$term['slug']:'';}
        };$termSlugMacro->resolver=$this->presentation;

        $termIdsMacro=new class extends \Jet_Engine_Base_Macros {
            public $resolver;public function macros_tag(){return'etg_filter_term_ids';}public function macros_name(){return'ETG Selected Term IDs';}
            public function macros_args(){return array('role'=>array('label'=>'Taxonomy Role','type'=>'text','default'=>'location'),'separator'=>array('label'=>'Separator','type'=>'text','default'=>','));}
            public function macros_callback($args=array()){if(!$this->resolver){return'';}$role=sanitize_key((string)($args['role']??''));$separator=(string)($args['separator']??',');if(strlen($separator)>5){$separator=',';}$c=$this->resolver->context();$set=(array)($c['term_sets'][$role]??array());if(!$set&&isset($c['terms'][$role])){$set=array($c['terms'][$role]);}$ids=array();foreach(array_slice($set,0,20)as$term){$term=(array)$term;$id=(int)($term['term_id']??0);if($id){$ids[]=$id;}}return implode($separator,array_values(array_unique($ids)));}
        };$termIdsMacro->resolver=$this->presentation;

        $termSlugsMacro=new class extends \Jet_Engine_Base_Macros {
            public $resolver;public function macros_tag(){return'etg_filter_term_slugs';}public function macros_name(){return'ETG Selected Term Slugs';}
            public function macros_args(){return array('role'=>array('label'=>'Taxonomy Role','type'=>'text','default'=>'location'),'separator'=>array('label'=>'Separator','type'=>'text','default'=>','));}
            public function macros_callback($args=array()){if(!$this->resolver){return'';}$role=sanitize_key((string)($args['role']??''));$separator=(string)($args['separator']??',');if(strlen($separator)>5){$separator=',';}$c=$this->resolver->context();$set=(array)($c['term_sets'][$role]??array());if(!$set&&isset($c['terms'][$role])){$set=array($c['terms'][$role]);}$values=array();foreach(array_slice($set,0,20)as$term){$term=(array)$term;$slug=$term['slug']??'';if(is_scalar($slug)&&''!==trim((string)$slug)){$values[]=trim((string)$slug);}}return implode($separator,array_values(array_unique($values)));}
        };$termSlugsMacro->resolver=$this->presentation;

        $termMetaMacro=new class extends \Jet_Engine_Base_Macros {
            public $resolver;public function macros_tag(){return'etg_filter_term_meta';}public function macros_name(){return'ETG Selected Term Meta';}
            public function macros_args(){return array('role'=>array('label'=>'Taxonomy Role','type'=>'text','default'=>'location'),'meta_key'=>array('label'=>'Meta Key','type'=>'text','default'=>''));}
            public function macros_callback($args=array()){if(!$this->resolver){return'';}$role=sanitize_key((string)($args['role']??''));$key=\ETG\DynamicFilterSEOBridge\Identifiers\FieldKey::normalize($args['meta_key']??'');if(!$role||!$key||!function_exists('get_term_meta')){return'';}$c=$this->resolver->context();$term=(array)($c['terms'][$role]??array());$id=(int)($term['term_id']??0);if(!$id){return'';}$value=get_term_meta($id,$key,true);return is_scalar($value)?(string)$value:'';}
        };$termMetaMacro->resolver=$this->presentation;

        $termMetaValuesMacro=new class extends \Jet_Engine_Base_Macros {
            public $resolver;public function macros_tag(){return'etg_filter_term_meta_values';}public function macros_name(){return'ETG Selected Term Meta Values';}
            public function macros_args(){return array('role'=>array('label'=>'Taxonomy Role','type'=>'text','default'=>'location'),'meta_key'=>array('label'=>'Meta Key','type'=>'text','default'=>''),'separator'=>array('label'=>'Separator','type'=>'text','default'=>','));}
            public function macros_callback($args=array()){if(!$this->resolver||!function_exists('get_term_meta')){return'';}$role=sanitize_key((string)($args['role']??''));$key=\ETG\DynamicFilterSEOBridge\Identifiers\FieldKey::normalize($args['meta_key']??'');$separator=(string)($args['separator']??',');if(strlen($separator)>5){$separator=',';}if(!$role||!$key){return'';}$c=$this->resolver->context();$set=(array)($c['term_sets'][$role]??array());if(!$set&&isset($c['terms'][$role])){$set=array($c['terms'][$role]);}$values=array();foreach(array_slice($set,0,20)as$term){$term=(array)$term;$id=(int)($term['term_id']??0);if(!$id){continue;}$v=get_term_meta($id,$key,true);if(is_scalar($v)&&''!==trim((string)$v)){$values[]=trim((string)$v);}}return implode($separator,array_values(array_unique($values)));}
        };$termMetaValuesMacro->resolver=$this->presentation;

        $rowsMacro=new class extends \Jet_Engine_Base_Macros {
            public $resolver;public function macros_tag(){return'etg_slot_rows';}public function macros_name(){return'ETG Content Slot Rows JSON';}
            public function macros_args(){return array('slot_id'=>array('label'=>'Content Slot ID','type'=>'text','default'=>''),'source_alias'=>array('label'=>'Source Alias','type'=>'text','default'=>''));}
            public function macros_callback($args=array()){if(!$this->resolver){return'';}$id=sanitize_key((string)($args['slot_id']??''));$alias=sanitize_key((string)($args['source_alias']??''));if(!$id||!$alias){return'';}$rows=$this->resolver->slotRows($id,$alias);$json=function_exists('wp_json_encode')?wp_json_encode($rows):json_encode($rows);return is_string($json)?$json:'';}
        };$rowsMacro->resolver=$this->presentation;
    }

    public function allowedContexts($contexts):array{$contexts=is_array($contexts)?$contexts:array();$contexts['etg-filter-context']='ETG Filter Context (presentation only)';return$contexts;}

    public function filterContextObject($object=null){
        $c=$this->presentation->context();if(empty($c['active'])||empty($c['in_scope'])||empty($c['runtime_ready'])||empty($c['filters'])){return false;}
        $out=array('etg_context'=>true,'authorizing'=>false,'title'=>(string)$this->presentation->value('title',$c),'intro'=>(string)$this->presentation->value('intro',$c),'keyword'=>(string)$this->presentation->value('keyword',$c),'result_count'=>(string)$this->presentation->value('result_count',$c),'query_id'=>(string)($c['query_id']??''),'provider'=>(string)($c['provider']??''),'language'=>(string)($c['language']??''));
        $roles=array_unique(array_merge(array_keys((array)($c['terms']??array())),array_keys((array)($c['term_sets']??array()))));
        foreach($roles as$rawRole){$role=sanitize_key((string)$rawRole);if(!$role){continue;}$term=(array)($c['terms'][$role]??array());foreach(array('term_id','name','slug','description','short_description','seo_title','meta_description','focus_keyword','image_id','location_level')as$key){$value=$term[$key]??'';if(is_scalar($value)){$out[$role.'_'.$key]=$value;}}if(!empty($term['gallery_ids'])&&is_array($term['gallery_ids'])){$out[$role.'_gallery_ids']=implode(',',array_filter(array_map('absint',$term['gallery_ids'])));}
            $set=(array)($c['term_sets'][$role]??array());if(!$set&&$term){$set=array($term);}$ids=$slugs=array();foreach(array_slice($set,0,self::MAX_MACRO_TERMS)as$item){$item=(array)$item;$id=(int)($item['term_id']??0);if($id){$ids[]=$id;}$slug=$item['slug']??'';if(is_scalar($slug)&&''!==trim((string)$slug)){$slugs[]=trim((string)$slug);}}$out[$role.'_term_ids']=implode(',',array_values(array_unique($ids)));$out[$role.'_term_slugs']=implode(',',array_values(array_unique($slugs)));
        }
        return(object)$out;
    }

    public function repeaterRows($items,$settings){
        $settings=is_array($settings)?$settings:array();$slot='';$alias='';
        if(!empty($settings['etg_dfsb_slot'])&&!empty($settings['etg_dfsb_source'])){$slot=sanitize_key((string)$settings['etg_dfsb_slot']);$alias=sanitize_key((string)$settings['etg_dfsb_source']);}
        if(''===$slot||''===$alias){foreach(array('_css_classes','css_classes','class')as$key){$classes=(string)($settings[$key]??'');if(preg_match('/(?:^|\s)etg-source--([a-z0-9_-]+)--([a-z0-9_-]+)(?:\s|$)/i',$classes,$m)){$slot=sanitize_key($m[1]);$alias=sanitize_key($m[2]);break;}}}
        if(''===$slot||''===$alias){foreach(array('dynamic_field_source','source','repeater_field','field')as$key){$candidate=(string)($settings[$key]??'');if(preg_match('/^etg-slot:([a-z0-9_-]+):([a-z0-9_-]+)$/i',$candidate,$m)){$slot=sanitize_key($m[1]);$alias=sanitize_key($m[2]);break;}}}
        if(''===$slot||''===$alias){return$items;}$rows=$this->presentation->slotRows($slot,$alias);return$rows?:$items;
    }

    public static function repeaterMarker(string$slot,string$alias):string{return'etg-source--'.sanitize_key($slot).'--'.sanitize_key($alias);}
}
