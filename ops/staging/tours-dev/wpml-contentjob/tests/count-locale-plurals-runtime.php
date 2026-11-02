<?php
define('ABSPATH','/');
function add_filter(...$args){}
require dirname(__DIR__).'/356-mad4b-jetengine-count-locale-v2.php';
$errors=[];$passes=0;
$check=static function($x,$label)use(&$errors,&$passes){if($x)$passes++;else $errors[]=$label;};
$expect=[
 'ar'=>[1=>'رحلة',2=>'رحلتان',6=>'رحلات',16=>'رحلة'],
 'fr'=>[1=>'circuit',2=>'circuits',6=>'circuits',16=>'circuits'],
 'de'=>[1=>'Tour',2=>'Touren',6=>'Touren',16=>'Touren'],
 'es'=>[1=>'excursión',2=>'excursiones',6=>'excursiones',16=>'excursiones'],
 'it'=>[1=>'tour',2=>'tour',6=>'tour',16=>'tour'],
];
foreach($expect as $lang=>$sizes){
 foreach($sizes as $n=>$word){
  $html='<div class="jet-listing-dynamic-field__content"><span class="jet-engine-query-count query-273 count-type-total" data-query="273">'.$n.'</span> Tours</div>';
  $r=mad4b_wpml_tour_count_render_v2($html,$lang);
  $check(str_contains($r,'>'.$n.'</span> '.$word.'</div>'),$lang.'_'.$n.'_localized');
  $check($r===mad4b_wpml_tour_count_render_v2($r,$lang),$lang.'_'.$n.'_idempotent');
 }
}
$wrong='<div><span class="jet-engine-query-count query-299">6</span> Tours</div>';
$check(mad4b_wpml_tour_count_render_v2($wrong,'ar')===$wrong,'wrong_query_untouched');
$wrong='<div><span class="plain-text query-273">6</span> Tours</div>';
$check(mad4b_wpml_tour_count_render_v2($wrong,'ar')===$wrong,'plain_text_untouched');
$check(mad4b_wpml_tour_count_render_v2('<div>Egypt 6 Tours</div>','ar')==='<div>Egypt 6 Tours</div>','no_counter_untouched');
echo json_encode(['passed'=>$passes,'failed'=>$errors],JSON_UNESCAPED_UNICODE).PHP_EOL;
if($errors)exit(1);
