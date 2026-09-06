<?php
namespace ETG\DynamicFilterSEOBridge\JetEngine;

final class FieldDiscovery {
    const MAX_FIELDS=500;
    const MAX_NESTED_DEPTH=5;

    private $normalizer;
    private $listing;

    public function __construct(ValueNormalizer $normalizer,ListingContextResolver $listing){$this->normalizer=$normalizer;$this->listing=$listing;}

    public function catalog():array{
        $fields=array();
        foreach($this->jetEngineMetaFields()as$field){$this->add($fields,$field);}
        foreach($this->currentObjectFields()as$field){$this->add($fields,$field);}
        foreach($this->cctFields()as$field){$this->add($fields,$field);}
        ksort($fields,SORT_STRING);
        $repeaters=$media=$gallery=array();
        foreach($fields as$key=>$field){$type=(string)($field['kind']??'scalar');if('repeater'===$type){$repeaters[$key]=$field;}elseif('gallery'===$type){$gallery[$key]=$field;$media[$key]=$field;}elseif('media'===$type){$media[$key]=$field;}}
        return array('contract'=>'etg.dfsb.jetengine-field-discovery.v1','authorizing'=>false,'read_only'=>true,'field_count'=>count($fields),'fields'=>$fields,'repeaters'=>$repeaters,'media'=>$media,'galleries'=>$gallery,'cct'=>$this->cctTypes());
    }

    private function jetEngineMetaFields():array{
        if(!function_exists('jet_engine')){return array();}
        try{
            $engine=jet_engine();if(!is_object($engine)||!isset($engine->meta_boxes)||!method_exists($engine->meta_boxes,'get_fields_for_select')){return array();}
            $raw=(array)$engine->meta_boxes->get_fields_for_select('plain');$out=array();
            foreach($raw as$key=>$field){
                if(is_array($field)){$name=(string)($field['value']??$field['name']??$field['id']??$key);$label=(string)($field['label']??$field['title']??$name);$type=(string)($field['type']??'');}
                else{$name=is_string($key)?$key:(string)$field;$label=(string)$field;$type='';}
                $name=$this->key($name);if(''===$name||$this->sensitive($name)){continue;}$out[]=array('key'=>$name,'label'=>sanitize_text_field($label?:$name),'source'=>'jetengine_meta_box','kind'=>$this->kind($name,$type,null),'path'=>$name,'authorizing'=>false);
            }
            return$out;
        }catch(\Throwable$e){return array();}
    }

    private function currentObjectFields():array{
        $object=$this->listing->currentObject();if(!$object){return array();}$data=is_object($object)?get_object_vars($object):(is_array($object)?$object:array());$out=array();
        foreach(array_slice($data,0,self::MAX_FIELDS,true)as$key=>$value){$key=$this->key((string)$key);if(''===$key||$this->sensitive($key)){continue;}$out[]=array('key'=>$key,'label'=>ucwords(str_replace(array('_','-'),' ',$key)),'source'=>'current_listing_object','kind'=>$this->kind($key,'',$value),'path'=>$key,'authorizing'=>false);if(is_array($value)){$this->nested($out,$key,$value,1);}}
        return$out;
    }

    private function cctTypes():array{
        if(!class_exists('\\Jet_Engine\\Modules\\Custom_Content_Types\\Module')){return array();}
        try{$manager=\Jet_Engine\Modules\Custom_Content_Types\Module::instance()->manager;if(!is_object($manager)||!method_exists($manager,'get_content_types')){return array();}$types=(array)$manager->get_content_types();$out=array();foreach($types as$slug=>$instance){$slug=$this->key((string)$slug);if(''===$slug||!is_object($instance)){continue;}$label=method_exists($instance,'get_arg')?(string)$instance->get_arg('name'):$slug;$out[$slug]=array('slug'=>$slug,'label'=>sanitize_text_field($label?:$slug),'authorizing'=>false,'read_only'=>true);}ksort($out,SORT_STRING);return$out;}catch(\Throwable$e){return array();}
    }

    private function cctFields():array{
        if(!class_exists('\\Jet_Engine\\Modules\\Custom_Content_Types\\Module')){return array();}
        try{$manager=\Jet_Engine\Modules\Custom_Content_Types\Module::instance()->manager;if(!is_object($manager)||!method_exists($manager,'get_content_types')){return array();}$out=array();foreach((array)$manager->get_content_types()as$slug=>$instance){if(!is_object($instance)||!method_exists($instance,'get_fields_list')){continue;}$slug=$this->key((string)$slug);$fields=(array)$instance->get_fields_list('all','blocks');foreach($fields as$key=>$field){$this->flattenCctField($out,$slug,$key,$field,0);}}return$out;}catch(\Throwable$e){return array();}
    }

    private function flattenCctField(array&$out,string$slug,$key,$field,int$depth):void{
        if($depth>self::MAX_NESTED_DEPTH||count($out)>=self::MAX_FIELDS){return;}$name='';$label='';$type='';$children=array();
        if(is_array($field)){$name=(string)($field['name']??$field['id']??$field['value']??$key);$label=(string)($field['title']??$field['label']??$name);$type=(string)($field['type']??'');$children=(array)($field['fields']??$field['repeater-fields']??$field['repeater_fields']??array());}else{$name=is_string($key)?$key:(string)$field;$label=(string)$field;}
        $name=$this->key($name);if(''===$name||$this->sensitive($name)){return;}$path='cct:'.$slug.':'.$name;$out[]=array('key'=>$name,'label'=>sanitize_text_field(($slug?'['.$slug.'] ':'').($label?:$name)),'source'=>'jetengine_cct','cct'=>$slug,'kind'=>$this->kind($name,$type,null),'path'=>$path,'authorizing'=>false);
        foreach($children as$childKey=>$child){$this->flattenCctField($out,$slug,$childKey,$child,$depth+1);}
    }

    private function nested(array&$out,string$prefix,array$value,int$depth):void{
        if($depth>self::MAX_NESTED_DEPTH||count($out)>=self::MAX_FIELDS){return;}foreach(array_slice($value,0,30,true)as$key=>$child){if(!is_string($key)){continue;}$key=$this->key($key);if(''===$key||$this->sensitive($key)){continue;}$path=$prefix.'.'.$key;$out[]=array('key'=>$key,'label'=>ucwords(str_replace(array('_','-'),' ',$path)),'source'=>'current_listing_object','kind'=>$this->kind($key,'',$child),'path'=>$path,'authorizing'=>false);if(is_array($child)){$this->nested($out,$path,$child,$depth+1);}}
    }

    private function add(array&$fields,array$field):void{if(count($fields)>=self::MAX_FIELDS){return;}$path=(string)($field['path']??$field['key']??'');if(''===$path){return;}$id=(string)($field['source']??'source').':'.$path;if(!isset($fields[$id])){$fields[$id]=$field;}}
    private function kind(string$key,string$type,$value):string{$needle=strtolower($key.' '.$type);if(false!==strpos($needle,'repeater')){return'repeater';}if(false!==strpos($needle,'gallery')||false!==strpos($needle,'slides')||false!==strpos($needle,'images')){return'gallery';}if(preg_match('/(?:image|photo|thumbnail|avatar|logo|icon|media)/',$needle)){return'media';}$decoded=$this->normalizer->decode($value);if(is_array($decoded)){foreach($decoded as$item){if(is_array($item)||is_object($item)){return'repeater';}}return'complex';}return'scalar';}
    private function key(string$key):string{$key=trim($key);if(strlen($key)>120){$key=substr($key,0,120);}return preg_match('/^[A-Za-z0-9_-]+$/',$key)?$key:'';}
    private function sensitive(string$key):bool{return(bool)preg_match('/(?:password|passwd|secret|token|api[_-]?key|credential|auth[_-]?key|nonce|session)/i',$key);}
}
