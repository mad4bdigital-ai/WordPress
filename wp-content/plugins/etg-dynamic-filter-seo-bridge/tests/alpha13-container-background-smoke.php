<?php
declare(strict_types=1);

function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value));}
function sanitize_text_field($value){return trim(strip_tags((string)$value));}
function absint($value){return abs((int)$value);}
function esc_url_raw($value){return filter_var((string)$value,FILTER_VALIDATE_URL)?(string)$value:'';}
function etg_bg_expect($condition,$message){if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
function etg_bg_has($needle,$haystack,$message){etg_bg_expect(false!==strpos($haystack,$needle),$message);}

$fixture=array();
function get_post_type($id){return 'attachment';}
function wp_attachment_is_image($id){return true;}
function wp_get_attachment_image_url($id,$size='full'){global $fixture;return isset($fixture[(int)$id]['url'])?$fixture[(int)$id]['url']:false;}
function get_attached_file($id){global $fixture;return isset($fixture[(int)$id]['file'])?$fixture[(int)$id]['file']:'';}

$root=dirname(__DIR__);
require_once $root.'/includes/Presentation/MediaAssetValidator.php';
require_once $root.'/includes/Presentation/ContentSlotRegistry.php';
require_once $root.'/includes/Elementor/ContainerDynamicBackground.php';
require_once $root.'/includes/Presentation/PresentationResolver.php';

use ETG\DynamicFilterSEOBridge\Presentation\ContentSlotRegistry;
use ETG\DynamicFilterSEOBridge\Elementor\ContainerDynamicBackground;
use ETG\DynamicFilterSEOBridge\Presentation\PresentationResolver;

$modes=ContentSlotRegistry::mediaModes();
etg_bg_expect(count($modes)===7,'seven governed collection modes remain available');
$registry=new ContentSlotRegistry();
foreach(array_keys($modes)as$mode){
    $image=$registry->get(ContentSlotRegistry::backgroundSlotId('image',$mode));
    $gallery=$registry->get(ContentSlotRegistry::backgroundSlotId('gallery',$mode));
    etg_bg_expect(($image['type']??'')==='image'&&($gallery['type']??'')==='gallery','virtual background slot types: '.$mode);
    etg_bg_expect(($image['media_mode']??'')===$mode&&($gallery['media_mode']??'')===$mode,'virtual slots preserve collection mode: '.$mode);
    etg_bg_expect(($gallery['authorizing']??true)===false&&($gallery['internal']??false)===true,'virtual slots remain internal/non-authorizing: '.$mode);
}
etg_bg_expect(count($registry->defaults())===8,'internal background slots stay outside editable Dynamic Content defaults');

$extension=file_get_contents($root.'/includes/Elementor/ContainerDynamicBackground.php');
$resolverSource=file_get_contents($root.'/includes/Presentation/PresentationResolver.php');
$bootstrap=file_get_contents($root.'/includes/Bootstrap.php');
$css=file_get_contents($root.'/assets/css/container-dynamic-background.css');
$js=file_get_contents($root.'/assets/js/container-dynamic-background.js');
$main=file_get_contents($root.'/etg-dynamic-filter-seo-bridge.php');

foreach(array('ETG Dynamic Background','ETG Dynamic Image','ETG Dynamic Slideshow','ETG Filter Slideshow','data-etg-dfsb-media-slot',"'data-etg-dfsb-media-target' => 'gallery'",'Preview Filter URL (Editor only)')as$needle){etg_bg_has($needle,$extension,'container extension contract: '.$needle);}
etg_bg_has('MediaAssetValidator::inspect',$extension,'manual Elementor attachment IDs use shared media health');
etg_bg_has('MediaAssetValidator::inspect',$resolverSource,'all ID-based resolver media uses shared media health');
etg_bg_has('new ContainerDynamicBackground($this->presentation,$slots)',$bootstrap,'Bootstrap owns container extension with shared slot registry');
etg_bg_expect(false===strpos($main,'new ETG\\DynamicFilterSEOBridge\\Elementor\\ContainerDynamicBackground'),'plugin entrypoint no longer creates a second container composition root');
etg_bg_has("define( 'ETG_DFSB_BOOT_BUILD', 'alpha13-container-background-3' );",$main,'deep replacement advances Safe Boot generation');

etg_bg_has('hydrateSlide',$js,'slides hydrate lazily');
etg_bg_has('IntersectionObserver',$js,'off-screen autoplay can pause');
etg_bg_has("document.addEventListener('visibilitychange'",$js,'hidden tabs pause autoplay');
etg_bg_has('sourceItems',$js,'uncompressed gallery survives responsive rerenders');
etg_bg_has('responsiveBreakpoints',$js,'runtime uses Elementor responsive breakpoints');
etg_bg_has('elementConnected',$js,'detached containers stop scheduling autoplay');
etg_bg_has("items.length < minimum",$js,'minimum slide threshold is fail-stable');
etg_bg_expect(false===strpos($js,'history.pushState')&&false===strpos($js,'history.replaceState'),'background runtime cannot mutate browser history');
$buildStart=strpos($js,'function buildSlides');$hydrateStart=strpos($js,'function hydrateSlide');
etg_bg_expect(false!==$buildStart&&false!==$hydrateStart&&$hydrateStart>$buildStart,'lazy hydration functions are ordered');
$buildBody=substr($js,$buildStart,$hydrateStart-$buildStart);
etg_bg_expect(false===strpos($buildBody,'backgroundImage'),'buildSlides must not eagerly assign every background URL');

etg_bg_has(':where(.etg-dfsb-dynamic-background)',$css,'ETG positioning uses zero-specificity fallback so Elementor positioning can win');
etg_bg_has('z-index: -1',$css,'ETG stage sits behind native Elementor/UAE children');
etg_bg_has('contain: paint',$css,'background stage owns paint containment');
etg_bg_expect(false===strpos($css,'> :not(.etg-dfsb-background-stage)'),'ETG must not rewrite arbitrary third-party child z-index');
etg_bg_expect(false===strpos($css,'.elementor-background-overlay'),'ETG must not take ownership of Elementor overlay stacking');
etg_bg_expect(false===strpos($css,'will-change:'),'ETG must not permanently promote every slide layer');

// Behavioral media-health regression: a healthy attachment canonicalizes to its
// current URL, while an orphaned attachment cannot survive via a stale saved URL.
$healthyFile=tempnam(sys_get_temp_dir(),'etg-bg-');
$missingFile=$healthyFile.'-missing';
$fixture[101]=array('url'=>'https://example.test/uploads/healthy.jpg','file'=>$healthyFile);
$fixture[102]=array('url'=>'https://example.test/uploads/orphan.jpg','file'=>$missingFile);
$containerReflection=new ReflectionClass(ContainerDynamicBackground::class);
$container=$containerReflection->newInstanceWithoutConstructor();
$normalize=$containerReflection->getMethod('normalizeImage');$normalize->setAccessible(true);
$healthy=$normalize->invoke($container,array('id'=>101,'url'=>'https://example.test/uploads/stale-healthy.jpg'));
$orphan=$normalize->invoke($container,array('id'=>102,'url'=>'https://example.test/uploads/stale-orphan.jpg'));
$urlOnly=$normalize->invoke($container,array('id'=>0,'url'=>'https://cdn.example.test/hero.webp'));
etg_bg_expect(($healthy['id']??0)===101&&($healthy['url']??'')===$fixture[101]['url'],'healthy attachment uses canonical inspected URL');
etg_bg_expect(($orphan['id']??-1)===0&&($orphan['url']??'')==='','orphaned attachment cannot be revived by stale Elementor URL');
etg_bg_expect(($urlOnly['id']??-1)===0&&($urlOnly['url']??'')==='https://cdn.example.test/hero.webp','URL-only dynamic media remains supported');

$resolverReflection=new ReflectionClass(PresentationResolver::class);
$resolver=$resolverReflection->newInstanceWithoutConstructor();
$mediaArray=$resolverReflection->getMethod('mediaArray');$mediaArray->setAccessible(true);
$resolved=$mediaArray->invoke($resolver,array(101,102));
etg_bg_expect(count($resolved)===1&&($resolved[0]['id']??0)===101,'central presentation media array drops orphaned attachment IDs');
@unlink($healthyFile);

echo "Alpha13 Elementor container dynamic-background deep media/stack/performance smoke tests passed.\n";
