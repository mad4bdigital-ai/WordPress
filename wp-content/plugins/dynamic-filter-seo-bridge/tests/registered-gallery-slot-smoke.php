<?php
namespace DynamicFilterSEOBridge\Content { final class GalleryComposer {public function ids($c,$mode){$GLOBALS['modes'][]=$mode;return [];}} final class ContentComposer {} }
namespace {
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function wp_kses_post($v){return $v;}
function get_option($k,$d=[]){return $GLOBALS['options'][$k]??$d;}
function check($v,$m){if(!$v)throw new RuntimeException($m);}
define('ABSPATH',__DIR__);$root=dirname(__DIR__);require $root.'/includes/Runtime/LegacyOptionMirror.php';foreach(['ContentSlotRegistry','PresentationResolver'] as $class)require $root.'/includes/Presentation/'.$class.'.php';require $root.'/includes/Elementor/ContainerDynamicBackground.php';
use DynamicFilterSEOBridge\Presentation\{PresentationResolver,ContentSlotRegistry};
$opt=ContentSlotRegistry::OPTION_NAME;$options=[$opt=>['custom_gallery'=>['id'=>'custom_gallery','label'=>'Company Gallery','type'=>'gallery','enabled'=>true],'disabled_gallery'=>['id'=>'disabled_gallery','type'=>'gallery','enabled'=>false],'text_slot'=>['id'=>'text_slot','type'=>'text','enabled'=>true]]];
$slots=new ContentSlotRegistry();$resolver=new PresentationResolver(static fn()=>[],new \DynamicFilterSEOBridge\Content\ContentComposer(),new \DynamicFilterSEOBridge\Content\GalleryComposer(),$slots);
$bg=new \DynamicFilterSEOBridge\Elementor\ContainerDynamicBackground($resolver,$slots);$ref=new ReflectionClass($bg);$select=$ref->getMethod('registeredGallerySlot');$choices=$ref->getMethod('registeredGalleryOptions')->invoke($bg);
check(isset($choices['custom_gallery'],$choices['hero_gallery'])&&!isset($choices['disabled_gallery'],$choices['text_slot']),'discover enabled gallery slots');
foreach(['custom_gallery'=>'custom_gallery','disabled_gallery'=>'','text_slot'=>'','etg_background_gallery_balanced'=>'','missing'=>''] as $id=>$expected)check($select->invoke($bg,['dfsb_background_registered_gallery_slot'=>$id])===$expected,'slot validation '.$id);
$initial=$ref->getMethod('initialGalleryItems');check($initial->invoke($bg,new stdClass(),'',[],8,2,'registered_slot')===[],'invalid selection no gallery');
$definition=['dfsb_background_gallery_meta_taxonomy'=>'custom-place','dfsb_background_gallery_meta_key'=>'Gallery_Case','dfsb_background_gallery_media_policy'=>'shared_only','dfsb_background_gallery_meta_scope'=>'term'];
$options[$opt]['exact']=['id'=>'exact','type'=>'gallery','term_gallery'=>$definition];
check($slots->matchingTermGallerySlot($definition)==='exact','unique exact match');
$withTemplate=$definition;$withTemplate['dfsb_background_gallery_meta_scope']='term_then_template';check($slots->matchingTermGallerySlot($withTemplate)==='exact','template fallback retains matching term source');
foreach(['dfsb_background_gallery_meta_key'=>'gallery_case','dfsb_background_gallery_media_policy'=>'mixed','dfsb_background_gallery_meta_taxonomy'=>'other','dfsb_background_gallery_meta_scope'=>'template'] as $k=>$v){$bad=$definition;$bad[$k]=$v;check($slots->matchingTermGallerySlot($bad)==='','reject mismatched '.$k);}
$options[$opt]['second']=['id'=>'second','type'=>'gallery','term_gallery'=>$definition];check($slots->matchingTermGallerySlot($definition)==='','ambiguous match');$options[$opt]['second']['enabled']=false;check($slots->matchingTermGallerySlot($definition)==='exact','disabled duplicate excluded');
$options[$opt]['broken']=['id'=>'broken','type'=>'gallery','term_gallery'=>['invalid'=>true]];check(!$slots->get('broken')['enabled'],'invalid definition does not use generic fallback');
echo "PASS: registered gallery discovery, disabled/type/unknown/virtual rejection, empty fallback\n";
}
