<?php
/** Read-only primary tour vs JetEngine nested repeater query contract tests. */
if(PHP_SAPI!=='cli')exit(70);
require dirname(__DIR__,4).'/wp-load.php';
if(wp_get_environment_type()!=='local'||!function_exists('shared_presentation_primary_tour_post_v1'))exit(71);
$issues=[];$cases=0;
$check=static function(bool $ok,string $name)use(&$issues,&$cases){$cases++;if(!$ok)$issues[]=$name;echo ($ok?'PASS ':'FAIL ').$name."\n";};
$tours=get_posts(['post_type'=>'tours-and-activities','post_status'=>'publish','posts_per_page'=>1,'orderby'=>'ID','order'=>'DESC','suppress_filters'=>true]);
if(!$tours){echo 'NO_TOURS_IN_TARGET\n';exit(72);}
$tour=$tours[0];
$originalMain=$GLOBALS['wp_the_query']??null;
$originalItem=$GLOBALS['wp_query']??null;
$primary=new WP_Query();$primary->is_singular=true;$primary->is_single=true;$primary->queried_object=$tour;$primary->queried_object_id=$tour->ID;$primary->post=$tour;
$nested=new WP_Query();$nested->is_singular=true;$nested->is_single=true;
if(!class_exists('Jet_Engine_Queried_Repeater_Item'))require_once WP_PLUGIN_DIR.'/jet-engine/includes/classes/repeater-item.php';
$row=new Jet_Engine_Queried_Repeater_Item((object)['station_id'=>7,'sort_num'=>1,'time'=>'09:00'],0,$tour->ID,346);
$nested->queried_object=$row;
$GLOBALS['wp_the_query']=$primary;$GLOBALS['wp_query']=$nested;
try {
 $got=shared_presentation_primary_tour_post_v1();
 $check($got instanceof WP_Post && $got->ID===$tour->ID,'Nested repeater resolves primary Tour identity');
 $check($nested->queried_object===$row && !property_exists($row,'post_type'),'No mutation or fabricated post_type on repeater');
 $primary->queried_object=$row;
 $check(shared_presentation_primary_tour_post_v1()?->ID===$tour->ID,'Fallback to verified primary queried_object_id during JetEngine override');
 $primary->queried_object=null;$primary->queried_object_id=0;
 $check(shared_presentation_primary_tour_post_v1()===null,'Missing original queried identity fails closed');
 $primary->queried_object=$tour;$primary->queried_object_id=$tour->ID;$primary->is_singular=false;
 $check(shared_presentation_primary_tour_post_v1()===null,'Non-singular main query fails closed');
 $primary->is_singular=true;
 $other=get_posts(['post_type'=>'page','post_status'=>'publish','posts_per_page'=>1,'suppress_filters'=>true]);
 if($other){$primary->queried_object=$other[0];$primary->post=$other[0];$primary->queried_object_id=$other[0]->ID;
  $check(shared_presentation_primary_tour_post_v1()===null,'Non-tour singular post rejected');}
} finally { $GLOBALS['wp_the_query']=$originalMain;$GLOBALS['wp_query']=$originalItem; }
echo 'TOTAL='. $cases.' FAILED='.count($issues)."\n";
exit($issues?1:0);
