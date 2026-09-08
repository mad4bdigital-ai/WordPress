<?php
declare(strict_types=1);

function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value));}
function sanitize_text_field($value){return trim(strip_tags((string)$value));}
function etg_bg_expect($condition,$message){if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
function etg_bg_has($needle,$haystack,$message){etg_bg_expect(false!==strpos($haystack,$needle),$message);}

$root=dirname(__DIR__);
require_once $root.'/includes/Presentation/ContentSlotRegistry.php';
use ETG\DynamicFilterSEOBridge\Presentation\ContentSlotRegistry;

$modes=ContentSlotRegistry::mediaModes();
etg_bg_expect(count($modes)===7,'seven governed collection modes remain available');
$registry=new ContentSlotRegistry();
foreach(array_keys($modes)as$mode){
    $imageId=ContentSlotRegistry::backgroundSlotId('image',$mode);
    $galleryId=ContentSlotRegistry::backgroundSlotId('gallery',$mode);
    $image=$registry->get($imageId);$gallery=$registry->get($galleryId);
    etg_bg_expect(($image['type']??'')==='image','virtual background image slot type: '.$mode);
    etg_bg_expect(($gallery['type']??'')==='gallery','virtual background gallery slot type: '.$mode);
    etg_bg_expect(($image['media_mode']??'')===$mode,'image slot preserves mode: '.$mode);
    etg_bg_expect(($gallery['media_mode']??'')===$mode,'gallery slot preserves mode: '.$mode);
    etg_bg_expect(($gallery['authorizing']??true)===false,'virtual gallery remains non-authorizing: '.$mode);
    etg_bg_expect(($gallery['internal']??false)===true,'virtual gallery is internal and absent from editable defaults: '.$mode);
}
etg_bg_expect(count($registry->defaults())===8,'internal background transport slots do not clutter editable content slots');

$extension=file_get_contents($root.'/includes/Elementor/ContainerDynamicBackground.php');
$css=file_get_contents($root.'/assets/css/container-dynamic-background.css');
$js=file_get_contents($root.'/assets/js/container-dynamic-background.js');
$main=file_get_contents($root.'/etg-dynamic-filter-seo-bridge.php');

foreach(array(
    'elementor/element/container/section_background/after_section_end',
    'elementor/frontend/container/before_render',
    'ETG Dynamic Background',
    'ETG Dynamic Image',
    'ETG Dynamic Slideshow',
    'etg_dfsb_background_gallery',
    'ETG Filter Slideshow',
    'data-etg-dfsb-media-slot',
    "'data-etg-dfsb-media-target' => 'gallery'",
    'data-etg-dfsb-background-fallback',
    'data-etg-dfsb-background-desktop',
    'data-etg-dfsb-background-tablet',
    'data-etg-dfsb-background-mobile',
    'Preview Filter URL (Editor only)',
)as$needle){etg_bg_has($needle,$extension,'container extension contract: '.$needle);}

etg_bg_has('position: relative',$css,'container owns a positioning context');
etg_bg_has('overflow: hidden',$css,'background cannot bleed outside chosen container');
etg_bg_has('isolation: isolate',$css,'background stacking is isolated');
etg_bg_has('@media (prefers-reduced-motion: reduce)',$css,'reduced-motion CSS contract exists');
etg_bg_has('etg-dfsb/ajax-presentation-reset',$js,'filter clear/reset restores initial background state');
etg_bg_has('etg-dfsb/media-updated',$js,'AJAX media event drives live background refresh');
etg_bg_has("detail.image",$js,'single-image AJAX media payload supported');
etg_bg_has('MutationObserver',$js,'Elementor editor rerender can initialize new containers');
etg_bg_has("window.matchMedia('(prefers-reduced-motion: reduce)')",$js,'reduced-motion disables animation in runtime');
etg_bg_expect(false===strpos($js,'history.pushState')&&false===strpos($js,'history.replaceState'),'container background runtime cannot mutate browser history');
etg_bg_expect(false===strpos($extension,'update_option(')&&false===strpos($extension,'wp_update_post('),'container extension cannot mutate WordPress content/configuration');
etg_bg_expect(false===strpos($extension,'body')&&false===strpos($css,'body'),'background engine never targets body/root page');
etg_bg_has("define( 'ETG_DFSB_BOOT_BUILD', 'alpha13-container-background-1' );",$main,'replacement gets a new Safe Boot generation');
etg_bg_has('new ETG\\DynamicFilterSEOBridge\\Elementor\\ContainerDynamicBackground',$main,'container extension is registered inside guarded full boot');

echo "Alpha13 Elementor container dynamic-background smoke tests passed.\n";
