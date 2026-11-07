<?php
define('ABSPATH','/');
define('HOUR_IN_SECONDS',3600);
define('ARRAY_A','ARRAY_A');
class FakeGalleryDB {
    public $postmeta='wp_postmeta';
    public $posts='wp_posts';
    public $last_error='';
    public function prepare($sql,...$args){return $sql;}
    public function get_results($sql,$format) {
        if (strpos($sql,'SHA2(pm.meta_value')!==false)
            return [['post_id'=>101,'digest'=>str_repeat('a',64)],
                    ['post_id'=>102,'digest'=>str_repeat('a',64)]];
        return [
            ['post_id'=>321,'meta_value'=>'2025/11/a.jpg'],
            ['post_id'=>322,'meta_value'=>'2025/11/b.jpg'],
        ];
    }
}
$GLOBALS['wpdb']=new FakeGalleryDB();
$GLOBALS['saved_gallery_meta']='';
$GLOBALS['cache_data']=[];
function add_filter(...$args){}
function add_action(...$args){}
function get_post_type($id){return 'tours-and-activities';}
function get_post_status($id){return 'publish';}
function get_current_blog_id(){return 1;}
function absint($n){return abs((int)$n);}
function wp_parse_url($url){return parse_url($url);}
function wp_cache_get($key,$group){return $GLOBALS['cache_data'][$group][$key]??false;}
function wp_cache_set($key,$value,$group,$ttl){$GLOBALS['cache_data'][$group][$key]=$value;return true;}
function apply_filters($name,$value,...$rest){return $value;}
require dirname(__DIR__,3).'/wp-content/mu-plugins/mad4b-tour-gallery-presentation.php';
function verify($name,$ok){
    if(!$ok){fwrite(STDERR,'FAIL '.$name."\n");exit(1);}
    echo 'PASS '.$name."\n";
}
$original=implode(',',[
 'https://staging.legacy.example/wp-content/uploads/2025/11/a.jpg',
 'https://staging.legacy.example/wp-content/uploads/2025/11/b.jpg',
 'https://staging.legacy.example/wp-content/uploads/2025/11/a.jpg',
]);
$GLOBALS['saved_gallery_meta']=$original;
$display=MAD4B_Tour_Gallery_Presentation_V2::normalize($original,'gallery',101);
verify('legacy URL mapping into local media IDs',$display==='321,322');
verify('source gallery metadata remains unchanged',$GLOBALS['saved_gallery_meta']===$original);
verify('known cache reused',MAD4B_Tour_Gallery_Presentation_V2::normalize($original,'gallery',101)===$display);
verify('non-gallery meta passed through',MAD4B_Tour_Gallery_Presentation_V2::normalize($original,'unrelated',101)===$original);
$issues=MAD4B_Tour_Gallery_Advisory_V2::issues([],['post_types'=>['tours-and-activities']]);
verify('duplicate media creates one advisory per canonical tour',count($issues)===2);
verify('medium-only advisory',count(array_filter($issues,
    static function($row){return ($row['severity']??'')==='medium'&&
       ($row['code']??'')==='media_gallery_reused_across_tours';}))===2);
verify('no other data mutation performed',$GLOBALS['saved_gallery_meta']===$original);
echo json_encode(['result'=>'PASS','advisories'=>count($issues)])."\n";
