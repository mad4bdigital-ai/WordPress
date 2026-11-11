<?php
define('ABSPATH','/');
$GLOBALS['hooks']=[];
$GLOBALS['lang']='en';
function add_filter($name,$fn,$prio=10,$args=1){$GLOBALS['hooks'][$name][$prio]=$fn;}
function apply_filters($name,$value){return $name==='wpml_current_language'?$GLOBALS['lang']:$value;}
function is_admin(){return false;}
function wp_doing_ajax(){return false;}
function esc_html($s){return htmlspecialchars($s,ENT_QUOTES,'UTF-8');}
function check($value,$title){if(!$value){fwrite(STDERR,'FAIL: '.$title."\n");exit(2);}}
require dirname(__DIR__).'/wp-content/mu-plugins/mad4b-service-inquiry-cta-v1.php';
$c=MAD4B_Service_Inquiry_CTA_V1::class;
$sample='<div><a class="elementor-button" href="#fluentform_6"><span class="elementor-button-content-wrapper"><span class="elementor-button-text">Book Now</span></span></a></div>';
$modified=$c::elementorCTA($sample);
check(str_contains($modified,'Request This Tour'),'Form CTA must reflect inquiry');
check(!str_contains($modified,'Book Now'),'Old booking promise removed');
check($c::elementorCTA('<a href="/book"><span class="elementor-button-text">Book Now</span></a>')==='<a href="/book"><span class="elementor-button-text">Book Now</span></a>','Unrelated actual booking CTA preserved');
foreach(['en','es','ar','fr','de','it'] as $l){
 $GLOBALS['lang']=$l;
 $label=$c::labels($l);
 check(str_contains($c::elementorCTA($sample),$label['anchor']),"CTA translated $l");
 $form=(object)['id'=>6,'fields'=>['fields'=>[['element'=>'custom_submit_button','settings'=>['button_ui'=>['text'=>'Submit']]]],
  'submitButton'=>['settings'=>['button_ui'=>['text'=>'Submit Form']]]]];
 $form=$c::fluentForm($form);
 check($form->fields['fields'][0]['settings']['button_ui']['text']===$label['submit'],"Form6 submit translated $l");
 check($form->fields['submitButton']['settings']['button_ui']['text']===$label['submit'],"Default button translated $l");
}
$GLOBALS['lang']='en';
$other=(object)['id'=>12,'fields'=>['fields'=>[]]];
check($c::fluentForm($other)===$other,'Unrelated form remains intact');
echo json_encode(['state'=>'PASS','checks'=>22,'live_form_data_written'=>false]).PHP_EOL;
