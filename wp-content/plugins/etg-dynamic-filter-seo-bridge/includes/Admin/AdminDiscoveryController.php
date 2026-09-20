<?php
namespace ETG\DynamicFilterSEOBridge\Admin;

use ETG\DynamicFilterSEOBridge\Config\ProfileRegistry;
use ETG\DynamicFilterSEOBridge\Identifiers\FieldKey;
use ETG\DynamicFilterSEOBridge\Presentation\ContentSlotRegistry;
use ETG\DynamicFilterSEOBridge\Presentation\ContentSourceResolver;
use ETG\DynamicFilterSEOBridge\Presentation\MediaInspector;

final class AdminDiscoveryController {
    const NONCE_ACTION='etg_dfsb_admin_discovery';
    const CATALOG_ACTION='etg_dfsb_fetch_metadata';
    const MEDIA_SLOTS_ACTION='etg_dfsb_fetch_media_slots';
    const SAVE_MEDIA_MODE_ACTION='etg_dfsb_save_media_slot_mode';
    const MAX_ITEMS=160;

    private $inspector;private $sources;private $profiles;private $slots;

    public function __construct(MediaInspector$inspector,ContentSourceResolver$sources,ProfileRegistry$profiles,ContentSlotRegistry$slots){$this->inspector=$inspector;$this->sources=$sources;$this->profiles=$profiles;$this->slots=$slots;}

    public function register():void{
        add_action('wp_ajax_'.self::CATALOG_ACTION,array($this,'catalog'));
        add_action('wp_ajax_'.self::MEDIA_SLOTS_ACTION,array($this,'mediaSlots'));
        add_action('wp_ajax_'.self::SAVE_MEDIA_MODE_ACTION,array($this,'saveMediaMode'));
    }

    public function catalog():void{
        $this->guard();
        $sourceType=sanitize_key((string)($_POST['source_type']??'term_meta'));
        $role=sanitize_key((string)($_POST['role']??''));
        $taxonomy=sanitize_key((string)($_POST['taxonomy']??''));
        $purpose=sanitize_key((string)($_POST['purpose']??'meta'));
        $items=array();$scopes=array();$reason='ready';

        if($taxonomy){$items=$this->taxonomyItems($taxonomy,$sourceType);$scopes[]=$taxonomy;}
        elseif(in_array($sourceType,array('term_meta','repeater'),true)&&$role){
            foreach($this->roleTaxonomies($role)as$tax){$items=array_merge($items,$this->taxonomyItems($tax,$sourceType));$scopes[]=$tax;}
            if(!$scopes){$reason='role_taxonomy_unavailable';}
        }elseif(in_array($sourceType,array('listing_meta','listing_field','repeater'),true)){
            $items=$this->jetEngineItems($sourceType,$purpose);$scopes[]='jetengine_runtime';
        }elseif('relation_meta'===$sourceType){$reason='relation_meta_requires_runtime_edge_context';}
        else{$items=$this->jetEngineItems($sourceType,$purpose);$scopes[]='jetengine_runtime';}

        $items=$this->dedupe($items);if(!$items&&'ready'===$reason){$reason='no_fetchable_metadata';}
        wp_send_json_success(array(
            'contract'=>'etg.dfsb.admin-metadata-fetch.v1','authorizing'=>false,'read_only'=>true,
            'source_type'=>$sourceType,'role'=>$role,'taxonomy'=>$taxonomy,'purpose'=>$purpose,
            'scopes'=>array_values(array_unique($scopes)),'reason'=>$reason,'count'=>count($items),'items'=>array_slice($items,0,self::MAX_ITEMS)
        ));
    }

    public function mediaSlots():void{
        $this->guard();$rows=array();
        foreach($this->slots->all()as$id=>$slot){$type=(string)($slot['type']??'');if(!in_array($type,array('image','gallery'),true)){continue;}$rows[]=array(
            'id'=>(string)$id,'label'=>(string)($slot['label']??$id),'type'=>$type,'origin'=>(string)($slot['origin']??'custom'),
            'mode'=>(string)($slot['media_mode']??('image'===$type?'priority':'combined')),'enabled'=>!empty($slot['enabled']),
            'authorizing'=>false
        );}
        wp_send_json_success(array('contract'=>'etg.dfsb.admin-media-slots.v1','authorizing'=>false,'read_only'=>true,'modes'=>ContentSlotRegistry::mediaModes(),'slots'=>$rows));
    }

    public function saveMediaMode():void{
        $this->guard();$id=sanitize_key((string)($_POST['slot_id']??''));$mode=sanitize_key((string)($_POST['media_mode']??''));
        if(''===$id||!isset(ContentSlotRegistry::mediaModes()[$mode])){wp_send_json_error(array('reason'=>'invalid_media_slot_mode'),400);}
        $slot=$this->slots->get($id);if(!$slot||!in_array((string)($slot['type']??''),array('image','gallery'),true)){wp_send_json_error(array('reason'=>'media_slot_unavailable'),404);}
        $slot['media_mode']=$mode;$result=$this->slots->save($slot);
        if(empty($result['saved'])){wp_send_json_error(array('reason'=>(string)($result['reason']??'save_failed')),500);}
        wp_send_json_success(array('contract'=>'etg.dfsb.admin-media-slot-mode.v1','authorizing'=>false,'profile_mutation'=>false,'slot_id'=>$id,'media_mode'=>$mode,'origin'=>(string)($result['slot']['origin']??'')));
    }

    private function taxonomyItems(string$taxonomy,string$sourceType):array{
        $scan=$this->inspector->scanTaxonomyMetadata($taxonomy,15,false);$out=array();
        foreach((array)($scan['fields']??array())as$key=>$row){$key=FieldKey::normalize($key);if(''===$key){continue;}$kind=(string)($row['kind']??'scalar');
            if('repeater'===$sourceType&&!in_array($kind,array('repeater','complex','gallery','media'),true)){continue;}
            $out[]=array(
                'value'=>$key,'key'=>$key,'label'=>(string)($row['label']??$key),'kind'=>$kind,'source'=>'taxonomy_meta','taxonomy'=>$taxonomy,
                'term_hits'=>(int)($row['term_hits']??0),'sample_terms'=>array_slice((array)($row['sample_terms']??array()),0,5),
                'sample_values'=>array_slice((array)($row['sample_values']??array()),0,5),'media_ids'=>array_slice((array)($row['media_ids']??array()),0,12),
                'confidence'=>(string)($row['confidence']??'observed'),'configured_as'=>array_values((array)($row['configured_as']??array())),
                'recommended'=>in_array($kind,array('gallery','media','repeater'),true),'authorizing'=>false
            );
        }return$out;
    }

    private function jetEngineItems(string$sourceType,string$purpose):array{
        $catalog=$this->sources->catalog();$discovery=(array)($catalog['field_discovery']??array());
        $fields=(array)($discovery['fields']??array());if('repeater'===$sourceType){$fields=(array)($discovery['repeaters']??array());}
        $out=array();foreach($fields as$field){if(!is_array($field)){continue;}$key=FieldKey::normalize($field['key']??'');$path=(string)($field['path']??$key);if(''===$key){continue;}
            $value='field'===$purpose&&'listing_field'===$sourceType?$path:$key;
            $out[]=array('value'=>$value,'key'=>$key,'path'=>$path,'label'=>(string)($field['label']??$key),'kind'=>(string)($field['kind']??'scalar'),'source'=>(string)($field['source']??'jetengine'),'taxonomy'=>'','term_hits'=>0,'sample_terms'=>array(),'sample_values'=>array(),'media_ids'=>array(),'confidence'=>'discovered','configured_as'=>array(),'recommended'=>in_array((string)($field['kind']??''),array('gallery','media','repeater'),true),'authorizing'=>false);
        }return$out;
    }

    private function roleTaxonomies(string$role):array{
        $out=array();foreach($this->profiles->all()as$profile){foreach((array)($profile['taxonomy_rules']??array())as$taxonomy=>$rule){$candidate=sanitize_key((string)(is_array($rule)?($rule['role']??$taxonomy):$taxonomy));if($candidate===$role){$taxonomy=sanitize_key((string)$taxonomy);if($taxonomy&&!in_array($taxonomy,$out,true)){$out[]=$taxonomy;}}}}return$out;
    }

    private function dedupe(array$items):array{
        $out=array();foreach($items as$item){if(!is_array($item)){continue;}$value=(string)($item['value']??'');if(''===$value){continue;}$id=$value;if(!isset($out[$id])){$item['taxonomies']=array_filter(array((string)($item['taxonomy']??'')));$out[$id]=$item;continue;}
            $existing=&$out[$id];$tax=(string)($item['taxonomy']??'');if($tax&&!in_array($tax,(array)($existing['taxonomies']??array()),true)){$existing['taxonomies'][]=$tax;}
            $existing['term_hits']=(int)($existing['term_hits']??0)+(int)($item['term_hits']??0);
            $existing['sample_terms']=array_slice(array_values(array_unique(array_merge((array)($existing['sample_terms']??array()),(array)($item['sample_terms']??array())))),0,5);
            $existing['sample_values']=array_slice(array_values(array_unique(array_merge((array)($existing['sample_values']??array()),(array)($item['sample_values']??array())))),0,5);
            $existing['media_ids']=array_slice(array_values(array_unique(array_merge((array)($existing['media_ids']??array()),(array)($item['media_ids']??array())))),0,12);
            $existing['recommended']=!empty($existing['recommended'])||!empty($item['recommended']);unset($existing);
        }
        uasort($out,static function($a,$b){$rec=((int)!empty($b['recommended']))<=>((int)!empty($a['recommended']));if(0!==$rec){return$rec;}$hits=((int)($b['term_hits']??0))<=>((int)($a['term_hits']??0));return 0!==$hits?$hits:strcmp((string)($a['value']??''),(string)($b['value']??''));});return array_values($out);
    }

    private function guard():void{
        if(!current_user_can('manage_options')){wp_send_json_error(array('reason'=>'forbidden'),403);}
        check_ajax_referer(self::NONCE_ACTION,'nonce');
    }
}
