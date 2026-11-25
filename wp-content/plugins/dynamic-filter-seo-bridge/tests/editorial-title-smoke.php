<?php
namespace DynamicFilterSEOBridge\Content { final class GalleryComposer {} }
namespace {
function sanitize_key($s){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$s));}
function apply_filters($hook,$value,$context){$GLOBALS['hooks'][]=$hook;return $value;}
require dirname(__DIR__).'/includes/Content/ContentComposer.php';
$c=new \DynamicFilterSEOBridge\Content\ContentComposer(new \DynamicFilterSEOBridge\Content\GalleryComposer());
function check($expected,$actual){if($expected!==$actual){fwrite(STDERR,var_export([$expected,$actual],true)."\n");exit(1);}}
$records=[['term_id'=>1,'name'=>'Aswan'],['term_id'=>2,'name'=>'Cairo'],['term_id'=>3,'name'=>'Luxor']];
$context=['profile'=>['composition_mode'=>'travel'],'terms'=>['location'=>$records[0]],'term_sets'=>['location'=>$records]];
foreach(['en'=>'Aswan, Cairo & Luxor','es'=>'Aswan, Cairo y Luxor','ar'=>'Aswan، Cairo وLuxor','de'=>'Aswan, Cairo und Luxor','fr'=>'Aswan, Cairo et Luxor','it'=>'Aswan, Cairo e Luxor'] as $lang=>$expected){$context['language']=$lang;check($expected,$c->title($context));}
$context['language']='en';$context['term_sets']['location']=[$records[0],$records[0],$records[1]];check('Aswan & Cairo',$c->title($context));
$context['term_sets']=[];check('Aswan',$c->title($context));
$context=['profile'=>['composition_mode'=>'generic'],'language'=>'en','term_sets'=>['destination'=>$records]];check('Aswan, Cairo & Luxor',$c->title($context));
check(['etg_filter_seo_h1','dfsb/seo/h1'],array_slice($GLOBALS['hooks'],-2));
echo "PASS: native multi-term title, six locales, generic roles, duplicate suppression, legacy fallback and hooks\n";
}
