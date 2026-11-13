<?php
/** Hermetic WordPress-free Tour Highlights behavior contract; zero site writes. */
define('ABSPATH','/isolated-highlights-test/');
$GLOBALS['meta']=array(7=>array('tour_highlights_content'=>'Legacy Nile highlights',
 'tour_highlights_mode'=>'manual','tour_highlights_review_status'=>'pending',
 'tour_highlights_items'=>array()));
$GLOBALS['term_allowed']=true;
$GLOBALS['hooks']=array();
$GLOBALS['meta_write_count']=0;
function add_action($name,$callback,$priority=10,$args=1){$GLOBALS['hooks'][]=$name;}
function add_filter($name,$callback,$priority=10,$args=1){$GLOBALS['hooks'][]=$name;}
function get_post_type($id){return $id===7?'tours-and-activities':(in_array($id,[910,920],true)?'jet-engine':'unknown');}
function get_post_status($id){return $id===7||$id===910?'publish':'draft';}
function get_post_meta($id,$key,$single=false){return $GLOBALS['meta'][$id][$key]??'';}
function get_option($name,$fallback=array()){return $name==='look-and-feel'?array('tour_highlights_default_mode'=>'manual'): $fallback;}
$GLOBALS['language']='';
function apply_filters($name,$value,...$args){
 if($name==='wpml_current_language')return $GLOBALS['language'];
 if($name==='wpml_object_id'&&$args===['jet-engine',false,'fr']&&$value===45927)return 910;
 if($name==='wpml_object_id'&&$args===['jet-engine',false,'de']&&$value===45927)return 920;
 return $value;
}

function get_queried_object_id(){return 7;}
function get_the_ID(){return 7;}
function wp_strip_all_tags($raw){return strip_tags($raw);}
function esc_html($raw){return htmlspecialchars((string)$raw,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function wp_kses_post($raw){return strip_tags((string)$raw,'<p><strong><em><a><ul><li>');}
function is_wp_error($raw){return false;}
function wp_slash($value){return $value;}
function wp_is_post_revision($id){return false;}
function wp_is_post_autosave($id){return false;}
function update_post_meta($id,$key,$value,$previous=null){
 if(($GLOBALS['meta'][$id][$key]??'')!==$previous)return false;
 $GLOBALS['meta'][$id][$key]=$value;$GLOBALS['meta_write_count']++;return true;
}
class WP_Term { public $term_id;public $name;public function __construct($id,$name){$this->term_id=$id;$this->name=$name;} }
$GLOBALS['term']=new WP_Term(33,'Luxor');
function wp_get_object_terms($id,$taxonomy,$args=array()){return $id===7&&$taxonomy==='destinations'?array($GLOBALS['term']):array();}
function get_term_meta($id,$name,$single=false){return $id===33&&$name==='destination_type'?'place_attraction':'';}
function get_term($id,$taxonomy){return $id===33&&$taxonomy==='destinations'?$GLOBALS['term']:null;}
function has_term($id,$taxonomy,$post){return $id===33&&$taxonomy==='destinations'&&$post===7;}
function get_term_link($term,$tax=''){return 'https://example.invalid/locations/luxor/';}
class MAD4B_Company_Term_Eligibility_V1 { public static function decide($term,$cpt,$consumer,$published){
 if($cpt!=='tours-and-activities'||$consumer!=='destination_discovery')throw new RuntimeException('wrong consumer scope');
 return array('allowed'=>$GLOBALS['term_allowed']);
}}
require dirname(__DIR__,2).'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-tour-highlights-v1.php';
function prove($assertion,$why){if(!$assertion){fwrite(STDERR,'FAIL: '.$why."\n");exit(2);}}
$klass='MAD4B_Tour_Highlights_V1';
prove($klass::mode(7)==='manual','manual must remain fallback');
prove($klass::render() === 'Legacy Nile highlights','manual changed legacy content');
$GLOBALS['meta'][7]['tour_highlights_mode']='assisted';
prove($klass::render()==='Legacy Nile highlights','assisted unapproved content became public');
$GLOBALS['meta'][7]['tour_highlights_mode']='auto';
prove($klass::render()==='','auto published unreviewed suggestions');
$klass::on_save(7,null,true);
prove($GLOBALS['meta_write_count']===1,'editor save did not propose source-linked item');
$items=$GLOBALS['meta'][7]['tour_highlights_items'];
prove(count($items)===1&&$items[0]['tour_highlight_status']==='needs_review','suggestions must be non-publishable');
prove($GLOBALS['meta'][7]['tour_highlights_content']==='Legacy Nile highlights','legacy content mutated');
prove($klass::render()==='','proposal leaked before approval');
$GLOBALS['meta'][7]['tour_highlights_review_status']='approved';
prove($klass::render()==='','locale approval alone published unapproved row');
$items[0]['tour_highlight_status']='approved';
$GLOBALS['meta'][7]['tour_highlights_items']=$items;
prove(strpos($klass::render(),'Luxor')!==false,'verified approved destination missing');
$GLOBALS['meta'][7]['tour_highlights_items']=array(array('tour_highlight_status'=>'approved','tour_highlight_source_type'=>'service',
 'tour_highlight_source_id'=>'99','tour_highlight_title'=>'Free pickup promised','tour_highlight_description'=>''));
prove($klass::render()==='','unbound service promise was published');
$GLOBALS['meta'][7]['tour_highlights_mode']='hide';
prove($klass::render()===''&&!$klass::has_highlights(7),'hide mode leaked highlights');
final class MockElement {public $attrs=array();function get_id(){return 'd90c3d1';}function get_settings($name){return '';}function add_render_attribute($which,$key,$value){$this->attrs[$key][]=$value;}}
$element=new MockElement();
prove($klass::section_visible(true,$element)===true,'hidden highlights incorrectly hid linked destinations');
$klass::decorate_container($element);
prove(in_array('tour-journey--places-only',$element->attrs['class']??array(),true),'places-only frontend mode not emitted');
prove(in_array('0',$element->attrs['data-tour-journey-has-highlights']??array(),true),'hidden highlights state not marked');
prove($GLOBALS['meta_write_count']===1,'frontend render wrote to postmeta');
prove(in_array('elementor/frontend/container/should_render',$GLOBALS['hooks'],true),'missing native Elementor display hook');
// Native Elementor container and JetEngine widget localization must follow
// the actual place-group ancestry and must never persist Elementor settings.
final class NativeGroup {private $classes;function __construct($classes){$this->classes=$classes;}
 function get_settings($name){return $name==='css_classes'?$this->classes:'';}}
final class NativeGrid {public $settings=array('lisitng_id'=>45927);public $writes=0;
 function get_name(){return 'jet-listing-grid';}
 function get_settings($key){return $this->settings[$key]??'';}
 function set_settings($key,$value){$this->settings[$key]=$value;$this->writes++;}}
$GLOBALS['language']='fr';
$outside=new NativeGrid();$klass::localize_native_grid($outside);
prove($outside->writes===0,'unrelated native Grid was localized');
$klass::enter_native_group(new NativeGroup('unrelated-section'));
$outsideNested=new NativeGrid();$klass::localize_native_grid($outsideNested);
prove($outsideNested->writes===0,'unrelated nested section was localized');
$klass::enter_native_group(new NativeGroup('destination-layout tour-place-group'));
$nested=new NativeGrid();$klass::localize_native_grid($nested);
prove($nested->settings['lisitng_id']==='910'&&$nested->writes===1,'FR linked JetEngine listing not localized');
$klass::enter_native_group(new NativeGroup('inner-nested'));$de=new NativeGrid();
$GLOBALS['language']='de';$klass::localize_native_grid($de);
prove($de->settings['lisitng_id']===45927&&$de->writes===0,'unpublished DE listing was published');
$klass::leave_native_group(null);$klass::leave_native_group(null);
$nowOutside=new NativeGrid();$klass::localize_native_grid($nowOutside);
prove($nowOutside->writes===0,'leaving place-group did not restore isolation');
$klass::leave_native_group(null);
$GLOBALS['language']='';$unknown=new NativeGrid();$klass::enter_native_group(new NativeGroup('tour-place-group'));
$klass::localize_native_grid($unknown);$klass::leave_native_group(null);
prove($unknown->writes===0,'missing WPML language silently rewired listing');
prove($GLOBALS['meta_write_count']===1,'render-only listing localization wrote postmeta');
prove(in_array('elementor/frontend/widget/before_render',$GLOBALS['hooks'],true),'native Elementor Grid localization hook missing');
echo 'PASS Native Tour Highlights: legacy safe, scoped company destinations, draft-only proposals, locale+row approvals, service-denial, places-only display, WPML Grid isolation, no frontend writes'.PHP_EOL;
