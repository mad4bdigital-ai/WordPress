<?php
declare(strict_types=1);
define('ABSPATH','/');
$GLOBALS['lang']='en';
function add_filter(...$args){}
function add_action(...$args){}
function has_action(...$args){return false;}
function apply_filters($name,$value,...$args){return $value;}
function get_locale(){return $GLOBALS['lang'].'_XX';}
function is_tax(...$args){return true;}
function wp_json_encode($data,$options=0){return json_encode($data,$options|JSON_THROW_ON_ERROR);}
require dirname(__DIR__,2).'/wp-content/mu-plugins/mad4b-destination-locale-fallback.php';
function check($value,$why){if(!$value)throw new RuntimeException($why);}
function node($before,$after){
 $tag='[elementor-tag id="x" name="archive-title" settings="'.rawurlencode(json_encode(['include_context'=>'','before'=>$before,'after'=>$after],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)).'"]';
 return [['elType'=>'widget','widgetType'=>'heading','id'=>'9871f1f','settings'=>['__dynamic__'=>['title'=>$tag],'title'=>'']]];
}
function settings($data){
 preg_match('/settings="([^"]+)"/',$data[0]['settings']['__dynamic__']['title'],$matches);
 return json_decode(rawurldecode($matches[1]),true,512,JSON_THROW_ON_ERROR);
}
foreach([
 'ar'=>['id'=>48542,'b'=>'استكشف معالم ','a'=>' السياحية','tree'=>'استكشف %s والوجهات القريبة'],
 'es'=>['id'=>56110,'b'=>'Explora las atracciones de ','a'=>'','tree'=>'Explora %s y destinos cercanos'],
 'it'=>['id'=>56096,'b'=>'Esplora le attrazioni di ','a'=>'','tree'=>'Esplora %s e le destinazioni vicine'],
 'fr'=>['id'=>45886,'b'=>'Découvrez les attractions de ','a'=>'','tree'=>'Explorez %s et les destinations proches'],
 'de'=>['id'=>45886,'b'=>'Entdecken Sie die Sehenswürdigkeiten von ','a'=>'','tree'=>'Entdecken Sie %s und nahegelegene Reiseziele'],
] as $lang=>$d){
 $GLOBALS['lang']=$lang;
 $input=node('Explore ',' Attractions..');
 $output=MAD4B_Destination_Locale_Fallback::editor_data($input,$d['id']);
 $s=settings($output);
 check(($s['before']??null)===$d['b'] && ($s['after']??null)===$d['a'],'Invalid dynamic heading '.$lang);
 $tree=MAD4B_Destination_Locale_Fallback::dynamic_heading_translate('Explore %s & Nearby Destinations','Explore %s & Nearby Destinations','default');
 check($tree===$d['tree'],'Invalid tree label '.$lang);
 check(MAD4B_Destination_Locale_Fallback::dynamic_heading_translate('Editor custom translated','Explore %s & Nearby Destinations','default')==='Editor custom translated','Overwrote editor translation '.$lang);
 check($input===node('Explore ',' Attractions..'),'Mutated original source '.$lang);
 echo "DYNAMIC_HEADING_PASS $lang\n";
}
$GLOBALS['lang']='es';
$translated=node('Explora las atracciones de ','');
check(settings(MAD4B_Destination_Locale_Fallback::editor_data($translated,56110))===settings($translated),'Editor translation overwritten');
echo "MAD4B_WPML_SOURCE_ORDER_I18N_PASS\n";
