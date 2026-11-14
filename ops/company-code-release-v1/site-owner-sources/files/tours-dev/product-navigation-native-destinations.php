<?php
/**
 * Local QA: preserve authored Mega Menu, project native Elementor Nested Tabs
 * from the original Destinations container; NO custom HTML/CSS renderer.
 */
defined('ABSPATH') || exit;

function native_destination_language(): string {
	return (string)(apply_filters('wpml_current_language',null) ?: apply_filters('wpml_default_language',null) ?: 'en');
}

/**
 * Resolve the actual requested WPML taxonomy language, never a stale
 * current-language fallback (e.g. French incorrectly resolving to Arabic).
 */
function native_destination_translation_id(int $canonical_id,string $language):int {
	static $memo=[];
	$key=$canonical_id.'|'.$language;
	if(isset($memo[$key]))return $memo[$key];
	$term=WP_Term::get_instance($canonical_id);
	if(!$term instanceof WP_Term)return $memo[$key]=0;
	if(!defined('ICL_SITEPRESS_VERSION')) {
		$id=(int)apply_filters('wpml_object_id',$canonical_id,'destinations',false,$language);
		return $memo[$key]=$id?:$canonical_id;
	}
	global $wpdb;
	$id=$wpdb->get_var($wpdb->prepare(
		"SELECT tt.term_id FROM {$wpdb->prefix}icl_translations source
		  JOIN {$wpdb->prefix}icl_translations translation
		    ON translation.trid=source.trid AND translation.element_type='tax_destinations'
		  JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=translation.element_id
		  WHERE source.element_id=%d AND source.element_type='tax_destinations'
		    AND translation.language_code=%s LIMIT 1",
		(int)$term->term_taxonomy_id,$language
	));
	return $memo[$key]=($id?absint($id):$canonical_id);
}
function native_destination_title(int $id, string $lang): string {
	$labels=['en'=>'Destinations','ar'=>'الوجهات','fr'=>'Destinations','de'=>'Reiseziele','es'=>'Destinos','it'=>'Destinazioni'];
	if ($id===0) return (string)apply_filters('wpml_translate_single_string', $labels[$lang]??'Destinations', 'Product navigation', 'destinations', $lang);
	$term=WP_Term::get_instance($id);
	if (!$term instanceof WP_Term) return '';
	$translations=['ar'=>[665=>'مصر',2764=>'الأردن',2759=>'السعودية',2769=>'الإمارات العربية المتحدة'],
		'it'=>[665=>'Egitto',2764=>'Giordania',2759=>'Arabia Saudita',2769=>'Emirati Arabi Uniti']];
	$lang_id=native_destination_translation_id($id,$lang);
	if ($lang_id) {
		$translated=WP_Term::get_instance($lang_id);
		if ($translated instanceof WP_Term && $translated->name!==$term->name)
			return $translated->name;
	}
	return $translations[$lang][$id]??$term->name;
}

function native_destination_configured(string $language): array {
	static $memo=[];
	if(array_key_exists($language,$memo))return $memo[$language];
	$options=get_option('destination-activation',[]);
	$country_ids=is_array($options)?(array)($options['active_countries']??[]):[];
	$out=[];$seen=[];
	foreach($country_ids as $raw_id) {
		$id=absint($raw_id);
		if (!$id || isset($seen[$id]))continue;
		$seen[$id]=1;
		$term=WP_Term::get_instance($id);
		if (!$term instanceof WP_Term || get_term_meta($id,'destination_type',true)!=='country'
			|| (string)get_term_meta($id,'is_public',true)!=='1'
			|| !in_array((string)get_term_meta($id,'is_featured',true),['1','true'],true)
			|| !native_destinations_term_has_featured_image($term))continue;
		$translated_id=native_destination_translation_id($id,$language);
		$target=$translated_id?WP_Term::get_instance($translated_id):$term;
		if (!$target instanceof WP_Term || (function_exists('destinations_activation_allowed')&&!destinations_activation_allowed($target->term_id)))continue;
		$link=get_term_link($target);
		if (is_wp_error($link)||!$link)continue;
		$out[]=['id'=>$id,'translated_id'=>(int)$target->term_id,'label'=>native_destination_title($id,$language),
			'url'=>(string)$link,'source_term'=>$term];
	}
	return $memo[$language]=$out;
}
/**
 * Selling countries for Elementor Header; configurable activation alone is
 * not sufficient. A displayed country must have at least one genuinely
 * sellable standalone-service child destination.
 */
function native_destination_approved(string $language): array {
	static $memo=[];
	if(array_key_exists($language,$memo))return $memo[$language];
	$out=[];
	foreach(native_destination_configured($language) as $country){
		if(native_destinations_eligible_children((int)$country['translated_id'],$language))
			$out[]=$country;
	}
	return $memo[$language]=$out;
}

function native_destination_detail(int $country_id,string $field): string {
	if($field==='label')return native_destination_title($country_id,native_destination_language());
	if($field==='url'){
		if($country_id===0)return native_destinations_general_archive_url(native_destination_language());
		$approved=native_destination_approved(native_destination_language());
		foreach($approved as $item)if($item['id']===$country_id)return $item['url'];
	}
	return '';
}

add_action('elementor/dynamic_tags/register',static function($manager){
	if(class_exists('Native_Destinations_Label_Tag',false))return;
	class Native_Destinations_Label_Tag extends \Elementor\Core\DynamicTags\Tag {
		public function get_name(){return 'native-selling-destinations-label';}
		public function get_title(){return 'Activated destination: native label';}
		public function get_group(){return 'product-navigation';}
		public function get_categories(){return [\Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY];}
		protected function register_controls(){
			$this->add_control('country_id',['label'=>'Canonical country ID (0 = Destinations)','type'=>\Elementor\Controls_Manager::NUMBER,'default'=>0]);
		}
		public function render(){
			echo esc_html(native_destination_detail((int)$this->get_settings('country_id'),'label'));
		}
	}
	class Native_Destinations_Url_Tag extends \Elementor\Core\DynamicTags\Data_Tag {
		public function get_name(){return 'native-selling-destinations-url';}
		public function get_title(){return 'Activated destination: native URL';}
		public function get_group(){return 'product-navigation';}
		public function get_categories(){return [\Elementor\Modules\DynamicTags\Module::URL_CATEGORY];}
		protected function register_controls(){
			$this->add_control('country_id',['label'=>'Canonical country ID','type'=>\Elementor\Controls_Manager::NUMBER,'default'=>665]);
		}
		public function get_value(array $options=[]){
			return native_destination_detail((int)$this->get_settings('country_id'),'url');
		}
	}
	$manager->register(new Native_Destinations_Label_Tag());
	$manager->register(new Native_Destinations_Url_Tag());
},50);

function native_destination_clone_ids(array $element, string $salt): array {
 // Retain native stylesheet bindings for opted-in design controls only.
 // The original selector is a CSS class, never a duplicate DOM ID.
 if(($element['settings']['nav_design_inner_enabled']??'')==='yes'
   ||($element['settings']['nav_design_enabled']??'')==='yes'){
  $source_class='elementor-element-'.sanitize_html_class((string)$element['id']);
  $classes=preg_split('/\s+/',trim((string)($element['settings']['_css_classes']??'')))?:[];
  $classes[]=$source_class;
  $element['settings']['_css_classes']=trim(implode(' ',array_unique($classes)));
 }
 $element['id']=substr(md5($salt.':'.$element['id']),0,7);
	if(isset($element['elements']))$element['elements']=array_map(
		static fn($node)=>native_destination_clone_ids($node,$salt),$element['elements']);
	return $element;
}
function native_destination_tag(string $name,int $id): string {
	return product_navigation_tag_markup($name,['country_id'=>$id]);
}

function native_destination_tabs_panel(array $panel,array $countries,string $lang): array {
	// Retain the exact original dark native Elementor Container settings.
	$authored_widgets=(array)($panel['elements']??[]);
	$tabs=[];$children=[];$tab_id=substr(md5($panel['id'].'native-country-tabs'),0,7);
	foreach($countries as $index=>$country) {
		$id=$country['id'];$tab_key=substr(md5($tab_id.':'.$id),0,7);
		$tabs[]=['_id'=>$tab_key,'tab_title'=>$country['label'],
			'__dynamic__'=>['tab_title'=>native_destination_tag('native-selling-destinations-label',$id)]];
		$content_widgets=[];
		foreach($authored_widgets as $widget) {
			$widget=native_destination_clone_ids($widget,(string)$id);
			if(($widget['widgetType']??'')==='jet-listing-grid') {
				// The old navigation eligibility filter has an authored fixed parent
				// (Egypt) intended for its original one-country grid. Here the company
				// sale-country eligibility already ran at the navigation projection.
				// Delegate per-country taxonomy/parent filtering to JetEngine natively.
				unset($widget['settings']['product_navigation_service'], $widget['settings']['product_navigation_channel']);
				$widget['settings']['terms_parent']=(string)$country['translated_id'];
				$widget['settings']['terms_hide_empty']='false';
				$widget['settings']['posts_num']=min((int)($widget['settings']['posts_num']??12),12);
			}
			if(($widget['widgetType']??'')==='button' && str_contains((string)($widget['settings']['_css_classes']??''),'navigation-editor-see-all')) {
				$widget['settings']['link']=['url'=>$country['url'],'is_external'=>'','nofollow'=>''];
				$widget['settings']['__dynamic__']['link']=native_destination_tag('native-selling-destinations-url',$id);
			}
			$content_widgets[]=$widget;
		}
		$children[]=['id'=>substr(md5($tab_id.':content:'.$id),0,7),'elType'=>'container','isInner'=>true,
			'settings'=>['_title'=>'Destination '.$country['label'],'content_width'=>'full','flex_direction'=>'column',
				'_css_classes'=>'native-destination-tab-content'],
			'elements'=>$content_widgets];
	}
	$panel['elements']=[['id'=>$tab_id,'elType'=>'widget','widgetType'=>'nested-tabs',
		'settings'=>[
			'tabs'=>$tabs,
			'tabs_title_space_between'=>['unit'=>'px','size'=>7,'sizes'=>[]],
			'tabs_title_spacing'=>['unit'=>'px','size'=>0,'sizes'=>[]],
			'horizontal_scroll_mobile'=>'disable',
			'_css_classes'=>'native-destinations-tabs',
		],
		'elements'=>$children]];
	return $panel;
}

function native_destination_project(array $node,string $lang): array {
	$countries=native_destination_approved($lang);
	$items=(array)($node['settings']['menu_items']??[]);
	$panels=(array)($node['elements']??[]);
	if(count($items)!==count($panels))return $node;
	$country_indices=[];
	foreach($items as $i=>$item) {
		if(!preg_match('/^term:(\d+)$/',(string)($item['product_navigation_key']??''),$m))continue;
		$term=get_term((int)$m[1],'destinations');
		if($term instanceof WP_Term&&get_term_meta($term->term_id,'destination_type',true)==='country')$country_indices[]=$i;
	}
	if(!$country_indices)return $node; // Editor still controls the existence of this root menu slot.
	$idx=$country_indices[0];$original=$items[$idx];$panel=$panels[$idx];
	if(count($countries)===1 && (string)($original['product_navigation_key']??'')==='term:'.$countries[0]['id'])
		return $node; // 100% unchanged for authored Egypt-only menu.
	$newItems=[];$newPanels=[];
	foreach($items as $i=>$item) {
		if(in_array($i,$country_indices,true)){
			if($i!==$idx || !$countries)continue;
			$item=$original;
			$source_id=count($countries)===1?$countries[0]['id']:0;
			$item['product_navigation_key']=$source_id?'term:'.$source_id:'destinations';
			$item['item_title']=native_destination_title($source_id,$lang);
			$item['item_link']=['url'=>$source_id?$countries[0]['url']:'','is_external'=>'','nofollow'=>''];
			$item['__dynamic__']['item_title']=native_destination_tag('native-selling-destinations-label',$source_id);
			if($source_id)
				$item['__dynamic__']['item_link']=native_destination_tag('native-selling-destinations-url',$source_id);
			else unset($item['__dynamic__']['item_link']);
			$item['item_dropdown_content']='yes';
			$newItems[]=$item;
			$newPanels[]=native_destination_tabs_panel($panel,$countries,$lang);
		}else{
			$newItems[]=$item;$newPanels[]=$panels[$i];
		}
	}
	$node['settings']['menu_items']=$newItems;
	$node['elements']=$newPanels;
	return $node;
}


/**
 * Strictly native Elementor menu projection. All visual structure is read from
 * an independently editable Elementor section, not manufactured markup.
 */
function native_destination_saved_panel(): ?array {
 static $cache=null;
 if($cache!==null)return $cache;
 $template_id = (int) get_option('_native_destinations_elementor_source_id',0);
 if(!$template_id || get_post_type($template_id) !== 'elementor_library'
  || get_post_meta($template_id,'_elementor_template_type',true)!=='section')return null;
 $a=json_decode((string)get_post_meta($template_id,'_elementor_data',true),true);
 $panel=$a[0]??null;
 if(!is_array($panel) || ($panel['elType']??'')!=='container'
   || ($panel['elements'][0]['widgetType']??'')!=='nested-tabs')return null;
 return $cache=$panel;
}
function native_destination_country_id_from_panel(array $panel): int {
 return absint($panel['settings']['country_source_id']??0);
}
function native_destination_country_content(array $panel,array $country,string $lang): array {
 $root=$panel;
 $walk=function(&$elements)use(&$walk,$country) {
  foreach($elements as &$elem) {
   $type=$elem['widgetType']??'';
   if($type==='jet-listing-grid'){
    // Remove only the original Egypt-fixed service source from cloned country
    // listings; the selling country was approved by the existing authority.
    unset($elem['settings']['product_navigation_service'],$elem['settings']['product_navigation_channel']);
    $elem['settings']['terms_parent']=(string)$country['translated_id'];
    $elem['settings']['terms_hide_empty']='false';
   }
   if($type==='button'&&str_contains((string)($elem['settings']['_css_classes']??''),'navigation-editor-see-all')){
    $elem['settings']['link']=['url'=>$country['url'],'is_external'=>'','nofollow'=>''];
    $elem['settings']['__dynamic__']['link']=native_destination_tag('native-selling-destinations-url',$country['id']);
   }
   if(!empty($elem['elements']))$walk($elem['elements']);
  }
  unset($elem);
 };
 $walk($root['elements']);
 $grid=null;
 $find=function($nodes)use(&$find,&$grid) {
  foreach($nodes as $node){
   if(($node['widgetType']??'')==='jet-listing-grid'){ $grid=$node; return; }
   if(!empty($node['elements']))$find($node['elements']);
   if($grid)return;
  }
 };
 $find($root['elements']);
 if($grid){
  $deep=native_destinations_deep_subtabs($grid,(int)$country['translated_id'],$lang);
  if($deep)$root['elements'][]=$deep;
 }
 return $root;
}
function native_destination_saved_tabs(array $countries,string $lang): ?array {
 $saved=native_destination_saved_panel();
 if(!$saved)return null;
 $source_widget=$saved['elements'][0];
 $rows=(array)($source_widget['settings']['tabs']??[]);
 $children=(array)($source_widget['elements']??[]);
 if(count($rows)!==count($children))return null;
 $lookup=[];
 foreach($children as $i=>$child) {
  $id=native_destination_country_id_from_panel($child);
  if($id) $lookup[$id]=['tab'=>$rows[$i],'content'=>$child];
 }
 $new_tabs=[];$new_children=[];
 foreach($countries as $country) {
  $id=(int)$country['id'];
  if(!isset($lookup[$id])) {
   // Future activated country, before an editor has added its tab:
   // safely reuse the saved Elementor structure, not HTML, with a unique ID.
   if(!$lookup)return null;
   $master=reset($lookup);
   $tab=$master['tab'];$tab['_id']=substr(md5('country'.$id),0,7);
   $child=native_destination_clone_ids($master['content'],'country-'.$id);
   $child['settings']['country_source_id']=$id;
  }else{
   $tab=$lookup[$id]['tab'];$child=$lookup[$id]['content'];
  }
  $tab['tab_title']=$country['label'];
  $tab['__dynamic__']['tab_title']=native_destination_tag('native-selling-destinations-label',$id);
  $child=native_destination_country_content($child,$country,$lang);
  $new_tabs[]=$tab;$new_children[]=$child;
 }
 $source_widget['settings']['tabs']=$new_tabs;
 $source_widget['elements']=$new_children;
 $saved['elements']=[$source_widget];
 return $saved;
}

function native_destination_authorized_menu(array $node,string $lang):array {
 if(($node['id']??'')!=='6072b3f'||($node['widgetType']??'')!=='mega-menu'
  || !str_contains((string)($node['settings']['_css_classes']??''),'product-native-navigation'))return $node;
 $countries=native_destination_approved($lang);
 $items=(array)($node['settings']['menu_items']??[]);
 $panels=(array)($node['elements']??[]);
 if(count($items)!==count($panels))return $node;
 $slot=null;$country_slots=[];
 foreach($items as $i=>$item){
  if(!preg_match('/^term:(\d+)$/',(string)($item['product_navigation_key']??''),$matches))continue;
  $term=get_term((int)$matches[1],'destinations');
  if($term instanceof WP_Term && get_term_meta($term->term_id,'destination_type',true)==='country')
   $country_slots[]=$i;
 }
 if(!$country_slots)return $node;
 $slot=$country_slots[0];
 // Even for Egypt-only, derive the label, URL and matching native Elementor
 // panel from the same sale-qualified dataset; authored source templates stay intact.
 $new_panel=count($countries) ? native_destination_saved_tabs($countries,$lang) : null;
 if($countries && !$new_panel)$countries=[]; // Fail CLOSED instead of leaking authored inactive countries.
 $result_items=[];$result_panels=[];
 foreach($items as $i=>$item){
  if(!in_array($i,$country_slots,true)){
   $result_items[]=$item;$result_panels[]=$panels[$i];continue;
  }
  if($i!==$slot || !$countries)continue;
  $country_count=count($countries);
  $country_id=$country_count===1?(int)$countries[0]['id']:0;
  $item['product_navigation_key']=$country_id?'term:'.$country_id:'destinations';
  $item['item_title']=$country_id?$countries[0]['label']:native_destination_title(0,$lang);
  $item['item_link']=['url'=>$country_id?$countries[0]['url']:'','is_external'=>'','nofollow'=>''];
  $item['item_dropdown_content']='yes';
  $item['__dynamic__']['item_title']=native_destination_tag('native-selling-destinations-label',$country_id);
  if($country_id)$item['__dynamic__']['item_link']=native_destination_tag('native-selling-destinations-url',$country_id);
  else unset($item['__dynamic__']['item_link']);
  if($country_id){
   // Use native Elementor country tab's existing Grid+CTA without the
   // tabs wrapper when just one selling country is enabled.
   $section=$new_panel['elements'][0]['elements'][0];
   // Remove only the country Tabs wrapper, retaining the authored inner Container.
   $new_panel['elements']=[$section];
  }
  $result_items[]=$item;$result_panels[]=$new_panel;
 }
 $node['settings']['menu_items']=$result_items;$node['elements']=$result_panels;
 return $node;
}
// QA-first integration: switch to production mode only after DOM/native editor
// acceptance and restore checks complete. Does not affect saved header data.
add_filter('elementor/frontend/builder_content_data',static function($data,$post_id) {
 if(!is_array($data))return $data;
 $gate='LIVE';
 if($gate!=='LIVE' && (!isset($_GET['native_country_qa'])
   || !hash_equals($gate,(string)$_GET['native_country_qa'])))return $data;
 $language=native_destination_language();
 $walk=function($nodes)use(&$walk,$language) {
  foreach($nodes as &$n){
   if(($n['id']??'')==='6072b3f' && ($n['widgetType']??'')==='mega-menu')
    $n=native_destination_authorized_menu($n,$language);
   else if(!empty($n['elements']))$n['elements']=$walk($n['elements']);
  }
  unset($n);
  return $nodes;
 };
 return $walk($data);
},35,2);

add_action('updated_option',static function($name){
 if($name==='destination-activation'){
  do_action('mad4b/public_html/safe_purge_all', 'product_navigation_native_destinations');
 }
},40,1);

// A nested Elementor document projected inside the published Header still needs
// the stylesheet GENERATED by that native Elementor source document. Without
// this enqueue, its authored button and nested-tab controls fall back to theme
// defaults (large gold square buttons, incorrect fonts and borders).
add_action('wp_enqueue_scripts',static function(){
 if(is_admin() || !class_exists('\\Elementor\\Core\\Files\\CSS\\Post'))return;
 $source=(int)get_option('_native_destinations_elementor_source_id',0);
 if(!$source || get_post_meta($source,'_elementor_template_type',true)!=='section')return;
 \Elementor\Core\Files\CSS\Post::create($source)->enqueue();
},32);

// The existing JetEngine listing's curated term field is literally "exerpt".
// Keep this field first; if an editor has not filled it, use the taxonomy's
// original Description. Scope to the source destination-card widget only.
add_filter('jet-engine/listings/dynamic-field/custom-value',static function($value,$settings,$widget=null){
 if($value!==null && $value!==false && $value!=='')return $value;
 if(($settings['dynamic_field_source']??'')!=='meta'
  || ($settings['dynamic_field_post_meta_custom']??'')!=='exerpt'
  || !str_contains((string)($settings['_css_classes']??''),'navigation-editor-card-excerpt'))return $value;
 if(!function_exists('jet_engine'))return $value;
 $term=jet_engine()->listings->data->get_current_object();
 if(!$term instanceof WP_Term || $term->taxonomy!=='destinations')return $value;
 $excerpt=trim((string)get_term_meta($term->term_id,'exerpt',true));
 if($excerpt!=='')return $excerpt;
 $desc=trim(wp_strip_all_tags((string)$term->description));
 return $desc!==''?$desc:$value;
},20,3);


/**
 * Eligibility is decided BEFORE JetEngine term-query pagination, scoped to the
 * Elementor-authored navigation's country-child Listing Grid only.
 */
function native_destinations_term_has_featured_image(WP_Term $term): bool {
	$value=get_term_meta($term->term_id,'featured_image',true);
	if(is_array($value)) {
		if(isset($value['url']))$value=$value['url'];
		elseif(isset($value['id']))$value=$value['id'];
		else return false;
	}
	if(is_numeric($value)&&absint($value)>0){
		return (bool)wp_get_attachment_url(absint($value));
	}
	if(!is_string($value) || trim($value)==='')return false;
	// WordPress media paths may contain UTF-8 characters (e.g. the Aswan
	// upload). FILTER_VALIDATE_URL rejects those legitimate file paths.
	$url=trim($value);
	$scheme=strtolower((string)wp_parse_url($url,PHP_URL_SCHEME));
	$host=(string)wp_parse_url($url,PHP_URL_HOST);
	return in_array($scheme,['http','https'],true) && $host!=='' && esc_url_raw($url)!=='';
}
/**
 * Authoritative MENU sale-readiness index, not general Discover/Explore
 * availability. Every post must be published and publicly permitted for a
 * service with an ACTIVE standalone sales channel. Tour components/extra_trip
 * never make a country appear in a public sale menu.
 *
 * Terms of actual sellable services are promoted through their public WPML EN
 * ancestor chain. An unpublished/disabled descendant cannot qualify its parent.
 * Rates/dates/stock remain later checkout checks, not automatic menu approvals.
 */
function native_destinations_sale_term_index(string $language):array {
 static $cache=[];
 $services=get_option('service-activation',[]);
 $config=get_option('destination-activation',[]);
 $key=$language.'|'.md5(wp_json_encode([$services,$config]));
 if(array_key_exists($key,$cache))return $cache[$key];
 $owners=['tours'=>'tours-and-activities','properties'=>'properties','transportations'=>'transportations'];
 $types=[];
 foreach($owners as $owner=>$cpt){
   if(!function_exists('service_activation_enabled')||!service_activation_enabled($owner))continue;
   if(!function_exists('service_channel_allowed')||!service_channel_allowed($owner,'standalone'))continue;
   $types[]=$cpt;
 }
 if(!$types)return $cache[$key]=[];
 global $wpdb;
 $quoted="'".implode("','",array_map('esc_sql',$types))."'";
 $sql="SELECT DISTINCT p.ID AS product_id,p.post_type,tt.term_id
       FROM {$wpdb->posts} p
       JOIN {$wpdb->term_relationships} r ON r.object_id=p.ID
       JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=r.term_taxonomy_id
       WHERE tt.taxonomy='destinations'
         AND p.post_status='publish' AND p.post_password=''
         AND p.post_type IN ($quoted)
         AND (p.post_type<>'tours-and-activities' OR (
           NOT EXISTS(SELECT 1 FROM {$wpdb->postmeta} extra
             WHERE extra.post_id=p.ID AND extra.meta_key='tour_mode' AND extra.meta_value='extra_trip')
           AND NOT EXISTS(SELECT 1 FROM {$wpdb->postmeta} visibility
             WHERE visibility.post_id=p.ID AND visibility.meta_key='tour_public_status'
               AND visibility.meta_value IN ('0','false','no','disabled'))))";
 $rows=$wpdb->get_results($sql);
 $index=[];$skip=[];
 // Request-local only: repeated destination terms from multiple published
 // services inherit their canonical EN mapping and ancestor eligibility.
 // No persisted sales authorization or cross-language/market sharing.
 $canonicalMemo=[];$ancestorMemo=[];
 foreach((array)$rows as $row){
   $product=(int)$row->product_id;
   // Explicit product-sales policy remains pluggable by existing engines.
   if(!isset($skip[$product])){
     $skip[$product]=(bool)apply_filters('native_destinations/public_sale_product_allowed',
       true,$product,(string)$row->post_type);
   }
   if(!$skip[$product])continue;
   $localized=(int)$row->term_id;
   if(!array_key_exists($localized,$canonicalMemo)){
     $en_id=(int)apply_filters('wpml_object_id',$localized,'destinations',false,'en');
     $canonicalMemo[$localized]=$en_id?:$localized;
   }
   $term_id=$canonicalMemo[$localized];
   $seen=[];
   // Only promote along an entirely public, valid ancestor path.
   while($term_id && count($seen)<30){
     if(isset($seen[$term_id]))break;
     $seen[$term_id]=1;
     if(!array_key_exists($term_id,$ancestorMemo)){
       $t=WP_Term::get_instance($term_id);
       $isAllowed=$t instanceof WP_Term
         && !in_array((string)get_term_meta($term_id,'is_public',true),['0','false','no','disabled'],true)
         && (!function_exists('destinations_activation_allowed')
             || destinations_activation_allowed($term_id));
       $ancestorMemo[$term_id]=[
         'allowed'=>$isAllowed,
         'parent'=>$t instanceof WP_Term?(int)$t->parent:0
       ];
     }
     $termStatus=$ancestorMemo[$term_id];
     if(!$termStatus['allowed']){
       $seen=[];break;
     }
     $term_id=$termStatus['parent'];
   }
   if($term_id!==0 || !$seen)continue;
   foreach(array_keys($seen) as $id)$index[(int)$id]=true;
 }
 return $cache[$key]=$index;
}
/** Visible term must be enabled in activation and sale-qualified. */
function native_destinations_sale_term_enabled(int $term_id,string $language):bool {
 $term=WP_Term::get_instance($term_id);
 if(!$term instanceof WP_Term)return false;
 $en_id=(int)apply_filters('wpml_object_id',$term_id,'destinations',false,'en');
 $canonical=$en_id?:$term_id;
 $index=native_destinations_sale_term_index($language);
 if(!isset($index[$canonical]))return false;
 // WPML may omit policy-neutral meta on a localized term while its
 // canonical source remains published. Inherit only an ABSENT is_public
 // value: an explicit localized '0' must always block publication.
 $localized_public=(string)get_term_meta($term_id,'is_public',true);
 $effective_public=$localized_public!==''
   ? $localized_public
   : (string)get_term_meta($canonical,'is_public',true);
 if(!in_array((string)get_term_meta($term_id,'is_featured',true),['1','true'],true)
   || $effective_public!=='1'
   || !native_destinations_term_has_featured_image($term))return false;
 if(function_exists('destinations_activation_allowed')&&!destinations_activation_allowed($term_id))return false;
 $kind=(string)get_term_meta($canonical,'destination_type',true);
 if($kind==='state_governorate'){
   $active=get_option('destination-activation',[]);
   $approved=array_map('intval',(array)($active['active_governorates']??[]));
   if(!in_array($canonical,$approved,true))return false;
 }
 return true;
}
/**
 * Works at *any* depth. No grandfathering of hidden siblings, parents or
 * children; a branch is offered only if it has authorized sales below it.
 */
function native_destinations_eligible_children(int $parent_id,string $language):array {
 static $memo=[];
 // One request-local hierarchy snapshot per locale, never persistent sales
 // eligibility: activation, publication and featured-image checks remain live.
 static $term_parents=null;
 static $locale_children=[];
 $services=get_option('service-activation',[]);
 $approval=get_option('destination-activation',[]);
 $key=$parent_id.'|'.$language.'|'.md5(wp_json_encode([$services,$approval]));
 if(isset($memo[$key]))return $memo[$key];
 $memo[$key]=[];
 $parent=WP_Term::get_instance($parent_id);
 if(!$parent instanceof WP_Term)return [];
 $en=(int)apply_filters('wpml_object_id',$parent_id,'destinations',false,'en');
 $en=$en?:$parent_id;
 // A term can only inherit sale authority from an approved country.
 // Use the canonical parent chain, not get_ancestors(): WPML can rewrite
 // even an EN source term's ancestors to the current locale (Egypt 665
 // becomes 669 in ES). Activation is keyed by canonical EN IDs.
 global $wpdb;
 if($term_parents===null){
   $term_parents=[];
   $rows=$wpdb->get_results(
     "SELECT term_id,parent FROM {$wpdb->term_taxonomy} WHERE taxonomy='destinations'",ARRAY_A);
   if(is_array($rows))foreach($rows as $row)
      $term_parents[(int)$row['term_id']]=(int)$row['parent'];
 }
 $path=[$en];$cursor=$en;$seen=[$en=>true];
 for($depth=0;$depth<30;$depth++){
   // Retain original SQL fallback for a term missing from the snapshot.
   $parent=array_key_exists($cursor,$term_parents)
      ? $term_parents[$cursor]
      : (int)$wpdb->get_var($wpdb->prepare(
        "SELECT parent FROM {$wpdb->term_taxonomy}
         WHERE term_id=%d AND taxonomy=%s LIMIT 1",
        $cursor,'destinations'));
   if($parent<1 || isset($seen[$parent]))break;
   $path[]=$parent;$seen[$parent]=true;$cursor=$parent;
 }
 $countries=native_destination_configured($language);
 $allowed=array_map(static fn($c)=>(int)$c['id'],$countries);
 if(!array_intersect($allowed,$path))return [];
 if(!isset(native_destinations_sale_term_index($language)[$en]))return [];
 // WPML's get_terms filter uses its mutable *current* language even when
 // lang=fr is requested explicitly. Resolve the requested locale from the
 // taxonomy translation table instead of accidentally returning AR children.
 global $wpdb;
 if(!array_key_exists($language,$locale_children)){
   $rows=$wpdb->get_results($wpdb->prepare(
     "SELECT tt.term_id,tt.parent FROM {$wpdb->term_taxonomy} tt
       JOIN {$wpdb->terms} t ON t.term_id=tt.term_id
       JOIN {$wpdb->prefix}icl_translations tr
         ON tr.element_id=tt.term_taxonomy_id AND tr.element_type='tax_destinations'
       WHERE tt.taxonomy='destinations' AND tr.language_code=%s
       ORDER BY t.name",$language),ARRAY_A);
   if(!is_array($rows))$locale_children[$language]=false;
   else{
     $tree=[];
     foreach($rows as $row)$tree[(int)$row['parent']][]=(int)$row['term_id'];
     $locale_children[$language]=$tree;
   }
 }
 $children=$locale_children[$language]===false
   ? $wpdb->get_col($wpdb->prepare(
     "SELECT tt.term_id FROM {$wpdb->term_taxonomy} tt
       JOIN {$wpdb->terms} t ON t.term_id=tt.term_id
       JOIN {$wpdb->prefix}icl_translations tr
         ON tr.element_id=tt.term_taxonomy_id AND tr.element_type='tax_destinations'
       WHERE tt.taxonomy='destinations' AND tt.parent=%d AND tr.language_code=%s
       ORDER BY t.name",$parent_id,$language))
   : ($locale_children[$language][$parent_id]??[]);
 if(!$children)return [];
 foreach($children as $child_id){
   $child_id=(int)$child_id;
   if(native_destinations_sale_term_enabled($child_id,$language))
     $memo[$key][]=$child_id;
 }
 return $memo[$key];
}
/**
 * Native Elementor Nested Tabs for sale-qualified descendant branches.
 * No custom markup or stylesheet. A branch without eligible children never
 * receives a tab. Stop at a safe maximum Elementor navigation depth.
 */
function native_destinations_deep_subtabs(array $source_grid,int $parent_id,string $lang,int $depth=1):?array {
 if($depth>5)return null;
 // Tabs are for a NEXT deeper level: do not create a governorate
 // tab unless its city/zone subgrid actually has sale-qualified children.
 $child_ids=array_values(array_filter(native_destinations_eligible_children($parent_id,$lang),
   static fn($id)=>count(native_destinations_eligible_children((int)$id,$lang))>0));
 if(!$child_ids)return null;
 $tabs=[];$contents=[];
 foreach(array_slice($child_ids,0,24) as $child_id){
   $term=WP_Term::get_instance($child_id);
   if(!$term instanceof WP_Term)continue;
   $key=substr(md5('menu-depth-'.$depth.'-'.$lang.'-'.$child_id),0,7);
   $tabs[]=['_id'=>$key,'tab_title'=>$term->name];
   $grid=native_destination_clone_ids($source_grid,'deep-grid-'.$lang.'-'.$child_id);
   unset($grid['settings']['product_navigation_service'],$grid['settings']['product_navigation_channel']);
   $grid['settings']['terms_parent']=(string)$child_id;
   $grid['settings']['terms_hide_empty']='false';
   $grid['settings']['posts_num']=12;
   // Keep the root listing immediate. Only collapsed descendant grids defer.
   if(wp_get_environment_type()==='staging' && !is_admin() && !wp_doing_ajax()){
     $grid['settings']['lazy_load']='yes';
     $grid['settings']['lazy_load_offset']=['unit'=>'px','size'=>0];
   }
   $children=[$grid];
   $nested=native_destinations_deep_subtabs($source_grid,$child_id,$lang,$depth+1);
   if($nested)$children[]=$nested;
   $contents[]=[
     'id'=>substr(md5('deep-content-'.$lang.'-'.$child_id),0,7),
     'elType'=>'container','isInner'=>true,
     'settings'=>['_title'=>'Destination '.$term->name,'content_width'=>'full','flex_direction'=>'column'],
     'elements'=>$children
   ];
 }
 if(!$tabs)return null;
 // Inherit editable native tab controls and their generated CSS selector.
 // Only the CSS class is shared; actual widget/content IDs remain unique.
 $source_panel=native_destination_saved_panel();
 $style_widget=$source_panel['elements'][0]??[];
 $style_settings=(array)($style_widget['settings']??[]);
 unset($style_settings['tabs'],$style_settings['_element_id']);
 if(($style_settings['nav_design_enabled']??'')==='yes'){
  $nested_variant=$style_settings['nav_design_nested_variant']??'inherit';
  if(in_array($nested_variant,['pills','outline','underline','cards'],true))
   $style_settings['nav_design_variant']=$nested_variant;
  $suffixes=['','_widescreen','_laptop','_tablet_extra','_tablet','_mobile_extra','_mobile'];
  foreach($suffixes as $suffix){
   $nested_direction=$style_settings['nav_design_nested_direction'.$suffix]??'inherit';
   if(in_array($nested_direction,['block-start','block-end','inline-start','inline-end'],true))
    $style_settings['tabs_direction'.$suffix]=$nested_direction;
  }
 }

 $style_settings['_css_classes']=trim((string)($style_settings['_css_classes']??'').' native-destination-deep-tabs elementor-element-'.sanitize_html_class((string)($style_widget['id']??'')));
 $style_settings['tabs']=$tabs;
 return [
  'id'=>substr(md5('deep-tabs-'.$lang.'-'.$parent_id),0,7),
  'elType'=>'widget','widgetType'=>'nested-tabs',
  'settings'=>$style_settings,
  'elements'=>$contents
 ];
}

add_filter('jet-engine/listing/grid/terms-query-args',static function($args,$grid,$settings){
	$classes=(string)($settings['_css_classes']??'');
	if(!str_contains($classes,'navigation-editor-grid')
	  || !empty($settings['product_navigation_service'])
	  || (int)($settings['lisitng_id']??$settings['listing_id']??0)!==67125)
	  return $args;
	$lang=native_destination_language();
	$parent=(int)($args['parent']??$settings['terms_parent']??0);
	$ids=native_destinations_eligible_children($parent,$lang);
	// [0] ensures no results; [] means "unfiltered" in WordPress.
	$args['include']=$ids?:[0];
	return $args;
},40,3);


function native_destinations_general_archive_url(string $language):string {
	// Dedicated published Elementor/WordPress page takes over automatically.
	foreach(['destinations','destination','explore-destinations'] as $path) {
		$page=get_page_by_path($path,OBJECT,'page');
		if(!$page instanceof WP_Post || $page->post_status!=='publish')continue;
		$local_id=(int)apply_filters('wpml_object_id',$page->ID,'page',false,$language);
		if(!$local_id)continue;
		$url=get_permalink($local_id);
		if(is_string($url)&&$url)return $url;
	}
	// No published dedicated Destinations archive yet. The user's existing
	// general search/archive is an Elementor Theme Builder archive template
	// (EN document 30861), not the Blog/posts archive. Native WP search with
	// an empty 's' value selects that archive and its WPML counterpart.
	$language_home=apply_filters('wpml_home_url',home_url('/'),$language);
	if(!is_string($language_home) || $language_home==='')$language_home=home_url('/');
	// A harmless source parameter is retained for future dedicated routing.
	// This is still a native search archive URL, not a direct template URL.
	return trailingslashit($language_home).'?s=&from=destinations';
}
add_filter('elementor/frontend/builder_content_data',static function($data,$post_id){
	if(!is_array($data))return $data;
	$language=native_destination_language();
	$url=native_destinations_general_archive_url($language);
	$walk=function(array $parts)use(&$walk,$url):array{
		foreach($parts as &$item) {
			if(($item['id']??'')==='6072b3f' && ($item['widgetType']??'')==='mega-menu') {
				foreach($item['settings']['menu_items'] as &$menu){
					if(($menu['product_navigation_key']??'')!=='destinations')continue;
					$menu['item_link']=['url'=>$url,'is_external'=>'','nofollow'=>''];
					$menu['__dynamic__']['item_link']=native_destination_tag('native-selling-destinations-url',0);
				}
				unset($menu);
			} elseif(!empty($item['elements']))$item['elements']=$walk($item['elements']);
		}
		unset($item);
		return $parts;
	};
	return $walk($data);
},45,2);

// Dynamic cloned grids do not exist as saved Elementor elements. JetEngine
// signs their emitted settings and validates the signature in its public AJAX
// handler. Use the publicly viewed page as request context, not the source
// elementor_library document, which is deliberately not publicly viewable.
add_filter('jet-engine/listing/grid/lazy-load/post-id',static function($postId,$settings){
 if(wp_get_environment_type()!=='staging' || is_admin() || wp_doing_ajax()
   || !str_contains((string)($settings['_css_classes']??''),'navigation-editor-grid')
   || empty($settings['terms_parent']) )return $postId;
 $obj=get_queried_object();
 if($obj instanceof WP_Post && is_post_publicly_viewable($obj))return $obj->ID;
 return 0;
},30,2);
