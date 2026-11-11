<?php
/**
 * MAD4B OTA provider and source profile registry — safe read-only definitions.
 * No network calls, no accounts, no ingestion side effects and no prices written.
 */
defined('ABSPATH') || exit;
if(!class_exists('MAD4B_OTA_Provider_Source_Profiles_V1')){
final class MAD4B_OTA_Provider_Source_Profiles_V1 {
 const CONTRACT='mad4b.ota-provider-source-profiles.v1';
 public static function definitions():array {
  return [
   'airbnb'=>[
    'brand'=>'Airbnb','hosts'=>['airbnb.com','www.airbnb.com'],
    'provider'=>['route'=>'registered_provider','connection'=>'not_configured',
      'transport'=>'authorized_owner_feed_or_partner_agreement',
      'supports'=>['property_rate'],'enabled'=>false],
    'scraping'=>['route'=>'source_research_profile','transport'=>'disabled_public_scrape',
      'enabled'=>false,'requires_explicit_platform_authorization'=>true,
      'allowed_fields'=>['property_description','unit_description','amenities','occupancy'],
      'price_policy'=>'hold','publication'=>'manual_review'],
    'rate_targets'=>['property_rate'=>'property-rates'],
   ],
   'booking_com'=>[
    'brand'=>'Booking.com','hosts'=>['booking.com','www.booking.com'],
    'provider'=>['route'=>'registered_provider','connection'=>'not_configured',
      'transport'=>'connectivity_api_or_authorized_channel_manager',
      'supports'=>['property_rate','unit_rate'],'enabled'=>false],
    'scraping'=>['route'=>'source_research_profile','transport'=>'authorized_capture_only',
      'enabled'=>false,'requires_explicit_platform_authorization'=>true,
      'allowed_fields'=>['property_description','unit_description','amenities','capacity','room_types'],
      'price_policy'=>'hold','publication'=>'manual_review'],
    'rate_targets'=>['property_rate'=>'property-rates','unit_rate'=>'property-rates_with_unit_id'],
   ],
  ];
 }
 public static function resolve(string $provider,string $route):array|WP_Error {
  $defs=self::definitions();$item=$defs[$provider]??null;
  if(!$item)return new WP_Error('ota_profile_provider_unknown');
  if(!in_array($route,['provider','scraping'],true))return new WP_Error('ota_profile_route_invalid');
  return ['contract'=>self::CONTRACT,'provider_id'=>$provider,'brand'=>$item['brand'],
     'route'=>$route,'configuration'=>$item[$route],
     'rate_targets'=>$item['rate_targets'],'network_enabled'=>false,
     'commercial_authority'=>false];
 }
 public static function classify(array $observation):array|WP_Error {
  $keys=['provider_id','route','source_url','property_id','unit_id','rate_kind',
    'authorization_evidence','connection_verified','listing_verified','price','currency',
    'checkin','checkout','occupancy','fees_complete','collected_at'];
  if(array_diff(array_keys($observation),$keys))return new WP_Error('ota_observation_unknown_fields');
  $provider=$observation['provider_id']??null;$route=$observation['route']??null;
  if(!is_string($provider)||!is_string($route))return new WP_Error('ota_observation_identity_invalid');
  $profile=self::resolve($provider,$route);
  if(is_wp_error($profile))return $profile;
  $rateKind=$observation['rate_kind']??null;
  if(!in_array($rateKind,array_keys($profile['rate_targets']),true))
    return new WP_Error('ota_observation_rate_kind_unsupported');
  $property=filter_var($observation['property_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
  if(!$property)return new WP_Error('ota_observation_property_id_invalid');
  $unit=$observation['unit_id']??null;
  if($rateKind==='unit_rate'){
    $unit=filter_var($unit,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if(!$unit)return new WP_Error('ota_observation_unit_id_required');
  }elseif($unit!==null)return new WP_Error('ota_observation_unit_id_unexpected');
  $url=$observation['source_url']??null;
  if(!is_string($url)||!filter_var($url,FILTER_VALIDATE_URL)||parse_url($url,PHP_URL_SCHEME)!=='https')
    return new WP_Error('ota_observation_url_invalid');
  $host=strtolower((string)parse_url($url,PHP_URL_HOST));
  if(!in_array($host,self::definitions()[$provider]['hosts'],true))
    return new WP_Error('ota_observation_host_mismatch');
  $configured=$profile['configuration'];
  // Static registry defaults deny direct collection; a tenant connection must
  // be verified via its own authenticated adapter in a future implementation.
  $verified=!empty($configured['enabled']) && !empty($observation['authorization_evidence'])
    && !empty($observation['connection_verified']) && !empty($observation['listing_verified']);
  $pricePresent=array_key_exists('price',$observation);
  $price=$observation['price']??null;
  if($pricePresent && (!is_numeric($price)||!is_finite((float)$price)||(float)$price<0))
    return new WP_Error('ota_observation_price_invalid');
  return ['contract'=>'mad4b.ota-rate-intake-candidate.v1',
   'provider_id'=>$provider,'route'=>$route,'property_id'=>(int)$property,
   'unit_id'=>$unit===null?null:(int)$unit,'rate_kind'=>$rateKind,
   'target_rate_cpt'=>'property-rates','source_url'=>$url,
   'source_price_observed'=>$pricePresent,'rate_amount'=>null,
   'status'=>$verified?'requires_domain_rate_and_availability_validation':
      ($route==='scraping'?'research_only_no_automated_capture':'connection_required'),
   'provider_connection_verified'=>$verified,
   'direct_scraping_permitted'=>false,'price_authorized'=>false,
   'create_rate_authorized'=>false,'checkout_authority'=>false];
 }
}
}
