<?php
namespace ETG\DynamicFilterSEOBridge\Presentation;

require_once dirname(__DIR__) . '/Identifiers/FieldKey.php';

use ETG\DynamicFilterSEOBridge\Identifiers\FieldKey;
use ETG\DynamicFilterSEOBridge\JetEngine\ListingContextResolver;
use ETG\DynamicFilterSEOBridge\JetEngine\QueryRunner;
use ETG\DynamicFilterSEOBridge\JetEngine\RelationResolver;
use ETG\DynamicFilterSEOBridge\JetEngine\ValueNormalizer;
use ETG\DynamicFilterSEOBridge\JetEngine\FieldDiscovery;

final class ContentSourceResolver {
    const MAX_SOURCES=24;
    const MAX_ITEMS=100;

    private $listing;private $queries;private $relations;private $normalizer;private $fields;
    public function __construct(ListingContextResolver$listing,QueryRunner$queries,RelationResolver$relations,ValueNormalizer$normalizer,FieldDiscovery$fields=null){$this->listing=$listing;$this->queries=$queries;$this->relations=$relations;$this->normalizer=$normalizer;$this->fields=$fields;}

    public function normalizeSource(array$source):array{
        $type=sanitize_key((string)($source['type']??'listing_field'));$allowed=array('term_field','term_meta','context','listing_field','listing_meta','repeater','query','relation','relation_meta');if(!in_array($type,$allowed,true)){$type='listing_field';}
        $alias=sanitize_key((string)($source['alias']??''));$field=$this->path((string)($source['field']??''));$aggregate=sanitize_key((string)($source['aggregate']??'first'));if(!in_array($aggregate,array('first','join','count','ids','image','gallery','json'),true)){$aggregate='first';}
        $direction='parents'===(string)($source['direction']??'')?'parents':'children';$separator=(string)($source['separator']??', ');if(strlen($separator)>20){$separator=substr($separator,0,20);}
        return array('alias'=>$alias,'label'=>sanitize_text_field((string)($source['label']??$alias)),'type'=>$type,'role'=>sanitize_key((string)($source['role']??'')),'field'=>$field,'meta_key'=>FieldKey::normalize($source['meta_key']??''),'query_id'=>max(0,(int)($source['query_id']??0)),'relation_id'=>max(0,(int)($source['relation_id']??0)),'direction'=>$direction,'aggregate'=>$aggregate,'separator'=>$separator,'limit'=>max(1,min(self::MAX_ITEMS,(int)($source['limit']??20))),'authorizing'=>false,'read_only'=>true);
    }
    public function normalizeSources($sources):array{if(is_string($sources)){$decoded=json_decode($sources,true);$sources=is_array($decoded)?$decoded:array();}$out=array();foreach(array_slice((array)$sources,0,self::MAX_SOURCES)as$source){if(!is_array($source)){continue;}$source=$this->normalizeSource($source);if(''===$source['alias']){continue;}$out[$source['alias']]=$source;}ksort($out,SORT_STRING);return$out;}
    public function value(array$source,array$context=array()):string{$source=$this->normalizeSource($source);return$this->aggregate($this->raw($source,$context),$source,$context);}

    public function rows(array$source,array$context=array()):array{
        $source=$this->normalizeSource($source);$type=$source['type'];
        if('repeater'===$type){
            if($source['role']){$term=(array)($context['terms'][$source['role']]??array());$id=(int)($term['term_id']??0);$raw=$id&&$source['meta_key']&&function_exists('get_term_meta')?get_term_meta($id,$source['meta_key'],true):array();return array_slice($this->normalizer->rows($raw),0,$source['limit']);}
            return array_slice($this->listing->repeaterRows($source['meta_key']),0,$source['limit']);
        }
        if('query'===$type){return array_slice($this->queries->items($source['query_id'],$source['limit']),0,$source['limit']);}
        if('relation'===$type){$id=$this->anchorId($source,$context);return array_slice($this->relations->items($source['relation_id'],$id,$source['direction'],$source['limit']),0,$source['limit']);}
        return$this->normalizer->rows($this->raw($source,$context));
    }

    public function mediaIds(array$source,array$context=array()):array{
        $source=$this->normalizeSource($source);$ids=array();if(in_array($source['type'],array('repeater','query','relation'),true)){foreach($this->rows($source,$context)as$item){$value=$this->itemValue($item,$source['field']);$ids=array_merge($ids,$this->normalizer->attachmentIds($value));}}else{$ids=$this->normalizer->attachmentIds($this->raw($source,$context));}
        return array_slice(array_values(array_unique(array_filter(array_map('absint',$ids)))),0,$source['limit']);
    }

    public function diagnose(array$source,array$context=array()):array{
        $source=$this->normalizeSource($source);$reason='ready';$available=true;$rows=0;$media=array();$sample='';
        if('query'===$source['type']&&!isset($this->queries->options()[$source['query_id']])){$available=false;$reason='query_not_found';}
        elseif(in_array($source['type'],array('relation','relation_meta'),true)&&!isset($this->relations->options()[$source['relation_id']])){$available=false;$reason='relation_not_found';}
        elseif(in_array($source['type'],array('term_field','term_meta'),true)&&empty($source['role'])){$available=false;$reason='taxonomy_role_required';}
        elseif(in_array($source['type'],array('listing_meta','repeater','term_meta','relation_meta'),true)&&''===$source['meta_key']){$available=false;$reason='meta_key_required';}
        if($available){
            if(in_array($source['type'],array('repeater','query','relation'),true)){$rows=count($this->rows($source,$context));}
            $sample=$this->value($source,$context);if(in_array($source['aggregate'],array('image','gallery'),true)){$media=$this->mediaIds($source,$context);}
            if(''===$sample&&0===$rows&&!$media){$reason='empty_at_current_context';}
        }
        return array('contract'=>'etg.dfsb.jetengine-source-diagnostic.v1','authorizing'=>false,'read_only'=>true,'available'=>$available,'reason'=>$reason,'source'=>$source,'row_count'=>$rows,'media_ids'=>array_slice($media,0,10),'sample'=>strlen($sample)>240?substr($sample,0,240):$sample);
    }

    public function catalog():array{
        return array('contract'=>'etg.dfsb.jetengine-content-sources.v3','authorizing'=>false,'read_only'=>true,'source_types'=>array('term_field'=>'Selected taxonomy term field','term_meta'=>'Selected taxonomy term meta','context'=>'Filter/runtime context path','listing_field'=>'Current JetEngine Listing/Component/CCT object field','listing_meta'=>'Current JetEngine Listing object meta','repeater'=>'JetEngine repeater rows; set Role to read term meta, blank Role for current listing meta','query'=>'JetEngine Query Builder results including Posts/Terms/Users/CCT/Repeater queries','relation'=>'JetEngine relation parents/children; set Role to anchor on selected term, blank Role for current listing object','relation_meta'=>'JetEngine relation metadata values for each parent/child edge'),'aggregates'=>array('first','join','count','ids','image','gallery','json'),'queries'=>$this->queries->options(),'relations'=>$this->relations->options(),'listing_context'=>$this->listing->describe(),'field_discovery'=>$this->fields?$this->fields->catalog():array());
    }

    private function raw(array$source,array$context){
        switch($source['type']){
            case'term_field':$term=(array)($context['terms'][$source['role']]??array());return$this->normalizer->path($term,$source['field']);
            case'term_meta':$term=(array)($context['terms'][$source['role']]??array());$id=(int)($term['term_id']??0);return$id&&$source['meta_key']&&function_exists('get_term_meta')?get_term_meta($id,$source['meta_key'],true):'';
            case'context':return$this->normalizer->path($context,$source['field']);
            case'listing_field':return$this->listing->property($source['field']);
            case'listing_meta':return$this->listing->meta($source['meta_key']);
            case'repeater':return$this->rows($source,$context);
            case'query':return$this->queries->items($source['query_id'],$source['limit']);
            case'relation':return$this->relations->items($source['relation_id'],$this->anchorId($source,$context),$source['direction'],$source['limit']);
            case'relation_meta':return$this->relations->metaValues($source['relation_id'],$this->anchorId($source,$context),$source['direction'],$source['meta_key'],$source['limit']);
        }return'';
    }
    private function aggregate($raw,array$source,array$context):string{
        $aggregate=$source['aggregate'];if('count'===$aggregate){return(string)(is_array($raw)||$raw instanceof \Countable?count($raw):(''===$this->normalizer->scalar($raw)?0:1));}if(in_array($aggregate,array('image','gallery'),true)){$ids=$this->mediaIds($source,$context);return'image'===$aggregate?(string)($ids[0]??0):implode(',',$ids);}
        $values=array();if(in_array($source['type'],array('repeater','query','relation'),true)){foreach($this->rows($source,$context)as$item){$value=$this->itemValue($item,$source['field']);$scalar=$this->normalizer->scalar($value);if(''!==$scalar){$values[]=$scalar;}}}elseif(is_array($raw)){foreach($raw as$item){$scalar=$this->normalizer->scalar($source['field']?$this->itemValue($item,$source['field']):$item);if(''!==$scalar){$values[]=$scalar;}}}else{$scalar=$this->normalizer->scalar($raw);if(''!==$scalar){$values[]=$scalar;}}
        $values=array_values(array_unique($values));if('ids'===$aggregate){$ids=array_values(array_unique(array_filter(array_map('absint',$values))));return implode(',',$ids);}if('json'===$aggregate){$json=function_exists('wp_json_encode')?wp_json_encode(array_slice($values,0,$source['limit'])):json_encode(array_slice($values,0,$source['limit']));return is_string($json)?$json:'';}if('join'===$aggregate){return implode($source['separator'],array_slice($values,0,$source['limit']));}return(string)($values[0]??'');
    }
    private function anchorId(array$source,array$context):int{if(!empty($source['role'])){$term=(array)($context['terms'][$source['role']]??array());$id=(int)($term['term_id']??0);if($id){return$id;}}return$this->objectId($this->listing->currentObject());}
    private function itemValue($item,string$field){if(''===$field){return$item;}if(0===strpos($field,'meta:')){return$this->listing->meta(substr($field,5),$item);}return$this->listing->property($field,$item);}
    private function objectId($object):int{foreach(array('ID','id','_ID','term_id')as$key){if(is_object($object)&&isset($object->{$key})&&is_numeric($object->{$key})){return(int)$object->{$key};}if(is_array($object)&&isset($object[$key])&&is_numeric($object[$key])){return(int)$object[$key];}}return 0;}
    private function path(string$path):string{$path=trim($path);if(strlen($path)>160){$path=substr($path,0,160);}return preg_match('/^[A-Za-z0-9_.:\/-]*$/',$path)?$path:'';}
}
