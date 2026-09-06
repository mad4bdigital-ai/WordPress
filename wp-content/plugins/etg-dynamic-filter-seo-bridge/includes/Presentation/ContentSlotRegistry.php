<?php
namespace ETG\DynamicFilterSEOBridge\Presentation;

final class ContentSlotRegistry {
    const OPTION_NAME='etg_dfsb_dynamic_content_slots';
    const CONTRACT='etg.dfsb.dynamic-content-slots.v2';
    const MAX_SLOTS=100;
    const MAX_SOURCES=24;

    public function defaults():array{
        $definitions=array(
            'hero_title'=>array('id'=>'hero_title','label'=>'Hero Title','enabled'=>true,'type'=>'text','template'=>'{{title}}','fallback'=>'','prefix'=>'','suffix'=>'','max_length'=>240),
            'hero_intro'=>array('id'=>'hero_intro','label'=>'Hero Intro','enabled'=>true,'type'=>'html','template'=>'{{intro}}','fallback'=>'','prefix'=>'','suffix'=>'','max_length'=>2000),
            'hero_image'=>array('id'=>'hero_image','label'=>'Hero Image','enabled'=>true,'type'=>'image','template'=>'{{resolved}}','fallback'=>'','prefix'=>'','suffix'=>'','max_length'=>0),
            'hero_gallery'=>array('id'=>'hero_gallery','label'=>'Hero Gallery','enabled'=>true,'type'=>'gallery','template'=>'{{resolved}}','fallback'=>'','prefix'=>'','suffix'=>'','max_length'=>0),
            'location_section'=>array('id'=>'location_section','label'=>'Location Section','enabled'=>true,'type'=>'html','template'=>'<section class="etg-filter-term-section etg-filter-term-section--location"><h2>{{term:location:name}}</h2><div>{{term:location:description}}</div></section>','fallback'=>'','prefix'=>'','suffix'=>'','max_length'=>8000),
            'tour_type_section'=>array('id'=>'tour_type_section','label'=>'Tour Type Section','enabled'=>true,'type'=>'html','template'=>'<section class="etg-filter-term-section etg-filter-term-section--tour-type"><h2>{{term:tour_type:name}}</h2><div>{{term:tour_type:description}}</div></section>','fallback'=>'','prefix'=>'','suffix'=>'','max_length'=>8000),
            'style_section'=>array('id'=>'style_section','label'=>'Style Section','enabled'=>true,'type'=>'html','template'=>'<section class="etg-filter-term-section etg-filter-term-section--style"><h2>{{term:style:name}}</h2><div>{{term:style:description}}</div></section>','fallback'=>'','prefix'=>'','suffix'=>'','max_length'=>8000),
            'results_summary'=>array('id'=>'results_summary','label'=>'Results Summary','enabled'=>true,'type'=>'text','template'=>'{{result_summary}}','fallback'=>'','prefix'=>'','suffix'=>'','max_length'=>300),
        );
        $out=array();foreach($definitions as $id=>$slot){$n=$this->normalize($slot,$id);$n['origin']='built_in';$out[$id]=$n;}return$out;
    }

    public function all():array{$out=$this->defaults();foreach($this->stored()as$id=>$slot){$n=$this->normalize($slot,(string)$id);if(''===$n['id']){continue;}$n['origin']=isset($out[$n['id']])?'override':'custom';$out[$n['id']]=$n;}ksort($out,SORT_STRING);return array_slice($out,0,self::MAX_SLOTS,true);}
    public function get(string $id):array{$slots=$this->all();$id=sanitize_key($id);return isset($slots[$id])?(array)$slots[$id]:array();}
    public function isDefault(string $id):bool{$id=sanitize_key($id);return isset($this->defaults()[$id]);}

    public function save(array $slot):array{
        $stored=$this->stored();$slot=$this->normalize($slot,(string)($slot['id']??''));
        if(''===$slot['id']){return array('saved'=>false,'reason'=>'slot_id_required','slot'=>array());}
        $all=$this->all();if(!isset($stored[$slot['id']])&&!isset($all[$slot['id']])&&count($all)>=self::MAX_SLOTS){return array('saved'=>false,'reason'=>'slot_limit_exceeded','slot'=>$slot);}
        unset($slot['origin']);$stored[$slot['id']]=$slot;ksort($stored,SORT_STRING);if(function_exists('update_option')){update_option(self::OPTION_NAME,$stored,false);} $slot['origin']=$this->isDefault($slot['id'])?'override':'custom';
        return array('saved'=>true,'reason'=>'saved','slot'=>$slot,'authorizing'=>false,'profile_mutation'=>false);
    }

    public function delete(string $id):bool{$id=sanitize_key($id);$stored=$this->stored();if(!isset($stored[$id])){return false;}unset($stored[$id]);if(function_exists('update_option')){update_option(self::OPTION_NAME,$stored,false);}return true;}

    public function normalize(array $slot,string $fallbackId=''):array{
        $id=sanitize_key((string)($slot['id']??$fallbackId));$type=sanitize_key((string)($slot['type']??'text'));
        if(!in_array($type,array('text','html','url','image','gallery','json'),true)){$type='text';}
        $template=$this->boundedText($slot['template']??'',12000,'html'===$type);$fallback=$this->boundedText($slot['fallback']??'',4000,'html'===$type);$prefix=$this->boundedText($slot['prefix']??'',1000,'html'===$type);$suffix=$this->boundedText($slot['suffix']??'',1000,'html'===$type);
        $max=is_numeric($slot['max_length']??null)?(int)$slot['max_length']:0;$max=max(0,min(20000,$max));$enabled=$this->truthy($slot['enabled']??true);
        $fingerprint=preg_replace('/[^a-f0-9]/','',strtolower((string)($slot['source_inventory_fingerprint']??'')));if(!is_string($fingerprint)||64!==strlen($fingerprint)){$fingerprint='';}
        $sources=$this->sources($slot['sources']??array());$chain=$this->chain($slot['fallback_chain']??array(),array_keys($sources));
        return array('contract'=>self::CONTRACT,'id'=>$id,'label'=>sanitize_text_field((string)($slot['label']??$id)),'enabled'=>$enabled,'type'=>$type,'template'=>$template,'fallback'=>$fallback,'prefix'=>$prefix,'suffix'=>$suffix,'max_length'=>$max,'sources'=>$sources,'fallback_chain'=>$chain,'source_inventory_fingerprint'=>$fingerprint,'authorizing'=>false,'profile_mutation'=>false);
    }

    private function sources($value):array{
        if(is_string($value)){$decoded=json_decode($value,true);$value=is_array($decoded)?$decoded:array();}
        $out=array();foreach(array_slice((array)$value,0,self::MAX_SOURCES)as$source){if(!is_array($source)){continue;}$alias=sanitize_key((string)($source['alias']??''));if(''===$alias){continue;}$source['alias']=$alias;$out[$alias]=$source;}ksort($out,SORT_STRING);return$out;
    }
    private function chain($value,array $aliases):array{
        if(is_string($value)){$value=preg_split('/[\r\n,|]+/',$value);}
        $allowed=array_fill_keys($aliases,true);$out=array();foreach((array)$value as$alias){$alias=sanitize_key((string)$alias);if(''!==$alias&&isset($allowed[$alias])&&!in_array($alias,$out,true)){$out[]=$alias;}}if(!$out){$out=$aliases;}return array_slice($out,0,self::MAX_SOURCES);
    }
    private function stored():array{$raw=function_exists('get_option')?get_option(self::OPTION_NAME,array()):array();if(is_string($raw)){$decoded=json_decode($raw,true);$raw=is_array($decoded)?$decoded:array();}$out=array();foreach(array_slice((array)$raw,0,self::MAX_SLOTS,true)as$key=>$slot){if(!is_array($slot)){continue;}$n=$this->normalize($slot,(string)$key);if(''!==$n['id']){$out[$n['id']]=$n;}}ksort($out,SORT_STRING);return$out;}
    private function truthy($value):bool{if(is_string($value)){return in_array(strtolower(trim($value)),array('1','true','yes','on'),true);}return(bool)$value;}
    private function boundedText($value,int$max,bool$html):string{$value=is_scalar($value)?(string)$value:'';if($html&&function_exists('wp_kses_post')){$value=wp_kses_post($value);}if(strlen($value)>$max){$value=substr($value,0,$max);}return$value;}
}
