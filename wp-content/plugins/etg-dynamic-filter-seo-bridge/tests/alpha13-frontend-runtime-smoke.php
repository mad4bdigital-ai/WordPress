<?php
declare(strict_types=1);

function etg_expect($condition,$message){if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
function etg_has($needle,$haystack,$message){etg_expect(false!==strpos($haystack,$needle),$message);}

$root=dirname(__DIR__);
$js=file_get_contents($root.'/assets/js/ajax-filter-state.js');
$parser=file_get_contents($root.'/includes/JetSmartFilters/AjaxFilterStateParser.php');
$live=file_get_contents($root.'/includes/Elementor/DynamicTags/LiveBindingTrait.php');
$filter=file_get_contents($root.'/includes/Elementor/DynamicTags/FilterValueTag.php');
$slot=file_get_contents($root.'/includes/Elementor/DynamicTags/ContentSlotTag.php');
$inventory=file_get_contents($root.'/includes/Elementor/DynamicTags/InventoryValueTag.php');
$term=file_get_contents($root.'/includes/Elementor/DynamicTags/TermFieldTag.php');
$section=file_get_contents($root.'/includes/Elementor/DynamicTags/TermSectionTag.php');
$shortcodes=file_get_contents($root.'/includes/Elementor/Shortcodes.php');
$catalog=file_get_contents($root.'/includes/Presentation/InventoryContentCatalog.php');

foreach(array('autoGroupKey','activeGroupKeys','baseArchivePath','request_path','archive_path','data-etg-dfsb-fallback','ambiguous_auto_group') as $needle){etg_has($needle,$js,'browser hardening: '.$needle);}
etg_expect(false===strpos($js,'history.pushState')&&false===strpos($js,'history.replaceState'),'browser bridge cannot mutate history');
etg_has("strpos( $path, '/jsf/' )",$parser,'parser strips JetSmartFilters pretty path suffix');
etg_has("'request_path' => $requestPath",$parser,'parser preserves request path evidence');
etg_has("'archive_path' => $archivePath",$parser,'parser exposes base archive scope');
etg_has('AJAX Live Update',$live,'Elementor live toggle');
etg_has('AJAX Group',$live,'Elementor provider/query group control');
etg_has('data-etg-dfsb-group',$live,'live group attribute');
etg_has('data-etg-dfsb-fallback',$live,'fallback transport attribute');
etg_has('etgValueOrFallback',$filter,'base text tags preserve Elementor fallback');
foreach(array($slot,$inventory,$term,$section) as $source){etg_has('use LiveBindingTrait',$source,'dynamic content tag uses live binding trait');}
etg_has("$group='auto'",$shortcodes,'empty shortcode group becomes fail-closed Auto');
etg_has("'groups'=>$groups",$catalog,'catalog exposes governed provider/query groups');
etg_has("'authorizing'=>false",$catalog,'group catalog remains non-authorizing');
foreach(array($live,$filter,$slot,$inventory,$term,$section,$catalog) as $source){etg_expect(false===strpos($source,'update_option('),'front-end hardening cannot write options');}

echo "Alpha13 front-end runtime hardening smoke tests passed.\n";
