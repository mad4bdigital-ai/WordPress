<?php
namespace BookingExperience {
 function tour_stay_rate_plan($id){ return $GLOBALS['plan']; }
}
namespace {
 define('ABSPATH','/');
 $hooks=[];
 function add_action($key,$cb,...$args){$GLOBALS['hooks'][$key]=$cb;}
 function add_filter($key,$cb,...$args){$GLOBALS['filters'][$key]=$cb;}
 function is_singular(){return true;}
 function content_url($path=''){return 'https://tours.dev.mad4b.com/wp-content/'.$path;}
 function get_the_ID(){return 201;}
 function get_post_type($id){return $id===201?'tour-rates':(in_array($id,[101,102],true)?'properties':null);}
 function get_post_status($id){return in_array($id,[201,101,102],true)?'publish':'draft';}
 function get_post_meta($id,$key,$one=true){return $GLOBALS['has_properties'];}
 function is_wp_error($s){return false;}
 function __($string,$domain){return $string;}
 function wp_json_encode($p){return json_encode($p);}
 class WP_Post {public $ID=201; public $post_type='tour-rates';}
 function jet_engine(){return (object)['listings'=>(object)['data'=>new class {function get_current_object(){return new WP_Post();}}]];}
 class WidgetTest {
  public $attrs=[];
  function get_name(){return 'jet-listing-grid';}
  function get_settings($key){return $key==='_css_classes'?'booking-choice-list':'';}
  function add_render_attribute($name,$key,$value){$this->attrs[$key]=$value;}
 }
 $assert=function($ok,$label){if(!$ok){fwrite(STDERR,'FAIL '.$label."\n");exit(1);}echo 'PASS '.$label."\n";};
 require dirname(__DIR__,4).'/wp-content/mu-plugins/listing-option-selection.php';
 $f=$hooks['elementor/frontend/widget/before_render'];
 $run=function($plan,$has='1')use($f){$GLOBALS['plan']=$plan;$GLOBALS['has_properties']=$has;$w=new WidgetTest();$f($w);return [$w->attrs['data-booking-choice-eligible'],json_decode($w->attrs['data-booking-choice-plan'],true)];};
 [$eligible,$plan]=$run(['mode'=>'simple_or','properties'=>[],'stages'=>[]]);
 $assert($eligible==='0'&&$plan['mode']==='unavailable'&&$plan['reason']==='no_eligible_rate_properties','Empty Rate-to-Properties relation hides the entire hotel selector');
 [$eligible,$plan]=$run(['mode'=>'simple_or','properties'=>[101],'stages'=>[]]);
 $assert($eligible==='1'&&$plan['stages'][0]['properties']===[101],'Published linked Property preserves hotel choice');
 [$eligible,$plan]=$run(['mode'=>'simple_or','properties'=>[999],'stages'=>[]]);
 $assert($eligible==='0','Unpublished, foreign or invalid Property is not an eligible display choice');
 [$eligible,$plan]=$run(['mode'=>'multi_stage','stages'=>[['key'=>'Cairo','label'=>'Cairo','properties'=>[101]],['key'=>'Luxor','label'=>'Luxor','properties'=>[102]]]]);
 $assert($eligible==='1'&&count($plan['stages'])===2,'Real multi-stage property plans remain fully visible');
 [$eligible,$plan]=$run(['mode'=>'simple_or','properties'=>[101]],'0');
 $assert($eligible==='0','Explicitly disabled Rate accommodation remains hidden regardless of the raw plan');
}
