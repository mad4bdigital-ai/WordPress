<?php
declare(strict_types=1);
function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function ok($c,$m){if(!$c){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
function has($n,$h,$m){ok(false!==strpos($h,$n),$m);}
$root=dirname(__DIR__);
require_once $root.'/includes/Presentation/ContentSlotRegistry.php';
use ETG\DynamicFilterSEOBridge\Presentation\ContentSlotRegistry;
$modes=ContentSlotRegistry::mediaModes();ok(count($modes)===7,'seven governed collection modes remain available');
$r=new ContentSlotRegistry();
foreach(array_keys($modes) as $mode){
  $image=$r->get(ContentSlotRegistry::backgroundSlotId('image',$mode));
  $gallery=$r->get(ContentSlotRegistry::backgroundSlotId('gallery',$mode));
  ok(($image['type']??'')==='image'&&($gallery['type']??'')==='gallery','virtual background slot types: '.$mode);
  ok(($image['media_mode']??'')===$mode&&($gallery['media_mode']??'')===$mode,'virtual slots preserve collection mode: '.$mode);
  ok(($gallery['authorizing']??true)===false&&($gallery['internal']??false)===true,'virtual slots remain internal/non-authorizing: '.$mode);
}
ok(count($r->defaults())===8,'internal background slots stay outside editable Dynamic Content defaults');
$ext=file_get_contents($root.'/includes/Elementor/ContainerDynamicBackground.php');
$css=file_get_contents($root.'/assets/css/container-dynamic-background.css');
$js=file_get_contents($root.'/assets/js/container-dynamic-background.js');
$main=file_get_contents($root.'/etg-dynamic-filter-seo-bridge.php');
foreach(array('ETG Dynamic Background','ETG Dynamic Image','ETG Dynamic Slideshow','ETG Filter Slideshow','data-etg-dfsb-media-slot',"'data-etg-dfsb-media-target' => 'gallery'",'Preview Filter URL (Editor only)') as $n){has($n,$ext,'container extension contract: '.$n);}
has('sourceItems',$js,'uncompressed gallery survives responsive rerenders');
has('responsiveBreakpoints',$js,'runtime uses Elementor responsive breakpoints');
has('elementConnected',$js,'detached containers stop scheduling autoplay');
has("current.paused = true",$js,'mouseenter pauses autoplay');has("current.paused = false",$js,'mouseleave resumes autoplay');
has("items.length < minimum",$js,'minimum slide threshold is fail-stable');
has('contain: paint',$css,'background stage owns paint containment');
ok(!preg_match('/\.etg-dfsb-dynamic-background\s*\{[^}]*overflow\s*:\s*hidden/s',$css),'ETG must not clip arbitrary Container content');
ok(false===strpos($css,'will-change:'),'ETG must not permanently promote every slide layer');
ok(false===strpos($js,'history.pushState')&&false===strpos($js,'history.replaceState'),'background runtime cannot mutate browser history');
has("define( 'ETG_DFSB_BOOT_BUILD', 'alpha13-container-background-2' );",$main,'deep replacement advances Safe Boot generation');
has('new ETG\\DynamicFilterSEOBridge\\Elementor\\ContainerDynamicBackground',$main,'guarded full boot still registers container backgrounds');
echo "Alpha13 Elementor container dynamic-background deep smoke tests passed.\n";
