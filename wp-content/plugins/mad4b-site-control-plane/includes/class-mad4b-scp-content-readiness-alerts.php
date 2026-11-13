<?php
/**
 * MAD4B Content Readiness & Alerts.
 * Site-neutral issue registry for existing WordPress content owners.
 * No writes on GET or plugin bootstrap; no automatic external notifications.
 */
defined('ABSPATH') || exit;
require_once __DIR__.'/class-mad4b-scp-readiness-workflow-options.php';
require_once __DIR__.'/class-mad4b-scp-content-completeness.php';
require_once __DIR__.'/class-mad4b-scp-geo-quality.php';
require_once __DIR__.'/class-mad4b-scp-locale-experience-alerts.php';
require_once __DIR__.'/class-mad4b-scp-brand-experience-quality.php';
require_once __DIR__.'/class-mad4b-scp-presentation-integrity.php';

final class MAD4B_SCP_Content_Readiness_Alerts {
    const VERSION = '1.0.0';
    const PAGE = 'mad4b-content-readiness-alerts';
    const STORE = 'mad4b_content_readiness_issues_v1';
    const POLICY = 'mad4b_content_readiness_rules_v1';
    const AUDIT = 'mad4b_content_readiness_audit_v1';
    const MAX_POSTS = 3000;
    const MAX_ISSUES = 3000;

    public static function boot() {
        MAD4B_SCP_Admin_Route_Registry::schedule_submenu(array(__CLASS__, 'menu'));
        add_action('admin_post_mad4b_readiness_scan', array(__CLASS__, 'handle_scan'));
        add_action('admin_post_mad4b_readiness_board_sync', array(__CLASS__, 'handle_board_sync'));
        add_action('admin_post_mad4b_readiness_batch_sync', array(__CLASS__, 'handle_batch_sync'));
        add_action('admin_post_mad4b_readiness_save_rules', array(__CLASS__, 'handle_save_rules'));
        add_action('admin_post_mad4b_readiness_export', array(__CLASS__, 'handle_export'));
        add_action('admin_bar_menu', array(__CLASS__, 'admin_bar'), 95);
        add_action('rest_api_init', array(__CLASS__, 'register_rest_routes'));
        add_action('admin_notices', array(__CLASS__, 'admin_notice'));
        MAD4B_SCP_Readiness_Workflow_Options::boot();
        MAD4B_SCP_Content_Completeness::boot();
    }
    public static function rules() {
        $saved = (array) get_option(self::POLICY, array());
        // Defaults are derived from this site's published public CPT registry.
        // No company-specific post type, field name, or domain is hard-coded.
        $default_types = array_values(get_post_types(array('public'=>true, '_builtin'=>false), 'names'));
        $types = array_values(array_unique(array_filter(array_map('sanitize_key',
            (array)(array_key_exists('post_types', $saved) ? $saved['post_types'] : $default_types)))));
        $valid = array();
        foreach ($types as $t) if (post_type_exists($t) && !in_array($t, array('attachment', 'revision'), true)) $valid[]=$t;
        return array(
            'post_types' => array_slice($valid, 0, 24),
            'origin_fields' => self::map($saved['origin_fields'] ?? array()),
            'origin_source_fields' => self::map($saved['origin_source_fields'] ?? array()),
            'origin_source_taxonomies' => self::map($saved['origin_source_taxonomies'] ?? array()),
            'invalid_origin_levels' => array_values(array_filter(array_map('sanitize_key', (array)($saved['invalid_origin_levels'] ?? array())))),
            'location_taxonomies' => self::map($saved['location_taxonomies'] ?? array()),
            'media_fields' => self::map($saved['media_fields'] ?? array()),
            'media_reuse_threshold' => max(0, min(100, absint($saved['media_reuse_threshold'] ?? 0))),
            'owner_fields' => self::map($saved['owner_fields'] ?? array()),
            'end_date_fields' => self::map($saved['end_date_fields'] ?? array()),
            'stop_sale_fields' => self::map($saved['stop_sale_fields'] ?? array()),
            'geo_quality_enabled' => !empty($saved['geo_quality_enabled']),
            'scheduled_scan_enabled' => !empty($saved['scheduled_scan_enabled']),
            'scheduled_scan_interval_minutes' => max(15,min(1440,absint($saved['scheduled_scan_interval_minutes'] ?? 60))),
            'board_id' => absint($saved['board_id'] ?? 0),
            'stage_id' => absint($saved['stage_id'] ?? 0),
            'assignee_id' => absint($saved['assignee_id'] ?? 0),
            'check_wpml' => !isset($saved['check_wpml']) || (bool)$saved['check_wpml'],
            'check_cron' => !isset($saved['check_cron']) || (bool)$saved['check_cron'],
            'form_id' => absint($saved['form_id'] ?? 0),
            'form_unread_days' => max(1, min(90, absint($saved['form_unread_days'] ?? 7))),
            'cron_max_age_seconds' => max(3600, min(604800, absint($saved['cron_max_age_seconds'] ?? 86400))),
        );
    }
    private static function map($value) {
        $result = array();
        foreach ((array)$value as $type=>$key) {
            $type = sanitize_key((string)$type);
            $key = sanitize_key((string)$key);
            if ($type && $key) $result[$type]=$key;
        }
        return $result;
    }
    private static function issue($type, $id, $code, $severity, $label) {
        $fingerprint = hash('sha256', get_current_blog_id() . '|' . $type . '|' . $id . '|' . $code);
        return array('id'=>$fingerprint,'post_type'=>$type,'object_id'=>absint($id),
            'code'=>$code,'severity'=>$severity,'label'=>$label);
    }
    private static function parse_end_date($value) {
        if (is_numeric($value)) return (int)$value;
        if (!is_string($value) || '' === trim($value)) return 0;
        $ts = strtotime($value);
        return false === $ts ? 0 : (int)$ts;
    }
    private static function has_media($post_id, $field) {
        if (has_post_thumbnail($post_id)) return true;
        if (!$field) return false;
        $value = get_post_meta($post_id, $field, true);
        if (is_array($value)) return count(array_filter($value)) > 0;
        return is_scalar($value) && trim((string)$value) !== '' && (string)$value !== '0';
    }
    private static function canonical($id, $post_type) {
        if (!has_filter('wpml_object_id')) return (int)$id;
        $default = apply_filters('wpml_default_language', null);
        if (!is_string($default) || !$default) return (int)$id;
        $translated = apply_filters('wpml_object_id', $id, $post_type, false, $default);
        return $translated ? (int)$translated : (int)$id;
    }
    public static function discover($config = null) {
        $cfg = is_array($config) ? $config : self::rules();
        $issues = array(); $counts=array();$scanned=0;$complete=true;$media_groups=array();
        foreach ($cfg['post_types'] as $type) {
            if (!post_type_exists($type)) continue;
            $ids=get_posts(array('post_type'=>$type,'post_status'=>'publish',
                'posts_per_page'=>self::MAX_POSTS+1,'fields'=>'ids','suppress_filters'=>true,
                'orderby'=>'ID','order'=>'ASC','no_found_rows'=>true));
            $counts[$type]=count($ids);
            if (count($ids)>self::MAX_POSTS) {$complete=false;$ids=array_slice($ids,0,self::MAX_POSTS);}
            foreach ($ids as $id) {
                $id=(int)$id;$scanned++;
                if (!empty($cfg['check_wpml']) && defined('ICL_SITEPRESS_VERSION')) {
                    $details=apply_filters('wpml_post_language_details',null,$id);
                    if (!is_array($details) || empty($details['language_code']))
                        $issues[]=self::issue($type,$id,'wpml_language_unregistered','high','Missing WPML source-language registration; confirm source and translation family before repair');
                }
                $canonical=self::canonical($id,$type);
                if ($canonical !== $id) continue; // one authoritative content-readiness issue per translation family
                $origin=$cfg['origin_fields'][$type]??'';
                $originIsMissing=$origin && !get_post_meta($id,$origin,true);
                $sourceField=$cfg['origin_source_fields'][$type]??'';
                $sourceTax=$cfg['origin_source_taxonomies'][$type]??'';
                $invalidOrigin=false;
                // The source term is an input, not an instruction to copy a value
                // into translations. Validate its actual taxonomy and level.
                if ($sourceField && $sourceTax && taxonomy_exists($sourceTax)) {
                    $rawSource=get_post_meta($id,$sourceField,true);
                    $term_id=absint($rawSource);
                    if ($rawSource !== '' && $rawSource !== null && !$term_id) {
                        $invalidOrigin=true;
                        $issues[]=self::issue($type,$id,'origin_source_invalid_reference','high',
                            'Source origin does not resolve to a taxonomy term; review the original record');
                    } elseif ($term_id) {
                        $original=apply_filters('wpml_default_language',null);
                        if ($original && has_filter('wpml_object_id')) {
                            $mapped=apply_filters('wpml_object_id',$term_id,$sourceTax,false,$original);
                            if ($mapped) $term_id=absint($mapped);
                        }
                        $term=get_term($term_id,$sourceTax);
                        if (!($term instanceof WP_Term) || is_wp_error($term)) {
                            $invalidOrigin=true;
                            $issues[]=self::issue($type,$id,'origin_source_invalid_reference','high',
                                'Source origin term is missing from the configured taxonomy');
                        } else {
                            $level=sanitize_key((string)get_term_meta($term_id,'destination_type',true));
                            if ($level && in_array($level,(array)($cfg['invalid_origin_levels']??array()),true)) {
                                $invalidOrigin=true;
                                $issue=self::issue($type,$id,'origin_source_too_broad','high',
                                    'The origin source is too broad for a pickup: '.sanitize_text_field($term->name).' ('. $level .')');
                                $issue['source_evidence']=array('taxonomy'=>$sourceTax,'term_id'=>$term_id,'level'=>$level);
                                $issues[]=$issue;
                            }
                        }
                    }
                }
                // One root cause first: a continent cannot be used to fill the
                // absent pickup address, so do not create two tasks for it.
                if ($originIsMissing && !$invalidOrigin) {
                    $variablePickup=self::source_backed_hotel_pickup($id,$type);
                    if ($variablePickup) {
                        $issue=self::issue($type,$id,'pickup_guest_location_capture_unverified','medium',
                            'Published tour has variable hotel pickup. Validate guest hotel/location collection in the actual booking flow; a fixed departure point does not apply.');
                        $issue['source_evidence']=$variablePickup;
                        $issues[]=$issue;
                    } else {
                        $issues[]=self::issue($type,$id,'origin_missing','medium',
                            'Origin and approved pickup source must be reviewed; do not infer location from title');
                    }
                }
                $taxonomy=$cfg['location_taxonomies'][$type]??'';
                if ($taxonomy && taxonomy_exists($taxonomy)) {
                    $terms=wp_get_object_terms($id,$taxonomy,array('fields'=>'ids'));
                    if (is_wp_error($terms) || !$terms)
                        $issues[]=self::issue($type,$id,'location_unlinked','medium','Source taxonomy has no terms');
                }
                $media=$cfg['media_fields'][$type]??'';
                if ($media && !self::has_media($id,$media))
                    $issues[]=self::issue($type,$id,'media_source_unverified','medium','Missing approved media in source field '.$media.'; review the media source and destination relevance');
                if ($media && !empty($cfg['media_reuse_threshold']) && $cfg['media_reuse_threshold']>=2) {
                    $raw=get_post_meta($id,$media,true);
                    if (is_array($raw) && count($raw)>0) {
                        $signature=hash('sha256',wp_json_encode($raw));
                        $media_groups[$type.'|'.$signature][]=$id;
                    }
                }
                $owner=$cfg['owner_fields'][$type]??'';
                if ($owner) {
                    $linked=absint(get_post_meta($id,$owner,true));
                    if (!$linked || !get_post($linked))
                        $issues[]=self::issue($type,$id,'owner_reference_missing','high','Required source owner relation is absent or invalid');
                }
                $end=$cfg['end_date_fields'][$type]??'';
                if ($end) {
                    $time=self::parse_end_date(get_post_meta($id,$end,true));
                    $stop=$cfg['stop_sale_fields'][$type]??'';
                    if (!$time) {
                        $issues[]=self::issue($type,$id,'rate_end_date_missing','high',
                            'Rate has no valid end date; review the authoritative pricing policy');
                    } elseif ($time < time()) {
                        $safe=$stop && (string)get_post_meta($id,$stop,true)==='1';
                        $issues[]=self::issue($type,$id,
                            $safe?'expired_rate_renewal_required':'expired_rate_sale_not_blocked',
                            $safe?'medium':'critical',
                            $safe?'Expired source rate requires commercial renewal':'Expired source rate has not been stopped for sale');
                    }
                }
            }
        }
        if (!empty($cfg['check_cron'])) {
            $cron=get_option('cron',array());$old=0;
            foreach ((array)$cron as $timestamp=>$hooks) {
                if (!is_numeric($timestamp) || time()-(int)$timestamp<$cfg['cron_max_age_seconds']) continue;
                foreach ((array)$hooks as $hook=>$events) $old += count((array)$events);
            }
            if ($old) $issues[]=self::issue('site',0,'cron_overdue','critical',
                'WordPress cron events overdue: ' . $old);
        }
        if (!empty($cfg['media_reuse_threshold']) && $cfg['media_reuse_threshold']>=2) {
            foreach ($media_groups as $group) {
                if (count($group)<$cfg['media_reuse_threshold'])continue;
                foreach ($group as $shared_id) {
                    $issues[]=self::issue(get_post_type($shared_id),$shared_id,
                        'media_source_shared_across_records','medium',
                        'The same media gallery is reused across multiple content records; verify destination relevance and media provenance');
                }
            }
        }
        $form_id=absint($cfg['form_id'] ?? 0);
        if ($form_id) {
            global $wpdb;
            $table=$wpdb->prefix.'fluentform_submissions';
            $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table));
            if ($exists===$table) {
                $days=max(1,min(90,absint($cfg['form_unread_days']??7)));
                $before=gmdate('Y-m-d H:i:s',time()-$days*86400);
                $unread=(int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$table}` WHERE form_id=%d AND status=%s AND created_at<%s",
                    $form_id,'unread',$before));
                if($unread) $issues[]=self::issue('site',0,'form_submissions_unread','high',
                    'Fluent Forms pending follow-up older than '.$days.' days: '.$unread);
            }
        }
        // Preserve issues from separately owned read-only source scanners.
        // Incomplete/malformed input never reaches the reconciler.
        $external=apply_filters('mad4b/content_readiness/external_issues',array(),$cfg);
        if(!is_array($external))$complete=false;
        else {
            $externalIds=array();
            foreach ($external as $row) {
                // The known DFSB provider owns these records. It cannot
                // impersonate another issue, set task IDs, or pre-resolve
                // the issue through untrusted filter output.
                $code=is_array($row)?(string)($row['code']??''):'';
                $id=is_array($row)?(string)($row['id']??''):'';
                $type=is_array($row)?(string)($row['post_type']??''):'';
                $object_id=is_array($row)?absint($row['object_id']??0):0;
                $source=is_array($row)?(string)($row['source']??''):'';
                $expected=hash('sha256',get_current_blog_id().'|'.$type.'|'.$object_id.'|'.$code);
                $dfsb=preg_match('/^dfsb_[a-z0-9_-]+$/D',$code) &&
                    $source==='dynamic-filter-seo-bridge' && $type==='site' && $object_id===0;
                $localization=preg_match('/^loc_(?:layout|text|missing|h1|brand|binding|style)_(?:ar|de|en|es|fr|it)$/D',$code) &&
                    $source==='mad4b-elementor-localization' &&
                    $object_id>0 && post_type_exists($type) &&
                    get_post_type($object_id)===$type && get_post_status($object_id)==='publish' &&
                    ( ((string)(apply_filters('wpml_post_language_details',null,$object_id)['language_code']??'')) ===
                        (string)apply_filters('wpml_default_language',null)
                      || (str_starts_with($code,'loc_brand_') &&
                          ((string)(apply_filters('wpml_post_language_details',null,$object_id)['language_code']??''))===substr($code,-2) &&
                          !apply_filters('wpml_object_id',$object_id,$type,false,(string)apply_filters('wpml_default_language',null))) );
                $performance=preg_match('/^perf_(?:(?:rbcss|lcpdelay|fontcss|inlinecss)_[a-f0-9]{12}|ttfb_site|lcpdiscover_home|ratecss_product|ratecss_source_missing|empty_accommodation_ui|mobile_toc_spacing|rate_origin_ui|rate_schedule_default|toc_bottom_gap|tour_h2_global|rate_locale_parity|rate_schema_drift|rate_origin_config|rate_orphan|catalog_initial_layout|catalog_svg_price_first_paint|catalog_template_drift|chosen_crawlability|ard_manifest|header_escape_text|forced_reflow_home|lcp_request_wait|swiper_focus_[a-f0-9]{12}|probe_stale)$/D',$code) &&
                    $source==='mad4b-live-performance' && $type==='site' && $object_id===0;
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
                // The MAD4B Policy Intelligence provider owns only these
                // bounded native Rate-selection gaps for published Tours.
                // Source tour publishing is an advisory-only, first-party
                // Content Readiness provider. Validate its own local tour
                // marker and the exact bounded issue-code/severity contract.
                // It cannot grant publishing authority or change statuses.
                $companyTourPublication=$source==='mad4b-company-tour-publication'
                    && $type==='tours-and-activities' && $object_id>0
                    && get_post_type($object_id)===$type
                    && in_array(get_post_status($object_id),['publish','draft'],true)
                    && (string)get_post_meta($object_id,
                        '_mad4b_source_content_loop_key_v1',true)!==''
                    && class_exists('MAD4B_SCP_Site_Profile',false)
                    && MAD4B_SCP_Site_Profile::site_uuid()!==''
                    && (
                       ($code==='brand_core_source_publish_blocked'
                           && ($row['severity']??'')==='high')
                       || ($code==='tour_rank_math_seo_pending'
                           && ($row['severity']??'')==='high')
                       || ($code==='tour_supplier_rate_evidence_pending'
                           && ($row['severity']??'')==='medium')
                       || (preg_match('/^tour_wpml_seo_(?:ar|de|es|fr|it)$/D',$code)
                           && ($row['severity']??'')==='medium')
                    );
                $companyPolicy = $source==='mad4b-policy-intelligence' &&
                    in_array($type,['tours-and-activities','properties'],true) && $object_id>0 &&
                    get_post_type($object_id)===$type &&
                    get_post_status($object_id)==='publish' &&
                    in_array($code,[
                        'policy_rate_no_valid','policy_rate_ambiguous',
                        'policy_rate_limit','policy_rate_provider_missing',
                        'policy_rate_currency','policy_hotel_no_valid',
                        'policy_hotel_ambiguous','policy_hotel_currency'
                    ],true);
                // Preserve scoring only for the exact DFSB WPML gap contract.
                // Other external feeds cannot supply priority/triage metadata.
                $wpmlGap = $dfsb && preg_match(
                    '/^dfsb_wpml_(?:term_missing|route_slug|base_slug_unverified|cpt_base_unverified)_[a-z0-9_-]+_[0-9]+_[a-z]{2}$/D',
                    $code
                );
                $wpmlPriorityValid = !$wpmlGap || (
                    is_int($row['priority']??null) &&
                    $row['priority']>=0 && $row['priority']<=100 &&
                    is_array($row['triage']??null) &&
                    in_array($row['triage']['tier']??'',array(
                        'visitor_facing_published_term','destination_not_public',
                        'active_service_published_taxonomy','active_service_frontend_cpt',
                        'internal_or_unproven_cpt','candidate','term_unavailable',
                        'term_count_unavailable','unregistered_subject'
                    ),true) &&
                    (int)($row['triage']['priority']??-1)===$row['priority']
                );
                $structural = preg_match('/^loc_ui_(?:orphan_grid|empty_card|duplicate_terms)_(?:ar|de|en|es|fr|it)_[a-f0-9]{10}$/D',$code) &&
                    $source==='mad4b-locale-structural-health' &&
                    $type==='elementor_library' && $object_id>0 &&
                    get_post_type($object_id)==='elementor_library' &&
                    get_post_status($object_id)==='publish' &&
                    (string)(apply_filters('wpml_post_language_details',null,$object_id)['language_code']??'')===
                      (string)apply_filters('wpml_default_language',null);
                $ratePlan=$source==='mad4b-rate-planner'&&$type==='tours-and-activities'&&$object_id>0&&get_post_status($object_id)==='publish'&&preg_match('/^rate_plan_[a-z0-9_]+_[a-f0-9]{12}$/D',$code);
                // Read-only gallery quality advisory. Exact provider, post
                // scope and severity; does not modify or suppress media.
                $gallery=$source==='mad4b-tour-gallery-diagnostics' &&
                    $code==='media_gallery_reused_across_tours' &&
                    $type==='tours-and-activities' && $object_id>0 &&
                    get_post_type($object_id)===$type &&
                    get_post_status($object_id)==='publish' &&
                    ($row['severity']??'')==='medium';
                $gttd=$source==='mad4b-google-ttd-pricing' &&
                    $code==='gttd_requirements_unverified' &&
                    $type==='tours-and-activities' && $object_id>0 &&
                    get_post_type($object_id)===$type && get_post_status($object_id)==='publish' &&
                    ($row['severity']??'')==='high' &&
                    is_array($row['source_evidence']??null) &&
                    ($row['source_evidence']['contract']??'')==='mad4b.gttd-readiness-checklist.v1' &&
                    is_array($row['source_evidence']['checklist']??null) &&
                    count($row['source_evidence']['checklist'])===5;
                // First-party taxonomy FAQ source, bounded to advisory-only site issues.
                // Does not authorize publication or inject Content Jobs directly.
                $faqGap=$source==='mad4b-taxonomy-faq' && $type==='site' && $object_id===0 &&
                    preg_match('/^faq_term_missing_(?:destinations|tour-types|tour-styles|season|good-for|duration-options|attractions-types|tour_categories)_[1-9][0-9]{0,8}$/D',$code) &&
                    ($row['severity']??'')==='medium';
                // Bound homepage copy alerts to the site's published source page
                // and fixed language/area/priority pair. No external task IDs or writes.
                $homeCopy=$source==='mad4b-home-translation' &&
                    $type==='page' && $object_id===(int)get_option('page_on_front') &&
                    $object_id>0 && get_post_status($object_id)==='publish' &&
                    preg_match('/^home_copy_(?:hero|seo)_(?:es|fr|de|ar|it)$/D',$code) &&
                    ($row['severity']??'')==='high' &&
                    is_int($row['priority']??null) &&
                    $row['priority']===(str_starts_with($code,'home_copy_hero_')?100:90);
                // Exact first-party Elementor Copy Coverage advisory. Never
                // accepts arbitrary provider IDs, owner/job/status overrides.
                $elementorCopy=$source==='mad4b-elementor-copy-coverage' &&
                    $object_id>0 && $type===get_post_type($object_id) &&
                    get_post_status($object_id)==='publish' &&
                    preg_match('/^elm_copy_(?:hero_static_locale|catalog_fallback_en|same_as_source|filter_week_typo)_(?:en|es|fr|de|it|ar)_[a-f0-9]{12}$/D',$code,$copyMatch) &&
                    ($row['severity']??'')===
                        ((int)($row['priority']??0)>=95?'high':'medium') &&
                    is_int($row['priority']??null) &&
                    $row['priority']===(
                        str_contains($code,'hero_static_locale_')?100:
                        (str_contains($code,'catalog_fallback_en_')?95:
                        (str_contains($code,'same_as_source_')?78:75)));
                // MAD4B Media Origin Integrity is a read-only provider.
                // Accept only the exact source, fixed issue codes and current
                // company/post/sha evidence; never accept external mutations.
                $mediaOriginCodes=array(
                    'media_foreign_upload_origin'=>'high',
                    'media_local_attachment_missing'=>'high',
                    'media_local_attachment_ambiguous'=>'medium',
                    'media_duplicate_attachment_records'=>'medium',
                    'media_gallery_shared_across_tours'=>'medium',
                    'media_presentation_images_shared'=>'low',
                    'media_gallery_destination_review'=>'high',
                    'media_reference_malformed'=>'medium',
                );
                $moEvidence=is_array($row['source_evidence']??null)
                    ?$row['source_evidence']:array();
                $mediaOrigin=$source==='mad4b-media-origin-integrity'
                    && isset($mediaOriginCodes[$code])
                    && ($row['severity']??'')===$mediaOriginCodes[$code]
                    && $type==='tours-and-activities' && $object_id>0
                    && get_post_type($object_id)===$type
                    && get_post_status($object_id)==='publish'
                    && class_exists('MAD4B_Media_Origin_Integrity_V1',false)
                    && ($moEvidence['contract']??'')==='mad4b.media-origin-integrity.v1'
                    && ($moEvidence['post_type']??'')===$type
                    && (int)($moEvidence['post_id']??0)===$object_id
                    && (string)($moEvidence['site_uuid']??'')===(string)
                        MAD4B_SCP_Site_Profile::site_uuid()
                    && preg_match('/^[a-f0-9]{64}$/D',
                        (string)($moEvidence['source_sha256']??''))
                    && isset($moEvidence['publication_authorized'])
                    && $moEvidence['publication_authorized']===false
                    && isset($moEvidence['media_replacement_authorized'])
                    && $moEvidence['media_replacement_authorized']===false;
                // Site-bound exact tour photo quality alerts: no arbitrary
                // provider code, no third-party mutations and no writes on GET.
                $tourPhotoSeverity=[
                    'media_gallery_mass_reuse_requires_reselection'=>'high',
                    'media_synthetic_source_prohibited'=>'high',
                    'media_hero_original_duplicate'=>'high',
                    'media_gallery_original_duplicate'=>'high',
                    'media_hero_slot_empty'=>'high',
                    'media_hero_source_unresolved'=>'high',
                    'media_gallery_unresolved'=>'high',
                    'media_featured_original_unresolved'=>'high',
                    'media_gallery_original_unverified'=>'high',
                    'media_gallery_missing'=>'medium',
                    'media_story_coverage_gap'=>'medium',
                    'media_itinerary_geography_conflict'=>'high',
                    'media_near_duplicate_review'=>'medium',
                    'media_origin_provenance_unverified'=>'low',
                ];
                $photoEvidence=is_array($row['source_evidence']??null)?$row['source_evidence']:[];
                $tourPhotoQuality=$source==='mad4b-tour-photo-quality'
                    && isset($tourPhotoSeverity[$code])
                    && ($row['severity']??'')===$tourPhotoSeverity[$code]
                    && $type==='tours-and-activities' && $object_id>0
                    && get_post_type($object_id)===$type && get_post_status($object_id)==='publish'
                    && class_exists('MAD4B_Tour_Photo_Quality_V1',false)
                    && ($photoEvidence['contract']??'')===MAD4B_Tour_Photo_Quality_V1::CONTRACT
                    && (int)($photoEvidence['post_id']??0)===$object_id
                    && (string)($photoEvidence['site_uuid']??'')===(string)MAD4B_SCP_Site_Profile::site_uuid()
                    && hash_equals((string)($photoEvidence['state_sha256']??''),
                        MAD4B_Tour_Photo_Quality_V1::state_sha($object_id))
                    && (int)($photoEvidence['events_count']??0)>0;
                // MAD4B Story Observability V2 is a read-only scanner. Accept
                // only site-local persisted alerts, a valid snapshot contract,
                // current provider identity and exact site/record evidence.
                $storyEvidence=is_array($row['source_evidence']??null)?$row['source_evidence']:[];
                $storyMonitor=$source==='mad4b-story-observability'
                    && $type==='tours-and-activities' && $object_id>0
                    && get_post_type($object_id)===$type && get_post_status($object_id)==='publish'
                    && class_exists('MAD4B_Story_Observability_V2',false)
                    && MAD4B_Story_Observability_V2::accepts_issue($row)
                    && ($storyEvidence['contract']??'')===MAD4B_Story_Observability_V2::CONTRACT
                    && ($storyEvidence['site_uuid']??'')===(string)MAD4B_SCP_Site_Profile::site_uuid()
                    && (int)($storyEvidence['post_id']??0)===$object_id
                    && preg_match('/^[a-f0-9]{64}$/D',(string)($storyEvidence['input_sha256']??''));
                // First-party Nile Cruise inheritance evidence: bounded to the
                // exact Tour, code/severity contract and fresh site-local SHA.
                // Advisory only; cannot create taxonomy links or authorize publish.
                $nileInheritance=$source==='mad4b-nile-intelligent-inheritance'
                    && $type==='tours-and-activities' && $object_id>0
                    && get_post_type($object_id)===$type
                    && get_post_status($object_id)==='publish'
                    && class_exists('MAD4B_Nile_Intelligent_Inheritance_V1',false)
                    && MAD4B_Nile_Intelligent_Inheritance_V1::accepts_issue($row);
                $valid=$wpmlPriorityValid && is_array($row) && ($dfsb||$localization||$performance||$pricing||$structural||$ratePlan||$gallery||$gttd||$companyPolicy||$companyTourPublication||$faqGap||$homeCopy||$elementorCopy||$mediaOrigin||$tourPhotoQuality||$storyMonitor||$nileInheritance) &&
                    hash_equals($expected,$id) &&
                    in_array($row['severity']??'',array('critical','high','medium','low'),true) &&
                    is_string($row['label']??null) &&
                    trim($row['label'])!=='' && strlen($row['label'])<=240 &&
                    !isset($row['status']) && !isset($row['board_task_id']) &&
                    !isset($externalIds[$id]);
                if (!$valid) {$complete=false;break;}
                $externalIds[$id]=true;
                $accepted=array(
                    'id'=>$id,'post_type'=>$type,'object_id'=>$object_id,'code'=>$code,
                    'severity'=>$row['severity'],'label'=>sanitize_text_field($row['label']),
                    'source'=>$source
                );
                if ($homeCopy) $accepted['priority']=$row['priority'];
                if ($elementorCopy) $accepted['priority']=$row['priority'];
                if ($wpmlGap) {
                    $accepted['priority']=$row['priority'];
                    $triage=$row['triage'];
                    // Bounded read-only evidence, not authorization or posting instructions.
                    $accepted['triage']=array(
                        'tier'=>sanitize_key($triage['tier']),
                        'service_active'=>(bool)($triage['service_active']??false),
                        'frontend_visible'=>(bool)($triage['frontend_visible']??false),
                        'source_published_term_count'=>absint($triage['source_published_term_count']??0),
                        'published_posts'=>absint($triage['published_posts']??0),
                        'destination_is_public'=>sanitize_text_field((string)($triage['destination_is_public']??''))
                    );
                }
                if($storyMonitor){
                    $issues[count($issues)-1]['source_evidence']=[
                        'contract'=>MAD4B_Story_Observability_V2::CONTRACT,
                        'site_uuid'=>$storyEvidence['site_uuid'],
                        'post_id'=>$object_id,
                        'input_sha256'=>$storyEvidence['input_sha256'],
                        'run_id'=>substr(sanitize_text_field((string)($storyEvidence['run_id']??'')),0,64),
                        'summary'=>array_intersect_key((array)($storyEvidence['summary']??[]),array_flip([
                            'missing_source_locale','available_locales','published_family_count',
                            'locale','duplicates','canonical_photo_count','divergent_languages',
                            'unmapped_visit_days','items','review',
                            'javascript_versioned_present','stylesheet_present']))
                    ];
                    $issues[count($issues)-1]['priority']=$row['severity']==='high'?96:72;
                }
                if($tourPhotoQuality){
                    $issues[count($issues)-1]['source_evidence']=[
                        'contract'=>MAD4B_Tour_Photo_Quality_V1::CONTRACT,
                        'site_uuid'=>$photoEvidence['site_uuid'],
                        'post_id'=>$object_id,
                        'state_sha256'=>$photoEvidence['state_sha256'],
                        'events_count'=>(int)$photoEvidence['events_count'],
                        'cross_tour_photo_reuse_allowed'=>true];
                    $issues[count($issues)-1]['priority']=$code==='media_synthetic_source_prohibited'?100:
                        ($code==='media_hero_original_duplicate'?96:
                        ($code==='media_gallery_original_duplicate'?95:60));
                }
                if($mediaOrigin){
                    $keep=array(
                        'contract','site_uuid','brand_id','post_type','post_id',
                        'source_sha256','gallery_fingerprint_sha256','fields',
                        'source_hosts','foreign_reference_count','unique_foreign_paths',
                        'local_unique_attachment_paths','missing_local_paths',
                        'ambiguous_local_paths','equivalent_attachment_record_surplus',
                        'shared_by_tour_families','shared_visual_fields',
                        'shared_presentation_field_count','title_destination_signals',
                        'filename_destination_signals','labeled_gallery_paths',
                        'filename_signal_disjoint_paths','destination_review_candidate',
                        'location_evidence_is_filename_only','total_references',
                        'local_match_is_not_editorial_approval',
                        'provenance_unverified','remote_content_not_fetched',
                        'publication_authorized','media_replacement_authorized',
                    );
                    $accepted['source_evidence']=
                        array_intersect_key($moEvidence,array_flip($keep));
                    $accepted['priority']=
                        $code==='media_gallery_destination_review'?94:
                        ($code==='media_gallery_shared_across_tours'?95:
                        ($code==='media_presentation_images_shared'?95:
                        ($code==='media_local_attachment_missing'?92:
                        ($code==='media_foreign_upload_origin'?89:62))));
                }
                if($gttd){
                    $clean=[];
                    foreach(['price','fees_and_taxes','occupancy','availability','checkout_match'] as $name) {
                        $item=$row['source_evidence']['checklist'][$name]??null;
                        if(!is_array($item)||!in_array($item['status']??null,['passed','action_required'],true)){
                            $complete=false;break;
                        }
                        $clean[$name]=['status'=>$item['status'],
                            'action'=>sanitize_text_field((string)($item['action']??''))];
                    }
                    if (!$complete || count($clean)!==5)break;
                    $evidence=[
                        'contract'=>'mad4b.gttd-readiness-checklist.v1',
                        'checklist'=>$clean,
                        'missing'=>array_keys(array_filter($clean,static fn($x)=>$x['status']==='action_required')),
                        'price_state'=>sanitize_key((string)($row['source_evidence']['price_state']??'')),
                    ];
                    $accepted['source_evidence']=$evidence;
                }
                $issues[]=$accepted;
            }
        }
        if (class_exists('MAD4B_Listing_Media_Diagnostics', false))
            $issues=array_merge($issues,MAD4B_Listing_Media_Diagnostics::discover());
        $completeness=MAD4B_SCP_Content_Completeness::discover();
        if(empty($completeness['complete']))$complete=false;
        else $issues=array_merge($issues,(array)$completeness['issues']);
        // Browser-observed locale parity uses public, fresh, reviewed evidence.
        // Zero full-page requests on GET or bootstrap; no automatic translation.
        $localeAudit=MAD4B_SCP_Locale_Experience_Alerts::discover();
        if(empty($localeAudit['complete']))$complete=false;
        else $issues=array_merge($issues,(array)$localeAudit['issues']);
        // First-party, source-only geo/transport candidates; existing Ledger owns reconciliation.
        $geo=MAD4B_SCP_Geo_Quality::discover($cfg);
        if(empty($geo['complete']))$complete=false;
        else $issues=array_merge($issues,(array)$geo['issues']);
        // First-party brand-context and visual-authenticity quality candidates.
        // Read-only and exact source-scoped. No inference of AI image origin.
        $experience=MAD4B_SCP_Brand_Experience_Quality::discover($cfg);
        if(empty($experience['complete']))$complete=false;
        else $issues=array_merge($issues,(array)$experience['issues']);
        // First-party, bounded and source-only Elementor/JetEngine presentation
        // contract audit. No browser, no CSS mutation, no front-end rendering.
        $presentation=MAD4B_SCP_Presentation_Integrity_V1::discover();
        if(empty($presentation['complete'])) $complete=false;
        else $issues=array_merge($issues,(array)$presentation['issues']);
        if(count($issues)>self::MAX_ISSUES)$complete=false;
        return array('contract'=>'mad4b.content-readiness.discovery.v1','complete'=>$complete,
            'post_counts'=>$counts,'scanned'=>$scanned,'issues'=>$issues,
            'completeness_evaluated'=>absint($completeness['evaluated']??0),
            'completeness_skipped_unknown'=>absint($completeness['skipped_unknown']??0));
    }
    /**
     * First-party source determines whether a FIXED origin is applicable.
     * Does not certify the guest-facing booking location collection.
     * Reject stale/incomplete evidence, negated pickup claims, and wrong tenants.
     */
    private static function source_backed_hotel_pickup($id,$type) {
        if($type!=='tours-and-activities'||get_post_status($id)!=='publish'||
           !class_exists('MAD4B_SCP_Research_Groups')||
           !class_exists('MAD4B_SCP_Site_Profile'))return false;
        $highlight=get_post_meta($id,'highlight_rows',true);
        if(!is_string($highlight)||strlen($highlight)>60000)return false;
        $text=trim(wp_strip_all_tags($highlight));
        if(!preg_match('/\bhotel\s+pick[ -]?up\b/i',$text) ||
           preg_match('/\b(?:no|not|without|excluded|not included)\b.{0,36}\bhotel\s+pick[ -]?up\b/i',$text))
           return false;
        $site=(string)MAD4B_SCP_Site_Profile::site_uuid();
        $canonical=self::canonical($id,$type);
        global $wpdb;
        $group=$wpdb->prefix.'mad4b_research_groups';
        $record=$wpdb->prefix.'mad4b_research_records';
        $raw=$wpdb->get_var($wpdb->prepare(
            "SELECT r.data_json FROM {$record} r INNER JOIN {$group} g ON g.id=r.group_id
             WHERE g.task_key=%s AND g.site_uuid=%s AND r.provider_id=%s
             ORDER BY r.id DESC LIMIT 1",
            'origin:tour:'.$canonical,$site,'native_wp_source'));
        $e=is_string($raw)?json_decode($raw,true):null;
        if(!is_array($e)||($e['contract']??'')!=='mad4b.origin-evidence-workflow.v1'||
           (int)($e['post_id']??0)!==$id ||
           ($e['site_uuid']??'')!==$site ||
           ($e['classification']??'')!=='variable_hotel_pickup' ||
           ($e['exact_pickup_verified']??true)!==false)
            return false;
        $terms=wp_get_object_terms($id,'destinations',['fields'=>'ids']);
        if(is_wp_error($terms))return false;
        $terms=array_map('intval',(array)$terms);sort($terms,SORT_NUMERIC);
        $source=hash('sha256',wp_json_encode([
            'canonical_id'=>$canonical,
            'origin_term'=>get_post_meta($id,'tour_origin_location_term',true),
            'destination_terms'=>$terms
        ],JSON_UNESCAPED_SLASHES));
        if(!hash_equals((string)($e['source_sha256']??''),$source))return false;
        // A stored classification alone cannot outlive changed tour copy.
        $quotes=(array)($e['source_snippets']['highlight_rows']??[]);
        $matched=false;
        $now=trim(preg_replace('/\s+/u',' ',$text));
        foreach($quotes as $quote){
            $quote=trim(preg_replace('/\s+/u',' ',(string)$quote));
            if(strlen($quote)>=35 && str_contains($now,$quote) &&
               preg_match('/\bhotel\s+pick[ -]?up\b/i',$quote))$matched=true;
        }
        if(!$matched)return false;
        $issue_key=hash('sha256',get_current_blog_id().'|'.$type.'|'.$id.'|origin_missing');
        $ledger=(array)get_option(self::STORE,[]);
        if(($ledger[$issue_key]['content_job_id']??'')!==(string)($e['job_id']??''))return false;
        return ['policy'=>'variable_guest_hotel_pickup','source'=>'published_tour_highlights',
            'source_meta_sha256'=>hash('sha256',$highlight),
            'research_job_id'=>(string)($e['job_id']??''),
            'fixed_origin_required'=>false,'guest_location_capture_verified'=>false];
    }

    public static function run_scheduled() {
        if (!self::trusted_scheduled_cli()) return new WP_Error('mad4b_readiness_scheduler_unauthorized');
        $cfg=self::rules();
        if (empty($cfg['scheduled_scan_enabled'])) return new WP_Error('mad4b_readiness_scheduler_disabled');
        $audit=(array)get_option(self::AUDIT,array());$latest=0;
        foreach ($audit as $item)
            if (($item['event']??'')==='scan') $latest=max($latest,strtotime((string)($item['at']??''))?:0);
        $interval=$cfg['scheduled_scan_interval_minutes']*60;
        if ($latest && time()-$latest<$interval)
            return array('status'=>'not_due','next_eligible_at'=>gmdate('c',$latest+$interval));
        return self::reconcile(self::discover(),true);
    }
    private static function trusted_scheduled_cli() {
        return PHP_SAPI==='cli' && defined('MAD4B_READINESS_SCHEDULED_RUN')
            && MAD4B_READINESS_SCHEDULED_RUN===true;
    }
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
    private static function rule_enabled_for($issue,$cfg) {
        if (($issue['source']??'')==='mad4b-presentation-integrity')
            return str_starts_with((string)($issue['code']??''),'pres_browser_')
                ? MAD4B_SCP_Presentation_Integrity_V1::browser_covered()
                : MAD4B_SCP_Presentation_Integrity_V1::covered();
        if (($issue['source']??'')==='mad4b-listing-media-browser') {
            return class_exists('MAD4B_Listing_Media_Diagnostics',false) &&
                (MAD4B_Listing_Media_Diagnostics::browser_readback()['live']??'')==='background_present';
        }
        if (($issue['source']??'')==='mad4b-listing-media') return class_exists('MAD4B_Listing_Media_Diagnostics',false);
        if(($issue['source']??'')==='mad4b-google-ttd-pricing' &&
            ($issue['code']??'')==='gttd_requirements_unverified')
            return class_exists('MAD4B_GTTD_Readiness_V1') &&
                has_filter('mad4b/content_readiness/external_issues')!==false;
        if(($issue['source']??'')==='mad4b-tour-gallery-diagnostics')
            return has_filter('mad4b/content_readiness/external_issues')!==false;
        if(($issue['source']??'')==='mad4b-media-origin-integrity')
            return class_exists('MAD4B_Media_Origin_Integrity_V1',false)
                && has_filter('mad4b/content_readiness/external_issues')!==false;
        if(($issue['source']??'')==='mad4b-rate-planner')return false; // Only its explicit, covered Tour/period scan may resolve planner gaps.
        if(($issue['source']??'')==='mad4b-locale-experience' &&
            preg_match('/^lexp_(?:asset_http_error|cross_locale_link|visible_term_parity|filter_visibility_drift|untranslated_ui_candidate)_[a-z]{2}$/D',(string)($issue['code']??'')))
            return class_exists('MAD4B_SCP_Locale_Experience_Alerts') &&
                MAD4B_SCP_Locale_Experience_Alerts::current()!==null;

        if(($issue['source']??'')==='mad4b-home-translation')
            return has_filter('mad4b/content_readiness/external_issues')!==false;
        $type=$issue['post_type']??'';$code=$issue['code']??'';
        // Rotating UI scans are intentionally partial. Do not auto-resolve a
        // structural issue for a source template omitted from this scan window.
        if(str_starts_with((string)$code,'loc_ui_') &&
           ($issue['source']??'')==='mad4b-locale-structural-health')
            return class_exists('MAD4B_WPML_Visible_UI_Alerts_V1') &&
                MAD4B_WPML_Visible_UI_Alerts_V1::covered_source(absint($issue['object_id']??0));
        if(str_starts_with($code,'loc_') && ($issue['source']??'')==='mad4b-elementor-localization')
            return class_exists('MAD4B_WPML_Visible_UI_Alerts_V1') &&
                MAD4B_WPML_Visible_UI_Alerts_V1::covered_locale_source(absint($issue['object_id']??0));
        if($type==='site' && str_starts_with($code,'perf_') &&
           ($issue['source']??'')==='mad4b-live-performance')
            return class_exists('MAD4B_Live_Render_Blocking_Monitor_V1') &&
                MAD4B_Live_Render_Blocking_Monitor_V1::report()['fresh'];
        if($type==='site' && str_starts_with($code,'dfsb_')){
            // Never resolve a source issue when its scanner is disconnected.
            return has_filter('mad4b/content_readiness/external_issues')!==false;
        }
        if((str_starts_with($code,'policy_rate_')||str_starts_with($code,'policy_hotel_')) &&
           ($issue['source']??'')==='mad4b-policy-intelligence') {
            return class_exists('MAD4B_Company_Policy_Intelligence_V2')
                && in_array($code,[
                    'policy_rate_no_valid','policy_rate_ambiguous',
                    'policy_rate_limit','policy_rate_provider_missing',
                    'policy_rate_currency','policy_hotel_no_valid',
                    'policy_hotel_ambiguous','policy_hotel_currency'
                ],true);
        }
        if(str_starts_with($code,'ccr_')) {
            $settings=MAD4B_SCP_Content_Completeness::settings();
            if(empty($settings['enabled'])||($issue['source']??'')!=='content-completeness')return false;
            $rule_id=(string)($issue['source_evidence']['rule_id']??'');
            foreach($settings['rules'] as $rule)
                if(($rule['id']??'')===$rule_id)return true;
            return false;
        }
        if(str_starts_with($code,'geo_'))
            return !empty($cfg['geo_quality_enabled']) &&
                in_array($type,$cfg['post_types'],true) &&
                in_array($code,MAD4B_SCP_Geo_Quality::CODES,true);
        if($code==='cron_overdue')return (bool)$cfg['check_cron'];
        if($code==='form_submissions_unread')return !empty($cfg['form_id']);
        if(!in_array($type,$cfg['post_types'],true))return false;
        switch($code) {
            case 'wpml_language_unregistered':return (bool)$cfg['check_wpml'];
            case 'origin_missing':return !empty($cfg['origin_fields'][$type]);
            case 'pickup_guest_location_capture_unverified':return !empty($cfg['origin_fields'][$type]);
            case 'origin_source_too_broad':
            case 'origin_source_invalid_reference':return !empty($cfg['origin_source_fields'][$type])&&!empty($cfg['origin_source_taxonomies'][$type]);
            case 'location_unlinked':return !empty($cfg['location_taxonomies'][$type])&&taxonomy_exists($cfg['location_taxonomies'][$type]);
            case 'media_source_unverified':return !empty($cfg['media_fields'][$type]);
            case 'media_source_shared_across_records':return !empty($cfg['media_fields'][$type])&&!empty($cfg['media_reuse_threshold']);
            case 'owner_reference_missing':return !empty($cfg['owner_fields'][$type]);
            case 'rate_end_date_missing':return !empty($cfg['end_date_fields'][$type]);
            case 'expired_rate_renewal_required':
            case 'expired_rate_sale_not_blocked':return !empty($cfg['end_date_fields'][$type]);
            default:return false;
        }
    }
    private static function audit($event,$data) {
        $rows=(array)get_option(self::AUDIT,array());
        $rows[]=array('event'=>$event,'data'=>$data,'at'=>gmdate('c'),'actor'=>get_current_user_id());
        update_option(self::AUDIT,array_slice($rows,-100),false);
    }
    public static function summary($issues=null) {
        $issues=is_array($issues)?$issues:(array)get_option(self::STORE,array());
        $summary=array('open'=>0,'critical'=>0,'high'=>0,'medium'=>0,'resolved'=>0,'board_linked'=>0);
        foreach($issues as $issue) {
            if(($issue['status']??'')==='resolved'){$summary['resolved']++;continue;}
            $summary['open']++;
            $priority=$issue['severity']??'medium';
            if(isset($summary[$priority]))$summary[$priority]++;
            if(!empty($issue['board_task_id']))$summary['board_linked']++;
        }
        return $summary;
    }
    /** Read endpoints plus local-client-only, governed task synchronization. */
    public static function register_rest_routes() {
        $namespace='mad4b-content-readiness/v1';
        register_rest_route($namespace, '/summary', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'rest_summary'),
            'permission_callback' => array(__CLASS__, 'rest_permission'),
        ));
        register_rest_route($namespace, '/issues', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'rest_issues'),
            'permission_callback' => array(__CLASS__, 'rest_permission'),
            'args'=>array(
                'source'=>array('type'=>'string','required'=>false),
                'language'=>array('type'=>'string','required'=>false),
                'assignment'=>array('type'=>'string','required'=>false,
                    'enum'=>array('all','linked','unassigned','content_job','board')),
            ),
        ));
        // Only real, authenticated local-client credentials may write.
        register_rest_route($namespace, '/sync-one', array(
            'methods'=>'POST',
            'callback'=>array(__CLASS__,'rest_sync_one'),
            'permission_callback'=>array(__CLASS__,'rest_local_client_write_permission'),
            'args'=>array('issue_id'=>array('required'=>true,'type'=>'string')),
        ));
        register_rest_route($namespace, '/sync-batch', array(
            'methods'=>'POST',
            'callback'=>array(__CLASS__,'rest_sync_batch'),
            'permission_callback'=>array(__CLASS__,'rest_local_client_write_permission'),
        ));
    }
    public static function rest_permission() {
        return current_user_can('edit_others_posts');
    }
    public static function rest_local_client_write_permission() {
        if(!current_user_can('manage_options') || !class_exists('MAD4B_SCP_Local_Client')
           || !MAD4B_SCP_Local_Client::bound()
           || !class_exists('MAD4B_SCP_Identity_Context')
           || !class_exists('MAD4B_SCP_Policy'))return false;
        $identity=MAD4B_SCP_Identity_Context::current();
        if(is_wp_error($identity)||empty($identity['authenticated'])
           ||($identity['subject_type']??'')!=='wp_app_password'
           ||($identity['auth_method']??'')!=='wp_application_password'
           ||(int)($identity['wp_user_id']??0)!==get_current_user_id())return false;
        return MAD4B_SCP_Policy::can_mutate();
    }
    public static function rest_sync_one($request) {
        $id=strtolower(trim((string)$request->get_param('issue_id')));
        if(!preg_match('/^[a-f0-9]{64}$/D',$id))
            return new WP_Error('mad4b_readiness_invalid_issue','Exact source issue fingerprint required.',array('status'=>400));
        $res=self::sync_board($id);
        return is_wp_error($res)?$res:rest_ensure_response(array(
            'contract'=>'mad4b.content-readiness.sync.v1','issue_id'=>$id,
            'status'=>$res['status']??'unknown','task_id'=>absint($res['task_id']??0)));
    }
    public static function rest_sync_batch($request) {
        $res=self::batch_sync();
        return is_wp_error($res)?$res:rest_ensure_response(array(
            'contract'=>'mad4b.content-readiness.batch-sync.v1','result'=>$res));
    }
    public static function rest_summary($request) {
        $rows=(array)get_option(self::STORE,array());
        $audit=(array)get_option(self::AUDIT,array());
        $last=null;
        for($i=count($audit)-1;$i>=0;$i--) {
            if (($audit[$i]['event']??'')==='scan') {
                $last=$audit[$i]['at']??null;break;
            }
        }
        return rest_ensure_response(array(
            'contract'=>'mad4b.content-readiness.summary.v1',
            'counts'=>self::summary($rows),
            'last_reconciled_at'=>$last,
            'snapshot_count'=>count($rows),
            // Verified browser control is QA evidence, never an Issue/Task.
            'media_verification'=>class_exists('MAD4B_Listing_Media_Diagnostics',false)
                ? MAD4B_Listing_Media_Diagnostics::verification_evidence() : null,
            'media_verification_history'=>class_exists('MAD4B_Listing_Media_Diagnostics',false)
                ? MAD4B_Listing_Media_Diagnostics::verification_history_summary() : null,
            'media_incident_outbox'=>class_exists('MAD4B_Media_Incident_Bridge',false)
                ? MAD4B_Media_Incident_Bridge::summary() : null,
            'read_only'=>true,
        ));
    }
    private static function priority_compare($a,$b) {
        $sa=(($a['status']??'open')==='resolved')?1:0;
        $sb=(($b['status']??'open')==='resolved')?1:0;
        if($sa!==$sb)return $sa<=>$sb;
        $priority=array('critical'=>0,'high'=>1,'medium'=>2,'low'=>3);
        $pa=$priority[$a['severity']??'low']??4;
        $pb=$priority[$b['severity']??'low']??4;
        if($pa!==$pb)return $pa<=>$pb;
        if (($a['source']??'')==='mad4b-home-translation' || ($b['source']??'')==='mad4b-home-translation') {
            $ap=(int)($a['priority']??0); $bp=(int)($b['priority']??0);
            if($ap!==$bp)return $bp<=>$ap;
        }
        // Among equally severe issues, prioritize verified WPML term
        // translation work before ordinary SEO housekeeping.
        $ar=(str_starts_with((string)($a['code']??''),'dfsb_wpml_') ? 0 : 1);
        $br=(str_starts_with((string)($b['code']??''),'dfsb_wpml_') ? 0 : 1);
        if($ar!==$br)return $ar<=>$br;
        $ap=max(0,min(100,(int)($a['priority']??0)));
        $bp=max(0,min(100,(int)($b['priority']??0)));
        // Dynamic verified public-surface impact overrides only ordering INSIDE
        // the existing severity/taxonomy tier. Never rewrites provider priority.
        $impactA=apply_filters('mad4b/content_readiness/impact_score',null,$a);
        $impactB=apply_filters('mad4b/content_readiness/impact_score',null,$b);
        if(is_int($impactA) && is_int($impactB) && $impactA!==$impactB)
            return $impactB<=>$impactA;
        if(is_int($impactA) && !is_int($impactB))return -1;
        if(!is_int($impactA) && is_int($impactB))return 1;
        if($ap!==$bp)return $bp<=>$ap;
        return strcmp($b['last_seen']??'',$a['last_seen']??'');
    }
    public static function rest_issues($request) {
        $page=max(1,absint($request->get_param('page')??1));
        $per_page=max(1,min(100,absint($request->get_param('per_page')??25)));
        $status=sanitize_key((string)($request->get_param('status')??'open'));
        if(!in_array($status,array('open','resolved','all'),true))$status='open';
        $severity=sanitize_key((string)($request->get_param('severity')??''));
        $type=sanitize_key((string)($request->get_param('post_type')??''));
        $code=sanitize_key((string)($request->get_param('code')??''));
        $source=sanitize_key((string)($request->get_param('source')??''));
        $languageRaw=(string)($request->get_param('language')??'');
        $language=strtolower(trim($languageRaw));
        // Deny invalid input before sanitization can silently turn a malformed
        // or injected language into an unfiltered site-wide request.
        if($language!=='' && !preg_match('/^[a-z]{2}$/D',$language))
            return new WP_Error('mad4b_readiness_locale_filter_invalid',
                'Use an exact two-letter language.',array('status'=>400));
        $assignment=sanitize_key((string)($request->get_param('assignment')??'all'));
        if(!in_array($assignment,array('all','linked','unassigned','content_job','board'),true))
            return new WP_Error('mad4b_readiness_assignment_filter_invalid',
                'Unsupported assignment filter.',array('status'=>400));
        $result=array();
        foreach((array)get_option(self::STORE,array()) as $row) {
            if(!is_array($row))continue;
            $state=(($row['status']??'open')==='resolved')?'resolved':'open';
            if($status!=='all' && $state!==$status)continue;
            if($severity && ($row['severity']??'')!==$severity)continue;
            if($type && ($row['post_type']??'')!==$type)continue;
            if($code && ($row['code']??'')!==$code)continue;
            $sourceValue=sanitize_key((string)($row['source']??'native'));
            if($source && $sourceValue!==$source)continue;
            $issueLanguage=sanitize_key((string)($row['content_job_request']['language']??''));
            if(!$issueLanguage && preg_match('/_([a-z]{2})$/D',(string)($row['code']??''),$m))
                $issueLanguage=$m[1];
            if($language && $issueLanguage!==$language)continue;
            $hasJob=!empty($row['content_job_id']);
            $hasBoard=!empty($row['board_task_id']);
            if(($assignment==='linked' && !$hasJob && !$hasBoard)
                ||($assignment==='unassigned' && ($hasJob||$hasBoard))
                ||($assignment==='content_job' && !$hasJob)
                ||($assignment==='board' && !$hasBoard))continue;
            $e=(array)($row['source_evidence']??array());
            $result[]=array(
                'issue_id'=>(string)($row['id']??''),
                'post_type'=>(string)($row['post_type']??''),
                'object_id'=>absint($row['object_id']??0),
                'code'=>(string)($row['code']??''),
                'severity'=>(string)($row['severity']??'medium'),
                'priority'=>max(0,min(100,absint($row['priority']??0))),
                'source'=>$sourceValue,
                'language'=>$issueLanguage?:null,
                'content_job_id'=>$hasJob?sanitize_text_field((string)$row['content_job_id']):null,
                'locale_evidence'=>($row['source']??'')==='mad4b-locale-experience'
                    ?(array)($row['source_evidence']??array()):null,
                'content_job_request'=>($row['source']??'')==='mad4b-locale-experience'
                    ?(array)($row['content_job_request']??array()):null,
                'label'=>(string)($row['label']??''),
                'status'=>$state,
                'first_seen'=>$row['first_seen']??null,
                'last_seen'=>$row['last_seen']??null,
                'resolved_at'=>$row['resolved_at']??null,
                'board_task_id'=>absint($row['board_task_id']??0)?:null,
                'gttd_checklist'=>($row['source']??'')==='mad4b-google-ttd-pricing' ?(array)($e['checklist']??[]):null,
                'source_evidence'=>$e?array(
                    'taxonomy'=>sanitize_key((string)($e['taxonomy']??'')),
                    'term_id'=>absint($e['term_id']??0),
                    'level'=>sanitize_key((string)($e['level']??'')),
                ):null,
            );
        }
        usort($result,array(__CLASS__,'priority_compare'));
        $total=count($result);
        return rest_ensure_response(array(
            'contract'=>'mad4b.content-readiness.issues.v1',
            'read_only'=>true,'total'=>$total,'page'=>$page,
            'per_page'=>$per_page,'pages'=>(int)ceil($total/$per_page),
            'items'=>array_slice($result,($page-1)*$per_page,$per_page),
        ));
    }

    public static function menu() {
        MAD4B_SCP_Admin_Route_Registry::register(self::PAGE,'manage_options');
        add_submenu_page('mad4b-control-plane','Content Readiness & Alerts',
            'Content Readiness','manage_options',self::PAGE,array(__CLASS__,'render'));
    }
    public static function admin_bar($bar) {
        if (!is_admin_bar_showing() || !current_user_can('manage_options'))return;
        $counts=self::summary();if(!$counts['open'])return;
        $bar->add_node(array('id'=>'mad4b-content-alerts','title'=>'Content Alerts: '.(int)$counts['open'],
            'href'=>admin_url('admin.php?page='.self::PAGE)));
    }
    public static function admin_notice() {
        if (!current_user_can('edit_others_posts') || get_current_screen() === null) return;
        if (get_current_screen()->id !== 'dashboard') return;
        $workflow=MAD4B_SCP_Readiness_Workflow_Options::settings();
        if(empty($workflow['dashboard_alerts']))return;
        $counts=self::summary();
        if(!$counts['critical']&&!$counts['high'])return;
        $link=esc_url(admin_url('admin.php?page='.self::PAGE));
        echo '<div class="notice notice-warning"><p><strong>Content Readiness:</strong> '.
            (int)$counts['critical'].' Critical / '.(int)$counts['high'].
            ' High issues. <a href="'.$link.'">Review and assign</a></p></div>';
    }
    /** Export currently observed issues for a human source-data repair queue. */
    public static function handle_export() {
        if (!current_user_can('edit_others_posts')) wp_die('Forbidden',403);
        check_admin_referer('mad4b_readiness_export');
        $status=sanitize_key((string)wp_unslash($_GET['readiness_status']??'open'));
        if (!in_array($status,array('open','resolved','all'),true)) $status='open';
        $severity=sanitize_key((string)wp_unslash($_GET['readiness_severity']??'all'));
        if (!in_array($severity,array('all','critical','high','medium','low'),true)) $severity='all';
        $rows=array_values(array_filter((array)get_option(self::STORE,array()),
            static function($row)use($status,$severity){
                if(!is_array($row))return false;
                $state=(($row['status']??'open')==='resolved')?'resolved':'open';
                return ($status==='all'||$state===$status)
                    && ($severity==='all'||($row['severity']??'medium')===$severity);
            }));
        usort($rows,array(__CLASS__,'priority_compare'));
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="mad4b-content-readiness-'.gmdate('Ymd').'.csv"');
        $output=fopen('php://output','w');
        fputcsv($output,array('issue_id','severity','status','post_type','post_id','content_title','code',
            'source_taxonomy','source_term_id','source_level','description','edit_url','last_seen'));
        foreach($rows as $row){
            $e=(array)($row['source_evidence']??array());
            $id=absint($row['object_id']??0);
            $url=$id?get_edit_post_link($id,'raw'):'';
            $values=array($row['id']??'',$row['severity']??'',$row['status']??'',
                $row['post_type']??'',$id,($id?html_entity_decode(wp_strip_all_tags(get_the_title($id)),ENT_QUOTES,'UTF-8'):''),$row['code']??'',$e['taxonomy']??'',
                absint($e['term_id']??0),$e['level']??'',$row['label']??'',
                $url,$row['last_seen']??'');
            // Prevent spreadsheet formula execution in exported source labels.
            $values=array_map(static function($v){
                $v=(string)$v;
                return preg_match('/^[=+@\-\t\r]/',$v)?"'".$v:$v;
            },$values);
            fputcsv($output,$values);
        }
        fclose($output);
        exit;
    }
    public static function handle_scan() {
        if(!current_user_can('manage_options'))wp_die('Forbidden',403);
        check_admin_referer('mad4b_readiness_scan');
        $result=self::reconcile(self::discover());
        set_transient('mad4b_readiness_result_'.get_current_user_id(),
            is_wp_error($result)?$result->get_error_code():$result,120);
        wp_safe_redirect(admin_url('admin.php?page='.self::PAGE));exit;
    }
    public static function handle_save_rules() {
        if(!current_user_can('manage_options'))wp_die('Forbidden',403);
        check_admin_referer('mad4b_readiness_rules');
        $names=['origin_fields','origin_source_fields','origin_source_taxonomies','location_taxonomies','media_fields','owner_fields','end_date_fields','stop_sale_fields'];
        $settings=array();
        $types=explode(',',(string)wp_unslash($_POST['post_types']??''));
        $settings['post_types']=array_slice(array_values(array_filter(array_map('sanitize_key',$types))),0,24);
        foreach($names as $key) {
            $settings[$key]=array();
            $pairs=explode(',',(string)wp_unslash($_POST[$key]??''));
            foreach($pairs as $pair) {
                $parts=explode(':',trim($pair),2);
                if(count($parts)===2){$t=sanitize_key($parts[0]);$v=sanitize_key($parts[1]);if($t&&$v)$settings[$key][$t]=$v;}
            }
        }
        $settings['invalid_origin_levels']=array_values(array_filter(array_map('sanitize_key',explode(',',(string)wp_unslash($_POST['invalid_origin_levels']??'')))));
        $settings['board_id']=absint($_POST['board_id']??0);
        $settings['stage_id']=absint($_POST['stage_id']??0);
        $settings['assignee_id']=absint($_POST['assignee_id']??0);
        $settings['media_reuse_threshold']=max(0,min(100,absint($_POST['media_reuse_threshold']??0)));
        $settings['form_id']=absint($_POST['form_id']??0);
        $settings['form_unread_days']=max(1,min(90,absint($_POST['form_unread_days']??7)));
        $settings['geo_quality_enabled']=isset($_POST['geo_quality_enabled']);
        $settings['scheduled_scan_enabled']=isset($_POST['scheduled_scan_enabled']);
        $settings['scheduled_scan_interval_minutes']=max(15,min(1440,absint($_POST['scheduled_scan_interval_minutes']??60)));
        $settings['check_wpml']=isset($_POST['check_wpml']);
        $settings['check_cron']=isset($_POST['check_cron']);
        $settings['cron_max_age_seconds']=86400;
        update_option(self::POLICY,$settings,false);
        self::audit('rule_update',array('post_types'=>$settings['post_types']));
        wp_safe_redirect(admin_url('admin.php?page='.self::PAGE));exit;
    }
    /** Provider-scoped reconciliation: never resolves another source or an unexamined Tour. */
    public static function reconcile_rate_plan(array $rows,array $covered,string $scope) {
        if(!class_exists('MAD4B_Lifecycle_Access')||!MAD4B_Lifecycle_Access::can('commerce_manage_pricing'))return new WP_Error('rate_plan_alert_permission_denied');
        foreach($covered as $id)if(get_post_type($id)!=='tours-and-activities'||!current_user_can('edit_post',$id))return new WP_Error('rate_plan_alert_object_denied');
        global $wpdb;$lock='mad4b_wpml_url_jobs_'.get_current_blog_id();if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)return new WP_Error('rate_plan_alert_ledger_busy');
        try {
            $old=(array)get_option(self::STORE,[]);$new=$old;$seen=[];$now=gmdate('c');
            foreach($rows as $row){$type=$row['post_type']??'';$id=(int)($row['object_id']??0);$code=$row['code']??'';$key=hash('sha256',get_current_blog_id().'|'.$type.'|'.$id.'|'.$code);
                if(($row['source']??'')!=='mad4b-rate-planner'||$type!=='tours-and-activities'||!in_array($id,$covered,true)||!preg_match('/^rate_plan_[a-z0-9_]+_[a-f0-9]{12}$/D',$code)||!hash_equals($key,(string)($row['id']??''))||($row['source_evidence']['planner_scope']??'')!==$scope)return new WP_Error('rate_plan_alert_row_invalid');
                $seen[$key]=true;$before=$old[$key]??[];$new[$key]=array_merge($before,$row,['status'=>'open','first_seen'=>$before['first_seen']??$now,'last_seen'=>$now,'resolved_at'=>null,'reopens'=>(int)($before['reopens']??0)+(($before['status']??'')==='resolved'?1:0)]);
            }
            foreach($new as $key=>$row)if(($row['source']??'')==='mad4b-rate-planner'&&($row['source_evidence']['planner_scope']??'')===$scope&&in_array((int)$row['object_id'],$covered,true)&&!isset($seen[$key])&&($row['status']??'')!=='resolved'){$new[$key]['status']='resolved';$new[$key]['resolved_at']=$now;}
            if(count($new)>self::MAX_ISSUES)return new WP_Error('rate_plan_native_alert_capacity');
            update_option(self::STORE,$new,false);if(get_option(self::STORE)!==$new)return new WP_Error('rate_plan_alert_readback_failed');
            self::audit('rate_plan_reconcile',['scope'=>$scope,'covered'=>count($covered),'current'=>count($rows)]);
            if(class_exists('MAD4B_SCP_Readiness_Integrations'))MAD4B_SCP_Readiness_Integrations::record_changes($old,$new);
        }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
        // Tasks are a separate provider receipt; task failures never erase ledger alerts.
        $tasks=[];$workflow=MAD4B_SCP_Readiness_Workflow_Options::settings();$allow=class_exists('MAD4B_Lifecycle_Access')&&MAD4B_Lifecycle_Access::can('commerce_manage_request_tasks');
        if($allow){
            foreach($new as $key=>$row){if(($row['source']??'')!=='mad4b-rate-planner'||($row['source_evidence']['planner_scope']??'')!==$scope||!in_array((int)$row['object_id'],$covered,true))continue;
                if(!empty($row['board_task_id'])&&class_exists(\FluentBoards\App\Models\Task::class)){$task=\FluentBoards\App\Models\Task::find((int)$row['board_task_id']);if($task&&$task->source==='mad4b-readiness'&&(string)$task->source_id===$key){if($row['status']==='resolved')$task->close();elseif($task->status==='closed')$task->reopen();}}
            }
            if(!empty($workflow['board_tasks']))foreach($rows as $row){$key=$row['id'];$weights=['critical'=>3,'high'=>2,'medium'=>1,'low'=>0];if(($weights[$row['severity']]??0)<($weights[$workflow['minimum_severity']]??2))continue;if(!empty($new[$key]['board_task_id']))continue;$result=self::sync_rate_plan_board($key);$tasks[$key]=is_wp_error($result)?['error'=>$result->get_error_code()]:$result;}
        }
        return ['status'=>'native_ledger_reconciled','issues'=>count($rows),'tasks'=>$tasks,'email_sent'=>false];
    }
    public static function sync_rate_plan_board(string $id) {
        global $wpdb;$lock='mad4b_wpml_url_jobs_'.get_current_blog_id();if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)return new WP_Error('rate_plan_alert_ledger_busy');
        try {$rows=(array)get_option(self::STORE,[]);if(($rows[$id]['source']??'')!=='mad4b-rate-planner')return new WP_Error('rate_plan_board_source_invalid');return self::sync_board($id);}
        finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }

    public static function sync_board($issue_id) {
        $sourceRows=(array)get_option(self::STORE,[]);$ratePlan=($sourceRows[$issue_id]['source']??'')==='mad4b-rate-planner';
        $scoped=$ratePlan&&class_exists('MAD4B_Lifecycle_Access')&&MAD4B_Lifecycle_Access::can('commerce_manage_pricing')&&MAD4B_Lifecycle_Access::can('commerce_manage_request_tasks')&&current_user_can('edit_post',(int)$sourceRows[$issue_id]['object_id']);
        if(!$scoped&&!current_user_can('manage_options'))return new WP_Error('mad4b_readiness_permission_denied');
        if(!$scoped&&(!class_exists('MAD4B_SCP_Policy') || !MAD4B_SCP_Policy::can_mutate()))
            return new WP_Error('mad4b_readiness_write_gate_closed');
        $workflow=MAD4B_SCP_Readiness_Workflow_Options::settings();
        if(empty($workflow['board_tasks']))
            return new WP_Error('mad4b_readiness_board_sync_disabled');
        $board=$workflow['board_id'];$stage=$workflow['stage_id'];
        if(!MAD4B_SCP_Readiness_Workflow_Options::validate_board_stage($board,$stage) ||
           !class_exists(\FluentBoards\App\Services\TaskService::class))
            return new WP_Error('mad4b_readiness_board_not_configured');
        $issues=(array)get_option(self::STORE,array());
        $row=$issues[$issue_id]??null;
        if(!$row || ($row['status']??'')==='resolved')return new WP_Error('mad4b_readiness_issue_not_open');
        if(!empty($row['board_task_id']))return array('status'=>'already_linked','task_id'=>(int)$row['board_task_id']);
        global $wpdb;
        $existing=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fbs_tasks WHERE board_id=%d AND source=%s AND source_id=%s LIMIT 1",
            $board,'mad4b-readiness',$issue_id));
        if($existing){
            $issues[$issue_id]['board_task_id']=$existing;
            update_option(self::STORE,$issues,false);
            return array('status'=>'recovered_link','task_id'=>$existing);
        }
        // Native board service enforces stage membership. Source ID is the issue fingerprint.
        $subject='['.strtoupper($row['severity']).'] '.$row['code'].' #'.$row['object_id'];
        $link=$row['object_id']?get_edit_post_link($row['object_id'],'raw'):'';
        $taskChecklist='';
        if (($row['source']??'')==='mad4b-google-ttd-pricing') {
            foreach(['price','fees_and_taxes','occupancy','availability','checkout_match'] as $name) {
                $entry=$row['source_evidence']['checklist'][$name]??[];
                $taskChecklist.="\n- [".(($entry['status']??'')==='passed'?'x':' ')."] ".$name.
                    ': '.sanitize_text_field((string)($entry['action']??''));
            }
            $taskChecklist.="\nClose only after a fresh live Google Things to do price, fees, occupancy, availability and checkout readback.";
        }
        $taskData=array('title'=>$subject,'stage_id'=>$stage,'source'=>'mad4b-readiness',
            'source_id'=>$issue_id,
            'description'=>'Readiness Issue: '.$issue_id."\n".esc_url_raw((string)$link).'  '.sanitize_text_field($row['label']).$taskChecklist. (!empty($row['content_job_id']) ? "\nNative ContentJob: ".sanitize_text_field((string)$row['content_job_id']) : ''));
        $assigned=MAD4B_SCP_Readiness_Workflow_Options::assigned_user($row);
        if($assigned)$taskData['assignees']=array($assigned);
        try {
            $svc=$ratePlan?new class extends \FluentBoards\App\Services\TaskService {public function sendMailAfterTaskModify($column,$assigneeIds,$taskId){return;}}:new \FluentBoards\App\Services\TaskService();
            $task=$svc->createTask($taskData,$board);
        } catch (Throwable $e) {
            return new WP_Error('mad4b_readiness_board_provider_failed','Fluent Boards rejected task creation.');
        }
        $taskId=absint($task->id??0);
        if(!$taskId)return new WP_Error('mad4b_readiness_board_receipt_missing');
        $issues[$issue_id]['board_task_id']=$taskId;
        update_option(self::STORE,$issues,false);
        self::audit('board_task_created',array('issue'=>$issue_id,'task_id'=>$taskId));
        return array('status'=>'created','task_id'=>$taskId);
    }
    public static function batch_sync() {
        if(!current_user_can('manage_options'))
            return new WP_Error('mad4b_readiness_permission_denied');
        if(!class_exists('MAD4B_SCP_Policy')||!MAD4B_SCP_Policy::can_mutate())
            return new WP_Error('mad4b_readiness_write_gate_closed');
        $workflow=MAD4B_SCP_Readiness_Workflow_Options::settings();
        if(empty($workflow['board_tasks'])||
            !MAD4B_SCP_Readiness_Workflow_Options::validate_board_stage($workflow['board_id'],$workflow['stage_id']))
            return new WP_Error('mad4b_readiness_board_not_configured');
        $issues=array_values((array)get_option(self::STORE,array()));
        usort($issues,array(__CLASS__,'priority_compare'));
        $priority=array('critical'=>0,'high'=>1,'medium'=>2,'low'=>3);
        $ceiling=$priority[$workflow['minimum_severity']]??1;
        $results=array();$limit=$workflow['batch_limit'];
        foreach($issues as $issue){
            if(count($results)>=$limit)break;
            if(!is_array($issue)||($issue['status']??'')==='resolved'||!empty($issue['board_task_id']))continue;
            if(($priority[$issue['severity']??'medium']??3)>$ceiling)continue;
            $result=self::sync_board((string)($issue['id']??''));
            $results[]=array('issue'=>$issue['id']??'',
                'status'=>is_wp_error($result)?$result->get_error_code():($result['status']??'unknown'),
                'task_id'=>is_wp_error($result)?0:absint($result['task_id']??0));
        }
        return array('processed'=>count($results),'results'=>$results);
    }
    public static function handle_batch_sync() {
        if(!current_user_can('manage_options'))wp_die('Forbidden',403);
        check_admin_referer('mad4b_readiness_batch_sync');
        $result=self::batch_sync();
        set_transient('mad4b_readiness_result_'.get_current_user_id(),
            is_wp_error($result)?$result->get_error_code():$result,120);
        wp_safe_redirect(admin_url('admin.php?page='.MAD4B_SCP_Readiness_Workflow_Options::PAGE));
        exit;
    }
    public static function handle_board_sync() {
        if(!current_user_can('manage_options'))wp_die('Forbidden',403);
        check_admin_referer('mad4b_readiness_board_sync');
        $issue=sanitize_text_field((string)($_POST['issue_id']??''));
        $result=self::sync_board($issue);
        set_transient('mad4b_readiness_result_'.get_current_user_id(),
            is_wp_error($result)?$result->get_error_code():$result,120);
        wp_safe_redirect(admin_url('admin.php?page='.self::PAGE));exit;
    }
    public static function render() {
        if(!current_user_can('edit_others_posts'))wp_die('Forbidden',403);
        $rules=self::rules();$issues=(array)get_option(self::STORE,array());$counts=self::summary($issues);
        $flash=get_transient('mad4b_readiness_result_'.get_current_user_id());
        delete_transient('mad4b_readiness_result_'.get_current_user_id());
        echo '<div class="wrap"><h1>Content Readiness &amp; Alerts</h1>';
        echo '<p>Source-backed issue ledger. Approved local scheduler reconciles issues; external tasks and messages remain governed separately.</p>';
        echo '<p><a class="button button-secondary" href="'.esc_url(admin_url('admin.php?page='.MAD4B_SCP_Readiness_Workflow_Options::PAGE)).'">Follow-up &amp; Notifications Settings</a></p>';
        if($flash)echo '<div class="notice notice-info"><pre>'.esc_html(wp_json_encode($flash)).'</pre></div>';
        echo '<h2>Open: '.(int)$counts['open'].' | Critical: '.(int)$counts['critical'].' | High: '.(int)$counts['high'].' | Resolved: '.(int)$counts['resolved'].'</h2>';
        if(class_exists('MAD4B_Listing_Media_Diagnostics',false)) {
            $mediaEvidence=MAD4B_Listing_Media_Diagnostics::verification_evidence();
            echo '<h2>Listing media verification (read-only)</h2>';
            echo '<p>Controlled CSS injection is QA evidence only; it never opens an issue or dispatches a task. ';
            echo 'Status: <strong>'.esc_html($mediaEvidence['state']??'unavailable').'</strong>';
            if(!empty($mediaEvidence['observed_at']))
                echo ' | Observed: '.esc_html(gmdate('Y-m-d H:i:s', (int)$mediaEvidence['observed_at'])).' UTC';
            echo '</p>';
            $mediaHistory=MAD4B_Listing_Media_Diagnostics::verification_history_summary();
            echo '<p class="description">Saved QA readbacks: '.(int)$mediaHistory['count'].
                '. Stored separately from active content incidents.</p>';
            if(class_exists('MAD4B_Media_Incident_Bridge',false)) {
                $bridge=MAD4B_Media_Incident_Bridge::summary();
                echo '<p class="description">Verified source transitions pending original Broker ACK: '.
                    (int)$bridge['pending_original_broker_ack'].
                    '. No external dispatch from this bridge.</p>';
            }
        }
        if(current_user_can('manage_options')) {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('mad4b_readiness_scan');
        echo '<input type="hidden" name="action" value="mad4b_readiness_scan">';
        submit_button('Scan & reconcile content issues','primary','submit',false);echo '</form>';
        echo '<h2>Site-specific source rules</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('mad4b_readiness_rules');echo '<input type="hidden" name="action" value="mad4b_readiness_save_rules">';
        $fields=array('post_types'=>'Content post types (comma-separated)',
            'origin_fields'=>'Origin meta by post type',
            'origin_source_fields'=>'Origin source term ID meta by post type',
            'origin_source_taxonomies'=>'Origin term taxonomy by post type',
            'location_taxonomies'=>'Location taxonomy by post type',
            'media_fields'=>'Media meta by post type',
            'owner_fields'=>'Owner meta by post type',
            'end_date_fields'=>'End date meta by post type',
            'stop_sale_fields'=>'Stop sale meta by post type');
        foreach($fields as $field=>$name){
            $value=$rules[$field];
            if(is_array($value)) {
                $parts=array();foreach($value as $t=>$key)$parts[]=is_string($t)?$t.':'.$key:$key;
                $value=implode(', ',$parts);
            }
            echo '<p><label>'.esc_html($name).'<br><input class="large-text" name="'.esc_attr($field).'" value="'.esc_attr((string)$value).'"></label></p>';
        }
        echo '<p><label>Disallowed origin levels (CSV)<br><input class="large-text" name="invalid_origin_levels" value="'.esc_attr(implode(',',(array)$rules['invalid_origin_levels'])).'"></label></p>';
        foreach(array('scheduled_scan_interval_minutes'=>'Scheduled scan minimum interval (minutes)', 'media_reuse_threshold'=>'Shared gallery review threshold (0 disables)', 'form_id'=>'Fluent Forms ID', 'form_unread_days'=>'Unread follow-up threshold (days)') as $field=>$label)
            echo '<p><label>'.esc_html($label).' <input type="number" min="0" name="'.esc_attr($field).'" value="'.(int)$rules[$field].'"></label></p>';
        foreach(array('geo_quality_enabled'=>'Enable source-only station, cruise boarding and transport-price quality alerts (no geocoding or pricing writes)','scheduled_scan_enabled'=>'Enable local server-side scheduled scans (issue ledger only; no notifications or tasks)','check_wpml'=>'Detect missing WPML language','check_cron'=>'Detect overdue WP-Cron') as $field=>$label)
            echo '<p><label><input type="checkbox" name="'.esc_attr($field).'" '.checked($rules[$field],true,false).'>'.esc_html($label).'</label></p>';
        submit_button('Save rules');echo '</form>';
        }
        $requestedStatus=sanitize_key((string)wp_unslash($_GET['readiness_status']??'open'));
        if(!in_array($requestedStatus,array('open','resolved','all'),true))$requestedStatus='open';
        $requestedSeverity=sanitize_key((string)wp_unslash($_GET['readiness_severity']??'all'));
        if(!in_array($requestedSeverity,array('all','critical','high','medium','low'),true))$requestedSeverity='all';
        $rows=array_values(array_filter($issues,static function($row) use($requestedStatus,$requestedSeverity){
            if(!is_array($row))return false;
            $state=(($row['status']??'open')==='resolved')?'resolved':'open';
            return ($requestedStatus==='all'||$state===$requestedStatus) &&
                ($requestedSeverity==='all'||($row['severity']??'medium')===$requestedSeverity);
        }));
        usort($rows,array(__CLASS__,'priority_compare'));
        $totalRows=count($rows);
        $totalPages=max(1,(int)ceil($totalRows/100));
        $issuePage=max(1,min($totalPages,absint($_GET['readiness_page']??1)));
        echo '<h2>Issues</h2><form method="get" style="margin:12px 0">';
        echo '<input type="hidden" name="page" value="'.esc_attr(self::PAGE).'">';
        foreach(array('readiness_status'=>array('open'=>'Open','resolved'=>'Resolved','all'=>'All'),
                      'readiness_severity'=>array('all'=>'All severities','critical'=>'Critical','high'=>'High','medium'=>'Medium','low'=>'Low')) as $field=>$options){
            $selected=$field==='readiness_status'?$requestedStatus:$requestedSeverity;
            echo '<label>'.esc_html($field==='readiness_status'?'Status':'Severity').' <select name="'.esc_attr($field).'">';
            foreach($options as $key=>$value)echo '<option value="'.esc_attr($key).'" '.selected($selected,$key,false).'>'.esc_html($value).'</option>';
            echo '</select></label> ';
        }
        echo '<button class="button">Filter</button></form>';
        $exportUrl=wp_nonce_url(add_query_arg(array('action'=>'mad4b_readiness_export',
            'readiness_status'=>$requestedStatus,'readiness_severity'=>$requestedSeverity),
            admin_url('admin-post.php')),'mad4b_readiness_export');
        echo '<p><a class="button button-secondary" href="'.esc_url($exportUrl).'">Export matching issues (CSV)</a></p>';
        echo '<p class="description">Showing '.($totalRows?((($issuePage-1)*100)+1):0).'-'.min($issuePage*100,$totalRows).' of '.$totalRows.' matching issues.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Severity</th><th>Source</th><th>Issue</th><th>Status</th><th>Fluent Boards</th></tr></thead><tbody>';
        foreach(array_slice($rows,($issuePage-1)*100,100) as $row) {
            $url=$row['object_id']?get_edit_post_link($row['object_id']):'';
            $label=esc_html((string)$row['post_type'].' #'.(int)$row['object_id']);
            if($url)$label='<a href="'.esc_url($url).'">'.$label.'</a>';
            $evidence=(array)($row['source_evidence']??array());
            if (!empty($evidence['term_id']) && !empty($evidence['taxonomy'])) {
                $termUrl=get_edit_term_link(absint($evidence['term_id']),sanitize_key($evidence['taxonomy']));
                $sourceLabel='Source: '.sanitize_key($evidence['taxonomy']).' #'.absint($evidence['term_id']).
                    ' / '.sanitize_key($evidence['level']??'unverified');
                if (is_string($termUrl) && $termUrl)
                    $label.='<p class="description"><a href="'.esc_url($termUrl).'">'.esc_html($sourceLabel).'</a></p>';
                else $label.='<p class="description">'.esc_html($sourceLabel).'</p>';
            }
            $qualityDetails='';
            if(($row['source']??'')==='mad4b-brand-experience') {
                $ctx=(array)($row['source_evidence']??[]);
                $authority=sanitize_key((string)($ctx['authority_level']??'provisional'));
                $guidance=sanitize_text_field((string)($ctx['review_guidance']??''));
                $qualityDetails='<details style="margin-top:7px;max-width:590px"><summary>Brand Context &amp; review evidence · '
                    .esc_html($authority).'</summary>';
                $qualityDetails.='<p><strong>Brand:</strong> '.esc_html((string)($ctx['brand_scope']['brand_name']??'unavailable'))
                    .' · <strong>Context revision:</strong> '.(int)($ctx['brand_scope']['revision']??0).'</p>';
                if($guidance!=='')$qualityDetails.='<p>'.esc_html($guidance).'</p>';
                $request=(array)($row['content_job_request']??[]);
                if($request)$qualityDetails.='<p><strong>Content Job intake:</strong> '
                    .esc_html((string)($request['status']??'PRIORITY_INTAKE'))
                    .' · publication not authorized</p>';
                if(!empty($ctx['media_candidates']) && is_array($ctx['media_candidates'])) {
                    $qualityDetails.='<p><a href="'.esc_url(admin_url('admin.php?page=dfsb-media-lab'))
                        .'">Open Media Lab</a></p><div style="display:flex;flex-wrap:wrap;gap:8px">';
                    foreach(array_slice($ctx['media_candidates'],0,4) as $img) {
                        $url=esc_url((string)($img['url']??''));
                        if($url==='')continue;
                        $qualityDetails.='<a href="'.$url.'" target="_blank" rel="noopener noreferrer"><img alt="Media review candidate" loading="lazy" src="'
                            .$url.'" style="width:92px;height:62px;object-fit:cover;border-radius:5px"></a>';
                    }
                    $qualityDetails.='</div>';
                }
                $qualityDetails.='</details>';
            }
            echo '<tr><td>'.esc_html($row['severity']).'</td><td>'.$label.'</td><td>'
                .esc_html($row['label']).$qualityDetails.'</td><td>'.esc_html($row['status']).'</td><td>';
            if(!empty($row['board_task_id']))echo 'Task #'.(int)$row['board_task_id'];
            elseif(($row['status']??'')!=='resolved' && current_user_can('manage_options')) {
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                wp_nonce_field('mad4b_readiness_board_sync');
                echo '<input type="hidden" name="action" value="mad4b_readiness_board_sync">';
                echo '<input type="hidden" name="issue_id" value="'.esc_attr($row['id']).'">';
                echo '<button class="button" '.disabled(empty(MAD4B_SCP_Readiness_Workflow_Options::settings()['board_tasks'])||!class_exists('MAD4B_SCP_Policy')||!MAD4B_SCP_Policy::can_mutate(),true,false).'>Create Board Task</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        if($totalPages>1){
            echo '<p class="tablenav-pages">Page '.(int)$issuePage.' of '.(int)$totalPages.' ';
            foreach(array('Previous'=>$issuePage-1,'Next'=>$issuePage+1) as $label=>$target){
                if($target<1||$target>$totalPages)continue;
                $url=add_query_arg(array('page'=>self::PAGE,'readiness_status'=>$requestedStatus,
                    'readiness_severity'=>$requestedSeverity,'readiness_page'=>$target),admin_url('admin.php'));
                echo '<a class="button" style="margin-left:8px" href="'.esc_url($url).'">'.esc_html($label).'</a>';
            }
            echo '</p>';
        }
        echo '</div>';
    }
}
