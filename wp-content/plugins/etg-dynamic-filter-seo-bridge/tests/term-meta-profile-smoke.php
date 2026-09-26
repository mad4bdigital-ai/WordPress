<?php
declare(strict_types=1);
class WP_Term { public $term_id=7; public $taxonomy='property_city'; public $name='Cairo'; public $slug='cairo'; public $description='Default description'; public $count=12; public $parent=0; }

$GLOBALS['etg_term_meta_files']=array();
$etgTmp=sys_get_temp_dir().'/etg-term-meta-'.getmypid();
@mkdir($etgTmp,0777,true);
foreach(range(11,24)as$id){$path=$etgTmp.'/'.$id.'.jpg';file_put_contents($path,'image-'.$id);$GLOBALS['etg_term_meta_files'][$id]=$path;}

$GLOBALS['term_meta']=array(
  7=>array(
    'custom_seo_title'=>'Cairo Property SEO',
    'custom_meta'=>'Premium Cairo properties.',
    'gallery_primary'=>array(11,12),
    'gallery_secondary'=>'13,14',
    'thumbnail_id'=>15,
    'image'=>16,
  ),
);
function sanitize_key($key){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$key));}
function get_term_meta($id,$key,$single=true){return $GLOBALS['term_meta'][$id][$key]??'';}
function absint($v){return abs((int)$v);}
function get_ancestors($id,$taxonomy,$type){return array();}
function get_term($id,$taxonomy){return null;}
function apply_filters($hook,$value){return $value;}
function get_post_type($id){return isset($GLOBALS['etg_term_meta_files'][(int)$id])?'attachment':'post';}
function wp_attachment_is_image($id){return isset($GLOBALS['etg_term_meta_files'][(int)$id]);}
function wp_get_attachment_image_url($id,$size){return isset($GLOBALS['etg_term_meta_files'][(int)$id])?'https://example.test/uploads/'.$id.'.jpg':false;}
function get_attached_file($id){return $GLOBALS['etg_term_meta_files'][(int)$id]??'';}
function maybe_unserialize($value){if(!is_string($value)){return$value;}$out=@unserialize($value);return false===$out&&'b:0;'!==$value?$value:$out;}
function expect_same($expected,$actual,string $message):void{if($expected!==$actual){fwrite(STDERR,"FAILED: {$message}\nExpected: ".var_export($expected,true)."\nActual: ".var_export($actual,true)."\n");exit(1);}}
require_once dirname(__DIR__).'/includes/Terms/TermMetaReader.php';
use ETG\DynamicFilterSEOBridge\Terms\TermMetaReader;
$reader=new TermMetaReader();$term=new WP_Term();
$data=$reader->read($term,array(
 'seo_title'=>array('custom_seo_title'),
 'meta_description'=>array('custom_meta'),
 'gallery'=>array('gallery_primary','gallery_secondary'),
));
expect_same('Cairo Property SEO',$data['seo_title'],'profile field map overrides/adds SEO title source');
expect_same('Premium Cairo properties.',$data['meta_description'],'profile field map supplies custom description source');
expect_same(array(15,11,12,13,14),$data['gallery_ids'],'multiple configured gallery fields are aggregated and image prepended once');
$GLOBALS['term_meta'][7]['gallery_primary']=array(array('ID'=>21),array('id'=>22));
$GLOBALS['term_meta'][7]['gallery_secondary']=json_encode(array(23,24));
$data=$reader->read($term,array('gallery'=>array('gallery_primary','gallery_secondary')));
expect_same(array(15,21,22,23,24),$data['gallery_ids'],'array/object-like and JSON gallery values normalize across multiple fields');

@unlink($GLOBALS['etg_term_meta_files'][15]);
$data=$reader->read($term,array('gallery'=>array('gallery_primary','gallery_secondary')));
expect_same(16,$data['image_id'],'stale thumbnail attachment falls through to the next healthy configured image');
expect_same('image',$data['media_sources']['image'][0]['key'],'healthy fallback image source remains traceable');
expect_same(array(16,21,22,23,24),$data['gallery_ids'],'stale primary media is removed from runtime gallery payload without mutating source Meta');

foreach(range(11,24)as$id){if(isset($GLOBALS['etg_term_meta_files'][$id])){@unlink($GLOBALS['etg_term_meta_files'][$id]);}}
@rmdir($etgTmp);
fwrite(STDOUT,"Profile term-meta field mapping and media health smoke tests passed.\n");
