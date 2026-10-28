<?php
/** WordPress-free contract smoke suite. Intentionally performs zero DB/network I/O. */
error_reporting(E_ALL);
define('ABSPATH',__DIR__);
function wp_get_environment_type(){return 'local';}
function get_post_type($id){return $id===201?'tours-and-activities':($id===202?'transportations':'page');}
class WP_Post {public int $ID;public string $post_type;function __construct(int $id,string $type){$this->ID=$id;$this->post_type=$type;}}
class WP_Query {public bool $is_singular=false;public mixed $queried_object=null;public int $queried_object_id=0;public mixed $post=null;}
function get_post($id){return new WP_Post((int)$id,get_post_type((int)$id));}
require __DIR__.'/../mu-plugins/shared-product-core/contracts/field-shape-contract-v1.php';
require __DIR__.'/../mu-plugins/shared-product-core/contracts/primary-tour-query-context-v1.php';
$fail=[];$passed=0;
$expect=function($condition,$description)use(&$fail,&$passed){$passed++;if(!$condition)$fail[]=$description;};
$c='Shared_Product_Field_Shape_Contract_v1';
$invalid=[['id'=>'one','settings'=>['jedv_conditions'=>[['__dynamic__'=>['jedv_field'=>'[elementor-tag id="x" name="jet-post-custom-image" settings="%7B%7D"]']]]],'elements'=>[]]];
$valid=[['id'=>'one','settings'=>['__dynamic__'=>['background_image'=>'[elementor-tag id="x" name="jet-post-custom-image" settings="%7B%7D"]']],'elements'=>[]]];
$expect(count($c::inspect_elementor($invalid))===1,'invalid text/image binding rejected');
$expect($c::inspect_elementor($valid)===[],'valid image binding accepted');
$expect($c::inspect_repeater_value([['time'=>'09:00']])===[],'repeater array accepted');
$expect(count($c::inspect_repeater_value('[{"time":"09:00"}]'))===1,'serialized JSON string blocked');
$expect($c::is_guarded_repeater(201,'faq_item'),'tour FAQ guarded');
$expect($c::is_guarded_repeater(202,'trans_train_stops'),'transport stops guarded');
$tour=new WP_Post(201,'tours-and-activities');
$main=new WP_Query();$main->is_singular=true;$main->queried_object=$tour;$main->queried_object_id=201;$main->post=$tour;
$nested=new WP_Query();$nested->is_singular=true;$nested->queried_object=(object)['station_id'=>15];
$GLOBALS['wp_the_query']=$main;$GLOBALS['wp_query']=$nested;
$expect(shared_presentation_primary_tour_post_v1()?->ID===201,'nested repeater context resolves original tour');
$expect(!property_exists($nested->queried_object,'post_type'),'repeater not modified');
$main->queried_object=$nested->queried_object;
$expect(shared_presentation_primary_tour_post_v1()?->ID===201,'verified queried id falls back to real post');
$main->queried_object_id=0;$main->post=null;
$expect(shared_presentation_primary_tour_post_v1()===null,'missing original context denied');
$main->is_singular=false;
$expect(shared_presentation_primary_tour_post_v1()===null,'archive context denied');
$main->is_singular=true;$main->queried_object=new WP_Post(205,'page');
$expect(shared_presentation_primary_tour_post_v1()===null,'non-tour post denied');
if($fail){fwrite(STDERR,implode("\n",$fail)."\n");exit(1);}
echo 'OFFLINE_CONTRACT_SMOKE PASS '.$passed.'/'.$passed.' php='.PHP_VERSION."\n";
