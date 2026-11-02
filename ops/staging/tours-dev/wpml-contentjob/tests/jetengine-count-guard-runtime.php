<?php
error_reporting(E_ALL);
define('ABSPATH','/fake/');
require dirname(__DIR__).'/356-mad4b-wpml-jetengine-count-guard.php';
$widget='<div class="jet-listing-dynamic-field__content" ><span class="jet-engine-query-count query-273 count-type-total" data-query="273">6</span> Tours</div>';
$wrapper=static fn($id,$content)=>'<div data-elementor-id="'.$id.'" class="elementor elementor-'.$id.' elementor-45927"><div data-id="402c8ca">'.$content.'</div></div>';
$labels=['ar'=>'رحلات','fr'=>'circuits','de'=>'Touren','es'=>'excursiones','it'=>'tour'];
$ids=['ar'=>'68816','fr'=>'68818','de'=>'68821','es'=>'68823','it'=>'68825'];
$passes=0;
function check($pass,$label){global $passes;if(!$pass){fwrite(STDERR,"FAIL: $label\n");exit(1);} $passes++;}
foreach($labels as $locale=>$label){
  $original=$wrapper($ids[$locale],$widget);
  $changed=mad4b_wpml_destination_count_html_v1($original,$locale);
  check(str_contains($changed,'>6</span> '.$label.'</div>'),$locale.' localized suffix');
  check(!str_contains($changed,'</span> Tours</div>'),$locale.' no old English');
  check(mad4b_wpml_destination_count_html_v1($changed,$locale)===$changed,$locale.' idempotence');
  check(mad4b_wpml_destination_count_html_v1($wrapper('99999',$widget),$locale)===$wrapper('99999',$widget),$locale.' wrong listing untouched');
}
check(mad4b_wpml_destination_count_html_v1($wrapper('68816',$widget),'en')===$wrapper('68816',$widget),'English unchanged');
$other='<div class="jet-listing-dynamic-field__content"><span class="jet-engine-query-count query-274">6</span> Tours</div>';
check(mad4b_wpml_destination_count_html_v1($wrapper('68816',$other),'ar')===$wrapper('68816',$other),'different query unchanged');
$other='<div class="jet-listing-dynamic-field__content"><span class="not-a-counter query-273">6</span> Tours</div>';
check(mad4b_wpml_destination_count_html_v1($wrapper('68816',$other),'ar')===$wrapper('68816',$other),'non JetEngine counter unchanged');
check(str_contains(mad4b_wpml_destination_count_html_v1($widget,'ar',true),'>6</span> رحلات</div>'),'widget render mode');
$translated='<div class="jet-listing-dynamic-field__content"><span class="jet-engine-query-count query-273">16</span> رحلات</div>';
check(mad4b_wpml_destination_count_html_v1($wrapper('68816',$translated),'ar')===$wrapper('68816',$translated),'already translated unchanged');
$destinations='El-fayoum, Cairo, Giza, Luxor';
check(str_contains(mad4b_wpml_destination_count_html_v1($wrapper('68816',$widget.$destinations),'ar'),$destinations),'dynamic destination labels untouched');
echo "PASS: $passes locale, noninterference and idempotence assertions\n";
