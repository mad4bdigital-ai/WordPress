<?php
/** Native JetSmartFilters options bounded to the verified destination discovery set. */
defined('ABSPATH')||exit;


/** Descendants, never parents or siblings; depth is not artificially limited. */
function registry_destination_activity_descendants(int $term_id):array {
 if(!$term_id||!taxonomy_exists('destinations'))return [];
 $ids=get_term_children($term_id,'destinations');
 if(is_wp_error($ids))return [];
 return array_values(array_unique(array_filter(array_map('intval',$ids),static function($id){
  return registry_destination_activity_term_ready($id);
 })));
}
add_filter('jet-smart-filters/filters/filter-options',static function($options,$filter_id){
 if(!in_array((int)$filter_id,[67261,67262],true))return $options;
 $current=registry_destination_activity_current_term();
 if(!$current)return [''=>__('Select…','default')];
 $available=registry_destination_activity_ids($current);
 if(!$available)return [''=>__('Select…','default')];
 $keep=[];
 if((int)$filter_id===67262){
  foreach(registry_destination_activity_descendants($current) as $child){
   // Only expose an option with at least one eligible activity in its subtree.
   if(registry_destination_activity_ids($child))$keep[$child]=true;
  }
 }else{
  foreach($available as $post_id){
   $terms=wp_get_post_terms($post_id,'tour-styles',['fields'=>'ids']);
   if(is_wp_error($terms))continue;
   foreach($terms as $id)$keep[(int)$id]=true;
  }
 }
 $filtered=[];
 foreach((array)$options as $key=>$value){
  if((string)$key==='' || (is_array($value)&&($value['value']??false)==='')){
   $filtered[$key]=$value;continue;
  }
  $v=is_array($value)?($value['value']??null):$key;
  if($v!==null && isset($keep[(int)$v]))$filtered[$key]=$value;
 }
 return $filtered;
},35,2);

/**
 * Elementor count dynamic tag for the copied *native* JetEngine Dynamic Field.
 * It derives activity visibility from the same Registry owner IDs as the grid;
 * the old Tours Query Builder 273 does not participate.
 */
function registry_destination_activity_visible_count():int{
 $term=registry_destination_activity_current_term();
 if(!$term)return 0;
 $ids=registry_destination_activity_ids($term);
 $path=rawurldecode((string)($_SERVER['REQUEST_URI']??''));
 if(preg_match('~/jsf/jet-engine:registry_destination_activity_grid/tax/([^/]+)~',$path,$match)){
  foreach(['tour-styles','destinations'] as $tax){
   if(!preg_match('~(?:^|[;,])'.preg_quote($tax,'~').':([a-z0-9-]+)~i',$match[1],$tm))continue;
   $selected=get_term_by('slug',sanitize_title($tm[1]),$tax);
   if(!$selected instanceof WP_Term)return 0;
   if($tax==='destinations'){
    $desc=registry_destination_activity_descendants($term);
    if(!in_array((int)$selected->term_id,$desc,true))return 0;
    $ids=array_values(array_filter($ids,static fn($id)=>registry_destination_activity_term_matches_post((int)$id,(int)$selected->term_id)));
   }else{
    $ids=array_values(array_filter($ids,static function($id)use($selected){
     $t=wp_get_post_terms((int)$id,'tour-styles',['fields'=>'ids']);
     return !is_wp_error($t)&&in_array((int)$selected->term_id,array_map('intval',$t),true);
    }));
   }
  }
 }
 return count($ids);
}
add_action('elementor/dynamic_tags/register',static function($manager):void{
 if(!class_exists('Registry_Activity_Count_Tag',false)){
  class Registry_Activity_Count_Tag extends \Elementor\Core\DynamicTags\Data_Tag {
   public function get_name(){return 'registry-activity-count';}
   public function get_title(){return 'Registry — Eligible Destination Activities Count';}
   public function get_group(){return 'product-navigation';}
   public function get_categories(){return [\Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY];}
   public function get_value(array $options=[]){
    $n=registry_destination_activity_visible_count();
    $is_ar=(string)apply_filters('wpml_current_language',null)==='ar';
    return $is_ar?('عرض '.number_format_i18n($n).' من الأنشطة'):('Showing '.number_format_i18n($n).' '.($n===1?'activity':'activities'));
   }
  }
 }
 $manager->register(new Registry_Activity_Count_Tag());
},50);

/**
 * JetSmartFilters mixed mode replaces Listing Grid items over AJAX while
 * Elementor's original Query Count dynamic tag sits outside that provider.
 * Preserve the native Elementor widget, and synchronize its *visible-items*
 * count after a supported native provider redraw. No CSS/alternate renderer.
 */
add_action('wp_footer',static function():void{
 if(!is_tax('destinations')||!registry_destination_activity_current_term())return;
?>
<script id="registry-destination-activity-count-bridge">
(function(){
  function init(){
    const root=document.querySelector('.registry-destination-activities');
    if(!root)return;
    const label=root.querySelector('.jet-listing-dynamic-field__content');
    if(!label)return;
    const lang=(document.documentElement.getAttribute('lang')||'en').toLowerCase();
    const ar=lang.startsWith('ar');
    let pending=false;
    function refresh(){
      pending=false;
      const count=root.querySelectorAll('.jet-listing-grid__item').length;
      const txt=ar?'عرض '+count.toLocaleString('ar-EG')+' من الأنشطة':
        'Showing '+count.toLocaleString('en-US')+' '+(count===1?'activity':'activities');
      if(label.textContent.trim()!==txt)label.textContent=txt;
    }
    function schedule(){
      if(!pending){pending=true;requestAnimationFrame(refresh);}
    }
    new MutationObserver(function(mutations){
      for(const m of mutations){
        const target=m.target.nodeType===1?m.target:m.target.parentElement;
        if(target && target.closest('.jet-listing-grid')){
          schedule();return;
        }
        if(Array.from(m.addedNodes).some(n=>n.nodeType===1 &&
            (n.matches?.('.jet-listing-grid__items,.jet-listing-grid__item') ||
             n.querySelector?.('.jet-listing-grid__items,.jet-listing-grid__item')))){
          schedule();return;
        }
      }
    }).observe(root,{childList:true,subtree:true});
    schedule();
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});
  else init();
})();
</script>
<?php
},150);
