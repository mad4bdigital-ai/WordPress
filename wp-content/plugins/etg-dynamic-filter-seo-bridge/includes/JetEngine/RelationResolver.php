<?php
namespace ETG\DynamicFilterSEOBridge\JetEngine;

require_once dirname(__DIR__) . '/Identifiers/FieldKey.php';

use ETG\DynamicFilterSEOBridge\Identifiers\FieldKey;

final class RelationResolver {
    const MAX_ITEMS=100;

    private $optionsCache=null;
    private $itemsCache=array();
    private $metaCache=array();

    public function options():array{
        if(null!==$this->optionsCache){return$this->optionsCache;}
        $this->optionsCache=array();
        if(!function_exists('jet_engine')){return$this->optionsCache;}
        try{
            $engine=jet_engine();
            if(!is_object($engine)||!isset($engine->relations)||!method_exists($engine->relations,'get_active_relations')){return$this->optionsCache;}
            $relations=(array)$engine->relations->get_active_relations();
            foreach($relations as$key=>$relation){
                if(!is_object($relation)||!method_exists($relation,'get_args')){continue;}
                $id=(int)$relation->get_args('id');if(!$id&&is_numeric($key)){$id=(int)$key;}if(!$id){continue;}
                $parent=(string)$relation->get_args('parent_object');$child=(string)$relation->get_args('child_object');
                $name=(string)$relation->get_args('name');if(''===$name){$name=$parent.' → '.$child;}
                $metaFields=array();foreach((array)$relation->get_args('meta_fields')as$field){if(!is_array($field)){continue;}$metaKey=FieldKey::normalize($field['name']??$field['id']??'');if(''===$metaKey){continue;}$metaFields[$metaKey]=sanitize_text_field((string)($field['title']??$field['label']??$metaKey));}
                $this->optionsCache[$id]=array('id'=>$id,'label'=>sanitize_text_field($name),'parent_object'=>$parent,'child_object'=>$child,'meta_fields'=>$metaFields,'authorizing'=>false,'read_only'=>true);
            }
            ksort($this->optionsCache,SORT_NUMERIC);
        }catch(\Throwable $e){}
        return$this->optionsCache;
    }

    public function items(int$relationId,int$objectId,string$direction='children',int$limit=30):array{
        $relationId=max(0,$relationId);$objectId=max(0,$objectId);$limit=max(1,min(self::MAX_ITEMS,$limit));$direction='parents'===$direction?'parents':'children';
        if(!$relationId||!$objectId){return array();}
        $cacheKey=$relationId.':'.$objectId.':'.$direction.':'.$limit;if(isset($this->itemsCache[$cacheKey])){return$this->itemsCache[$cacheKey];}
        $relation=$this->relation($relationId);if(!$relation){return$this->itemsCache[$cacheKey]=array();}
        $method='parents'===$direction?'get_parents':'get_children';if(!method_exists($relation,$method)){return$this->itemsCache[$cacheKey]=array();}
        try{$ids=$relation->{$method}($objectId,'ids');}catch(\Throwable$e){$ids=array();}
        if(!is_array($ids)){return$this->itemsCache[$cacheKey]=array();}
        $descriptor=$this->options();$meta=(array)($descriptor[$relationId]??array());$objectType='parents'===$direction?(string)($meta['parent_object']??''):(string)($meta['child_object']??'');
        $out=array();foreach(array_slice($ids,0,$limit)as$id){$id=absint($id);if(!$id){continue;}$item=$this->hydrate($id,$objectType);if(null!==$item){$out[]=$item;}}
        return$this->itemsCache[$cacheKey]=$out;
    }

    public function metaValues(int$relationId,int$objectId,string$direction,string$metaKey,int$limit=30):array{
        $relationId=max(0,$relationId);$objectId=max(0,$objectId);$limit=max(1,min(self::MAX_ITEMS,$limit));$direction='parents'===$direction?'parents':'children';$metaKey=FieldKey::normalize($metaKey);
        if(!$relationId||!$objectId||''===$metaKey){return array();}
        $cacheKey=$relationId.':'.$objectId.':'.$direction.':'.$metaKey.':'.$limit;if(isset($this->metaCache[$cacheKey])){return$this->metaCache[$cacheKey];}
        $relation=$this->relation($relationId);if(!$relation||!method_exists($relation,'get_meta')){return$this->metaCache[$cacheKey]=array();}
        $method='parents'===$direction?'get_parents':'get_children';if(!method_exists($relation,$method)){return$this->metaCache[$cacheKey]=array();}
        try{$ids=$relation->{$method}($objectId,'ids');}catch(\Throwable$e){$ids=array();}
        $values=array();foreach(array_slice((array)$ids,0,$limit)as$relatedId){$relatedId=absint($relatedId);if(!$relatedId){continue;}$parent='parents'===$direction?$relatedId:$objectId;$child='parents'===$direction?$objectId:$relatedId;try{$value=$relation->get_meta($parent,$child,$metaKey);}catch(\Throwable$e){$value='';}if(null!==$value&&false!==$value&&''!==$value){$values[]=$value;}}
        return$this->metaCache[$cacheKey]=$values;
    }

    private function relation(int$id){
        if(!$id||!function_exists('jet_engine')){return null;}
        try{$engine=jet_engine();if(!is_object($engine)||!isset($engine->relations)||!method_exists($engine->relations,'get_active_relations')){return null;}$relation=$engine->relations->get_active_relations($id);return is_object($relation)?$relation:null;}catch(\Throwable$e){return null;}
    }

    private function hydrate(int$id,string$type){
        if(!$id){return null;}
        if(0===strpos($type,'posts::')&&function_exists('get_post')){return get_post($id);}
        if(0===strpos($type,'terms::')&&function_exists('get_term')){$taxonomy=substr($type,7);return get_term($id,$taxonomy?:'');}
        if(('mix::users'===$type||0===strpos($type,'users::'))&&function_exists('get_user_by')){return get_user_by('id',$id);}
        if(0===strpos($type,'cct::')&&class_exists('\\Jet_Engine\\Modules\\Custom_Content_Types\\Module')){
            $slug=substr($type,5);
            try{$module=\Jet_Engine\Modules\Custom_Content_Types\Module::instance();$manager=is_object($module)&&isset($module->manager)?$module->manager:null;$contentType=is_object($manager)&&method_exists($manager,'get_content_types')?$manager->get_content_types($slug):null;if(is_object($contentType)&&isset($contentType->db)&&is_object($contentType->db)&&method_exists($contentType->db,'query')){$args=array(array('field'=>'_ID','operator'=>'=','value'=>$id,'type'=>'integer'));$rows=$contentType->db->query($args,1,0,array());if(is_array($rows)&&$rows){return reset($rows);}}}catch(\Throwable$e){}
        }
        $fallback=array('_ID'=>$id,'id'=>$id,'ID'=>$id,'object_type'=>$type);
        return function_exists('apply_filters')?apply_filters('etg_dfsb_jetengine_relation_item',$fallback,$id,$type):$fallback;
    }
}
