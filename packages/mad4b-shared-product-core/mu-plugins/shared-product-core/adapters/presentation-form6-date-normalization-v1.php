<?php
/** Calendar-day normalization for Fluent Form date fields. Read-only. */
defined('ABSPATH') || exit;

function shared_form6_date_format_v1(int $formId,string $field):string {
 static $cache=[];
 $key=$formId.':'.$field;
 if(isset($cache[$key]))return $cache[$key];
 $cache[$key]='';
 if($formId<1||$formId>1000000||!preg_match('/^[a-zA-Z0-9_]{1,64}$/D',$field))return '';
 global $wpdb;
 $json=$wpdb->get_var($wpdb->prepare(
  "SELECT form_fields FROM {$wpdb->prefix}fluentform_forms WHERE id=%d LIMIT 1",$formId));
 if(!is_string($json)||strlen($json)>2000000)return '';
 $tree=json_decode($json,true);
 $scan=static function($node)use(&$scan,$field,&$cache,$key) {
  if(!is_array($node))return;
  if(($node['attributes']['name']??null)===$field && ($node['element']??'')==='input_date'){
   $fmt=$node['settings']['date_format']??'';
   if(is_string($fmt)&&strlen($fmt)<70 &&
      preg_match('/^[a-zA-Z0-9\s.\/,_:\-]+$/D',$fmt))$cache[$key]=$fmt;
   return;
  }
  foreach($node as $part)if(is_array($part))$scan($part);
 };
 $scan($tree);
 return $cache[$key];
}
function shared_form6_date_text_v1(string $value):string {
 $value=strtr(trim($value),[
  '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
  '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
  '／'=>'/','－'=>'-'
 ]);
 global $wp_locale;
 if(!is_object($wp_locale))return $value;
 $months=[];
 for($n=1;$n<=12;$n++){
  $dt=DateTimeImmutable::createFromFormat('!n',(string)$n);
  $name=method_exists($wp_locale,'get_month')?$wp_locale->get_month(sprintf('%02d',$n)):'';
  $short=$name&&method_exists($wp_locale,'get_month_abbrev')?$wp_locale->get_month_abbrev($name):'';
  if(is_string($name)&&$name!=='')$months[$name]=$dt->format('F');
  if(is_string($short)&&$short!=='')$months[$short]=$dt->format('M');
 }
 uksort($months,static fn($a,$b)=>mb_strlen($b)<=>mb_strlen($a));
 foreach($months as $localized=>$english){
  $value=preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($localized,'/').'(?![\p{L}\p{N}])/iu',
   $english,$value);
 }
 return $value;
}
function shared_form6_date_parse_v1(string $value,string $format):?string {
 if($format===''||strlen($format)>70||
    !preg_match('/^[a-zA-Z0-9\s.\/,_:\-]+$/D',$format))return null;
 // Flatpickr's J ordinal day, K meridiem and S seconds differ from PHP.
 $format=strtr($format,['J'=>'jS','K'=>'A','S'=>'s']);
 try{$date=DateTimeImmutable::createFromFormat('!'.$format,$value,new DateTimeZone('UTC'));}
 catch(Throwable $e){return null;}
 if(!$date)return null;
 $errors=DateTimeImmutable::getLastErrors();
 if(is_array($errors)&&($errors['warning_count']||$errors['error_count']))return null;
 if(mb_strtolower($date->format($format))!==mb_strtolower($value))return null;
 return $date->format('Y-m-d');
}
function shared_form6_dates_equal_v1($value,$canonical,int $form=6,string $field='datetime'):bool {
 if(!is_string($value)||!is_string($canonical)||strlen($value)>110||
    !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$canonical)||
    shared_form6_date_parse_v1($canonical,'Y-m-d')!==$canonical)return false;
 $value=shared_form6_date_text_v1($value);
 if($value===$canonical)return true;
 $declared=shared_form6_date_format_v1($form,$field);
 if($declared!==''){
  $parsed=shared_form6_date_parse_v1($value,$declared);
  if($parsed!==null)return $parsed===$canonical;
 }
 // Date-only ISO timestamp: compare the written local calendar day.
 if(preg_match('/^(\d{4}-\d{2}-\d{2})[T ](?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d(?:\.\d{1,6})?)?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)?$/D',$value,$parts))
  return shared_form6_date_parse_v1($parts[1],'Y-m-d')===$canonical;
 $formats=[
  'Y-m-d','Y/m/d','Y.m.d','Y-n-j','Y/n/j','Y.n.j',
  'd-M-y','j-M-y','d-M-Y','j-M-Y','d-F-Y','j-F-Y','d F Y','j F Y',
  'M j, Y','M d, Y','F j, Y','F d, Y','j M, Y','j F, Y',
  'M j Y','F j Y','Y F j','Y M j',
  'd/m/Y','j/n/Y','m/d/Y','n/j/Y','d.m.Y','j.n.Y','m.d.Y','n.j.Y',
  'd-m-Y','j-n-Y','m-d-Y','n-j-Y'
 ];
 $matches=[];
 foreach($formats as $fmt){
  $parsed=shared_form6_date_parse_v1($value,$fmt);
  if($parsed!==null)$matches[$parsed]=true;
  // Day/month order is not guessed when both are legal but different.
  if(count($matches)>1)return false;
 }
 return count($matches)===1 && isset($matches[$canonical]);
}
