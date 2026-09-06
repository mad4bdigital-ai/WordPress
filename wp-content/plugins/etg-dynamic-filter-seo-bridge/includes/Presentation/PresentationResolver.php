<?php
namespace ETG\DynamicFilterSEOBridge\Presentation;

require_once dirname( __DIR__ ) . '/Identifiers/QueryId.php';
require_once dirname( __DIR__ ) . '/Identifiers/FieldKey.php';
require_once dirname( __DIR__ ) . '/Identifiers/PresentationToken.php';

use ETG\DynamicFilterSEOBridge\Content\ContentComposer;
use ETG\DynamicFilterSEOBridge\Content\GalleryComposer;
use ETG\DynamicFilterSEOBridge\Identifiers\FieldKey;
use ETG\DynamicFilterSEOBridge\Identifiers\PresentationToken;
use ETG\DynamicFilterSEOBridge\Identifiers\QueryId;

final class PresentationResolver {
    private $contextProvider;
    private $evidenceProvider;
    private $content;
    private $gallery;
    private $slots;
    private $sources;
    private $activeSlots=array();

    public function __construct(callable $contextProvider,ContentComposer $content,GalleryComposer $gallery,ContentSlotRegistry $slots,callable $evidenceProvider=null,ContentSourceResolver $sources=null){
        $this->contextProvider=$contextProvider;$this->content=$content;$this->gallery=$gallery;$this->slots=$slots;$this->evidenceProvider=$evidenceProvider;$this->sources=$sources;
    }

    public function context():array{
        $c=call_user_func($this->contextProvider);$c=is_array($c)?$c:array();if($this->renderable($c)){return$c;}
        if('disabled'!==(string)($c['scope']['reason']??'')||!$this->evidenceProvider){return$c;}
        $e=call_user_func($this->evidenceProvider);$e=is_array($e)?$e:array();if(empty($e['evidence_only'])||empty($e['dark_presentation_allowed'])){return$c;}return$this->renderable($e)?$e:$c;
    }

    public function value(string $token,array $context=null){
        $token=PresentationToken::normalize($token);$c=null===$context?$this->context():$context;if(''===$token||!$this->renderable($c)){return'';}
        if('title'===$token){return$this->content->title($c);}if('intro'===$token){return$this->content->intro($c);}if('keyword'===$token){return$this->content->keyword($c);}
        if('result_count'===$token){return is_numeric($c['result_count']??null)?(string)(int)$c['result_count']:'';}
        if('result_summary'===$token){$count=$c['result_count']??null;return is_numeric($count)?sprintf(_n('%d result','%d results',(int)$count,'etg-dynamic-filter-seo-bridge'),(int)$count):'';}
        if('breadcrumb'===$token){return implode(' › ',$this->content->breadcrumbLabels($c));}
        if('gallery_ids'===$token){return implode(',',array_map('absint',$this->gallery->ids($c,'combined')));}
        if('image_id'===$token){$image=$this->image('priority',$c);return !empty($image['id'])?(int)$image['id']:0;}
        if('image_url'===$token){$image=$this->image('priority',$c);return(string)($image['url']??'');}
        if(0===strpos($token,'context:')){$key=sanitize_key(substr($token,8));if('query_builder_query_id'===$key){return(string)($c['post_type_binding']['sources']['query_builder']['query_builder_query_id']??'');}return is_scalar($c[$key]??null)?(string)$c[$key]:'';}
        if(0===strpos($token,'url:')){return$this->url($c,sanitize_key(substr($token,4)));}
        if(0===strpos($token,'term:')){$parts=explode(':',$token,3);return 3===count($parts)?$this->termValue($c,sanitize_key($parts[1]),sanitize_key($parts[2])):'';}
        if(0===strpos($token,'terms:')){$parts=explode(':',$token,3);return 3===count($parts)?$this->termSetValue($c,sanitize_key($parts[1]),sanitize_key($parts[2])):'';}
        if(0===strpos($token,'termmeta:')){$parts=explode(':',$token,3);return 3===count($parts)?$this->termMeta($c,sanitize_key($parts[1]),FieldKey::normalize($parts[2])):'';}
        if(0===strpos($token,'topology:')){$parts=explode(':',$token,3);if(3!==count($parts)||'query_builder_query_id'!==$parts[2]){return'';}$observed=QueryId::normalize($c['query_id']??'');if(''===$observed||$parts[1]!==QueryId::tokenKey($observed)){return'';}return(string)($c['post_type_binding']['sources']['query_builder']['query_builder_query_id']??'');}
        return function_exists('apply_filters')?apply_filters('etg_dfsb_presentation_value','',$token,$c):'';
    }

    public function image(string $mode='priority',array $context=null):array{
        $c=null===$context?$this->context():$context;if(!$this->renderable($c)){return array('id'=>0,'url'=>'');}$mode=sanitize_key($mode);if(''===$mode){$mode='priority';}$ids=$this->gallery->ids($c,$mode);if(!$ids){return array('id'=>0,'url'=>'');}$id=(int)reset($ids);$url=$id&&function_exists('wp_get_attachment_image_url')?(string)(wp_get_attachment_image_url($id,'full')?:''):'';return array('id'=>$id,'url'=>$url);
    }

    public function gallery(string $mode='combined',array $context=null,int $limit=30):array{
        $c=null===$context?$this->context():$context;if(!$this->renderable($c)){return array();}$mode=sanitize_key($mode);if(''===$mode){$mode='combined';}$limit=max(1,min(30,$limit));return$this->mediaArray(array_slice($this->gallery->ids($c,$mode),0,$limit));
    }

    public function slot(string $id,array $context=null):string{
        $id=sanitize_key($id);if(''===$id||!empty($this->activeSlots[$id])){return'';}$slot=$this->slots->get($id);if(!$slot||empty($slot['enabled'])){return'';}$c=null===$context?$this->context():$context;if(!$this->renderable($c)){return'';}
        $this->activeSlots[$id]=true;
        try{
            $template=(string)($slot['template']??'');
            $rendered=preg_replace_callback('/\{\{\s*([A-Za-z0-9_:\-.]+)\s*\}\}/',function($m)use($c,$slot){$token=(string)$m[1];if('resolved'===strtolower($token)){return$this->resolvedSourceValue($slot,$c);}if(0===strpos(strtolower($token),'source:')){$alias=sanitize_key(substr($token,7));return$this->sourceValue($slot,$alias,$c);} $v=$this->value($token,$c);if(is_array($v)){return implode(',',array_map('strval',$v));}return is_scalar($v)?(string)$v:'';},$template);
            $rendered=is_string($rendered)?$rendered:'';if(''===trim(wp_strip_all_tags($rendered))){$rendered=(string)($slot['fallback']??'');}$rendered=(string)($slot['prefix']??'').$rendered.(string)($slot['suffix']??'');$max=(int)($slot['max_length']??0);if($max>0&&function_exists('mb_substr')){$rendered=mb_substr($rendered,0,$max);}elseif($max>0){$rendered=substr($rendered,0,$max);}return$this->sanitizeByType($rendered,(string)($slot['type']??'text'));
        }catch(\Throwable$e){return'';}finally{unset($this->activeSlots[$id]);}
    }

    public function slotImage(string $id,array $context=null):array{
        $slot=$this->slots->get($id);$c=null===$context?$this->context():$context;if(!$slot||empty($slot['enabled'])||!$this->renderable($c)){return array('id'=>0,'url'=>'');}
        $ids=$this->slotMediaIds($slot,$c,1);if($ids){$media=$this->mediaArray($ids);return$media?(array)$media[0]:array('id'=>0,'url'=>'');}
        $value=$this->slot($id,$c);$attachment=absint($value);if($attachment){$media=$this->mediaArray(array($attachment));return$media?(array)$media[0]:array('id'=>$attachment,'url'=>'');}
        return filter_var($value,FILTER_VALIDATE_URL)?array('id'=>0,'url'=>$value):array('id'=>0,'url'=>'');
    }

    public function slotGallery(string $id,array $context=null,int $limit=30):array{
        $slot=$this->slots->get($id);$c=null===$context?$this->context():$context;if(!$slot||empty($slot['enabled'])||!$this->renderable($c)){return array();}$limit=max(1,min(30,$limit));$ids=$this->slotMediaIds($slot,$c,$limit);
        if(!$ids){$ids=array_filter(array_map('absint',preg_split('/[,\s]+/',$this->slot($id,$c))));}return$this->mediaArray(array_slice(array_values(array_unique($ids)),0,$limit));
    }

    public function slotRows(string $id,string $alias,array $context=null):array{
        $slot=$this->slots->get($id);$c=null===$context?$this->context():$context;$alias=sanitize_key($alias);if(!$slot||!$this->sources||!$this->renderable($c)||!isset($slot['sources'][$alias])){return array();}return$this->sources->rows((array)$slot['sources'][$alias],$c);
    }
    public function sourceCatalog():array{return$this->sources?$this->sources->catalog():array();}
    public function slotType(string $id):string{$slot=$this->slots->get($id);return(string)($slot['type']??'text');}

    private function sourceValue(array $slot,string $alias,array $c):string{if(!$this->sources||!isset($slot['sources'][$alias])){return'';}return$this->sources->value((array)$slot['sources'][$alias],$c);}
    private function resolvedSourceValue(array $slot,array $c):string{
        if(!$this->sources){if('image'===(string)($slot['type']??'')){return(string)($this->image('priority',$c)['id']??0);}if('gallery'===(string)($slot['type']??'')){return implode(',',$this->gallery->ids($c,'combined'));}return'';}
        foreach((array)($slot['fallback_chain']??array_keys((array)($slot['sources']??array()))) as$alias){$alias=sanitize_key((string)$alias);if(!isset($slot['sources'][$alias])){continue;}if(in_array((string)($slot['type']??''),array('image','gallery'),true)){$ids=$this->sources->mediaIds((array)$slot['sources'][$alias],$c);if($ids){return'image'===(string)$slot['type']?(string)$ids[0]:implode(',',$ids);}}else{$value=$this->sources->value((array)$slot['sources'][$alias],$c);if(''!==trim($value)){return$value;}}}
        if('image'===(string)($slot['type']??'')){return(string)($this->image('priority',$c)['id']??0);}if('gallery'===(string)($slot['type']??'')){return implode(',',array_map('absint',$this->gallery->ids($c,'combined')));}return'';
    }
    private function slotMediaIds(array $slot,array $c,int $limit):array{if($this->sources){foreach((array)($slot['fallback_chain']??array_keys((array)($slot['sources']??array())))as$alias){$alias=sanitize_key((string)$alias);if(!isset($slot['sources'][$alias])){continue;}$ids=$this->sources->mediaIds((array)$slot['sources'][$alias],$c);if($ids){return array_slice($ids,0,$limit);}}}return array_slice($this->gallery->ids($c,'image'===(string)($slot['type']??'')?'priority':'combined'),0,$limit);}
    private function mediaArray(array $ids):array{$out=array();foreach($ids as$id){$id=absint($id);if(!$id){continue;}$url=function_exists('wp_get_attachment_image_url')?(string)(wp_get_attachment_image_url($id,'full')?:''):'';if(''===$url){continue;}$out[]=array('id'=>$id,'url'=>$url);}return$out;}

    private function termValue(array $c,string $role,string $field){$term=(array)($c['terms'][$role]??array());if(!$term){return'';}if('image_url'===$field){$id=(int)($term['image_id']??0);return$id&&function_exists('wp_get_attachment_image_url')?(string)(wp_get_attachment_image_url($id,'full')?:''):'';}if(!array_key_exists($field,$term)){return'';}$v=$term[$field];return is_scalar($v)?$v:'';}
    private function termSetValue(array $c,string $role,string $field){$set=(array)($c['term_sets'][$role]??array());if(!$set&&isset($c['terms'][$role])){$set=array((array)$c['terms'][$role]);}if(!$set){return'';}if('count'===$field){return(string)count($set);}$map=array('ids'=>'term_id','names'=>'name','slugs'=>'slug','descriptions'=>'description','short_descriptions'=>'short_description','seo_titles'=>'seo_title','meta_descriptions'=>'meta_description','focus_keywords'=>'focus_keyword');if(!isset($map[$field])){return'';}$key=$map[$field];$values=array();foreach(array_slice($set,0,20)as$term){$term=(array)$term;$v=$term[$key]??'';if(is_scalar($v)&&''!==trim((string)$v)){$values[]=trim((string)$v);}}$values=array_values(array_unique($values));$separator=in_array($field,array('descriptions','short_descriptions'),true)?"\n\n":', ';return implode($separator,$values);}
    private function termMeta(array $c,string $role,string $key){$key=FieldKey::normalize($key);$term=(array)($c['terms'][$role]??array());$id=(int)($term['term_id']??0);if(!$id||''===$key||!function_exists('get_term_meta')){return'';}$v=get_term_meta($id,$key,true);return is_scalar($v)?(string)$v:'';}
    private function url(array $c,string $which):string{$path='';if('current'===$which){$path=(string)($c['request_path']??($c['archive_path']??''));}elseif('archive'===$which){$path=(string)($c['archive_path']??'');}if(''===$path){return'';}if(filter_var($path,FILTER_VALIDATE_URL)){return$path;}return function_exists('home_url')?(string)home_url('/'.ltrim($path,'/')):$path;}
    private function sanitizeByType(string $value,string $type):string{if('html'===$type){return function_exists('wp_kses_post')?wp_kses_post($value):strip_tags($value);}if('url'===$type){return function_exists('esc_url_raw')?esc_url_raw($value):filter_var($value,FILTER_SANITIZE_URL);}if('image'===$type){$id=absint($value);if($id){return(string)$id;}return function_exists('esc_url_raw')?esc_url_raw($value):$value;}if('gallery'===$type){return implode(',',array_values(array_unique(array_filter(array_map('absint',preg_split('/[,\s]+/',$value))))));}if('json'===$type){$decoded=json_decode($value,true);if(JSON_ERROR_NONE!==json_last_error()){return'';}return function_exists('wp_json_encode')?wp_json_encode($decoded):json_encode($decoded);}return function_exists('sanitize_text_field')?sanitize_text_field(wp_strip_all_tags($value)):trim(strip_tags($value));}

    private function renderable(array $c):bool{if(empty($c['active'])||empty($c['in_scope'])||empty($c['runtime_ready'])||empty($c['filters'])){return false;}if(isset($c['scope_valid'])&&empty($c['scope_valid'])){return false;}$ajax='ajax'===(string)($c['state_transport']??'');if($ajax){if(empty($c['provider_observation_matches_state'])||(array_key_exists('filtered_query_complete',$c)&&empty($c['filtered_query_complete']))||!empty($c['unsupported_filter_props'])){return false;}}elseif(isset($c['provider_observation_matches_url'])&&empty($c['provider_observation_matches_url'])){return false;}$profile=(array)($c['profile']??array());$binding=(array)($c['post_type_binding']??array());if(!empty($profile['require_post_type_binding'])&&(empty($binding['observed'])||empty($binding['matches_profile']))){return false;}return empty($c['unknown_filters'])&&empty($c['malformed'])&&empty($c['missing_terms'])&&empty($c['translation_fallback'])&&!empty($c['terms']);}
}
