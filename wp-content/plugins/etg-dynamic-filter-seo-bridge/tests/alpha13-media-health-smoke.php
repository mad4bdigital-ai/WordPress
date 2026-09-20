<?php
declare(strict_types=1);

class WP_Term {
    public $term_id=77;
    public $taxonomy='location_jet';
    public $name='Aswan';
    public $slug='aswan-gov';
    public $description='Aswan';
    public $count=10;
    public $parent=0;
}

$GLOBALS['etg_health_files']=array();
$GLOBALS['etg_allow_missing_local']=array();
$root=sys_get_temp_dir().'/etg-media-health-'.getmypid();
@mkdir($root,0777,true);
foreach(array(16,21,22,23,24) as$id){$path=$root.'/'.$id.'.jpg';file_put_contents($path,'image-'.$id);$GLOBALS['etg_health_files'][$id]=$path;}
$GLOBALS['etg_health_files'][15]=$root.'/missing-15.jpg';
$GLOBALS['etg_health_files'][30]=$root.'/offloaded-30.jpg';
$GLOBALS['etg_allow_missing_local'][30]=true;

$GLOBALS['term_meta']=array(
    77=>array(
        'thumbnail_id'=>15,
        'image'=>16,
        'hero_image'=>24,
        'gallery'=>'15,21,22',
        'hero_gallery'=>serialize(array(23,24)),
    ),
);

function sanitize_key($key){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$key));}
function absint($v){return abs((int)$v);}
function get_term_meta($id,$key,$single=true){return $GLOBALS['term_meta'][$id][$key]??'';}
function get_ancestors($id,$taxonomy,$type){return array();}
function get_term($id,$taxonomy){return null;}
function get_post_type($id){return in_array((int)$id,array(15,16,21,22,23,24,30),true)?'attachment':'post';}
function wp_attachment_is_image($id){return in_array((int)$id,array(15,16,21,22,23,24,30),true);}
function wp_get_attachment_image_url($id,$size){return wp_attachment_is_image($id)?'https://example.com/uploads/'.$id.'.jpg':false;}
function get_attached_file($id){return $GLOBALS['etg_health_files'][(int)$id]??'';}
function maybe_unserialize($value){if(!is_string($value)){return$value;}$out=@unserialize($value);return false===$out&&'b:0;'!==$value?$value:$out;}
function apply_filters($hook,$value){
    $args=func_get_args();
    if('etg_dfsb_media_allow_missing_local_file'===$hook){$id=(int)($args[2]??0);return !empty($GLOBALS['etg_allow_missing_local'][$id]);}
    return$value;
}
function etg_expect($value,$message){if(!$value){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
function etg_same($expected,$actual,$message){if($expected!==$actual){fwrite(STDERR,"FAIL: $message\nEXPECTED ".var_export($expected,true)."\nACTUAL ".var_export($actual,true)."\n");exit(1);}}

$rootPlugin=dirname(__DIR__);
require_once $rootPlugin.'/includes/Presentation/MediaAssetValidator.php';
require_once $rootPlugin.'/includes/Terms/TermMetaReader.php';

use ETG\DynamicFilterSEOBridge\Presentation\MediaAssetValidator;
use ETG\DynamicFilterSEOBridge\Terms\TermMetaReader;

$stale=MediaAssetValidator::inspect(15);
etg_same(false,$stale['valid'],'stale local attachment must be rejected');
etg_same('missing_local_file',$stale['reason'],'stale local attachment reports explicit health reason');
$good=MediaAssetValidator::inspect(16);
etg_same(true,$good['valid'],'existing local image attachment is renderable');
$offloaded=MediaAssetValidator::inspect(30);
etg_same(true,$offloaded['valid'],'explicit offload compatibility filter may allow missing local file');

$reader=new TermMetaReader();
$data=$reader->read(new WP_Term());
etg_same(16,$data['image_id'],'reader skips stale thumbnail_id and falls through to next healthy image key');
etg_same('image',$data['media_sources']['image'][0]['key'],'trace records the healthy fallback image source');
etg_same(array(16,21,22,23,24),$data['gallery_ids'],'gallery excludes stale media, preserves serialized gallery and prepends healthy primary once');
etg_expect(false===strpos(json_encode($data),'"id":15'),'stale media ID never enters runtime presentation payload');

foreach(array(16,21,22,23,24)as$id){@unlink($GLOBALS['etg_health_files'][$id]);}
@rmdir($root);
fwrite(STDOUT,"Alpha13 media health and stale attachment fallback tests passed.\n");
