<?php
namespace DynamicFilterSEOBridge\Content { final class GalleryComposer {} final class ContentComposer {} }
namespace {
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
function taxonomy_exists($v){return in_array($v,['custom-place','other'],true);}
function get_option($k,$d=[]){return $k==='dfsb_shared_media_settings'?['enabled'=>true,'base_url'=>'https://media.example.com']: ($GLOBALS['options'][$k]??$d);}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function wp_parse_url($v,$component=-1){return parse_url($v,$component);}
class WP_Term {public function __construct(public int $term_id,public string $taxonomy,public string $slug){}}
function get_term($id,$tax){return $GLOBALS['terms'][$id]??null;}
function get_post_type($id){return in_array($id,[17,18],true)?'elementor_library':(in_array($id,[101,102,103],true)?'attachment':'');}
function wp_attachment_is_image($id){return $id!==103;}
function get_attached_file($id){return $id===102?__DIR__.'/missing-image.jpg':$GLOBALS['imageFile'];}
function wp_get_attachment_url($id){return 'https://company.example.com/uploads/'.$id.'.png';}
function wp_get_attachment_image_url($id,$size){return wp_get_attachment_url($id);}
function wp_upload_dir($time=null,$create=false){return ['baseurl'=>'https://company.example.com/uploads'];}
function wp_get_attachment_metadata($id){return ['width'=>1800,'height'=>900];}
function absint($v){return abs((int)$v);}

function get_post_meta($id,$key,$single){$GLOBALS['postReads'][]=[$id,$key];return ['shared:'.($id===17?'template.jpg':'other-template.jpg')];}
function get_term_meta($id,$key,$single){$GLOBALS['reads'][]=[$id,$key];return $GLOBALS['meta'][$id][$key]??[];}
function check($v,$m){if(!$v)throw new RuntimeException($m);}
define('ABSPATH',__DIR__);$root=dirname(__DIR__);require $root.'/includes/Runtime/LegacyOptionMirror.php';foreach(['ContentSlotRegistry','PresentationResolver','PortableMediaGallery','SharedMediaSources'] as $class)require $root.'/includes/Presentation/'.$class.'.php';
use DynamicFilterSEOBridge\Presentation\{PresentationResolver,ContentSlotRegistry};
$terms=[1=>new WP_Term(1,'custom-place','one'),2=>new WP_Term(2,'custom-place','two'),3=>new WP_Term(3,'other','three')];
$meta=[1=>['Gallery_Case'=>['shared:a.jpg','shared:dup.jpg','shared:b.jpg']],2=>['Gallery_Case'=>['shared:dup.jpg','shared:c.jpg','shared:d.jpg']],3=>['Gallery_Case'=>['shared:foreign.jpg']]];$reads=[];
$records=[];foreach($terms as $term)$records[]=['term_id'=>$term->term_id,'taxonomy'=>$term->taxonomy,'slug'=>$term->slug];
$records[]=['term_id'=>2,'taxonomy'=>'custom-place','slug'=>'wrong'];
$c=['active'=>true,'in_scope'=>true,'runtime_ready'=>true,'filters'=>['custom-place'=>['one','two']],'profile'=>['taxonomy_rules'=>['custom-place'=>['role'=>'place']]],'taxonomy_roles'=>['custom-place'=>'place'],'term_sets'=>['place'=>$records],'terms'=>['place'=>$records[0]]];
$r=new PresentationResolver(static fn()=>$c,new \DynamicFilterSEOBridge\Content\ContentComposer(),new \DynamicFilterSEOBridge\Content\GalleryComposer(),new ContentSlotRegistry());
$s=['dfsb_background_gallery_meta_taxonomy'=>'custom-place','dfsb_background_gallery_meta_key'=>'Gallery_Case','dfsb_background_gallery_meta_scope'=>'term_then_template','dfsb_background_gallery_media_policy'=>'shared_only'];
$out=$r->configuredTermGallery($s,$c,5);check(array_column($out,'path')===['a.jpg','dup.jpg','c.jpg','b.jpg','d.jpg'],'round robin and dedup');check($reads===[[1,'Gallery_Case'],[2,'Gallery_Case']],'exact source key and selected taxonomy only');
$bad=$c;$bad['in_scope']=false;$reads=[];check($r->configuredTermGallery($s,$bad)===null&&!$reads,'invalid evidence no reads');
$bad=$c;$bad['state_transport']='ajax';check($r->configuredTermGallery($s,$bad)===null&&!$reads,'unverified AJAX no reads');
$bad=$c;$bad['term_sets']['place']=[['term_id'=>2,'taxonomy'=>'custom-place','slug'=>'wrong']];check($r->configuredTermGallery($s,$bad)===[]&&!$reads,'wrong slug no reads');
$s['dfsb_background_gallery_media_policy']='local_only';check($r->configuredTermGallery($s,$c)===[],'shared excluded in local mode');
$s['dfsb_background_gallery_meta_scope']='template';$reads=[];check($r->configuredTermGallery($s,$c)===[]&&!$reads,'template only no term reads');
$element=new class {public function get_document(){return new class {public function get_main_id(){return 17;}};}};
$s['dfsb_background_gallery_media_policy']='shared_only';$postReads=[];$fallback=\DynamicFilterSEOBridge\Presentation\PortableMediaGallery::fromConfiguredMeta($element,$s);check(array_column($fallback,'path')===['template.jpg']&&$postReads===[[17,'Gallery_Case']],'case sensitive template fallback');
$s['dfsb_background_gallery_meta_scope']='term';$definition=ContentSlotRegistry::termGalleryDefinition($s);
$options=[ContentSlotRegistry::OPTION_NAME=>['exact'=>['id'=>'exact','type'=>'gallery','term_gallery'=>$definition]]];
check($r->slotGallery('exact',$c,5)===$r->configuredTermGallery($s,$c,5),'registered gallery and initial resolver parity');
check($r->slot('exact',$c)==='','shared gallery does not expose unrelated local IDs');
$missing=$c;$missing['term_sets']['place']=[];check($r->slotGallery('exact',$missing,5)===[],'no unrelated media fallback');
require $root.'/includes/Elementor/ContainerDynamicBackground.php';
$bg=new \DynamicFilterSEOBridge\Elementor\ContainerDynamicBackground($r,new ContentSlotRegistry());
$method=(new ReflectionClass($bg))->getMethod('templateGalleryItems');
$other=new class {public function get_document(){return new class {public function get_main_id(){return 18;}};}};
$reads=[];$postReads=[];
check(array_column($method->invoke($bg,$element,$s,8,2),'url')===['https://media.example.com/template.jpg'],'rendered template fallback');
check(array_column($method->invoke($bg,$other,$s,8,2),'url')===['https://media.example.com/other-template.jpg'],'separate template owner');
check($postReads===[[17,'Gallery_Case'],[18,'Gallery_Case']]&&!$reads,'template fallback reads only exact document field');
$imageFile=tempnam(sys_get_temp_dir(),'dfsb-image-');file_put_contents($imageFile,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jP0cAAAAASUVORK5CYII='));
try {
    $s['dfsb_background_gallery_media_policy']='mixed';$meta[1]['Gallery_Case']=[101,102,103,'shared:a.jpg'];$meta[2]['Gallery_Case']=[];
    $options[ContentSlotRegistry::OPTION_NAME]['exact']['term_gallery']=ContentSlotRegistry::termGalleryDefinition($s);
    $initial=$r->configuredTermGallery($s,$c,5);$ajax=$c;$ajax['state_transport']='ajax';$ajax['provider_observation_matches_state']=true;$ajax['presentation_state_complete']=true;
    $live=$r->slotGallery('exact',$ajax,5);check($initial===$live&&count($live)===2,'exact initial AJAX media parity');
    check($live[0]['id']===101&&$live[0]['url']==='/uploads/101.png'&&$live[0]['width']===1800&&$live[0]['height']===900&&$live[0]['aspect_ratio']===2.0&&$live[0]['dimensions_source']==='attachment_metadata','local measured dimensions');
    check($live[1]['width']===0&&$live[1]['height']===0&&$live[1]['dimensions_source']==='unknown','unverified shared dimensions explicit');
    $normalize=(new ReflectionClass($bg))->getMethod('normalizeImage');check($normalize->invoke($bg,$initial[0])===$live[0],'Elementor uses same local descriptor');
    $policy=['name'=>'wide','min_width'=>1200,'min_height'=>0,'min_ratio'=>1.5];$suitable=(new ReflectionClass($bg))->getMethod('filterSuitableItems');check($suitable->invoke($bg,$live,$policy)===[$live[0]],'wide hero rejects unknown dimensions');
}finally{unlink($imageFile);}
echo "PASS: configured taxonomy/key, selected terms, balanced dedup, source policy, evidence and template isolation\n";
}
