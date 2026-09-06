<?php
namespace ETG\DynamicFilterSEOBridge\Presentation;

final class ContentSlotRegistry {
    const OPTION_NAME = 'etg_dfsb_dynamic_content_slots';
    const CONTRACT = 'etg.dfsb.dynamic-content-slots.v1';
    const MAX_SLOTS = 100;

    public function all(): array {
        $raw = function_exists( 'get_option' ) ? get_option( self::OPTION_NAME, array() ) : array();
        if ( is_string( $raw ) ) { $decoded=json_decode($raw,true); $raw=is_array($decoded)?$decoded:array(); }
        $out=array();
        foreach(array_slice((array)$raw,0,self::MAX_SLOTS,true) as $key=>$slot){if(!is_array($slot)){continue;}$normalized=$this->normalize($slot,(string)$key);if(''!==$normalized['id']){$out[$normalized['id']]=$normalized;}}
        ksort($out,SORT_STRING);return$out;
    }

    public function get(string $id):array{$slots=$this->all();$id=sanitize_key($id);return isset($slots[$id])?(array)$slots[$id]:array();}

    public function save(array $slot):array{
        $slots=$this->all();$slot=$this->normalize($slot,(string)($slot['id']??''));if(''===$slot['id']){return array('saved'=>false,'reason'=>'slot_id_required','slot'=>array());}
        if(!isset($slots[$slot['id']])&&count($slots)>=self::MAX_SLOTS){return array('saved'=>false,'reason'=>'slot_limit_exceeded','slot'=>$slot);}
        $slots[$slot['id']]=$slot;ksort($slots,SORT_STRING);if(function_exists('update_option')){update_option(self::OPTION_NAME,$slots,false);}
        return array('saved'=>true,'reason'=>'saved','slot'=>$slot,'authorizing'=>false,'profile_mutation'=>false);
    }

    public function delete(string $id):bool{$id=sanitize_key($id);$slots=$this->all();if(!isset($slots[$id])){return false;}unset($slots[$id]);if(function_exists('update_option')){update_option(self::OPTION_NAME,$slots,false);}return true;}

    public function normalize(array $slot,string $fallbackId=''):array{
        $id=sanitize_key((string)($slot['id']??$fallbackId));$type=sanitize_key((string)($slot['type']??'text'));if(!in_array($type,array('text','html','url','image'),true)){$type='text';}
        $template=$this->boundedText($slot['template']??'',4000,false);$fallback=$this->boundedText($slot['fallback']??'',2000,$type==='html');$prefix=$this->boundedText($slot['prefix']??'',500,$type==='html');$suffix=$this->boundedText($slot['suffix']??'',500,$type==='html');
        $max=is_numeric($slot['max_length']??null)?(int)$slot['max_length']:0;$max=max(0,min(20000,$max));
        $enabled=$this->truthy($slot['enabled']??true);$fingerprint=preg_replace('/[^a-f0-9]/','',strtolower((string)($slot['source_inventory_fingerprint']??'')));if(!is_string($fingerprint)||64!==strlen($fingerprint)){$fingerprint='';}
        return array('contract'=>self::CONTRACT,'id'=>$id,'label'=>sanitize_text_field((string)($slot['label']??$id)),'enabled'=>$enabled,'type'=>$type,'template'=>$template,'fallback'=>$fallback,'prefix'=>$prefix,'suffix'=>$suffix,'max_length'=>$max,'source_inventory_fingerprint'=>$fingerprint,'authorizing'=>false,'profile_mutation'=>false);
    }

    private function truthy($value):bool{if(is_string($value)){return in_array(strtolower(trim($value)),array('1','true','yes','on'),true);}return(bool)$value;}
    private function boundedText($value,int $max,bool $html):string{$value=is_scalar($value)?(string)$value:'';$value=$html&&function_exists('wp_kses_post')?wp_kses_post($value):$value;if(strlen($value)>$max){$value=substr($value,0,$max);}return$value;}
}
