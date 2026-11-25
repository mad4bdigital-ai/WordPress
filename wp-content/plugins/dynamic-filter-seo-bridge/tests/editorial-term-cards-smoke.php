<?php
namespace DynamicFilterSEOBridge\Content { final class GalleryComposer {} final class ContentComposer {} }
namespace {
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function absint($v){return abs((int)$v);}
function wp_strip_all_tags($v){return strip_tags(preg_replace('/<(script|style)\\b[^>]*>.*?<\\/\\1>/is','',$v));}
function esc_html($v){return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
function esc_attr($v){return esc_html($v);}
function wp_kses_post($v){return $v;}
function check($v,$m){if(!$v)throw new RuntimeException($m);}
$root=dirname(__DIR__);require $root.'/includes/Presentation/ContentSlotRegistry.php';require $root.'/includes/Presentation/PresentationResolver.php';require $root.'/includes/Presentation/InventoryContentCatalog.php';require $root.'/includes/Presentation/AjaxPresentationEndpoint.php';
use DynamicFilterSEOBridge\Presentation\{PresentationResolver,ContentSlotRegistry,InventoryContentCatalog,AjaxPresentationEndpoint};
$slots=new ContentSlotRegistry();$r=new PresentationResolver(static fn()=>[],new \DynamicFilterSEOBridge\Content\ContentComposer(),new \DynamicFilterSEOBridge\Content\GalleryComposer(),$slots);
$one=['term_id'=>1,'name'=>'A & B','description'=>'<style>body{display:none}</style><span style="color:red">Copy</span>'];$two=['term_id'=>2,'name'=>'Second'];
$c=['active'=>true,'in_scope'=>true,'runtime_ready'=>true,'filters'=>['custom'=>[1,2]],'provider'=>'generic-provider','query_id'=>'arbitrary-query','profile'=>['taxonomy_rules'=>['custom'=>['role'=>'place']]],'terms'=>['place'=>$one],'term_sets'=>['place'=>[$one,$one,$two],'foreign'=>[['term_id'=>3,'name'=>'Foreign']]]];
$html=$r->value('editorial_cards',$c);check(substr_count($html,'data-term-id=')===2,'deduplicate');check(str_contains($html,'A &amp; B')&&str_contains($html,'Copy')&&!str_contains($html,'style=')&&!str_contains($html,'display:none')&&!str_contains($html,'Foreign'),'safe declared role copy');
check(str_contains($r->value('terms:place:sections',$c),'<details'),'role cards');check($r->value('terms:foreign:sections',$c)==='','foreign role rejected');
$single=$c;$single['term_sets']['place']=[$one];check(str_contains($r->value('editorial_cards',$single),'<article'),'single card');
$bad=$c;$bad['in_scope']=false;check($r->value('editorial_cards',$bad)==='','out of scope');$bad=$c;$bad['state_transport']='ajax';check($r->value('editorial_cards',$bad)==='','unverified AJAX');$bad['provider_observation_matches_state']=true;$bad['presentation_state_complete']=true;check($r->value('editorial_cards',$bad)===$html,'verified AJAX parity');
$catalog=(new InventoryContentCatalog())->build([],[$c['profile']]);foreach(['editorial_cards','terms:place:sections'] as $token)check($catalog['tokens'][$token]['type']==='html','catalog HTML type');
$ref=new ReflectionClass(AjaxPresentationEndpoint::class);$endpoint=$ref->newInstanceWithoutConstructor();$allowed=$ref->getMethod('tokenAllowed');check($allowed->invoke($endpoint,'editorial_cards',$c)&&$allowed->invoke($endpoint,'terms:place:sections',$c)&&!$allowed->invoke($endpoint,'terms:foreign:sections',$c),'endpoint scope');
$slot=$slots->get('editorial_cards');check($slot['origin']==='built_in'&&$slot['enabled']&&$slot['type']==='html','built-in selectable slot');
check($r->slot('editorial_cards',$c)===$html,'original slot renderer parity');check($r->slot('editorial_cards',$bad)===$html,'verified AJAX slot parity');
require $root.'/includes/Elementor/DynamicTags/DynamicTagRuntime.php';
\DynamicFilterSEOBridge\Elementor\DynamicTags\DynamicTagRuntime::configure($r,$slots,static fn()=>$catalog);
check(isset(\DynamicFilterSEOBridge\Elementor\DynamicTags\DynamicTagRuntime::slotOptionsByTypes(['html'])['editorial_cards']),'Elementor HTML selector');
check(!isset(\DynamicFilterSEOBridge\Elementor\DynamicTags\DynamicTagRuntime::slotOptionsByTypes(['image'])['editorial_cards']),'not an image slot');
echo "PASS: opt-in cards, declared roles, escaping, duplicates, single/multi, verified AJAX and catalog parity\n";
}
