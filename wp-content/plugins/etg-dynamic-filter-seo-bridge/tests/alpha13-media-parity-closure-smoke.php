<?php
declare(strict_types=1);

function etg_media_parity_expect($condition,$message){if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
function etg_media_parity_has($needle,$haystack,$message){etg_media_parity_expect(false!==strpos($haystack,$needle),$message);}

$root=dirname(__DIR__);
$container=file_get_contents($root.'/includes/Elementor/ContainerDynamicBackground.php');
$sources=file_get_contents($root.'/includes/Presentation/ContentSourceResolver.php');
$presentation=file_get_contents($root.'/includes/Presentation/PresentationResolver.php');
$gallery=file_get_contents($root.'/includes/Content/GalleryComposer.php');
$imageTag=file_get_contents($root.'/includes/Elementor/DynamicTags/FilterImageTag.php');
$shortcodes=file_get_contents($root.'/includes/Elementor/Shortcodes.php');
$inspector=file_get_contents($root.'/includes/Admin/JetEngineInspectorPage.php');

etg_media_parity_has("if (\$items && count(\$items) < \$minimum) { \$items = array(\$items[0]); }",$container,'server initial slideshow must collapse under-minimum selected collection to its first healthy item');
etg_media_parity_expect(false===strpos($container,"resolver->gallery('combined'"),'server initial slideshow must not silently broaden the selected collection to combined');
etg_media_parity_has('discoveredMediaIds',$sources,'content source diagnostics preserve raw discovered media identity');
etg_media_parity_has('MediaAssetValidator::isRenderableImage',$sources,'content source renderable IDs are health filtered');
foreach(array('discovered_media_ids','rejected_media_ids','media_health_filtered','media_not_renderable') as $needle){etg_media_parity_has($needle,$sources,'content source diagnostic health contract: '.$needle);}
etg_media_parity_has('MediaAssetValidator::isRenderableImage',$gallery,'all GalleryComposer collections enforce the shared media-health gate');
etg_media_parity_has('healthCache',$gallery,'GalleryComposer caches health decisions for the request-scoped composer instance');
etg_media_parity_has('MediaAssetValidator::inspect',$imageTag,'Elementor image fallback attachment IDs must pass media health');
etg_media_parity_has('MediaAssetValidator::inspect',$shortcodes,'direct term image shortcodes must pass media health');
etg_media_parity_has("if('gallery_ids'===\$token){\$media=\$this->mediaArray",preg_replace('/\s+/','',$presentation),'gallery_ids presentation token must emit only renderable media');
etg_media_parity_has("in_array(\$field,array('image_id','image_url'),true)",preg_replace('/\s+/','',$presentation),'term image presentation tokens share the health resolver');
foreach(array('discovered_media_ids','media_ids (renderable)','rejected_media_ids','media_health_filtered') as $needle){etg_media_parity_has($needle,$inspector,'JetEngine Inspector exposes discovered versus renderable media: '.$needle);}

echo "Alpha13 media parity, renderable diagnostics and output-health closure tests passed.\n";
