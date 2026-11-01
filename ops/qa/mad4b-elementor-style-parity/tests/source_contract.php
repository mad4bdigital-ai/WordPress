<?php
// Isolated pure-merge source assertions; no WordPress data or network dependency.
define('ABSPATH','/');
function add_action(...$args){}
require dirname(__DIR__,4).'/wp-content/mu-plugins/305-mad4b-elementor-style-parity.php';
$assert=static function($v,$name){if(!$v){fwrite(STDERR,'FAIL '.$name."\n");exit(1);}echo 'PASS '.$name."\n";};
$src=[['id'=>'w1','elType'=>'widget','widgetType'=>'button','settings'=>[
 'text'=>'English','link'=>['url'=>'/en/plan/'],
 '__globals__'=>['button_text_color'=>'globals/colors?id=80789f7',
 'button_background_hover_color'=>'globals/colors?id=e14cd5e','hover_color'=>'globals/colors?id=text'],
 'hover_animation'=>'grow']]];
$target=[['id'=>'w1','elType'=>'widget','widgetType'=>'button','settings'=>[
 'text'=>'تخطيط الرحلة','link'=>['url'=>'/ar/plan/'],
 'css_classes'=>'rtl',
 '__globals__'=>['button_text_color'=>'globals/colors?id=secondary',
 'button_background_hover_color'=>'globals/colors?id=secondary','hover_color'=>'globals/colors?id=primary'],
 'hover_animation'=>'none']]];
$out=MAD4B_Elementor_Style_Parity_V1::merge_test($src,$target);
$settings=$out['tree'][0]['settings'];
$assert(count($out['plans']['w1'])===4,'normal, hover, and animation divergence detected');
$assert(($settings['__globals__']['button_text_color']??'')==='globals/colors?id=80789f7','normal text token restored');
$assert(($settings['__globals__']['button_background_hover_color']??'')==='globals/colors?id=e14cd5e','hover background restored');
$assert(($settings['__globals__']['hover_color']??'')==='globals/colors?id=text','hover text restored');
$assert(($settings['hover_animation']??'')==='grow','hover animation restored');
$assert(($settings['text']??'')==='تخطيط الرحلة','translated text not overwritten');
$assert(($settings['link']['url']??'')==='/ar/plan/','localized URL not overwritten');
$assert(($settings['css_classes']??'')==='rtl','RTL class not overwritten');
$repeat=MAD4B_Elementor_Style_Parity_V1::merge_test($src,$out['tree']);
$assert(!$repeat['plans'],'second execution is idempotent');
$missing=MAD4B_Elementor_Style_Parity_V1::merge_test($src,[]);
$assert(!$missing['plans']&&$missing['tree']===[],'missing source widgets not inserted blindly');
$other=MAD4B_Elementor_Style_Parity_V1::merge_test($src,[['id'=>'w1','elType'=>'widget','widgetType'=>'heading','settings'=>['text'=>'Heading']]]);
$assert(!$other['plans'],'widget type mismatch leaves translated content alone');
