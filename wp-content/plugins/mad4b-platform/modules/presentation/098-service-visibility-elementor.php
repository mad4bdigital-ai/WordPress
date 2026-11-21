<?php
/**
 * Elementor service visibility controls, brand-neutral and isolated to local lab.
 * Source of truth: JetEngine Options Page `service-activation`.
 */
if (!defined('ABSPATH') || (!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED)) return;
function service_visibility_choices(){return [''=>'Always display','tours'=>'Tours & Activities','properties'=>'Hotels & Properties','transportations'=>'Transportations','visas'=>'Visas'];}
function service_visibility_active($key){
 if($key==='transportations'
  &&class_exists('MAD4B_Extra_Quick_Preview_V1',false)
  &&MAD4B_Extra_Quick_Preview_V1::scoped_transport_query_allowed())return true;
 if(defined('ABK_PRIVATE_SERVICE_PREVIEW') && ABK_PRIVATE_SERVICE_PREVIEW && current_user_can('manage_options') && isset($GLOBALS['abk_private_service_preview_key']) && $key===$GLOBALS['abk_private_service_preview_key'])return true;
 if(!$key)return true;
 if(!in_array($key,['tours','properties','transportations','visas'],true))return false;
 // `tour_only` never exposes standalone archives, detail pages, or indexes.
 return function_exists('service_channel_allowed')
  ?service_channel_allowed($key,'standalone'):false;
}
add_action('elementor/element/after_section_end',static function($element,$section_id){
 static $registered=null;
 if(null===$registered)$registered=new WeakMap();
 if(isset($registered[$element])||!method_exists($element,'start_controls_section'))return;
 $registered[$element]=true;
 $element->start_controls_section('service_visibility_section',[
  'label'=>'Service visibility',
  'tab'=>\Elementor\Controls_Manager::TAB_ADVANCED,
 ]);
 $element->add_control('service_visibility_key',[
  'label'=>'Show when service is active',
  'type'=>\Elementor\Controls_Manager::SELECT,
  'options'=>service_visibility_choices(),
  'default'=>'',
  'description'=>'Select a service controlled by JetEngine Service Activation. Disabled services stay editable in Elementor.',
 ]);
 $element->end_controls_section();
},25,2);
add_action('elementor/element/nested-tabs/section_tabs/before_section_end',static function($element){
 $control=$element->get_controls('tabs');if(!is_array($control)||empty($control['fields']))return;
 $fields=$control['fields'];
 $fields['service_visibility_key']=['name'=>'service_visibility_key','label'=>'Show when service is active','type'=>\Elementor\Controls_Manager::SELECT,'options'=>service_visibility_choices(),'default'=>''];
 $element->update_control('tabs',['fields'=>$fields]);
},25,1);
add_filter('elementor/frontend/builder_content_data',static function($nodes,$post_id){
 if(!is_array($nodes))return $nodes;
 // Keep all service controls editable in Elementor and WordPress admin previews.
 if(is_admin()||(class_exists('Elementor\\Plugin')&&\Elementor\Plugin::$instance->preview->is_preview_mode()))return $nodes;
 $walk=static function($items)use(&$walk){
  $out=[];
  foreach($items as $item){
   if(!is_array($item)){$out[]=$item;continue;}
   $settings=$item['settings']??[];
   if(!service_visible_in_current_page((string)($settings['service_visibility_key']??''))&& !empty($settings['service_visibility_key']))continue;
   if(($item['widgetType']??'')==='nested-tabs'&&isset($settings['tabs'])&&is_array($settings['tabs'])){
    $original_tabs=$settings['tabs'];$children=$item['elements']??[];$tabs=[];$kept_children=[];
    foreach($original_tabs as $i=>$tab){
     if(!service_visible_in_current_page((string)($tab['service_visibility_key']??''))&& !empty($tab['service_visibility_key']))continue;
     $tabs[]=$tab;
     if(isset($children[$i]))$kept_children[]=$children[$i];
    }
    if(!$tabs)continue;
    $item['settings']['tabs']=$tabs;
    if(isset($item['elements']))$item['elements']=$walk($kept_children);
   }elseif(isset($item['elements'])&&is_array($item['elements']))$item['elements']=$walk($item['elements']);
   $out[]=$item;
  }
  return $out;
 };
 return $walk($nodes);
},105,2);
// Apply service availability to public catalog/archive queries without changing admin inventories.
add_action('pre_get_posts',static function($query){
 if(!$query instanceof WP_Query || (is_admin()&&!wp_doing_ajax()) || $query->get('suppress_filters'))return;
 if($query->is_main_query()&&class_exists('MAD4B_Extra_Quick_Preview_V1',false)
    &&MAD4B_Extra_Quick_Preview_V1::scoped_transport_query_allowed($query))return;
 $types=(array)$query->get('post_type');
 $services=['tours-and-activities'=>'tours','properties'=>'properties','units'=>'properties','property-rates'=>'properties','transportations'=>'transportations','transport-rates'=>'transportations','travel-visa'=>'visas'];
 if(!$types || in_array('any',$types,true))return;
 $restricted=array_values(array_filter($types,static fn($type)=>!isset($services[$type])||service_visible_in_current_page($services[$type])));
 if(count($restricted)===count($types))return;
 $query->set('post_type',$restricted?:['__service_not_available__']);
},101);
// Disabled services should never have publicly accessible detail pages.
add_action('wp',static function(){
 if(is_admin()||wp_doing_ajax()||!is_singular())return;
 $type=get_post_type(get_queried_object_id());
 $map=['tours-and-activities'=>'tours','properties'=>'properties','units'=>'properties','property-rates'=>'properties','transportations'=>'transportations','transport-rates'=>'transportations','travel-visa'=>'visas'];
 if(!isset($map[$type])||service_visibility_active($map[$type]))return;
 global $wp_query;
 if($wp_query instanceof WP_Query)$wp_query->set_404();
 status_header(404);nocache_headers();
 $file=get_404_template();if($file&&is_file($file))require $file;else echo 'Not Found';exit;
},1000);
add_filter('rank_math/sitemap/entry',static function($entry,$type,$object){
 if($type!=='post'||!$object instanceof WP_Post)return $entry;
 $map=['tours-and-activities'=>'tours','properties'=>'properties','units'=>'properties','property-rates'=>'properties','transportations'=>'transportations','transport-rates'=>'transportations','travel-visa'=>'visas'];
 $service=$map[$object->post_type]??'';
 return $service&&!service_visibility_active($service)?false:$entry;
},1200,3);
// Final JetSmartFilters option-level guard, including cached/indexed option sources.
add_filter('jet-smart-filters/filters/filter-options',static function($options,$filter_id){
 if(!is_array($options)||is_admin()&&!wp_doing_ajax())return $options;
 $tax=(string)get_post_meta((int)$filter_id,'_source_taxonomy',true);
 $tax_service=['property-types'=>'properties','transportation-types'=>'transportations','transport-service-type'=>'transportations','vehicle_classes'=>'transportations'];
 if(isset($tax_service[$tax])&&!service_visible_in_current_page($tax_service[$tax]))return array_intersect_key($options,[''=>true]);
 if($tax!=='destinations'||!function_exists('destinations_activation_allowed'))return $options;
 foreach($options as $id=>$label){
  if($id===''||!is_scalar($id))continue;
  $term=ctype_digit((string)$id)?get_term((int)$id,'destinations'):get_term_by('slug',(string)$id,'destinations');
  if($term instanceof WP_Term&&!destinations_activation_allowed($term->term_id))unset($options[$id]);
 }
 return $options;
},1500,2);
// Every saved activation change invalidates cached public presentation and SEO output.
add_action('update_option_service-activation',static function($previous,$current){
 if($previous===$current)return;
 do_action('mad4b/public_html/safe_purge_all', '098_service_visibility_elementor');
 if(class_exists('RankMath\\Sitemap\\Cache'))\RankMath\Sitemap\Cache::invalidate_storage();
 // WordPress already invalidates the changed option cache. Never flush all Redis keys.
},15,2);
// Semantic marker survives translations that omit Elementor's optional CSS IDs.
add_action('elementor/frontend/before_render',static function($element){
 if(!method_exists($element,'get_settings')||!method_exists($element,'add_render_attribute'))return;
 // The authored service select has an empty default. Querying Elementor's
 // processed settings for widgets without this key may resolve unrelated
 // Dynamic Tags hundreds of times on a Tour Cold Render.
 // Keep native settings evaluation for unknown/v4 props and explicit
 // service settings; only skip a proven absent key in the raw element.
 if(method_exists($element,'get_data')){
  $raw=$element->get_data();
  if(is_array($raw) && isset($raw['settings']) && is_array($raw['settings'])
     && !array_key_exists('service_visibility_key',$raw['settings'])
     && !array_key_exists('service_visibility_key',(array)($raw['settings']['__dynamic__']??[]))
     && !array_key_exists('props',$raw))return;
 }
 $service=$element->get_settings('service_visibility_key');
 if(!in_array($service,['tours','properties','transportations','visas'],true))return;
 $element->add_render_attribute('_wrapper','data-service-key',$service);
},100);
// JetEngine Query Builder can return cached posts without running WP_Query.
// Filter recognizable post objects only; preserve custom content types and term data.
add_filter('jet-engine/query-builder/query/items',static function($items,$query){
 if(!is_array($items)||(is_admin()&&!wp_doing_ajax()))return $items;
 $service_types=['tours-and-activities'=>'tours','properties'=>'properties','units'=>'properties','property-rates'=>'properties','transportations'=>'transportations','transport-rates'=>'transportations','travel-visa'=>'visas'];
 return array_values(array_filter($items,static function($item)use($service_types){
  if($item instanceof WP_Post){$type=$item->post_type;}
  elseif(is_object($item)&&isset($item->post_type,$item->ID)){$type=(string)$item->post_type;}
  else return true;
  return !isset($service_types[$type])||service_visible_in_current_page($service_types[$type]);
 }));
},1500,2);
