<?php
/**
 * MAD4B Content Readiness & Alerts.
 * Site-neutral issue registry for existing WordPress content owners.
 * No writes on GET or plugin bootstrap; no automatic external notifications.
 */
defined('ABSPATH') || exit;
require_once __DIR__.'/class-mad4b-scp-readiness-workflow-options.php';
require_once __DIR__.'/class-mad4b-scp-content-completeness.php';
require_once __DIR__.'/class-mad4b-scp-content-readiness-admin-view.php';

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
                    $issues[]=self::issue($type,$id,'media_source_unverified','medium','Missing approved media in source field '.$media.'; import licensed media or review the source');
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
                        'The same media gallery is reused across multiple content records; verify licensing and source');
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
                $valid=$wpmlPriorityValid && is_array($row) && ($dfsb||$localization||$performance) &&
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
                $issues[]=$accepted;
            }
        }
        $completeness=MAD4B_SCP_Content_Completeness::discover();
        if(empty($completeness['complete']))$complete=false;
        else $issues=array_merge($issues,(array)$completeness['issues']);
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
        $e=apply_filters('mad4b/content_readiness/source_backed_hotel_pickup',false,$id,$type);
        return is_array($e) && !empty($e['source_meta_sha256']) &&
            ($e['fixed_origin_required']??true)===false ? $e : false;
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
        $originType=sanitize_key((string)apply_filters('mad4b/content_readiness/source_origin_post_type',''));
        if(!$originType || !post_type_exists($originType))$originType='';
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
            if($code==='pickup_guest_location_capture_unverified' && $originType && ($row['post_type']??'')===$originType){
                $priorKey=hash('sha256',get_current_blog_id().'|'.$originType.'|'.absint($row['object_id']).'|origin_missing');
                if(!empty($old[$priorKey]['content_job_id']) &&
                   ($row['source_evidence']['research_job_id']??'')===$old[$priorKey]['content_job_id']){
                    $new[$key]['content_job_id']=$old[$priorKey]['content_job_id'];
                    $new[$key]['reclassified_from_origin_issue']=$priorKey;
                    $new[$key]['research_evidence_reused']=true;
                }
            }
            // Existing Issue Ledger is the only source of origin research intake.
            // Native ContentJob creation remains governed by its own actor gate.
            if ($code==='origin_missing' && $originType && ($row['post_type']??'')===$originType) {
                $post_id=absint($row['object_id']??0);
                $canonical_id=self::canonical($post_id,$originType);
                $site_uuid=class_exists('MAD4B_SCP_Site_Profile')
                    ? (string) MAD4B_SCP_Site_Profile::site_uuid() : '';
                if ($post_id && $canonical_id && $site_uuid
                    && get_post_status($post_id)==='publish') {
                    $term_ids=wp_get_object_terms($post_id,'destinations',array('fields'=>'ids'));
                    $term_ids=is_wp_error($term_ids) ? array() : array_map('intval',(array)$term_ids);
                    sort($term_ids,SORT_NUMERIC);
                    $fingerprint=hash('sha256',$site_uuid.'|'.$originType.'|'.$canonical_id.'|origin_missing');
                    $source_sha=hash('sha256',wp_json_encode(array(
                        'canonical_id'=>$canonical_id,
                        'origin_term'=>get_post_meta($post_id,'tour_origin_location_term',true),
                        'destination_terms'=>$term_ids,
                    ),JSON_UNESCAPED_SLASHES));
                    $new[$key]['content_job_request']=array(
                        'contract'=>'mad4b.readiness-contentjob-intake.v1',
                        'status'=>!empty($previous['content_job_id']) ? 'LINKED' : 'PRIORITY_INTAKE',
                        'priority'=>85, 'source_issue_id'=>$key,
                        'gap_code'=>'origin_missing','post_type'=>$originType,
                        'source_post_id'=>$post_id,'canonical_post_id'=>$canonical_id,
                        'site_uuid'=>$site_uuid,'content_type'=>'research_observation',
                        'source_sha256'=>$source_sha,'deduplication_key'=>$fingerprint,
                        'reason'=>'Resolve the canonical origin source using official evidence; distinguish pickup from cruise boarding, and verify coordinates before any writeback.',
                        'requires_governed_content_job_admission'=>true,
                        'publication_authorized'=>false,'price_inference_authorized'=>false
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
        $type=$issue['post_type']??'';$code=$issue['code']??'';
        if(str_starts_with($code,'loc_') && ($issue['source']??'')==='mad4b-elementor-localization')
            return has_filter('mad4b/content_readiness/external_issues')!==false;
        if($type==='site' && str_starts_with($code,'perf_') &&
           ($issue['source']??'')==='mad4b-live-performance')
            return class_exists('MAD4B_Live_Render_Blocking_Monitor_V1') &&
                MAD4B_Live_Render_Blocking_Monitor_V1::report()['fresh'];
        if($type==='site' && str_starts_with($code,'dfsb_')){
            // Never resolve a source issue when its scanner is disconnected.
            return has_filter('mad4b/content_readiness/external_issues')!==false;
        }
        if(str_starts_with($code,'ccr_')) {
            $settings=MAD4B_SCP_Content_Completeness::settings();
            if(empty($settings['enabled'])||($issue['source']??'')!=='content-completeness')return false;
            $rule_id=(string)($issue['source_evidence']['rule_id']??'');
            foreach($settings['rules'] as $rule)
                if(($rule['id']??'')===$rule_id)return true;
            return false;
        }
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
            'read_only'=>true,
        ));
    }
    public static function sort_issues(array &$rows):void {
        usort($rows,array(__CLASS__,'priority_compare'));
    }
    private static function priority_compare($a,$b) {
        $sa=(($a['status']??'open')==='resolved')?1:0;
        $sb=(($b['status']??'open')==='resolved')?1:0;
        if($sa!==$sb)return $sa<=>$sb;
        $priority=array('critical'=>0,'high'=>1,'medium'=>2,'low'=>3);
        $pa=$priority[$a['severity']??'low']??4;
        $pb=$priority[$b['severity']??'low']??4;
        if($pa!==$pb)return $pa<=>$pb;
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
        $result=array();
        foreach((array)get_option(self::STORE,array()) as $row) {
            if(!is_array($row))continue;
            $state=(($row['status']??'open')==='resolved')?'resolved':'open';
            if($status!=='all' && $state!==$status)continue;
            if($severity && ($row['severity']??'')!==$severity)continue;
            if($type && ($row['post_type']??'')!==$type)continue;
            if($code && ($row['code']??'')!==$code)continue;
            $e=(array)($row['source_evidence']??array());
            $result[]=array(
                'issue_id'=>(string)($row['id']??''),
                'post_type'=>(string)($row['post_type']??''),
                'object_id'=>absint($row['object_id']??0),
                'code'=>(string)($row['code']??''),
                'severity'=>(string)($row['severity']??'medium'),
                'label'=>(string)($row['label']??''),
                'status'=>$state,
                'first_seen'=>$row['first_seen']??null,
                'last_seen'=>$row['last_seen']??null,
                'resolved_at'=>$row['resolved_at']??null,
                'board_task_id'=>absint($row['board_task_id']??0)?:null,
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
        $settings['scheduled_scan_enabled']=isset($_POST['scheduled_scan_enabled']);
        $settings['scheduled_scan_interval_minutes']=max(15,min(1440,absint($_POST['scheduled_scan_interval_minutes']??60)));
        $settings['check_wpml']=isset($_POST['check_wpml']);
        $settings['check_cron']=isset($_POST['check_cron']);
        $settings['cron_max_age_seconds']=86400;
        update_option(self::POLICY,$settings,false);
        self::audit('rule_update',array('post_types'=>$settings['post_types']));
        wp_safe_redirect(admin_url('admin.php?page='.self::PAGE));exit;
    }
    public static function sync_board($issue_id) {
        if(!current_user_can('manage_options'))return new WP_Error('mad4b_readiness_permission_denied');
        if(!class_exists('MAD4B_SCP_Policy') || !MAD4B_SCP_Policy::can_mutate())
            return new WP_Error('mad4b_readiness_write_gate_closed');
        $workflow=MAD4B_SCP_Readiness_Workflow_Options::settings();
        if(empty($workflow['board_tasks']))
            return new WP_Error('mad4b_readiness_board_sync_disabled');
        $board=$workflow['board_id'];$stage=$workflow['stage_id'];
        if(!MAD4B_SCP_Readiness_Workflow_Options::validate_board_stage($board,$stage) ||
           !class_exists('FluentBoards\\App\\Services\\TaskService'))
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
        $taskData=array('title'=>$subject,'stage_id'=>$stage,'source'=>'mad4b-readiness',
            'source_id'=>$issue_id,
            'description'=>'Readiness Issue: '.$issue_id."\n".esc_url_raw((string)$link).'  '.sanitize_text_field($row['label']));
        $assigned=MAD4B_SCP_Readiness_Workflow_Options::assigned_user($row);
        if($assigned)$taskData['assignees']=array($assigned);
        try {
            $task=(new \FluentBoards\App\Services\TaskService())->createTask($taskData,$board);
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
        MAD4B_SCP_Content_Readiness_Admin_View::render();
    }

}

