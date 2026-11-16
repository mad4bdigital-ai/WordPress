<?php
declare(strict_types=1);
define('ABSPATH','/test');
$fixtures=[
  101=>(object)['ID'=>101,'post_type'=>'tour-rates','post_status'=>'publish'],
  202=>(object)['ID'=>202,'post_type'=>'tours-and-activities','post_status'=>'publish'],
  303=>(object)['ID'=>303,'post_type'=>'page','post_status'=>'publish'],
  404=>(object)['ID'=>404,'post_type'=>'tour-rates','post_status'=>'draft']
];
function add_action(...$args){} function add_filter(...$args){}
function get_post($id){return $GLOBALS['fixtures'][(int)$id]??null;}
function wp_is_post_revision($id){return false;}
function wp_is_post_autosave($id){return false;}
function get_post_type_object($type){return (object)['public'=>true];}
function get_post_type($id){return get_post($id)->post_type??false;}
function get_post_meta($id,$key,$single=true){return $key==='related_tour_id'&&in_array((int)$id,[101,404],true)?202:0;}
function get_permalink($id){$p=get_post($id);return 'https://tours.dev.mad4b.com/'.($p->post_type==='tour-rates'?'tour-rates':($p->post_type==='page'?'pages':'tours-and-activities')).'/'.(int)$id.'/';}
function get_option($name,$default=null){return $name==='home'?'https://tours.dev.mad4b.com':$default;}
function wp_parse_url($url,$part=-1){return parse_url($url,$part);}
require $argv[1];
$r=new ReflectionClass('MAD4B_Cache_Lifecycle_V1');
function readp($n){global $r;$p=$r->getProperty($n);if(PHP_VERSION_ID<80100)$p->setAccessible(true);return $p->getValue();}
function resetp(){global $r;foreach(['posts','extra','removed'] as $n){$p=$r->getProperty($n);if(PHP_VERSION_ID<80100)$p->setAccessible(true);$p->setValue(null,[]);}$p=$r->getProperty('membership_change');if(PHP_VERSION_ID<80100)$p->setAccessible(true);$p->setValue(null,false);}
function check($ok,$what){if(!$ok){fwrite(STDERR,"FAIL $what\n");exit(1);}echo "PASS $what\n";}
resetp();MAD4B_Cache_Lifecycle_V1::post(101);
check(array_keys(readp('posts'))===[202],'internal rate invalidates only linked public tour');
check(readp('extra')===['https://tours.dev.mad4b.com/tours-and-activities/202/'],'no internal-rate URL enqueued');
resetp();MAD4B_Cache_Lifecycle_V1::removed(101);
check(array_keys(readp('posts'))===[202],'removed internal rate still invalidates tour');
check(readp('removed')===[],'removed internal rate never generates 404 tombstone');
resetp();MAD4B_Cache_Lifecycle_V1::post(303);
check(array_keys(readp('posts'))===[303]&&count(readp('extra'))===1,'ordinary public page unchanged');
resetp();MAD4B_Cache_Lifecycle_V1::removed(303);
check(count(readp('removed'))===1,'ordinary removed page still creates tombstone');
resetp();MAD4B_Cache_Lifecycle_V1::post(404);
check(readp('posts')===[],'draft rate cannot enqueue public tour URL without force');
echo "PASS 7/7 targeted routing assertions\n";
