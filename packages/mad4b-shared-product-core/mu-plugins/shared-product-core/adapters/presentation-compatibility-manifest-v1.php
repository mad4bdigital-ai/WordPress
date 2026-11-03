<?php
/**
 * Presentation Compatibility Manifest v1 — read-only facade.
 * No rendering, price resolution, order creation, user-state storage or
 * vendor plugin mutations are permitted in this class.
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'local' || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
final class Shared_Product_Presentation_Compatibility_v1 {
 private const SCHEMA_VERSION='1.0.0';
 private static function capability(bool $available,string $source,string $missing='not_installed'): array {
  return ['status'=>$available?'supported':'unsupported','source'=>$source,
          'reason'=>$available?null:$missing];
 }
 private static function swatch_vendor_installed(): bool {
  $active=(array)get_option('active_plugins',[]);
  foreach($active as $path){
   if(preg_match('~(^|/)(?:woocommerce-variation-swatches|woo-variation-swatches|variation-swatches|swatches-for-woocommerce)(?:/|$)~i',(string)$path))return true;
  }
  return false;
 }
 public static function manifest(): array {
  $elementor=class_exists('Elementor\\Plugin');
  $jetengine=class_exists('Jet_Engine\\Query_Builder\\Manager');
  $jsf=class_exists('Jet_Smart_Filters');
  $wpml=(defined('ICL_SITEPRESS_VERSION') || class_exists('SitePress'));
  $wcml=class_exists('woocommerce_wpml') || isset($GLOBALS['woocommerce_wpml']);
  return [
   'schema_version'=>self::SCHEMA_VERSION,
   'read_only'=>true,
   'environment'=>wp_get_environment_type(),
   'capabilities'=>[
    'elementor'=>self::capability($elementor,'Elementor\\Plugin'),
    'jetengine'=>self::capability($jetengine,'Jet_Engine Query Builder'),
    'jetsmartfilters'=>self::capability($jsf,'Jet_Smart_Filters'),
    'woocommerce'=>self::capability(class_exists('WooCommerce'),'WooCommerce'),
    'wpml'=>self::capability($wpml,'WPML'),
    'wcml'=>self::capability($wcml,'WCML'),
    'swatches_vendor'=>self::capability(self::swatch_vendor_installed(),'registered Swatches vendor','no_verified_swatch_vendor'),
    'visual_selection'=>self::capability(function_exists('shared_product_get_rate_option_context') || file_exists(WPMU_PLUGIN_DIR.'/listing-option-selection.php'),'MU visual selection adapter'),
    'presentation_context_v1'=>self::capability(class_exists('Shared_Product_Presentation_Context_v1'),'Local read-only WPML/Listing context'),
    'discovery_search_presentation_v1'=>self::capability(class_exists('Shared_Product_Discovery_Search_Presentation_v1'),'Read-only Search Scope/Term Listing context'),
    'destination_term_presentation_v1'=>self::capability(class_exists('Shared_Product_Destination_Term_Presentation_v1'),'Read-only taxonomy ancestors, siblings, descendants, eligible sections and Elementor term dynamic fields'),
    'service_public_access_v1'=>self::capability(class_exists('Shared_Service_Public_Access_v1'),'Service Activation governs public routes and rendered link eligibility'),
    'taxonomy_archive_presentation_v1'=>self::capability(class_exists('Shared_Product_Taxonomy_Archive_Presentation_v1'),'Read-only registry of native taxonomy archives, publication and service-channel eligibility'),
    'presentation_selection_v1'=>self::capability(is_readable(WPMU_PLUGIN_DIR.'/shared-product-core/assets/presentation-selection-state-v1.js'),'Local in-memory, read-only selection-event facade'),
    'fluent_form_arrival_projection_v1'=>self::capability(is_readable(WPMU_PLUGIN_DIR.'/shared-product-core/assets/presentation-fluent-projection-v1.js') && function_exists('shared_product_form_booking_intent') && function_exists('shared_product_booking_v2_validate'),'Existing Fluent Form #6 Arrival Date to Booking Configuration v2 read-only projection'),
    'form6_server_candidate_v1'=>self::capability(class_exists('Shared_Product_Presentation_Form6_Candidate_v1'),'Server-verified, nonfinancial Form #6/Rate/Stays/Extras candidate'),
    'tour_transport_presentation_v1'=>self::capability(class_exists('Shared_Product_Presentation_Transport_Context_v1'),'Strict local Tour/Rate/Taxonomy display context, native 45152/40299 bindings'),
    'jetengine_gallery_lazy_visibility_v1'=>self::capability(is_readable(WPMU_PLUGIN_DIR.'/shared-product-core/assets/presentation-media-visibility-v1.js'),'Local reusable lazy JetEngine gallery media adapter, viewport-based and read-only'),
    'pricing_registry'=>self::capability(function_exists('pricing_audience_quote'),'pricing_audience_quote'),
    'booking_configuration_v2'=>self::capability(function_exists('shared_product_booking_configuration_v2_normalize') || file_exists(__DIR__.'/../pricing/booking-configuration-v2.php'),'Booking Configuration v2'),
   ],
   // Presentation is orchestration of native sources, not a new engine.
   // Stages are intentionally ordered so an invalid origin, service or native
   // taxonomy cannot create a selectable (but visually empty) transport card.
   'transport_card_visual_contract'=>[
    'source'=>'native_elementor_jetengine_listing',
    'elementor_template_id'=>45152,
    'jetengine_listing_id'=>40299,
    'title_widget_ids'=>['60139ae','295aa1c','dca5c50'],
    'title_element'=>'JetEngine Dynamic Field H3',
    'typography_authority'=>'Elementor global secondary typography and Brand Kit',
    'title_responsive_font_px'=>['small'=>15,'max'=>17],
    'title_color_authority'=>'native_kit',
    'image_source'=>'native JetEngine Gallery and Post Featured Image',
    'gallery_loading'=>'SharedPresentationMediaVisibilityV1@1.1',
    'gallery_aspect_ratio'=>'native Elementor gallery dimensions (no MU override)',
    'native_layout_preserved'=>true,
    'read_only'=>true,
   ],
   'transport_card_feature_contract'=>[
    'source'=>'Original Elementor 40299 + JetEngine dynamic fields and taxonomies',
    'enforcement'=>'regression_guard_not_render_override',
    'road'=>[
      'passengers'=>['field'=>'trans_no_passengers','widget'=>'2da154e'],
      'luggage'=>['field'=>'trans_luggage','widget'=>'d6d746b'],
      'doors'=>['field'=>'trans_no_door','widget'=>'3d0cbc1'],
      'amenities'=>['taxonomy'=>'amenities','query_id'=>244,'listing_id'=>37443,'widget'=>'2293b41'],
      'native_dropbars'=>['379a1ec','4c445b4','9d971fc'],
    ],
    'train'=>[
      'luggage'=>['field'=>'trans_luggage','widget'=>'0c1569c'],
      'line'=>['field'=>'trans_train_line_id','widget'=>'ff1c8fe'],
      'stops'=>['field'=>'trans_train_stops','query_id'=>346,'widget'=>'c3012f0'],
      'amenities'=>['taxonomy'=>'amenities','query_id'=>244,'widget'=>'08d0eb9'],
    ],
    'air'=>[
      'scope'=>['field'=>'trans_flight_scope_calc','widget'=>'5cd3e1f'],
      'luggage'=>['field'=>'trans_luggage','widget'=>'925534f'],
      'amenities'=>['taxonomy'=>'amenities','query_id'=>244,'widget'=>'1c0986f'],
    ],
    'missing_value_policy'=>'Hide only the unsupported property, never the entire mode card',
    'design_authority'=>'Native Elementor styling and Global Brand Kit',
    'qa_data_policy'=>'Isolated fixtures do not represent supplier offers or actual schedules',
    'booking_authorized'=>false,
   ],
   'media_presentation_contract'=>[
    'schema_version'=>'1.1.0',
    'media_provider'=>'jet-engine-gallery-slider',
    'compatibility'=>['JetEngine Swiper','LiteSpeed data-src placeholders'],
    'trigger'=>'IntersectionObserver per image, Swiper DOM additions and JetSmartFilters rerender',
    'root_margin'=>'300px vertical / 120px horizontal',
    'load_policy'=>'visible and adjacent slides only; browser image loading=lazy',
    'trust'=>'same-origin URLs beneath wp-content/uploads',
    'fallback'=>'scroll/resize proximity loading when IntersectionObserver is unavailable',
    'scope'=>'all Local front-end JetEngine galleries, independent of Tour ID',
    'presentation_authority'=>'read_only_no_media_or_order_writes',
   ],
   'transport_presentation_contract'=>[
    'schema_version'=>'1.0.0',
    'denial_policy'=>'fail_closed',
    'environment'=>'local_lab',
    'stages'=>[
      'source_identity'=>'WP main singular Tour or signed same-origin JSF Tour context',
      'native_binding'=>'Elementor Template 45152 / JetEngine Listing 40299 / Query 258-260',
      'service_mode'=>'service_channel_allowed:transportations/tour_component',
      'component_visibility'=>'per-Tour tour_vehicles|train|air_transport_status',
      'relation'=>'Tour meta linked transport IDs; no unlinked broad discovery',
      'published_and_active'=>'transportations post publish and trans_available_status=1',
      'taxonomy'=>'transportation-types parent equals trans_mode and attached trans_type child',
      'rate_identity'=>'selected Tour Rate belongs to current Tour; no arbitrary event IDs',
      'selection_policy'=>'existing BookingExperience per-Rate included/additional/inquiry',
      'price_and_inventory'=>'delegated to existing pricing/availability authority',
      'writes'=>'denied',
    ],
    'native_jsf'=>'provider+listing+query and cryptographic signature with same-origin normalized Referer',
    'scope'=>'display_and_nonfinancial_preference_only',
    'limits'=>['max_related_transports'=>80,'max_related_rates'=>80],
    'cart_authorized'=>false,'supplier_authorized'=>false,
   ],
   'authority'=>[
    'data'=>'JetEngine Query Builder / original relations',
    'price'=>'pricing_registry',
    'availability'=>'existing_booking_engine',
    'supplier_confirmation'=>'forbidden',
    'customer_submissions'=>'forbidden',
   ],
   'slots'=>['tour.rate_list'=>['kind'=>'native_jet_listing','modes'=>['grid','accordion'],
       'binding_source'=>'verified_existing_elementor_template','context_source'=>'validated_wp_tour'],
      'tour.transport.vehicles'=>['kind'=>'native_jet_listing','template_id'=>45152,'listing_id'=>40299,
        'query_id'=>258,'provider_id'=>'jet-engine/tours_related_transportations_vehicles',
        'taxonomy'=>'transportation-types','mode_term_id'=>568,'category'=>'vehicles','read_only'=>true],
      'tour.transport.train'=>['kind'=>'native_jet_listing','template_id'=>45152,'listing_id'=>40299,
        'query_id'=>259,'provider_id'=>'jet-engine/tours_related_transportations_train',
        'taxonomy'=>'transportation-types','mode_term_id'=>578,'category'=>'train','read_only'=>true],
      'tour.transport.air'=>['kind'=>'native_jet_listing','template_id'=>45152,'listing_id'=>40299,
        'query_id'=>260,'provider_id'=>'jet-engine/tours_related_transportations_air',
        'taxonomy'=>'transportation-types','mode_term_id'=>594,'category'=>'air','read_only'=>true],
      'taxonomy.transport.archive'=>['kind'=>'native_jet_listing','source_types'=>['taxonomy_terms','wp_posts'],'taxonomy'=>'transportation-types','template_id'=>40308,'query_id'=>246,'context_source'=>'Shared_Product_Taxonomy_Archive_Presentation_v1','read_only'=>true,'standalone_sale_authorized'=>false],
      'destination.term.related'=>['kind'=>'native_jet_listing','source_types'=>['taxonomy_terms'],'context_source'=>'Shared_Product_Destination_Term_Presentation_v1','template_id'=>45886,'read_only'=>true],
      'destination.term.information'=>['kind'=>'native_elementor_dynamic_tags','template_id'=>45799,'context_source'=>'Shared_Product_Destination_Term_Presentation_v1','read_only'=>true],
      'discovery.search.results'=>['kind'=>'native_jet_listing','source_types'=>['wp_posts','taxonomy_terms'],
       'template_id'=>30861,'context_source'=>'Shared_Product_Discovery_Search_Presentation_v1','read_only'=>true]],
   'adapters'=>[
     'elementor'=>['mode'=>'read_only','role'=>'native_widget_and_brand_kit'],
     'jetengine'=>['mode'=>'read_only','role'=>'native_query_and_relations'],
     'jetsmartfilters'=>['mode'=>'read_only','role'=>'native_provider_and_filter_state'],
     'visual_selection'=>['mode'=>'read_only','role'=>'legacy_event_source_only'],
     'presentation_selection_v1'=>['mode'=>'read_only','role'=>'event_normalization_no_write_authority'],
     'fluent_form_arrival_projection_v1'=>['mode'=>'read_only','role'=>'arrival_date_normalization_no_submission'],
     'transport_presentation_v1'=>['mode'=>'read_only','role'=>'verified_tour_and_rate_to_taxonomy_and_native_listing','selection'=>'preferences_only','commercial_write_authority'=>false],
     'media_visibility_v1'=>['mode'=>'read_only','role'=>'viewport_limited_jetengine_gallery_activation','file_write_authority'=>false],
   ],
   'lifecycle'=>['inspect','validate','bind_readonly'],
  ];
 }
 /** Confirm a real WordPress Tour, not a forged post ID or form submission. */
 public static function context_for_tour(int $tour_id): array {
  if($tour_id<=0 || get_post_type($tour_id)!=='tours-and-activities' || !in_array(get_post_status($tour_id),['publish','private'],true)) {
   return ['ok'=>false,'code'=>'INVALID_TOUR_CONTEXT'];
  }
  $currency=function_exists('pricing_display_currency')?pricing_display_currency():get_woocommerce_currency();
  $currency=strtoupper((string)$currency);
  if(!preg_match('/^[A-Z]{3}$/D',$currency))return ['ok'=>false,'code'=>'INVALID_DISPLAY_CURRENCY'];
  $locale=function_exists('apply_filters')?apply_filters('wpml_current_language',null):null;
  if(!is_string($locale)||$locale==='')$locale=determine_locale();
  return [
   'ok'=>true,'tour_id'=>$tour_id,'post_type'=>'tours-and-activities',
   'currency'=>$currency,'locale'=>sanitize_text_field($locale),
   'authority'=>'identity_verified_only','authorized_for_booking'=>false,
  ];
 }
 /** Validate a slot against the *existing* native Elementor Listing and Jet Query. */
 public static function validate_binding(array $input): array {
  $fields=['slot','mode','template_id','listing_id','query_id','provider_id'];
  if(array_diff($fields,array_keys($input)))return ['ok'=>false,'code'=>'MISSING_BINDING_FIELD'];
  if($input['slot']!=='tour.rate_list')return ['ok'=>false,'code'=>'UNKNOWN_SLOT'];
  if(!in_array($input['mode'],['grid','accordion'],true))return ['ok'=>false,'code'=>'INVALID_DISPLAY_MODE'];
  foreach(['template_id','listing_id','query_id'] as $id){
   if(!is_int($input[$id])||$input[$id]<=0)return ['ok'=>false,'code'=>'INVALID_IDENTIFIER'];
  }
  $provider=($input['mode']==='grid')?'tour_rates':'tour_rates_accordion';
  if($input['provider_id']!==$provider)return ['ok'=>false,'code'=>'PROVIDER_MODE_MISMATCH'];
  if(get_post_type($input['template_id'])!=='elementor_library' || get_post_type($input['listing_id'])!=='jet-engine') {
   return ['ok'=>false,'code'=>'UNKNOWN_ELEMENTOR_TEMPLATE'];
  }
  if(!class_exists('Jet_Engine\\Query_Builder\\Manager'))return ['ok'=>false,'code'=>'QUERY_ENGINE_UNAVAILABLE'];
  $query=Jet_Engine\Query_Builder\Manager::instance()->get_query_by_id((string)$input['query_id']);
  if(!$query)return ['ok'=>false,'code'=>'UNKNOWN_QUERY'];
  $tree=json_decode((string)get_post_meta($input['template_id'],'_elementor_data',true),true);
  if(!is_array($tree))return ['ok'=>false,'code'=>'INVALID_ELEMENTOR_LAYOUT'];
  $matched=false;
  $scan=function(array $nodes) use (&$scan,&$matched,$input,$provider): void {
   foreach($nodes as $node){
    if(!is_array($node))continue;
    $s=(array)($node['settings']??[]);
    if(($node['widgetType']??'')==='jet-listing-grid'
      && (string)($s['_element_id']??'')===$provider
      && (int)($s['lisitng_id']??0)===$input['listing_id']
      && (int)($s['custom_query_id']??0)===$input['query_id'])$matched=true;
    if(!empty($node['elements'])&&is_array($node['elements']))$scan($node['elements']);
   }
  };
  $scan($tree);
  if(!$matched)return ['ok'=>false,'code'=>'BINDING_NOT_PRESENT_IN_NATIVE_TEMPLATE'];
  return ['ok'=>true,'code'=>'NATIVE_BINDING_VERIFIED', 'slot'=>$input['slot'],'provider_id'=>$provider,
   'template_id'=>$input['template_id'],'listing_id'=>$input['listing_id'],'query_id'=>$input['query_id'],
   'scope'=>'read_only'];
 }
}

/** Local-only, administrator-gated diagnostic; no query/body mutation. */
add_action('rest_api_init', static function (): void {
 register_rest_route('shared-product/v1','/presentation-compatibility',[
  'methods'=>WP_REST_Server::READABLE,
  'permission_callback'=>static fn(): bool=>current_user_can('manage_options'),
  'callback'=>static fn()=>rest_ensure_response(Shared_Product_Presentation_Compatibility_v1::manifest()),
 ]);
},20);
