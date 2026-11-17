<?php
/** Source-backed native Elementor/JetEngine Taxonomy Archive facade. Local Lab only. */
defined('ABSPATH')||exit;
if((!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED)||!defined('MAD4B_COMMERCE_RUNTIME_ENABLED')||!MAD4B_COMMERCE_RUNTIME_ENABLED)return;
function shared_archive_presentation_current():?array {
 if(!class_exists('Shared_Product_Taxonomy_Archive_Presentation_v1'))return null;
 $term=Shared_Product_Taxonomy_Archive_Presentation_v1::current();
 return $term?Shared_Product_Taxonomy_Archive_Presentation_v1::resolve($term):null;
}
add_filter('jet-engine/query-builder/types/posts-query/args',static function($args,$query){
 if((int)($query->id??0)!==246||
   !class_exists('Shared_Product_Taxonomy_Archive_Presentation_v1'))return $args;
 // This Query Builder ID belongs to the transportation archive alone.
 // No signed parent term context => fail closed, never broad CPT discovery.
 $term=Shared_Product_Taxonomy_Archive_Presentation_v1::current();
 return $term?Shared_Product_Taxonomy_Archive_Presentation_v1::native_args($term,(array)$args)
   :['post_type'=>['transportations'],'post__in'=>[0],'posts_per_page'=>1];
},1250,2);
add_filter('jet-engine/query-builder/query/items',static function($items,$query){
 if((int)($query->id??0)!==246||
   !class_exists('Shared_Product_Taxonomy_Archive_Presentation_v1'))return $items;
 $term=Shared_Product_Taxonomy_Archive_Presentation_v1::current();
 if(!$term)return [];
 return array_values(array_filter((array)$items,static function($item)use($term){
  $id=is_object($item)?(int)($item->ID??0):(int)$item;
  return $id>0&&Shared_Product_Taxonomy_Archive_Presentation_v1::verified_post($id,$term);
 }));
},1450,2);
// Make canonical WordPress archive counts/pagination honor the same policy.
add_action('pre_get_posts',static function($query):void {
 if(is_admin()||!$query->is_main_query()||!$query->is_tax()||
    !class_exists('Shared_Product_Taxonomy_Archive_Presentation_v1'))return;
 $term=$query->get_queried_object();
 if(!$term instanceof WP_Term || !Shared_Product_Taxonomy_Archive_Presentation_v1::source($term->taxonomy))return;
 $args=Shared_Product_Taxonomy_Archive_Presentation_v1::native_args($term,['posts_per_page'=>(int)get_option('posts_per_page',10)]);
 foreach(['post_type','post_status','tax_query','meta_query','post__in','no_found_rows','has_password'] as $key){
  if(array_key_exists($key,$args))$query->set($key,$args[$key]);
 }
},1020);
add_action('elementor/dynamic_tags/register',static function($registry):void{
 if(!class_exists('Elementor\\Core\\DynamicTags\\Tag'))return;
 if(!class_exists('Shared_Taxonomy_Archive_Dynamic_Tag',false)){
  class Shared_Taxonomy_Archive_Dynamic_Tag extends \Elementor\Core\DynamicTags\Tag{
   public function get_name(){return 'shared-taxonomy-archive-context';}
   public function get_title(){return 'Unified Taxonomy Archive Context';}
   public function get_group(){return 'site';}
   public function get_categories(){return ['text'];}
   protected function register_controls(){
    $this->add_control('field',[
      'label'=>'Source field','type'=>\Elementor\Controls_Manager::SELECT,
      'options'=>[
        'name'=>'Current Term Name','parent'=>'Immediate Parent Name',
        'description'=>'Current Term Description','service_state'=>'Service Discovery State',
      ],'default'=>'name'
    ]);
   }
   public function render(){
    $ctx=shared_archive_presentation_current();
    if(!$ctx||empty($ctx['ok']))return;
    $field=(string)$this->get_settings('field');
    $value='';
    if($field==='name')$value=$ctx['name'];
    elseif($field==='parent')$value=$ctx['parent']?->name??'';
    elseif($field==='description'){
      $t=Shared_Product_Taxonomy_Archive_Presentation_v1::current();
      $value=$t?wp_strip_all_tags($t->description):'';
    }elseif($field==='service_state')
      $value=!$ctx['discovery_allowed']?'Unavailable':($ctx['verified_count']>0?'Available to explore':'No public options');
    echo esc_html($value);
   }
  }
 }
 $registry->register(new Shared_Taxonomy_Archive_Dynamic_Tag);
},55);
add_filter('elementor/frontend/builder_content_data',static function($nodes,$document_id){
 $id=(int)$document_id;
 if(!in_array($id,[40308,44322,40352,40392],true)||
   !is_array($nodes)||is_admin()||
   !class_exists('Shared_Product_Taxonomy_Archive_Presentation_v1'))return $nodes;
 $ctx=shared_archive_presentation_current();
 if(!$ctx||empty($ctx['ok']))return $nodes;
 if(in_array($id,[40352,40392],true)){
  // Tour-only mode: discovery permitted but native list cards may not
  // advertise unverified standalone prices or booking buttons.
  if($ctx['standalone_allowed'])return $nodes;
  $prune=function($items)use(&$prune){
   $out=[];
   foreach($items as $n){
    if(in_array((string)($n['id']??''),['a92fdea','a938df0'],true))continue;
    if(!empty($n['elements']))$n['elements']=$prune($n['elements']);
    $out[]=$n;
   }
   return $out;
  };
  return $prune($nodes);
 }
 // Keep the native authored archive: only remove inactive controls and
 // use its own Jet Listing notice when the service has no public items.
 $show=(bool)$ctx['show_filters'];
 $prune=function($items)use(&$prune,$show,$ctx){
  $out=[];
  foreach($items as $n){
   if(!$show && in_array((string)($n['id']??''),[
     '7d67ce7','9154715','2008c11','308c0bb5',
   ],true))continue;
   if(!$show && ($n['id']??'')==='9a44016'){
    // Native Elementor Container width controls; the removed 20% filter
    // column must be absorbed by results. Keep the original 20% right rail.
    // Existing tablet/mobile width controls stay at 100% unchanged.
    $n['settings']['width']=['unit'=>'%','size'=>80,'sizes'=>[]];
   }
   if(($n['id']??'')==='7739f090'){
    $n['settings']['not_found_message']=Shared_Product_Taxonomy_Archive_Presentation_v1::empty_message($ctx);
    // The data source remains native Jet Query 246 and native Listing 40352.
   }
   if(!empty($n['elements']))$n['elements']=$prune($n['elements']);
   $out[]=$n;
  }
  return $out;
 };
 return $prune($nodes);
},175,2);

/**
 * Match the existing destination gallery fix for the two registered transport
 * archive taxonomies. Slick owns the gallery once initialized, including arrows.
 * No body clipping, unrelated Elementor widget styling or vendor overrides.
 */
add_action('wp_head',static function():void {
 if(!class_exists('Shared_Product_Taxonomy_Archive_Presentation_v1') ||
    !Shared_Product_Taxonomy_Archive_Presentation_v1::current())return;
 echo '<style id="shared-term-archive-gallery-preinit">'.
 '.tax-transportation-types .elementor-element-d3b8065 .uael-img-gallery-wrap:not(.slick-initialized),'.
 '.tax-transport-service-type .elementor-element-d3b8065 .uael-img-gallery-wrap:not(.slick-initialized)'.
 '{overflow-x:clip!important;}'.
 '</style>'."\n";
},1);

/**
 * Elementor saves static CSS for the default 20/60/20 three-column layout.
 * Builder data alone cannot update those generated CSS declarations. With
 * the 20% Filter Rail excluded from the actual frontend, the native Content
 * Container must occupy the released column. Limit this bridge to *empty*
 * native transport archive pages at desktop width; keep the original 20/60/20
 * on archives with filters, and the authored 100%-stack on tablet/mobile.
 * This rule affects only native Elementor Container 9a44016, not card styling.
 */
add_action('wp_head',static function():void {
 $ctx=shared_archive_presentation_current();
 if(!$ctx||empty($ctx['ok'])||!empty($ctx['show_filters']))return;
 echo '<style id="shared-taxonomy-results-flex-balance">'.
 '@media (min-width:1025px){'.
 'body.tax-transportation-types .elementor-40308 .elementor-element-9a44016,'.
 'body.tax-transportation-types .elementor-44322 .elementor-element-9a44016,'.
 'body.tax-transport-service-type .elementor-40308 .elementor-element-9a44016,'.
 'body.tax-transport-service-type .elementor-44322 .elementor-element-9a44016'.
 '{--width:80%;width:80%;max-width:80%;}'.
 '}'.
 '</style>'."\n";
},99);

/**
 * Cross-plugin CTA bridge for native Fluent Forms on authored transport
 * taxonomy archive documents. Elementor owns colors/typography; Fluent Forms
 * has no native Elementor Button control, so only its submit primitives are
 * projected onto the same design tokens. Keep icon utility controls, Slick,
 * Elementor header CTA and Fluent stepper +/- visually distinct by role.
 */
add_action('wp_head',static function():void {
 if(!class_exists('Shared_Product_Taxonomy_Archive_Presentation_v1')||
    !Shared_Product_Taxonomy_Archive_Presentation_v1::current())return;
 $selector='body:is(.tax-transportation-types,.tax-transport-service-type) '.
  ':is(.elementor-40308,.elementor-44322) .ff-btn.ff-btn-submit';
 echo '<style id="shared-transport-action-buttons">'.
  $selector.'{'.
  'box-sizing:border-box!important;'.
  'min-height:44px!important;'.
  'padding:10px 20px!important;'.
  'border:1px solid transparent!important;'.
  'border-radius:8px!important;'.
  'font-family:var(--e-global-typography-text-font-family,Arial,sans-serif)!important;'.
  'font-size:var(--e-global-typography-text-font-size,16px)!important;'.
  'font-weight:600!important;'.
  'line-height:1.35!important;'.
  'text-align:center!important;'.
  'color:#fff!important;'.
  'background-color:var(--e-global-color-secondary,#47729e)!important;'.
  'transition:background-color .18s ease,color .18s ease!important;'.
  '}'.
  $selector.':hover:not(:disabled){'.
  'background-color:var(--e-global-color-primary,#2c3e50)!important;'.
  'color:#fff!important;'.
  '}'.
  $selector.':focus-visible{'.
  'outline:2px solid var(--e-global-color-accent,#d4b483)!important;'.
  'outline-offset:2px!important;'.
  '}'.
  $selector.':disabled{'.
  'opacity:.55!important;'.
  'cursor:not-allowed!important;'.
  '}'.
  '</style>'."\n";
},35);
