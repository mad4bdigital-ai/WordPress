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
require_once __DIR__.'/trait-mad4b-scp-readiness-reconciliation.php';
require_once __DIR__.'/trait-mad4b-scp-readiness-interface.php';

final class MAD4B_SCP_Content_Readiness_Alerts {
    use MAD4B_SCP_Readiness_Reconciliation_Trait;
    use MAD4B_SCP_Readiness_Interface_Trait;
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
                    'media_gallery_shared_across_tours'=>'high',
                    'media_presentation_images_shared'=>'high',
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
                $valid=$wpmlPriorityValid && is_array($row) && ($dfsb||$localization||$performance||$pricing||$structural||$ratePlan||$gallery||$gttd||$companyPolicy||$companyTourPublication||$faqGap||$homeCopy||$elementorCopy||$mediaOrigin) &&
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
    // Durable source-scoped reconciliation lives in the bound trait.

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
    // REST, board, and admin surface methods live in the bound interface trait.

}
