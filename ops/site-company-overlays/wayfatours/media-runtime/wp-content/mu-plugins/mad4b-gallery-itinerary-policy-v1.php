<?php
/**
 * Plugin Name: MAD4B Tour Gallery Itinerary Source Policy
 * Description: Read-only itinerary authority for UAEL Tour galleries: Tour post for normal trips, linked programs taxonomy for cruise_tour.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
final class MAD4B_Gallery_Itinerary_Policy_V1 {
 const VERSION='1.0.0';
 public static function normalize($v):string {
   return trim(html_entity_decode(wp_strip_all_tags((string)$v),ENT_QUOTES|ENT_HTML5,'UTF-8'));
 }
 public static function post_rows(int $post):array {
   $items=get_post_meta($post,'itinerary_item',true);
   if(!is_array($items))return [];
   $out=[];
   foreach($items as $row){
     if(!is_array($row))continue;
     $title=self::normalize($row['title']??'');
     $detail=self::normalize($row['description']??'');
     if($title===''&&$detail==='')continue;
     $stored=absint($row['number']??0);
     if(preg_match('/(?:day|d[ií]a|jour|tag|giorno|اليوم)\s*([0-9]{1,2})/iu',$title,$m))$num=absint($m[1]);
     else $num=$stored?:count($out)+1;
     if($num<1||$num>45||isset($out[$num]))continue;
     $out[$num]=['day'=>$num,'title'=>$title,'description'=>$detail];
   }
   ksort($out,SORT_NUMERIC);
   return array_values($out);
 }
 private static function localized_program_title(int $term,int $post):string {
   $details=apply_filters('wpml_post_language_details',null,$post);
   $lang=is_array($details)?(string)($details['language_code']??''):'';
   if($lang==='')$lang=(string)apply_filters('wpml_current_language','en');
   $mapped=(int)apply_filters('wpml_object_id',$term,'programs',false,$lang);
   if($mapped){
     $t=get_term($mapped,'programs');
     if($t instanceof WP_Term)return self::normalize($t->name);
   }
   $t=get_term($term,'programs');
   return $t instanceof WP_Term?self::normalize($t->name):'';
 }
 /**
  * Pure selection policy shared by a future signed Program filter and the
  * read-only test harness. An arbitrary Program ID is NEVER an authority.
  * A multi-program Cruise without an explicit verified choice stays closed.
  */
 public static function choose_program(array $assigned,int $requested=0):array {
   $allowed=array_values(array_unique(array_filter(array_map('absint',$assigned))));
   sort($allowed,SORT_NUMERIC);
   $count=count($allowed);
   if(!$count)return ['state'=>'no_assigned_program','program_id'=>0,'count'=>0];
   if($requested>0&&!in_array($requested,$allowed,true))
     return ['state'=>'unassigned_program_rejected','program_id'=>0,'count'=>$count];
   if($count>1&&$requested===0)
     return ['state'=>'selection_required','program_id'=>0,'count'=>$count];
   $id=$requested?:$allowed[0];
   return ['state'=>$count>1?'verified_multiple_selection':'single_program_default',
     'program_id'=>$id,'count'=>$count];
 }
 public static function resolve(int $post,int $canonical=0,int $verified_selected_program=0):array {
   $canonical=$canonical?:$post;
   $mode=(string)get_post_meta($canonical,'tour_mode',true);
   $normal=['mode'=>$mode,'authority'=>'tour_post_meta:itinerary_item',
     'program_id'=>0,'program_name'=>'','program_count'=>0,
     'state'=>$mode==='cruise_tour'?'cruise_tour_post_fallback':'standard_tour',
     'days'=>self::post_rows($canonical),'warnings'=>[],
     'can_claim_program'=>false,'selection_required'=>false];
   if($mode!=='cruise_tour')return $normal;
   // Optional price/Rate functionality MUST NOT be required to render a
   // public gallery. Treat the existing native Program contract as authority
   // only when its real taxonomy and term content are available.
   if(!function_exists('shared_product_tour_itinerary_contract_v1')
      ||!function_exists('shared_product_tour_program_allowed_v1')){
       $normal['warnings'][]='program_contract_unavailable';
       return $normal;
   }
   $source=shared_product_tour_itinerary_contract_v1($canonical);
   if(($source['authoritative_source']??'')!=='taxonomy:programs'){
     $normal['warnings'][]='cruise_program_not_linked';
     return $normal;
   }
   $programs=array_values(array_unique(array_filter(array_map('absint',
      (array)($source['linked_program_ids']??[])))));
   if(!$programs){
     $normal['warnings'][]='empty_program_reference';
     return $normal;
   }
   $decision=self::choose_program($programs,$verified_selected_program);
   // The Tour's previous embedded itinerary cannot select between distinct
   // Program routes. Missing or foreign selection must never show an
   // arbitrary itinerary. Only a previously verified caller may supply the
   // explicit third argument; no GET, cookie, or client label is read here.
   if(($decision['program_id']??0)===0)return [
      'mode'=>$mode,'authority'=>'taxonomy:programs',
      'program_id'=>0,'program_name'=>'','program_count'=>count($programs),
      'state'=>($decision['state']??'selection_required'),'days'=>[],
      'warnings'=>[($decision['state']??'selection_required')==='selection_required'
         ?'multiple_program_itineraries_requires_verified_selection'
         :'unassigned_program_selection_rejected'],
      'can_claim_program'=>false,'selection_required'=>true
   ];
   $pid=(int)$decision['program_id'];
   if(!shared_product_tour_program_allowed_v1($canonical,$pid)){
       return [
         'mode'=>$mode,'authority'=>'taxonomy:programs','program_id'=>0,
         'program_name'=>'','program_count'=>count($programs),
         'state'=>'unverified_program_reference','days'=>[],
         'warnings'=>['unverified_program_reference'],
         'can_claim_program'=>false,'selection_required'=>true
       ];
   }
   if(!class_exists('MAD4B_Cruise_Program_Composer_V1',false)){
       $normal['warnings'][]='native_program_day_parser_missing';
       return $normal;
   }
   $parsed=MAD4B_Cruise_Program_Composer_V1::day_rows($pid);
   $days=[];
   foreach((array)($parsed['days']??[])as $item){
     if(!is_array($item))continue;
     $number=absint($item['day']??0);
     $title=self::normalize($item['title']??'');
     $detail=self::normalize($item['detail']??'');
     if($number<1||$number>45||($title===''&&$detail==='')||isset($days[$number]))continue;
     $days[$number]=['day'=>$number,'title'=>$title,'description'=>$detail];
   }
   if(!$days){
     // Content-backed Program with no parsable rows is NOT a reliable
     // Itinerary. Keep the gallery, but never pretend the old Tour post
     // describes this different Program.
     return [
       'mode'=>$mode,'authority'=>'taxonomy:programs',
       'program_id'=>$pid,'program_name'=>self::localized_program_title($pid,$post),
       'program_count'=>count($programs),'state'=>'program_itinerary_missing',
       'days'=>[],'warnings'=>['program_term_itinerary_not_structured'],
       'can_claim_program'=>false,'selection_required'=>false
     ];
   }
   ksort($days,SORT_NUMERIC);
   $warnings=(array)($parsed['warnings']??[]);
   $canonicalDays=array_values($days);
   $sourceDayEvidence=$canonicalDays;
   $localized=self::post_rows($post);
   $details=apply_filters('wpml_post_language_details',null,$post);
   $lang=is_array($details)?(string)($details['language_code']??''):'';
   $default=(string)apply_filters('wpml_default_language','en');
   // A real translated Tour can provide UI strings, but the PROGRAM's day
   // numbers, order and day count remain authoritative. All days must match
   // 1:1; never splice unrelated itinerary text into a different Program.
   if(count($programs)===1 &&$post!==$canonical && $lang!==''&&$lang!==$default
      &&count($localized)===count($canonicalDays)){
       $keys=array_column($canonicalDays,'day');
       if($keys===array_column($localized,'day')){
         foreach($canonicalDays as $i=>&$day){
            $day['title']=$localized[$i]['title'];
            $day['description']=$localized[$i]['description'];
         }
         unset($day);
       }else $warnings[]=['code'=>'translated_tour_day_numbers_diverge'];
   }
   return [
     'mode'=>$mode,'authority'=>'taxonomy:programs:program_itinerary',
     'program_id'=>$pid,'program_name'=>self::localized_program_title($pid,$post),
     'program_count'=>count($programs),
     'state'=>count($programs)>1?'verified_multiple_reusable_program':'single_reusable_program',
     'days'=>$canonicalDays,
     'canonical_days'=>$sourceDayEvidence,
     'warnings'=>$warnings,
     'can_claim_program'=>true,'selection_required'=>false
   ];
 }
}
