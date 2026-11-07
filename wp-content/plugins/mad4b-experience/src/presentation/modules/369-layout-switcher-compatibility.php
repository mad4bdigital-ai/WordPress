<?php
/**
 * MAD4B Experience | Upgrade-safe JetEngine Layout Switcher compatibility.
 * Owns the signed listing resolver and browser behavior without patching
 * files in the Crocoblock-managed plugin. Requires original vendor API 1.0.x.
 */
defined('ABSPATH') || exit;
final class MAD4B_Layout_Switcher_Compatibility {
 private static $outerTourContext = null;
 private static function grid_config():array {
  static $c=null;
  if($c!==null)return $c;
  $c=[];
  if(!self::enabled()||!class_exists('MAD4B_SCP_Site_Profile',false))
   return $c;
  $profile=MAD4B_SCP_Site_Profile::status();
  $row=get_option('mad4b_experience_layout_switcher_grid_v1',[]);
  if(!is_array($profile)||!is_array($row)
   ||($profile['configured']??false)!==true
   ||($profile['origin_match']??false)!==true
   ||($profile['environment_match']??false)!==true
   ||($profile['deployment_binding_match']??false)!==true
   ||($row['site_uuid']??'')!==($profile['site_uuid']??null)
   ||(int)($row['archive_document_id']??0)<1
   ||(int)($row['listing_query_id']??0)<1
   ||(int)($row['terms_query_id']??0)<1
   ||!preg_match('/^[a-f0-9]{8}$/D',(string)($row['layout_widget_id']??''))
   ||!preg_match('/^[a-z0-9_-]{4,80}$/D',(string)($row['nav_element_id']??'')))
    return $c;
  $c=$row;
  return $c;
 }

 private static function compatible(): bool {
  if(!class_exists('Jet_Engine_Layout_Switcher\Frontend',false) ||
     !defined('JET_ENGINE_LAYOUT_SWITCHER_VERSION')) return false;
  $version=(string)JET_ENGINE_LAYOUT_SWITCHER_VERSION;
  return version_compare($version,'1.0.0','>=') && version_compare($version,'1.1.0','<');
 }
 private static function enabled(): bool {
  return class_exists('MAD4B_Presentation_Experience',false)
   && MAD4B_Presentation_Experience::enabled('layout-switcher-compatibility');
 }
 public static function register_hooks(): void {
  if(!self::enabled() || !self::compatible()) return;
  $self=new self();
  add_action('jet-engine/ajax-handlers/before-call-handler',[$self,'apply_authorized_ajax_layout'],5,2);
  add_filter('jet-engine/listing/render/jet-listing-grid/settings',[$self,'apply_authorized_render_layout'],30,1);
  add_filter('jet-engine/listing/listing-id',[$self,'authorized_listing_id_for_switch'],20,2);
  add_filter('jet-engine/listing/grid/nav-widget-settings',[$self,'canonical_archive_nav_settings'],90,3);
  add_filter('jet-engine/listing/query/items',[$self,'scope_first_tour_before_outer_loop'],999,3);
  add_action('jet-engine/query-builder/query/after-query-setup',[$self,'bind_nested_tour_terms_query'],999,1);
  add_filter('jet-engine/listing/pre-get-item-content',[$self,'scope_tour_before_item'],40,5);
  add_action('jet-engine/listings/frontend/object-done',[$self,'recover_tour_after_nested'],PHP_INT_MAX,2);
  add_filter('jet-engine/listings/frontend/listing-item-content',[$self,'recover_tour_after_content'],PHP_INT_MAX,3);

 }
 private static function asset_url(string $file): string {
  $url=plugin_dir_url(dirname(__DIR__,3).'/mad4b-experience.php').
   'src/presentation/assets/'.$file;
  return set_url_scheme($url,(string)wp_parse_url(home_url('/'),PHP_URL_SCHEME));
 }
 private static function asset_src(string $source,string $handle,string $target,string $name): string {
  if($handle!==$target || !self::enabled() || !self::compatible())return $source;
  $path=dirname(__DIR__).'/assets/'.$name;
  if(!is_readable($path)) return $source;
  return add_query_arg('ver',(string)filemtime($path),self::asset_url($name));
 }
 public static function script_src(string $source,string $handle): string {
  return self::asset_src($source,$handle,'jet-engine-layout-switcher','mad4b-layout-switcher.js');
 }
 /**
  * Layout interaction belongs to this module, not Elementor's visual CSS.
  * LiteSpeed can retain the original vendor CSS inside a combined asset even
  * when the vendor handle URL is replaced. A separate dependent style handle
  * guarantees actual pointer input reaches our last-intent handler.
  */
 public static function enqueue_interaction_style(): void {
  if(!self::enabled() || !self::compatible())return;
  $name='mad4b-layout-interaction.css';
  $path=dirname(__DIR__).'/assets/'.$name;
  if(!is_readable($path))return;
  wp_enqueue_style('mad4b-layout-interaction',self::asset_url($name),
   ['jet-engine-layout-switcher'],(string)filemtime($path));
 }
 /**
  * Correct the source widget ID in the published archive's NAV settings
  * *before* JetEngine signs the settings and its mutable query. Restoring a
  * Grid cookie can cause the render context to expose an ephemeral Elementor
  * ID; client-side rewriting would correctly fail HMAC validation.
  *
  * Only exact, unique, authored Elementor listing + switcher pairs qualify.
  * Other listings, AJAX requests and editor previews are untouched.
  */
 public function canonical_archive_nav_settings($nav, $settings, $render) {
  if(!is_array($nav)||!is_array($settings)||wp_doing_ajax()||
     !class_exists('Elementor\Plugin',false) ||
     (string)($settings['_element_id']??'')!==(string)(self::grid_config()['nav_element_id']??'') ||
     (string)($settings['custom_query_id']??'')!==(string)(self::grid_config()['listing_query_id']??'') ||
     empty($nav['_id']) || (string)($nav['_element_id']??'')!==(string)(self::grid_config()['nav_element_id']??''))
    return $nav;
  $document=\Elementor\Plugin::$instance->documents->get_current();
  if(!$document)return $nav;
  $document_id=absint($document->get_main_id());
  if(!$document_id || get_post_status($document_id)!=='publish' ||
     get_post_type($document_id)!=='elementor_library')return $nav;

  $source_archive=(int)(self::grid_config()['archive_document_id']??0);
  $languages=['en','ar','fr','de','es','it'];
  $eligible=false;
  foreach($languages as $language) {
   if($document_id===(int)apply_filters('wpml_object_id',$source_archive,'elementor_library',false,$language)) {
    $eligible=true;break;
   }
  }
  if(!$eligible)return $nav;

  // Resolve only real source controls in the active published document.
  $nodes=json_decode((string)get_post_meta($document_id,'_elementor_data',true),true);
  if(!is_array($nodes))return $nav;
  $matches=[];
  $switchers=[];
  $walk=function($items)use(&$walk,&$matches,&$switchers){
   foreach((array)$items as $node) {
    if(!is_array($node))continue;
    $node_id=(string)($node['id']??'');
    $type=(string)($node['widgetType']??'');
    if($type==='jet-listing-grid')$matches[$node_id]=(array)($node['settings']??[]);
    if($type==='jet-engine-layout-switcher')$switchers[]=(array)($node['settings']??[]);
    if(!empty($node['elements']))$walk($node['elements']);
   }
  };
  $walk($nodes);

  $canonical=[];
  foreach($matches as $widget_id=>$authored){
   if((string)($authored['_element_id']??'')!==(string)(self::grid_config()['nav_element_id']??'')||
      (string)($authored['custom_query_id']??'')!==(string)(self::grid_config()['listing_query_id']??'')||
      absint($authored['lisitng_id']??0)!==absint($settings['lisitng_id']??0))continue;
   foreach($switchers as $switcher){
    if((string)($switcher['widget_id']??'')===$widget_id && !empty($switcher['layouts']))
     $canonical[]=$widget_id;
   }
  }
  $canonical=array_values(array_unique($canonical));
  if(count($canonical)!==1)return $nav;
  if((string)$nav['_id']===(string)$canonical[0])return $nav;
  // Only _id changes; the original widget and query signatures are
  // generated downstream by JetEngine's existing HMAC implementation.
  $nav['_id']=$canonical[0];
  return $nav;
 }
 private static function is_tour_grid_ajax(): bool {
  $site=self::grid_config();
  if(!$site || empty($_REQUEST['switch_layout'])
   || (string)($_REQUEST['handler']??'')!=='get_listing'
   || (string)($_REQUEST['layout_widget']??'')!==$site['layout_widget_id'])return false;
  $doc=(int)($_REQUEST['layout_document']??0);
  if(!$doc || get_post_status($doc)!=='publish' ||
     get_post_type($doc)!=='elementor_library')return false;
  foreach(['en','fr','de','es','it','ar'] as $lang){
   $translated=(int)apply_filters('wpml_object_id',(int)(self::grid_config()['archive_document_id']??0),'elementor_library',false,$lang);
   if($doc===$translated)return true;
  }
  return false;
 }
 private static function restore_tour_post_context(\WP_Post $tour):void {
  global $post,$wp_query;
  $post=$tour;
  if($wp_query instanceof \WP_Query)$wp_query->post=$tour;
  setup_postdata($tour);
  if(function_exists('jet_engine'))jet_engine()->listings->data->set_current_object($tour);
 }
 public function bind_nested_tour_terms_query($query):void {
  if(!self::is_tour_grid_ajax()
   || !(self::$outerTourContext instanceof \WP_Post)
   || !is_object($query)
   || (int)($query->id??0)!==(int)(self::grid_config()['terms_query_id']??0)
   || ($query->query_type??'')!=='terms'
   || !is_array($query->final_query))return;
  $query->final_query['object_ids']=[(int)self::$outerTourContext->ID];
  $query->final_query['queried_object_id']=(int)self::$outerTourContext->ID;
 }
 public function scope_first_tour_before_outer_loop($items,$settings,$grid) {
  if(!self::is_tour_grid_ajax()
   || !is_array($items) || !($items[0]??null) instanceof \WP_Post
   || ($items[0])->post_type!=='tours-and-activities'
   || !is_array($settings)
   || (int)($settings['custom_query_id']??0)!==(int)(self::grid_config()['listing_query_id']??0)) return $items;
  self::$outerTourContext=$items[0];
  self::restore_tour_post_context($items[0]);
  return $items;
 }
 public function scope_tour_before_item($result,$item,$index,$grid,$query) {
  if(!self::is_tour_grid_ajax()
   || !($item instanceof \WP_Post)
   || $item->post_type!=='tours-and-activities'
   || !is_object($grid)||!method_exists($grid,'get_settings')
   || (int)$grid->get_settings('custom_query_id')!==(int)(self::grid_config()['listing_query_id']??0))return $result;
  self::$outerTourContext=$item;
  self::restore_tour_post_context($item);
  return $result;
 }
 public function recover_tour_after_nested($item,$listing) :void {
  if(!self::is_tour_grid_ajax()
   || !(self::$outerTourContext instanceof \WP_Post)
   || (int)$listing!==48562
   || !($item instanceof \WP_Term))return;
  self::restore_tour_post_context(self::$outerTourContext);
 }
 public function recover_tour_after_content($content,$listing,$item) {
  if(!self::is_tour_grid_ajax()
   || !(self::$outerTourContext instanceof \WP_Post)
   || !($item instanceof \WP_Post)
   || $item->ID!==self::$outerTourContext->ID)return $content;
  self::restore_tour_post_context($item);
  return $content;
 }
 public static function notice(): void {
  if(!self::enabled() || !current_user_can('manage_options') ||
     !defined('JET_ENGINE_LAYOUT_SWITCHER_VERSION') || self::compatible())return;
  echo '<div class="notice notice-warning"><p>Layout Switcher was updated to an unverified API version. Review the MAD4B compatibility module and run archive visual acceptance before approving the new version.</p></div>';
 }
 public static function boot(): void {
  add_action('init',[__CLASS__,'register_hooks'],13);
  add_action('wp_enqueue_scripts',[__CLASS__,'enqueue_interaction_style'],100);
  add_filter('script_loader_src',[__CLASS__,'script_src'],999,2);
  // Native Crocoblock CSS remains vendor-owned. This module owns only
  // the independent pointer-input behavior rule above.
  add_action('admin_notices',[__CLASS__,'notice']);
 }
	/** Match Render's slug without loading its frontend-only render base in AJAX. */
	private function get_authorized_layout_slug( array $layout ) {
		if ( ! empty( $layout['slug'] ) ) $value = $layout['slug'];
		elseif ( ! empty( $layout['label'] ) ) $value = $layout['label'];
		elseif ( ! empty( $layout['_id'] ) ) $value = 'layout-' . $layout['_id'];
		elseif ( ! empty( $layout['id'] ) ) $value = 'layout-' . $layout['id'];
		else $value = 'layout-' . md5( json_encode( $layout ) );
		return str_replace( ' ', '-', preg_replace( '/[^a-z0-9\\s\\-\\_]/', '', remove_accents( strtolower( (string) $value ) ) ) );
	}

	/** Resolve a layout from its published owner after core request validation. */
	public function apply_authorized_ajax_layout( $handler, $request ) {
		if ( empty( $request['switch_layout'] ) || 'get_listing' !== ( $request['handler'] ?? '' ) ) return;
		$settings = is_array( $request['widget_settings'] ?? null ) ? $request['widget_settings'] : array();
		$result = $this->resolve_authorized_ajax_layout( $request, $settings );
		if ( is_wp_error( $result ) ) wp_send_json_error( 'Invalid declared layout' );
		$_REQUEST['widget_settings'] = $result;
	}

	/** The client supplies a selector only, never a new authorized configuration. */
	public function resolve_authorized_ajax_layout( array $request, array $settings ) {
		$fail = new \WP_Error( 'invalid_declared_layout', 'Invalid declared layout' );
		$document_id = absint( $request['layout_document'] ?? 0 );
		$widget_id = is_scalar( $request['layout_widget'] ?? null ) ? (string) $request['layout_widget'] : '';
		$slug = is_scalar( $request['layout_slug'] ?? null ) ? (string) $request['layout_slug'] : '';
		if ( ! $document_id || 'publish' !== get_post_status( $document_id ) || ! $widget_id || ! $slug ) return $fail;
		$nodes = json_decode( (string) get_post_meta( $document_id, '_elementor_data', true ), true );
		if ( ! is_array( $nodes ) ) return $fail;
		$widget = null; $switchers = array();
		$walk = function( $items ) use ( &$walk, &$widget, &$switchers, $widget_id ) {
			foreach ( $items as $node ) {
				if ( ( $node['id'] ?? '' ) === $widget_id && 'jet-listing-grid' === ( $node['widgetType'] ?? '' ) ) $widget = $node['settings'] ?? array();
				if ( 'jet-engine-layout-switcher' === ( $node['widgetType'] ?? '' ) && (string) ( $node['settings']['widget_id'] ?? '' ) === $widget_id ) $switchers[] = $node['settings'];
				if ( ! empty( $node['elements'] ) ) $walk( $node['elements'] );
			}
		};
		$walk( $nodes );
		if ( ! $widget || (string) ( $settings['_id'] ?? '' ) !== $widget_id ||
			absint( $widget['lisitng_id'] ?? 0 ) !== absint( $settings['lisitng_id'] ?? 0 ) ||
			(string) ( $widget['custom_query_id'] ?? '' ) !== (string) ( $settings['custom_query_id'] ?? '' ) ) return $fail;
		$selected = null;
		foreach ( $switchers as $switcher ) foreach ( (array) ( $switcher['layouts'] ?? array() ) as $layout ) {
			if ( $this->get_authorized_layout_slug( $layout ) === $slug ) { $selected = $layout; break 2; }
		}
		if ( ! $selected ) return $fail;
		$target = ! empty( $selected['is_default_layout'] ) ? absint( $widget['lisitng_id'] ) : absint( $selected['lisitng_id'] ?? $widget['lisitng_id'] );
		if ( ! $target || 'publish' !== get_post_status( $target ) || 'jet-engine' !== get_post_type( $target ) || absint( $request['layout_listing'] ?? 0 ) !== $target ) return $fail;
		$settings['_layout_listing'] = $target;
		// Only authored presentation keys can alter the validated base payload.
		foreach ( array( '', '_widescreen', '_laptop', '_tablet_extra', '_tablet', '_mobile', '_mobile_extra' ) as $suffix ) {
			foreach ( array( 'columns', 'column_min_width' ) as $prefix ) {
				$key = $prefix . $suffix;
				if ( isset( $selected[$key] ) && is_scalar( $selected[$key] ) && is_numeric( $selected[$key] ) ) $settings[$key] = $selected[$key];
			}
		}
		return $settings;
	}

	/**
	 * Resolve only an existing published Elementor switcher target for actual
	 * JetEngine rendering. The user-supplied listing ID is NEVER authoritative.
	 */
	public function apply_authorized_render_layout( $settings ) {
		if ( ! is_array( $settings ) || empty( $_REQUEST['switch_layout'] ) ||
			( $_REQUEST['handler'] ?? '' ) !== 'get_listing' ||
			! isset( $settings['_id'] ) || ! isset( $_REQUEST['layout_widget'] ) ||
			(string) $settings['_id'] !== (string) $_REQUEST['layout_widget'] ) {
			return $settings;
		}
		$base = $_REQUEST['widget_settings'] ?? array();
		if ( ! is_array( $base ) ) { return $settings; }
		$authorized = $this->resolve_authorized_ajax_layout( $_REQUEST, $base );
		if ( is_wp_error( $authorized ) || empty( $authorized['_layout_listing'] ) ) {
			return $settings;
		}
		$settings['_layout_listing'] = absint( $authorized['_layout_listing'] );
		return $settings;
	}

	/**
	 * JetEngine can resolve listing ID before the render/settings hook runs.
	 * Guarantee the target template on the real listing-ID boundary, too.
	 * Never switch nested card listings or an unknown Elementor widget.
	 */
	public function authorized_listing_id_for_switch( $listing_id, $settings ) {
		if ( empty( $_REQUEST['switch_layout'] ) ||
			( $_REQUEST['handler'] ?? '' ) !== 'get_listing' ||
			! is_array( $settings ) ||
			(string) ( $settings['_id'] ?? '' ) !== (string) ( $_REQUEST['layout_widget'] ?? '' ) ) {
			return $listing_id;
		}
		$base = $_REQUEST['widget_settings'] ?? array();
		if ( ! is_array( $base ) ||
			absint( $base['lisitng_id'] ?? 0 ) !== absint( $settings['lisitng_id'] ?? 0 ) ) {
			return $listing_id;
		}
		$resolved = $this->resolve_authorized_ajax_layout( $_REQUEST, $base );
		if ( is_wp_error( $resolved ) || empty( $resolved['_layout_listing'] ) ) {
			return $listing_id;
		}
		return absint( $resolved['_layout_listing'] );
	}


}
MAD4B_Layout_Switcher_Compatibility::boot();
