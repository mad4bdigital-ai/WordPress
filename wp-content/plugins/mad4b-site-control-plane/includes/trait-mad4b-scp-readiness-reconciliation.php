<?php
defined( 'ABSPATH' ) || exit;

/**
 * Exact issue-ledger reconciliation with database lock, readback and audit.
 * Bound into the original Content Readiness class; not a new authority lane.
 */
trait MAD4B_SCP_Readiness_Reconciliation_Trait {
    public static function reconcile($scan,$scheduled=false) {
        if ($scheduled) {
            if (!self::trusted_scheduled_cli() || empty(self::rules()['scheduled_scan_enabled']))
                return new WP_Error('mad4b_readiness_scheduler_unauthorized');
        } elseif (!current_user_can('manage_options')) return new WP_Error('mad4b_readiness_permission_denied');
        if (!is_array($scan) || empty($scan['complete']) || !isset($scan['issues']))
            return new WP_Error('mad4b_readiness_incomplete_scan');
        // Serialize full-ledger reconciliation with the bounded WPML URL
        // ContentJob intake. Never overwrite a freshly linked issue using
        // a stale discovery snapshot from another WordPress CLI session.
        global $wpdb;
        $readinessLock='mad4b_wpml_url_jobs_'.get_current_blog_id();
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$readinessLock))!==1)
            return new WP_Error('mad4b_readiness_shared_ledger_lock_busy',
                'A governed ContentJob intake is updating the issue ledger; retry after it completes.');
        try {
        $old=(array)get_option(self::STORE,array());$now=gmdate('c');$new=$old;
        $seen=array();
        foreach ($scan['issues'] as $row) {
            $key=$row['id'];$seen[$key]=true;
            $previous=$new[$key]??array();
            $status=($previous['status']??'')==='resolved'?'open':($previous['status']??'open');
            $new[$key]=array_merge($previous,$row,
                array('status'=>$status,'first_seen'=>$previous['first_seen']??$now,'last_seen'=>$now,
                    // A reopened issue is active again: its old closure timestamp is not current.
                    'resolved_at'=>null,
                    'reopens'=>($previous['status']??'')==='resolved'?($previous['reopens']??0)+1:($previous['reopens']??0)));
            // This is an explicit, deduplicated ContentJob intake REQUEST.
            // The native ContentJob service owns real creation, and requires a
            // verified principal, brand scope, and independently selected market.
            $code=(string)($row['code']??'');
            // Brand review is a ContentJob intake REQUEST, never a direct
            // publish/write grant. Keep exact revisions and the live site brand.
            if (($row['source']??'')==='mad4b-brand-experience' &&
                in_array($code,['brand_listing_message_alignment','brand_experiential_media_authenticity'],true)
                && ($row['post_type']??'')==='tours-and-activities') {
                $e=(array)($row['source_evidence']??[]);
                $brand=(array)($e['brand_scope']??[]);
                $post_id=absint($row['object_id']??0);
                $actual_site=class_exists('MAD4B_SCP_Site_Profile')
                    ? (string)MAD4B_SCP_Site_Profile::site_uuid() : '';
                if($post_id && get_post_status($post_id)==='publish'
                    && $post_id===self::canonical($post_id,'tours-and-activities')
                    && $actual_site!=='' && hash_equals($actual_site,(string)($brand['site_uuid']??''))
                    && preg_match('/^[a-f0-9]{64}$/D',(string)($e['source_sha256']??''))
                    && preg_match('/^[a-f0-9]{64}$/D',(string)($e['brand_evidence_sha256']??''))) {
                    $new[$key]['content_job_request']=[
                        'contract'=>'mad4b.brand-quality-contentjob-intake.v1',
                        'status'=>(!empty($previous['content_job_id'])
                            && ($previous['source_evidence']['source_sha256']??'')===$e['source_sha256']
                            && ($previous['source_evidence']['brand_evidence_sha256']??'')===$e['brand_evidence_sha256'])
                            ?'LINKED':'PRIORITY_INTAKE',
                        'source_issue_id'=>$key,'gap_code'=>$code,
                        'priority'=>$code==='brand_listing_message_alignment'?82:76,
                        'site_uuid'=>$actual_site,'brand_id'=>(string)($brand['brand_id']??''),
                        'brand_revision'=>absint($brand['revision']??0),
                        'brand_evidence_sha256'=>(string)$e['brand_evidence_sha256'],
                        'brand_authority_level'=>(string)($e['authority_level']??'provisional'),
                        'post_type'=>'tours-and-activities','source_post_id'=>$post_id,
                        'canonical_post_id'=>$post_id,
                        'source_sha256'=>(string)$e['source_sha256'],
                        'content_type'=>$code==='brand_listing_message_alignment'?'editorial_review':'media_review',
                        'deduplication_key'=>hash('sha256',$actual_site.'|'.$post_id.'|'.$code.'|'.$e['brand_evidence_sha256'].'|'.$e['source_sha256']),
                        'reason'=>(string)($e['review_guidance']??'Brand-aware source review required'),
                        'requires_governed_content_job_admission'=>true,
                        'requires_current_brand_context'=>true,
                        'publication_authorized'=>false,
                        'media_replacement_authorized'=>false,
                    ];
                }
            }
            if($code==='pickup_guest_location_capture_unverified' && $row['post_type']==='tours-and-activities'){
                $priorKey=hash('sha256',get_current_blog_id().'|tours-and-activities|'.absint($row['object_id']).'|origin_missing');
                if(!empty($old[$priorKey]['content_job_id']) &&
                   ($row['source_evidence']['research_job_id']??'')===$old[$priorKey]['content_job_id']){
                    $new[$key]['content_job_id']=$old[$priorKey]['content_job_id'];
                    $new[$key]['reclassified_from_origin_issue']=$priorKey;
                    $new[$key]['research_evidence_reused']=true;
                }
            }
            // Existing Issue Ledger is the only source of origin research intake.
            // Native ContentJob creation remains governed by its own actor gate.
            if ($code==='origin_missing' && ($row['post_type']??'')==='tours-and-activities') {
                $post_id=absint($row['object_id']??0);
                $canonical_id=self::canonical($post_id,'tours-and-activities');
                $site_uuid=class_exists('MAD4B_SCP_Site_Profile')
                    ? (string) MAD4B_SCP_Site_Profile::site_uuid() : '';
                if ($post_id && $canonical_id && $site_uuid
                    && get_post_status($post_id)==='publish') {
                    $term_ids=wp_get_object_terms($post_id,'destinations',array('fields'=>'ids'));
                    $term_ids=is_wp_error($term_ids) ? array() : array_map('intval',(array)$term_ids);
                    sort($term_ids,SORT_NUMERIC);
                    $fingerprint=hash('sha256',$site_uuid.'|tours-and-activities|'.$canonical_id.'|origin_missing');
                    $source_sha=hash('sha256',wp_json_encode(array(
                        'canonical_id'=>$canonical_id,
                        'origin_term'=>get_post_meta($post_id,'tour_origin_location_term',true),
                        'destination_terms'=>$term_ids,
                    ),JSON_UNESCAPED_SLASHES));
                    $new[$key]['content_job_request']=array(
                        'contract'=>'mad4b.readiness-contentjob-intake.v1',
                        'status'=>!empty($previous['content_job_id']) ? 'LINKED' : 'PRIORITY_INTAKE',
                        'priority'=>85, 'source_issue_id'=>$key,
                        'gap_code'=>'origin_missing','post_type'=>'tours-and-activities',
                        'source_post_id'=>$post_id,'canonical_post_id'=>$canonical_id,
                        'site_uuid'=>$site_uuid,'content_type'=>'research_observation',
                        'source_sha256'=>$source_sha,'deduplication_key'=>$fingerprint,
                        'reason'=>'Resolve the canonical origin source using official evidence; distinguish pickup from cruise boarding, and verify coordinates before any writeback.',
                        'requires_governed_content_job_admission'=>true,
                        'publication_authorized'=>false,'price_inference_authorized'=>false
                    );
                }
            }
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
                $canonical_id=self::canonical($post_id,$type);
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
                && str_starts_with($code,'pricing_')) {
                $type=(string)($row['post_type']??'');
                $post_id=absint($row['object_id']??0);
                $site_uuid=class_exists('MAD4B_SCP_Site_Profile')
                    ? (string)MAD4B_SCP_Site_Profile::site_uuid() : '';
                $canonical=$post_id ? self::canonical($post_id,$type) : 0;
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
            if(($row['source']??'')==='dynamic-filter-seo-bridge'
                && preg_match('/^dfsb_wpml_(term_missing|route_slug|base_slug_unverified|cpt_base_unverified)_([a-z0-9_-]+)_([0-9]+)_([a-z]{2})$/D',$code,$m)) {
                $new[$key]['content_job_request']=array(
                    'contract'=>'mad4b.wpml-term-content-job-request.v1',
                    'status'=>!empty($previous['content_job_id'])?'LINKED':'PRIORITY_INTAKE',
                    'priority'=>max(0,min(100,(int)($row['priority']??30))),
                    'triage'=>(array)($row['triage']??array()),
                    'source_issue_id'=>$key,
                    'content_type'=>in_array($m[1],array('base_slug_unverified','cpt_base_unverified'),true)
                        ? 'wpml_rewrite_base_translation' : 'taxonomy_translation',
                    'subject_kind'=>$m[1]==='cpt_base_unverified' ? 'post_type' : 'taxonomy',
                    'subject_name'=>$m[2],
                    'taxonomy'=>$m[1]==='cpt_base_unverified' ? '' : $m[2],
                    'source_term_id'=>(int)$m[3], 'language'=>$m[4],
                    'reason'=>$m[1], 'deduplication_key'=>$key,
                    'requires_governed_content_job_admission'=>true
                );
            }
            // Media integrity findings are source-backed ContentJob intake
            // requests, not automatically published or blindly rewritten URLs.
            // Dedup key is scoped to exact company, post and metadata digest.
            if(($row['source']??'')==='mad4b-media-origin-integrity'
                && in_array((string)($row['code']??''),array(
                    'media_gallery_destination_review',
                    'media_gallery_shared_across_tours',
                    'media_presentation_images_shared',
                    'media_foreign_upload_origin',
                    'media_local_attachment_missing',
                    'media_local_attachment_ambiguous',
                    'media_duplicate_attachment_records'),true)) {
                $e=(array)($row['source_evidence']??array());
                $site=class_exists('MAD4B_SCP_Site_Profile',false)
                    ?(string)MAD4B_SCP_Site_Profile::site_uuid():'';
                $post_id=(int)($row['object_id']??0);
                if($site!=='' && $post_id>0
                    && hash_equals($site,(string)($e['site_uuid']??''))
                    && preg_match('/^[a-f0-9]{64}$/D',(string)($e['source_sha256']??''))) {
                    $policy=class_exists('MAD4B_SCP_Context_Authority',false)
                        ?MAD4B_SCP_Context_Authority::review_policy():array();
                    $mode=(string)($policy['mode']??'human_only');
                    // WPML siblings belong to one original media family.
                    // Do not create two Content Jobs for identical gallery
                    // content when EN and ES share one tour/gallery.
                    $default_language=(string)apply_filters(
                        'wpml_default_language','en');
                    $canonical=(int)apply_filters('wpml_object_id',
                        $post_id,'tours-and-activities',false,$default_language);
                    if($canonical<=0 || get_post_status($canonical)!=='publish')
                        $canonical=$post_id;
                    $language_details=apply_filters(
                        'wpml_post_language_details',null,$post_id);
                    $source_language=sanitize_key((string)(
                        is_array($language_details)
                        ?($language_details['language_code']??''):''));
                    $shared_gallery_code=in_array((string)$row['code'],array(
                        'media_gallery_shared_across_tours',
                        'media_presentation_images_shared',
                        'media_foreign_upload_origin',
                        'media_duplicate_attachment_records'),true);
                    $snapshot=$shared_gallery_code
                        ?(string)($e['gallery_fingerprint_sha256']??'')
                        :(string)$e['source_sha256'];
                    $dedup=hash('sha256',$site.'|'.$canonical.'|'.
                        $row['code'].'|'.$snapshot);
                    $new[$key]['content_job_request']=array(
                        'contract'=>'mad4b.media-integrity-contentjob-intake.v1',
                        'status'=>(!empty($previous['content_job_id'])
                            &&($previous['source_evidence']['source_sha256']??'')
                              ===$e['source_sha256'])?'LINKED':'PRIORITY_INTAKE',
                        'source_issue_id'=>$key,
                        'gap_code'=>(string)$row['code'],
                        'priority'=>(int)($row['priority']??80),
                        'site_uuid'=>$site,'brand_id'=>(string)($e['brand_id']??''),
                        'post_type'=>'tours-and-activities',
                        'source_post_id'=>$post_id,
                        'canonical_post_id'=>$canonical,
                        'source_language'=>$source_language,
                        'deduplication_scope'=>'canonical_tour_media_family',
                        'source_sha256'=>(string)$e['source_sha256'],
                        'gallery_sha256'=>(string)($e['gallery_fingerprint_sha256']??''),
                        'content_type'=>'media_review',
                        'review_mode_inherited'=>$mode,
                        'deduplication_key'=>$dedup,
                        'recommended_action'=>'compare_actual_photographs_with_tour_and_brand_context_then_select_verified_local_assets',
                        'original_meta_preserved'=>true,
                        'requires_governed_content_job_admission'=>true,
                        'requires_current_brand_context'=>true,
                        'requires_photographic_relevance_review'=>true,
                        'publication_authorized'=>false,
                        'media_replacement_authorized'=>false,
                    );
                }
            }
        }
        // Only close rules evaluated by this scan; removing a configured rule
        // must not silently resolve its pre-existing open issues.
        $rules=self::rules();
        foreach ($new as $key=>$row) {
            if(isset($seen[$key]) || ($row['status']??'')==='resolved')continue;
            if(!self::rule_enabled_for($row,$rules))continue;
            $new[$key]['status']='resolved';$new[$key]['resolved_at']=$now;
        }
        if (count($new)>self::MAX_ISSUES)
            return new WP_Error('mad4b_readiness_capacity_exceeded','Issue registry capacity exceeded. Nothing saved.');
        update_option(self::STORE,$new,false);
        $verified=(array)get_option(self::STORE,array());
        if($verified!==$new)
            return new WP_Error('mad4b_readiness_scan_readback_failed','Issue ledger write was not confirmed.');
        self::audit('scan',['scanned'=>$scan['scanned'],'current'=>count($scan['issues'])]);
        // Only newly confirmed source transitions enter the local event journal.
        // Event automation ingests held outbox rows, never dispatches email here.
        if(class_exists('MAD4B_SCP_Readiness_Integrations'))
            MAD4B_SCP_Readiness_Integrations::record_changes($old,$new);
        return self::summary($new);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$readinessLock));
        }
    }
}
