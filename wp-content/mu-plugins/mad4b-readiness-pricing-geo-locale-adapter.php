<?php
/**
 * Plugin Name: MAD4B Pricing, Geo and Locale Readiness Intake Adapter
 * Description: Source-owned, bounded read-only ContentJob intake requests.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
add_filter('mad4b/content_readiness/valid_pricing_alert',static function($prior,$row){
    if($prior || !is_array($row))return (bool)$prior;
    $source=(string)($row['source']??'');
    $type=(string)($row['post_type']??'');
    $object_id=(int)($row['object_id']??0);
    $code=(string)($row['code']??'');
                $pricing = $source==='mad4b-pricing-workbench' && (
                    ($type==='site' && $object_id===0 &&
                     in_array($code,['pricing_market_profile_missing','pricing_tax_policy_missing',
                       'pricing_property_rate_missing','pricing_transport_rate_missing'],true))
                    || ($object_id>0 && get_post_type($object_id)===$type &&
                      get_post_status($object_id)==='publish' &&
                      ( ($type==='tours-and-activities' &&
                          in_array($code,['pricing_native_rate_missing','pricing_display_inquiry_conflict'],true))
                        || ($type==='properties' && $code==='pricing_property_unit_missing')
                        || ($type==='transportations' && $code==='pricing_transport_rate_unapproved')
                      ))
                );

    return (bool)$pricing;
},20,2);

add_filter('mad4b/content_readiness/provider_job_intake',static function($current,$row,$previous,$key){
    if(!is_array($current)||!is_array($row)||!is_array($previous) ||
       !is_string($key) || ($current['id']??'')!==$key)return $current;
    $new=array($key=>$current);
    $code=(string)($row['code']??'');
    $resolve_canonical=static function($id,$post_type){
        if(!has_filter('wpml_object_id'))return (int)$id;
        $default=apply_filters('wpml_default_language',null);
        if(!is_string($default)||$default==='')return (int)$id;
        $translated=apply_filters('wpml_object_id',$id,$post_type,false,$default);
        return $translated?(int)$translated:(int)$id;
    };
            // Source-only Geo / transport ContentJob intake requests.
            // Discovery never creates a job, geocodes, changes taxonomy, prices,
            // or dispatches tasks; admission is delegated to the native provider.
            if (in_array($code, array(
                'geo_station_destination_unlinked',
                'geo_station_locality_unverified',
                'geo_station_coordinates_invalid',
                'geo_transport_route_stations_invalid',
                'geo_transport_price_evidence_unverified',
                'geo_transport_rate_expired',
                'geo_cruise_boarding_unverified',
                'geo_cruise_boarding_applicability_unverified',
                'geo_property_pickup_unverified'
            ), true) && !empty($row['object_id'])) {
                $post_id=absint($row['object_id']);
                $type=sanitize_key((string)($row['post_type']??''));
                $canonical_id=$resolve_canonical($post_id,$type);
                $site_uuid=class_exists('MAD4B_SCP_Site_Profile')
                    ? (string)MAD4B_SCP_Site_Profile::site_uuid() : '';
                if ($canonical_id && $site_uuid && get_post_status($post_id)==='publish') {
                    $evidence=(array)($row['source_evidence']??array());
                    $family=str_starts_with($code,'geo_transport_price')
                        || $code==='geo_transport_rate_expired'
                        ? 'transport_pricing_opportunity' : 'geo_source_verification';
                    $fingerprint=hash('sha256',$site_uuid.'|'.$type.'|'.$canonical_id.'|'.$code);
                    $source_sha=hash('sha256',wp_json_encode(array(
                        'post_id'=>$post_id,'canonical_id'=>$canonical_id,
                        'issue_code'=>$code,'evidence'=>$evidence,
                    ),JSON_UNESCAPED_SLASHES));
                    $new[$key]['content_job_request']=array(
                        'contract'=>'mad4b.geo-fact-verification-intake.v1',
                        'status'=>!empty($previous['content_job_id'])?'LINKED':'PRIORITY_INTAKE',
                        'priority'=>$family==='transport_pricing_opportunity'?58:68,
                        'source_issue_id'=>$key,'gap_code'=>$code,
                        'post_type'=>$type,'source_post_id'=>$post_id,
                        'canonical_post_id'=>$canonical_id,'site_uuid'=>$site_uuid,
                        'content_type'=>'research_observation',
                        'task_family'=>$family,'source_sha256'=>$source_sha,
                        'deduplication_key'=>$fingerprint,
                        'requires_governed_content_job_admission'=>true,
                        'independent_source_verification_required'=>true,
                        'external_dispatch_authorized'=>false,
                        'publication_authorized'=>false,
                        'taxonomy_write_authorized'=>false,
                        'price_inference_authorized'=>false,
                        'commercial_booking_authorized'=>false
                    );
                }
            }
            if(($row['source']??'')==='mad4b-locale-experience' &&
               preg_match('/^lexp_(asset_http_error|cross_locale_link|visible_term_parity|filter_visibility_drift|untranslated_ui_candidate)_([a-z]{2})$/D',$code,$m) &&
               class_exists('MAD4B_SCP_Locale_Experience_Alerts') &&
               MAD4B_SCP_Locale_Experience_Alerts::current()) {
                $site_uuid=class_exists('MAD4B_SCP_Site_Profile')
                    ?(string)MAD4B_SCP_Site_Profile::site_uuid():'';
                $e=(array)($row['source_evidence']??array());
                if($site_uuid && !empty($e['observations'])){
                    $new[$key]['priority']=max(0,min(100,(int)($row['priority']??0)));
                    $new[$key]['content_job_request']=array(
                       'contract'=>'mad4b.locale-experience-contentjob-intake.v1',
                       'status'=>!empty($previous['content_job_id'])?'LINKED':'PRIORITY_INTAKE',
                       'priority'=>$new[$key]['priority'],
                       'source_issue_id'=>$key,'site_uuid'=>$site_uuid,
                       'language'=>$m[2],'gap_code'=>$code,
                       'content_type'=>'localization_observation',
                       'task_family'=>in_array($m[1],array('asset_http_error','cross_locale_link'),true)
                          ?'published_experience_repair':'wpml_eligibility_investigation',
                       'source_sha256'=>hash('sha256',wp_json_encode($e,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),
                       'deduplication_key'=>hash('sha256',$site_uuid.'|'.$code),
                       'requires_governed_content_job_admission'=>true,
                       'requires_original_wpml_and_query_evidence'=>true,
                       'auto_translation_authorized'=>false,'auto_publication_authorized'=>false,
                       'external_scraper_dispatch_authorized'=>false
                    );
                }
            }
            // Pricing Workbench: durable, review-only native ContentJob requests.
            // The verified external scanner cannot provide job IDs or authorizations;
            // reconciler owns this intake contract after source validation.
            if (($row['source']??'')==='mad4b-pricing-workbench'
                && str_starts_with($code,'pricing_')
                && apply_filters('mad4b/content_readiness/valid_pricing_alert',false,$row)===true) {
                $type=(string)($row['post_type']??'');
                $post_id=absint($row['object_id']??0);
                $site_uuid=class_exists('MAD4B_SCP_Site_Profile')
                    ? (string)MAD4B_SCP_Site_Profile::site_uuid() : '';
                $canonical=$post_id ? $resolve_canonical($post_id,$type) : 0;
                if ($site_uuid && ($type==='site' || ($canonical
                    && get_post_status($post_id)==='publish'))) {
                    $configuration=in_array($code,array(
                        'pricing_market_profile_missing','pricing_tax_policy_missing'
                    ),true);
                    $subject_type=$configuration?'pricing_configuration_review':'pricing_source_research';
                    $fingerprint=hash('sha256', $site_uuid.'|pricing|'.$type.'|'.
                        ($canonical?:0).'|'.$code);
                    $snapshot=hash('sha256',wp_json_encode(array(
                        'site_uuid'=>$site_uuid,'code'=>$code,'type'=>$type,
                        'canonical_id'=>$canonical,
                        'post_modified_gmt'=>$post_id?get_post_field('post_modified_gmt',$post_id):'',
                        'label'=>(string)($row['label']??''),
                        'tax_option'=>$code==='pricing_tax_policy_missing'
                            ?get_option('woocommerce_calc_taxes'):null,
                    ),JSON_UNESCAPED_SLASHES));
                    $prior=(array)($previous['content_job_request']??array());
                    $linked=!empty($previous['content_job_id']);
                    $state=$linked
                        ? (($prior['source_sha256']??'')!==$snapshot
                            ?'SOURCE_CHANGED_REVIEW':'LINKED')
                        :'PRIORITY_INTAKE';
                    $new[$key]['content_job_request']=array(
                        'contract'=>'mad4b.pricing-source-contentjob-intake.v1',
                        'status'=>$state,'priority'=>($row['severity']??'')==='critical'?90:75,
                        'source_issue_id'=>$key,'gap_code'=>$code,
                        'post_type'=>$type,'source_post_id'=>$post_id,
                        'canonical_post_id'=>$canonical,'site_uuid'=>$site_uuid,
                        'content_type'=>$subject_type,'task_family'=>$subject_type,
                        'source_sha256'=>$snapshot,'deduplication_key'=>$fingerprint,
                        'requires_governed_content_job_admission'=>true,
                        'requires_independent_supplier_evidence'=>!$configuration,
                        'financial_authorization'=>false,'price_inference_authorized'=>false,
                        'publication_authorized'=>false,'checkout_authorized'=>false,
                        'external_dispatch_authorized'=>false
                    );
                }
            }

    return $new[$key];
},20,4);
