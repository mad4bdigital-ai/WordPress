<?php
/**
 * Native Elementor + JetEngine bindings for Unified Destination Term Context.
 * Read-only projection; never alter stored data or override a pricing engine.
 */
defined('ABSPATH')||exit;

function shared_destination_presentation_context():?array {
 if(!class_exists('Shared_Product_Destination_Term_Presentation_v1'))return null;
 $term=Shared_Product_Destination_Term_Presentation_v1::current();
 if(!$term)return null;
 static $memo=[];
 if(!array_key_exists($term->term_id,$memo))$memo[$term->term_id]=
   Shared_Product_Destination_Term_Presentation_v1::snapshot($term);
 return $memo[$term->term_id];
}
/** Elementor dynamic tag: pick parent by hierarchy type and hide if absent. */
add_action('elementor/dynamic_tags/register',static function($registry):void{
 if(!class_exists('Elementor\\Core\\DynamicTags\\Tag'))return;
 if(!class_exists('Shared_Destination_Ancestor_Dynamic_Tag',false)){
  class Shared_Destination_Ancestor_Dynamic_Tag extends \Elementor\Core\DynamicTags\Tag {
   public function get_name(){return 'shared-destination-ancestor';}
   public function get_title(){return 'Destination Ancestor (Unified Context)';}
   public function get_group(){return 'site';}
   public function get_categories(){return ['text'];}
   protected function register_controls(){
    $this->add_control('term_level',[
      'label'=>'Ancestor level',
      'type'=>\Elementor\Controls_Manager::SELECT,
      'options'=>['country'=>'Country','state_governorate'=>'Governorate','city'=>'City','zone_area'=>'Zone/Area'],
      'default'=>'country'
    ]);
    $this->add_control('link_to_term',[
      'label'=>'Link to ancestor archive',
      'type'=>\Elementor\Controls_Manager::SWITCHER,
      'label_on'=>'Yes','label_off'=>'No','return_value'=>'yes','default'=>'yes'
    ]);
   }
   public function render(){
    $term=Shared_Product_Destination_Term_Presentation_v1::current();
    if(!$term)return;
    $level=(string)$this->get_settings('term_level');
    $a=Shared_Product_Destination_Term_Presentation_v1::ancestor($term,$level);
    if(!$a||!Shared_Product_Destination_Term_Presentation_v1::activation($a))return;
    if($this->get_settings('link_to_term')==='yes'){
      $url=get_term_link($a,'destinations');
      if(!is_wp_error($url)){echo '<a href="'.esc_url($url).'">'.esc_html($a->name).'</a>';return;}
    }
    echo esc_html($a->name);
   }
  }
 }
 $registry->register(new Shared_Destination_Ancestor_Dynamic_Tag);
},51);
/**
 * Template trees are projected only for the front-end destination archive.
 * Elementor editor retains every authored widget, control and layout.
 */
add_filter('elementor/frontend/builder_content_data',static function($nodes,$document_id){
 if(!is_array($nodes)||is_admin()||!is_tax('destinations')||
    !class_exists('Shared_Product_Destination_Term_Presentation_v1'))return $nodes;
 if(class_exists('Elementor\\Plugin')&&\Elementor\Plugin::$instance->preview->is_preview_mode())return $nodes;
 $term=Shared_Product_Destination_Term_Presentation_v1::current();
 if(!$term)return $nodes;
 $ctx=shared_destination_presentation_context();
 $id=(int)$document_id;
 $cls='Shared_Product_Destination_Term_Presentation_v1';
 if($id===45799){
   $parent_nodes=['bc2185f'=>'address','e9545d9'=>'ancestor_state_governorate',
     'ece3a82'=>'ancestor_city','201b421'=>'ancestor_country'];
   $walk=function($items)use(&$walk,$parent_nodes,$term,$cls){
    $result=[];
    foreach($items as $node){
     if(isset($parent_nodes[$node['id']??''])){
      $field=$parent_nodes[$node['id']];
      if($cls::field($term,$field)==='')continue;
      // Existing legacy visibility checks read Post Meta on a Term:
      // authoritative resolver now controls this *container*.
      unset($node['settings']['jedv_conditions'],$node['settings']['display_condition_list']);
     }
     if(!empty($node['elements']))$node['elements']=$walk($node['elements']);
     $result[]=$node;
    }
    return $result;
   };
   return $walk($nodes);
 }
 if($id===45886){
   $currentType=(string)get_term_meta($term->term_id,'destination_type',true);
   $types=['2ef2d7f'=>'country','db1063b'=>'state_governorate','0c5652f'=>'city',
     'f74ca72'=>'zone_area','8729ddc'=>'place_attraction'];
   $walk=function($items,$depth=0)use(&$walk,$term,$cls,$types,$currentType){
    $out=[];
    foreach($items as $node){
     $nodeId=(string)($node['id']??'');
     if(isset($types[$nodeId])&&$types[$nodeId]!==$currentType)continue;
     // "Old Setup" holds the correctly authored native Elementor sections;
     // keep the style and eliminate empty whole sections, not just grids.
     if(($node['elType']??'')==='container'){
      $grids=[];
      $collect=function($children)use(&$collect,&$grids){
       foreach((array)$children as $child){
        if(($child['widgetType']??'')==='jet-listing-grid'){
         $qid=(int)($child['settings']['custom_query_id']??0);
         if(isset(Shared_Product_Destination_Term_Presentation_v1::QUERY_RELATIONS[$qid]))$grids[$qid]=true;
        }
        $collect($child['elements']??[]);
       }
      };
      $collect($node['elements']??[]);
      if($grids){
       $available=false;
       foreach(array_keys($grids) as $qid){
        if($cls::by_query_id($term,(int)$qid)){$available=true;break;}
       }
       if(!$available)continue;
       // Stale JetEngine query-has-items compares legacy Query Builder instead
       // of our resolved native source and can hide populated sections.
       unset($node['settings']['jedv_conditions'],$node['settings']['display_condition_list']);
      }
     }
     if(($node['widgetType']??'')==='jet-listing-grid'){
      $qid=(int)($node['settings']['custom_query_id']??0);
      if(isset($cls::QUERY_RELATIONS[$qid])){
       $node['settings']['shared_destination_relation_query_id']=$qid;
       $node['settings']['posts_num']=12;
      }
     }
     if(!empty($node['elements'])){
      $node['elements']=$walk($node['elements'],$depth+1);
      if($nodeId==='b00fece' && !count(array_filter($node['elements'],static fn($x)=>($x['elType']??'')==='container')))continue;
      if(isset($types[$nodeId])&&!$node['elements'])continue;
     }
     $out[]=$node;
    }
    return $out;
   };
   return $walk($nodes);
 }
 if($id===45774){
   $removeSections=['5f45e9c'=>'about','a6b6323'=>'tours','342d85e'=>'articles'];
   $walk=function($items)use(&$walk,$ctx,$term){
    $out=[];
    foreach($items as $node){
     $nid=(string)($node['id']??'');
     // Hide empty Google Map widget when no address is configured.
     if($nid==='0c4f56f' &&
        Shared_Product_Destination_Term_Presentation_v1::field($term,'address')==='')continue;
     // Do not hide whole main content, only its empty About heading/body.
     if(in_array($nid,['1fbd108','0679ee1'],true) && !$ctx['sections']['about'])continue;
     if(!empty($node['elements']))$node['elements']=$walk($node['elements']);
     $out[]=$node;
    }
    return $out;
   };
   return $walk($nodes);
 }
 if(in_array($id,Shared_Product_Destination_Term_Presentation_v1::ARCHIVE_DOCUMENTS,true)){
   $slot=['ba9312c'=>'tours','a96b2ed'=>'transfers','a71e383'=>'stay','a72c3e3'=>'more'];
   $out=[];
   foreach($nodes as $n){
    if(!empty($n['elements']))$n['elements']=shared_destination_archive_prune($n['elements'],$ctx['sections'],$slot);
    $out[]=$n;
   }
   return $out;
 }
 return $nodes;
},170,2);
function shared_destination_archive_prune(array $items,array $sections,array $slots):array {
 $out=[];
 foreach($items as $n){
  $slot=$slots[(string)($n['id']??'')]??null;
  if($slot!==null&&empty($sections[$slot]))continue;
  if(!empty($n['elements']))$n['elements']=shared_destination_archive_prune($n['elements'],$sections,$slots);
  $out[]=$n;
 }
 return $out;
}
/**
 * JetEngine grid query result: term relation context supersedes stale legacy
 * dynamic Query Builder macros for *opted-in* Elementor destination widgets.
 */
add_filter('jet-engine/listing/grid/query/query',static function($items,$settings,$render){
 if(!is_tax('destinations')||
  !class_exists('Shared_Product_Destination_Term_Presentation_v1'))return $items;
 $qid=(int)($settings['shared_destination_relation_query_id']??0);
 if(!isset(Shared_Product_Destination_Term_Presentation_v1::QUERY_RELATIONS[$qid]))return $items;
 $term=Shared_Product_Destination_Term_Presentation_v1::current();
 if(!$term)return [];
 return Shared_Product_Destination_Term_Presentation_v1::by_query_id($term,$qid);
},145,3);
/** Only live sections receive a menu item; static menu data is untouched. */
add_filter('wp_nav_menu_objects',static function($items,$args){
 $ctx=shared_destination_presentation_context();
 if(!$ctx || !is_array($items))return $items;
 $sections=$ctx['sections'];
 $anchors=['#about'=>'about','#tours'=>'tours','#trans'=>'transfers','#stay'=>'stay','#more'=>'more'];
 $matches=0;
 foreach($items as $item)if(isset($anchors[parse_url((string)$item->url,PHP_URL_FRAGMENT)?'#'.parse_url((string)$item->url,PHP_URL_FRAGMENT):'']))$matches++;
 if($matches<3)return $items;
 return array_values(array_filter($items,static function($item)use($anchors,$sections){
  $frag=parse_url((string)$item->url,PHP_URL_FRAGMENT);
  $key=$frag!==null?'#'.$frag:'';
  return !isset($anchors[$key])||!empty($sections[$anchors[$key]]);
 }));
},110,2);

/**
 * JetEngine Query Builder is the actual execution path for destination
 * term Query IDs 283-299. Intercept native query/items to validate every
 * term against unified relationship and display eligibility; this also
 * powers query-has-items and leaves authoring Query IDs intact.
 */
add_filter('jet-engine/query-builder/query/items',static function($items,$query){
 if(!is_tax('destinations')||
    !class_exists('Shared_Product_Destination_Term_Presentation_v1')||
    !is_object($query))return $items;
 $id=(int)($query->id??0);
 if(!isset(Shared_Product_Destination_Term_Presentation_v1::QUERY_RELATIONS[$id]))return $items;
 $term=Shared_Product_Destination_Term_Presentation_v1::current();
 if(!$term)return [];
 return Shared_Product_Destination_Term_Presentation_v1::by_query_id($term,$id);
},1700,2);

/**
 * Elementor-native section headings follow exact taxonomy relationships.
 * Controls live in the editor's Dynamic Tags UI; no shortcode or JS string
 * replacement is needed for names translated by WPML.
 */
add_action('elementor/dynamic_tags/register',static function($registry):void{
 if(!class_exists('Elementor\\Core\\DynamicTags\\Tag'))return;
 if(!class_exists('Shared_Destination_Hierarchy_Heading_Tag',false)){
  class Shared_Destination_Hierarchy_Heading_Tag extends \Elementor\Core\DynamicTags\Tag {
   public function get_name(){return 'shared-destination-hierarchy-heading';}
   public function get_title(){return 'Destination Relationship Heading';}
   public function get_group(){return 'site';}
   public function get_categories(){return ['text'];}
   protected function register_controls(){
    $this->add_control('heading_kind',[
      'label'=>'Relationship heading',
      'type'=>\Elementor\Controls_Manager::SELECT,
      'options'=>[
       'tree'=>'Explore the destination hierarchy',
       'regional_attractions'=>'Nearby attractions in current governorate'
      ],
      'default'=>'tree'
    ]);
   }
   public function render(){
    $term=Shared_Product_Destination_Term_Presentation_v1::current();
    if(!$term){return;}
    $mode=$this->get_settings('heading_kind');
    if($mode==='regional_attractions'){
      $gov=Shared_Product_Destination_Term_Presentation_v1::ancestor($term,'state_governorate');
      if(!$gov && get_term_meta($term->term_id,'destination_type',true)==='state_governorate')$gov=$term;
      if(!$gov){return;}
      echo esc_html(sprintf(__('Explore Attractions Around %s','default'),$gov->name));
      return;
    }
    echo esc_html(sprintf(__('Explore %s & Nearby Destinations','default'),$term->name));
   }
  }
 }
 $registry->register(new Shared_Destination_Hierarchy_Heading_Tag);
},52);

/**
 * Prevent a first-paint horizontal layout jump in the existing UAEL/Slick
 * Business Reviews gallery while its slides are still uninitialized.
 * Scope to destination term archive + widget ID. Slick removes this
 * restriction by adding .slick-initialized, preserving its native outside
 * navigation arrows and fully authored Elementor gallery styles.
 */
add_action('wp_head',static function():void{
 if(!is_tax('destinations'))return;
 echo '<style id="shared-destination-uael-preinit-boundary">'.
  '.tax-destinations .elementor-element-d3b8065 .uael-img-gallery-wrap:not(.slick-initialized){overflow-x:clip!important;}'.
  '</style>'."\n";
},1);
