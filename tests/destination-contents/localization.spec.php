<?php
declare(strict_types=1);
// No WordPress bootstrap or external APIs: deterministic regression test.
define('ABSPATH', '/');
$GLOBALS['mad4b_test_language'] = 'en';
function apply_filters($hook, $value, ...$args) {
    if ($hook === 'wpml_current_language') return $GLOBALS['mad4b_test_language'];
    if ($hook === 'wpml_translate_single_string') throw new RuntimeException('Recursive WPML filter must not be invoked');
    return $value;
}
function add_filter(...$args) {}
function add_action(...$args) {}
function has_action(...$args) {return false;}
function is_tax(...$args) {return true;}
function get_locale(){return $GLOBALS['mad4b_test_language'].'_XX';}
function wp_json_encode($v,$flags=0) {return json_encode($v,$flags|JSON_THROW_ON_ERROR);}
require dirname(__DIR__,2).'/wp-content/mu-plugins/mad4b-destination-locale-fallback.php';
function check($ok, $message) {if (!$ok) throw new RuntimeException($message);}
function widgetMap($nodes) {
    $out=[];
    $walk=function($rows) use (&$walk,&$out) {
      foreach($rows as $row) {
       if(($row['elType']??'')==='widget') $out[$row['id']]=$row['settings']??[];
       if(!empty($row['elements']))$walk($row['elements']);
      }
    };$walk($nodes);return $out;
}
$base=[
  ['elType'=>'widget','widgetType'=>'heading','id'=>'489d7e55','settings'=>['title'=>'Customized Trips']],
  ['elType'=>'widget','widgetType'=>'heading','id'=>'63dc9a7e','settings'=>['title'=>'5K+ Happy Customer']],
  ['elType'=>'widget','widgetType'=>'uael-infobox','id'=>'5cb8cc5c','settings'=>[
    'infobox_title'=>'We Believe That Travel is a Personal Journey',
    'infobox_description'=>MAD4B_Destination_Locale_Fallback::originals()['belief_body'],
    'infobox_link_text'=>'Read More','infobox_button_text'=>'Click Here'
  ]]
];
$expected=[
 'en'=>'We Believe That Travel is a Personal Journey',
 'es'=>'Creemos que cada viaje es una experiencia personal',
 'ar'=>'نؤمن بأن السفر رحلة شخصية',
 'it'=>'Crediamo che ogni viaggio sia un’esperienza personale',
 'fr'=>'Nous pensons que chaque voyage est une expérience personnelle',
 'de'=>'Wir glauben, dass jede Reise etwas Persönliches ist'
];
$count=0;
foreach($expected as $lang=>$title) {
 $GLOBALS['mad4b_test_language']=$lang;
 $changed=MAD4B_Destination_Locale_Fallback::editor_data($base,45891);
 $w=widgetMap($changed);
 check($w['5cb8cc5c']['infobox_title']===$title,'Wrong locale '.$lang);
 check($w['5cb8cc5c']['infobox_description']!=='','Missing description '.$lang);
 check(($w['489d7e55']['title']??'')!=='' && ($w['63dc9a7e']['title']??'')!=='','Missing other fields '.$lang);
 check($base[2]['settings']['infobox_title']==='We Believe That Travel is a Personal Journey','Mutated original');
 $custom=$base;
 $custom[2]['settings']['infobox_title']='Editor-owned custom phrase';
 $override=widgetMap(MAD4B_Destination_Locale_Fallback::editor_data($custom,45891));
 check($override['5cb8cc5c']['infobox_title']==='Editor-owned custom phrase','Overwrote editor customization');
 $untouched=MAD4B_Destination_Locale_Fallback::editor_data($base,999999);
 check($untouched===$base,'Touched unrelated Elementor template');
 $count++;
}
echo "MAD4B_LOCALE_REGRESSION_PASS languages=$count overrides_preserved=6 nonmatching_templates_unchanged=6\n";
