<?php
define('ABSPATH','/tmp/mad4b-fixture/');
$GLOBALS['mock']=[
  'options'=>['mad4b_ccss_public_dirty_v1'=>[
    'generation'=>1436,
    'ids'=>[
      'post:properties:35952'=>['generation'=>1435,'at'=>1795182088],
      'post:properties:35906'=>['generation'=>1436,'at'=>1795182088]]]],
  'meta'=>['tour_mode'=>'tour','cruise_runs'=>'','tour_related_rate_plans'=>'',
           'tour_queried_rates'=>'','related_program_terms'=>'','tour_cruise_amenities'=>''],
  'db'=>0,'post_status'=>'publish','titles'=>[35952=>'Sun Goddess',35906=>'M/S Nile Goddess']];
class MockDB {
 public $prefix='wp_';
 public function prepare($sql,...$args){return sprintf(str_replace('%d','%d',$sql),...$args);}
 public function get_var($sql){return $GLOBALS['mock']['db'];}
}
$GLOBALS['wpdb']=new MockDB;
function get_option($key,$default=null){return $GLOBALS['mock']['options'][$key]??$default;}
function get_post_meta($id,$key='',$single=false){
 $meta=$GLOBALS['mock']['meta'];
 return $key!==''?($meta[$key]??''):array_map(static fn($v)=>[$v],$meta);
}
function get_post_status($id){return $GLOBALS['mock']['post_status'];}
function get_the_title($id){return $GLOBALS['mock']['titles'][$id]??'';}
require dirname(__DIR__,3).'/wp-content/mu-plugins/422-mad4b-public-critical-css-v2-consumer.php';
$method=new ReflectionMethod('MAD4B_Public_Critical_CSS_Consumer_V2','unrelated_property_events');
$ctx=['type'=>'tours-and-activities','object_id'=>60473];
$html='<body class="page-id-60473"><main class="elementor-69052">Dolphin House Snorkeling Adventure</main></body>';
$original=$GLOBALS['mock'];
$checks=0;
function result($name,$expected,$ctx,$html) {
 global $method,$checks;
 $actual=$method->invoke(null,$ctx,1434,1436,$html);
 if ($actual!==$expected)throw new RuntimeException($name.' expected '.($expected?'true':'false').' actual '.($actual?'true':'false'));
 $checks++;echo "PASS $name
";
}
result('unrelated properties and contiguous history accepted',true,$ctx,$html);
$GLOBALS['mock']['options']['mad4b_ccss_public_dirty_v1']['ids']['post:properties:35952']['generation']=1434;
result('missing generation fails closed',false,$ctx,$html);
$GLOBALS['mock']=$original;
$GLOBALS['mock']['options']['mad4b_ccss_public_dirty_v1']['ids']['post:tours-and-activities:60655']=['generation'=>1436,'at'=>1795182088];
result('changed tour event fails closed',false,$ctx,$html);
$GLOBALS['mock']=$original;
$GLOBALS['mock']['meta']['tour_mode']='package';
result('package scope fails closed',false,$ctx,$html);
$GLOBALS['mock']=$original;
$GLOBALS['mock']['meta']['cruise_runs']='[{"program":1}]';
result('cruise programs fail closed',false,$ctx,$html);
$GLOBALS['mock']=$original;
$GLOBALS['mock']['meta']['property_link']='35952';
result('explicit property meta reference fails closed',false,$ctx,$html);
$GLOBALS['mock']=$original;
$GLOBALS['mock']['db']=1;
result('JetEngine relation fails closed',false,$ctx,$html);
$GLOBALS['mock']=$original;
$GLOBALS['mock']['db']=null;
result('failed relation lookup fails closed',false,$ctx,$html);
$GLOBALS['mock']=$original;
result('property title in HTML fails closed',false,$ctx,$html.'<div>Sun Goddess</div>');
result('property ID in HTML fails closed',false,$ctx,$html.'<div data-id="35906"></div>');
$result=$original;$GLOBALS['mock']=$result;
result('non-tour contexts fail closed',false,['type'=>'properties','object_id'=>35952],$html);
$GLOBALS['mock']=$original;
$GLOBALS['mock']['post_status']='draft';
result('unpublished tour fails closed',false,$ctx,$html);
$GLOBALS['mock']=$original;
$GLOBALS['mock']['options']['mad4b_ccss_public_dirty_v1']['generation']=1434;
result('stale ledger fails closed',false,$ctx,$html);
$GLOBALS['mock']=$original;
$GLOBALS['mock']['options']['mad4b_ccss_public_dirty_v1']['ids']['post:properties:35906']['generation']=1435;
result('duplicate generation fails closed',false,$ctx,$html);
echo "PASS $checks/$checks scoped dependency fixtures
";
