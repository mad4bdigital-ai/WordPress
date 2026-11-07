<?php
/**
 * Hermetic regression: a public hierarchy node is not required to be a
 * Featured commercial card. No WordPress DB or host write occurs.
 */
declare(strict_types=1);
define('ABSPATH', '/');
define('MAD4B_COMMERCE_RUNTIME_ENABLED', true);
class WP_Term {
    public $term_id; public $name; public $parent; public $taxonomy='destinations';
    public function __construct(int $id,string $name,int $parent=0) {
        $this->term_id=$id; $this->name=$name; $this->parent=$parent;
    }
    public static function get_instance(int $id) { return $GLOBALS['terms'][$id]??null; }
}
$GLOBALS['terms']=[
  665=>new WP_Term(665,'Egypt'),
  722=>new WP_Term(722,'Alexandria',665),
  2954=>new WP_Term(2954,'Alexandria City',722)
];
$GLOBALS['flags']=[
  665=>['is_public'=>'1','destination_type'=>'country','is_featured'=>'true'],
  722=>['is_public'=>'1','destination_type'=>'state_governorate','is_featured'=>'true','google_address'=>'Alexandria, Egypt'],
  2954=>['is_public'=>'1','destination_type'=>'city','is_featured'=>'false']
];
$GLOBALS['active_governors']=[722];
function get_term_meta($id,$key,$single=true){return $GLOBALS['flags'][$id][$key]??'';}
function destinations_activation_allowed($id){return true;}
function native_destination_configured($lang){return [['id'=>665]];}
function native_destinations_term_has_featured_image($term){return true;}
function get_ancestors($id,$taxonomy,$kind){return [2954=>[722,665],722=>[665],665=>[]][$id]??[];}
function get_term_children($id,$taxonomy){return [722=>[2954],665=>[722,2954]][$id]??[];}
function apply_filters($tag,$value,...$args){return $tag==='wpml_current_language'?'en':$value;}
function get_option($name,$default=[]){return $name==='destination-activation'?['active_governorates'=>$GLOBALS['active_governors']]:$default;}
function wp_strip_all_tags($value){return strip_tags($value);}
function is_wp_error($value){return false;}
require dirname(__DIR__,3).'/packages/mad4b-shared-product-core/mu-plugins/shared-product-core/adapters/presentation-destination-term-v1.php';
function verify($name,$ok){if(!$ok){fwrite(STDERR,"FAIL ".$name."\n");exit(1);}echo "PASS ".$name."\n";}
$c='Shared_Product_Destination_Term_Presentation_v1';
$city=$GLOBALS['terms'][2954];
$gov=$GLOBALS['terms'][722];
verify('public nonfeatured city is available for geographic browsing',$c::eligible_for_hierarchy($city,'en')===true);
verify('city remains absent from Featured cards',$c::eligible($city,'en')===false);
verify('city does not become a sale offer',$c::eligible_for_sale($city,'en')===false);
$related=$c::by_query_id($gov,285);
verify('governorate contains activated public city',count($related)===1 && $related[0]->term_id===2954);
$GLOBALS['flags'][2954]['is_public']='0';
verify('hidden city remains hidden',$c::eligible_for_hierarchy($city,'en')===false);
$GLOBALS['flags'][2954]['is_public']='1';
$GLOBALS['active_governors']=[];
verify('inactive governorate remains gated',$c::eligible_for_hierarchy($city,'en')===false);
$GLOBALS['active_governors']=[722];
verify('original editable address is preserved',$c::field($gov,'address')==='Alexandria, Egypt');
echo "RESULT=PASS\n";
