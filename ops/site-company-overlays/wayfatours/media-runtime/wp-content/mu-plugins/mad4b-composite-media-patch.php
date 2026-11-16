<?php
defined('ABSPATH') || exit;
/** Pure, non-writing candidate for schema-preserving JetEngine composite-media edits. */
final class MAD4B_Composite_Media_Patch_V1 {
 private static function fail(string $code): array {return ['ok'=>false,'error'=>$code];}
 private static function digest($value): string {return hash('sha256',json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}
 private static function media($value):bool {return (is_int($value)&&$value>0)||(is_string($value)&&($value!==''&&!str_contains($value,',')&&(ctype_digit($value)||filter_var($value,FILTER_VALIDATE_URL))));}
 /** No database access or WordPress effects: a caller must enforce signed approval, attachment rights and CAS on RAW meta. */
 public static function plan($old,array $schema,array $op):array {
  if(!isset($op['expected_sha256'])||!is_string($op['expected_sha256'])||!hash_equals(self::digest($old),$op['expected_sha256']))return self::fail('preimage_drift');
  if(!isset($schema['type'])||!in_array($schema['type'],['gallery','repeater'],true))return self::fail('unregistered_composite_type');
  if(!in_array(($op['action']??''),['append_gallery','set_repeater_media','append_repeater_gallery'],true))return self::fail('operation_unsupported');
  if(!self::media($op['media']??null))return self::fail('invalid_media_value');
  if($schema['type']==='gallery'&&$op['action']==='append_gallery'){
   if(is_string($old)){
    $parts=$old===''?[]:array_map('trim',explode(',',$old));
    if(array_filter($parts,static fn($v)=>$v===''))return self::fail('invalid_gallery_csv');
    $oldId=($parts!==[]&&ctype_digit($parts[0]));
    $newId=ctype_digit((string)$op['media']);
    if($parts!==[]&&$oldId!==$newId)return self::fail('gallery_format_mismatch');
    $new=implode(',',array_merge($parts,[(string)$op['media']]));
   }elseif(is_array($old)&&array_is_list($old)){
    $new=$old;
    if($old!==[]){
     $first=$old[0];
     if(is_array($first)){
      if(array_keys($first)!==['id','url']&&array_keys($first)!==['url','id'])return self::fail('unknown_gallery_object_shape');
      if(!isset($op['attachment_id'],$op['url'])||!is_int($op['attachment_id'])||$op['attachment_id']<1||!is_string($op['url']))return self::fail('missing_gallery_object_fields');
      $item=[];foreach(array_keys($first) as $k)$item[$k]=$k==='id'?$op['attachment_id']:$op['url'];
     }else{
      if(is_array($first)||!self::media($first)||ctype_digit((string)$first)!==ctype_digit((string)$op['media']))return self::fail('gallery_format_mismatch');
      $item=$op['media'];
     }
    }else{return self::fail('empty_gallery_format_unresolved');}
    $new[]=$item;
   }else return self::fail('gallery_shape_unsupported');
  }elseif($schema['type']==='repeater'&&in_array($op['action'],['set_repeater_media','append_repeater_gallery'],true)){
   if(!is_array($old))return self::fail('repeater_shape_unsupported');
   $row=$op['row']??null;$field=$op['field']??null;
   if(!(is_int($row)||is_string($row))||!array_key_exists($row,$old)||!is_array($old[$row]))return self::fail('repeater_row_missing');
   $wantedType=$op['action']==='append_repeater_gallery'?'gallery':'media';
   $allowed=false;$format=null;
   foreach((array)($schema['repeater_fields']??[]) as $f)if(($f['name']??null)===$field&&($f['type']??null)===$wantedType){$allowed=true;$format=$f['value_format']??null;}
   if(!$allowed)return self::fail('repeater_media_slot_not_registered');
   if(!array_key_exists($field,$old[$row]))return self::fail('repeater_media_slot_absent');
   $current=$old[$row][$field];
   $new=$old;
   if($wantedType==='gallery'){
    $nested=self::plan($current,['type'=>'gallery','format'=>$format],['action'=>'append_gallery','media'=>$op['media'],'attachment_id'=>$op['attachment_id']??null,'url'=>$op['url']??null,'expected_sha256'=>self::digest($current)]);
    if(!$nested['ok'])return $nested;
    $new[$row][$field]=$nested['value'];
   }else{
    if(is_array($current))return self::fail('nested_media_shape_requires_adapter');
    $new[$row][$field]=$op['media'];
   }
  }else return self::fail('schema_operation_mismatch');
  return ['ok'=>true,'old_sha256'=>self::digest($old),'new_sha256'=>self::digest($new),'changed'=>$new!==$old,'value'=>$new];
 }
}
