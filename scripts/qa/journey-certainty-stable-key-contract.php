<?php
if(!class_exists('MAD4B_Tour_Highlights_Presentation_V1',false))WP_CLI::error('Missing original presentation');
$c='MAD4B_Tour_Highlights_Presentation_V1';
$tests=[
 ['A visit to the Great Sphinx is included in the tour','Great Sphinx','included'],
 ['The Great Sphinx is optional on request','Great Sphinx','optional'],
 ['Great Sphinx is not included in this package','Great Sphinx','excluded'],
 ['Great Sphinx (optional stop if time allows)','Great Sphinx','optional'],
 ['No visit to the Great Sphinx is included','Great Sphinx','excluded'],
 ['Visite de la Grande Pyramide incluse','Grande Pyramide','included'],
 ['Gran Esfinge visita opcional','Gran Esfinge','optional'],
 ['La visite de la Grande Pyramide est exclue','Grande Pyramide','excluded'],
];
if(!method_exists($c,'visit_certainty'))WP_CLI::error('Certainty gate missing');
{
 $m=new ReflectionMethod($c,'visit_certainty');
 foreach($tests as [$text,$phrase,$expected]){
  $got=$m->invoke(null,$text,$phrase);
  echo wp_json_encode(['test'=>$phrase,'expected'=>$expected,'got'=>$got],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
  if($got!==$expected)WP_CLI::error('Certainty regression');
 }
}
if(!method_exists($c,'card_keys'))WP_CLI::error('Stable item keys missing');
{
 $cards=[
  ['title'=>'A scenic Nile stop','body'=>'A visit to the Great Sphinx'],
  ['title'=>'Local experiences','body'=>'Tour with a guide'],
  ['title'=>'A scenic Nile stop','body'=>'A visit to the Great Sphinx'],
 ];
 $a=$c::card_keys($cards);
 $b=$c::card_keys([$cards[1],$cards[0],$cards[2]]);
 echo wp_json_encode(['keys'=>$a,'reordered_keys'=>$b])."\n";
 if($a[0]!==$b[1]||$a[1]!==$b[0]||$a[2]!==$b[2]||count(array_unique($a))!==3)
  WP_CLI::error('Stable-key reorder/duplicate contract failed');
}
WP_CLI::success('Journey certainty/stable-key regression passed');
