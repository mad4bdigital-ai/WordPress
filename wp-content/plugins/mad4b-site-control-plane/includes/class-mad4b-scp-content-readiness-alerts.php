<?php
/**
 * MAD4B Content Readiness & Alerts.
 * Site-neutral issue registry for existing WordPress content owners.
 * No writes on GET or plugin bootstrap; no automatic external notifications.
 */
defined('ABSPATH') || exit;

final class MAD4B_SCP_Content_Readiness_Alerts {
    const VERSION = '1.0.0';
    const PAGE = 'mad4b-content-readiness-alerts';
    const STORE = 'mad4b_content_readiness_issues_v1';
    const POLICY = 'mad4b_content_readiness_rules_v1';
    const AUDIT = 'mad4b_content_readiness_audit_v1';
    const MAX_POSTS = 3000;
    const MAX_ISSUES = 3000;

    public static function boot() {
        add_action('admin_menu', array(__CLASS__, 'menu'), 30);
        add_action('admin_post_mad4b_readiness_scan', array(__CLASS__, 'handle_scan'));
        add_action('admin_post_mad4b_readiness_board_sync', array(__CLASS__, 'handle_board_sync'));
        add_action('admin_post_mad4b_readiness_save_rules', array(__CLASS__, 'handle_save_rules'));
        add_action('admin_bar_menu', array(__CLASS__, 'admin_bar'), 95);
        add_action('rest_api_init', array(__CLASS__, 'register_rest_routes'));
        add_action('admin_notices', array(__CLASS__, 'admin_notice'));
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
                        $issues[]=self::issue($type,$id,'wpml_language_unregistered','high','Published item has no WPML language record');
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
                if ($originIsMissing && !$invalidOrigin)
                    $issues[]=self::issue($type,$id,'origin_missing','medium','Origin field is empty');
                $taxonomy=$cfg['location_taxonomies'][$type]??'';
                if ($taxonomy && taxonomy_exists($taxonomy)) {
                    $terms=wp_get_object_terms($id,$taxonomy,array('fields'=>'ids'));
                    if (is_wp_error($terms) || !$terms)
                        $issues[]=self::issue($type,$id,'location_unlinked','medium','Source taxonomy has no terms');
                }
                $media=$cfg['media_fields'][$type]??'';
                if ($media && !self::has_media($id,$media))
                    $issues[]=self::issue($type,$id,'media_source_unverified','medium','No usable value found in configured media source');
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
                $expected=hash('sha256',get_current_blog_id().'|site|0|'.$code);
                $valid=is_array($row) && preg_match('/^dfsb_[a-z0-9_]+$/D',$code) &&
                    hash_equals($expected,$id) &&
                    ($row['source']??'')==='dynamic-filter-seo-bridge' &&
                    ($row['post_type']??'')==='site' && (int)($row['object_id']??-1)===0 &&
                    in_array($row['severity']??'',array('critical','high','medium','low'),true) &&
                    is_string($row['label']??null) &&
                    trim($row['label'])!=='' && strlen($row['label'])<=240 &&
                    !isset($row['status']) && !isset($row['board_task_id']) &&
                    !isset($externalIds[$id]);
                if (!$valid) {$complete=false;break;}
                $externalIds[$id]=true;
                $issues[]=array(
                    'id'=>$id,'post_type'=>'site','object_id'=>0,'code'=>$code,
                    'severity'=>$row['severity'],'label'=>sanitize_text_field($row['label']),
                    'source'=>'dynamic-filter-seo-bridge'
                );
            }
        }
        return array('contract'=>'mad4b.content-readiness.discovery.v1','complete'=>$complete,
            'post_counts'=>$counts,'scanned'=>$scanned,'issues'=>$issues);
    }
    public static function reconcile($scan) {
        if (!current_user_can('manage_options')) return new WP_Error('mad4b_readiness_permission_denied');
        if (!is_array($scan) || empty($scan['complete']) || !isset($scan['issues']))
            return new WP_Error('mad4b_readiness_incomplete_scan');
        $old=(array)get_option(self::STORE,array());$now=gmdate('c');$new=$old;
        $seen=array();
        foreach ($scan['issues'] as $row) {
            $key=$row['id'];$seen[$key]=true;
            $previous=$new[$key]??array();
            $status=($previous['status']??'')==='resolved'?'open':($previous['status']??'open');
            $new[$key]=array_merge($previous,$row,
                array('status'=>$status,'first_seen'=>$previous['first_seen']??$now,'last_seen'=>$now,
                    'reopens'=>($previous['reopens']??0)+(($previous['status']??'')==='resolved'?1:0)));
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
        self::audit('scan',['scanned'=>$scan['scanned'],'current'=>count($scan['issues'])]);
        return self::summary($new);
    }
    private static function rule_enabled_for($issue,$cfg) {
        $type=$issue['post_type']??'';$code=$issue['code']??'';
        if($type==='site' && str_starts_with($code,'dfsb_')){
            // Never resolve a source issue when its scanner is disconnected.
            return has_filter('mad4b/content_readiness/external_issues')!==false;
        }
        if($code==='cron_overdue')return (bool)$cfg['check_cron'];
        if($code==='form_submissions_unread')return !empty($cfg['form_id']);
        if(!in_array($type,$cfg['post_types'],true))return false;
        switch($code) {
            case 'wpml_language_unregistered':return (bool)$cfg['check_wpml'];
            case 'origin_missing':return !empty($cfg['origin_fields'][$type]);
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
    /** Admin/editor read endpoints; intentionally no REST mutation routes. */
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
    }
    public static function rest_permission() {
        return current_user_can('edit_others_posts');
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
    private static function priority_compare($a,$b) {
        $sa=(($a['status']??'open')==='resolved')?1:0;
        $sb=(($b['status']??'open')==='resolved')?1:0;
        if($sa!==$sb)return $sa<=>$sb;
        $priority=array('critical'=>0,'high'=>1,'medium'=>2,'low'=>3);
        $pa=$priority[$a['severity']??'low']??4;
        $pb=$priority[$b['severity']??'low']??4;
        if($pa!==$pb)return $pa<=>$pb;
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
        add_submenu_page('mad4b-control-plane','Content Readiness & Alerts',
            'Content Readiness','edit_others_posts',self::PAGE,array(__CLASS__,'render'));
    }
    public static function admin_bar($bar) {
        if (!is_admin_bar_showing() || !current_user_can('edit_others_posts'))return;
        $counts=self::summary();if(!$counts['open'])return;
        $bar->add_node(array('id'=>'mad4b-content-alerts','title'=>'Content Alerts: '.(int)$counts['open'],
            'href'=>admin_url('admin.php?page='.self::PAGE)));
    }
    public static function admin_notice() {
        if (!current_user_can('edit_others_posts') || get_current_screen() === null) return;
        if (get_current_screen()->id !== 'dashboard') return;
        $counts=self::summary(); if (!$counts['critical'])return;
        echo '<div class="notice notice-warning"><p><strong>Content Readiness:</strong> '.(int)$counts['critical'].' critical open issues. <a href="'.esc_url(admin_url('admin.php?page='.self::PAGE)).'">Review issues</a></p></div>';
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
        $rules=self::rules();$board=$rules['board_id'];$stage=$rules['stage_id'];
        if(!$board || !$stage || !class_exists('FluentBoards\\App\\Services\\TaskService'))
            return new WP_Error('mad4b_readiness_board_not_configured');
        $issues=(array)get_option(self::STORE,array());
        $row=$issues[$issue_id]??null;
        if(!$row || ($row['status']??'')==='resolved')return new WP_Error('mad4b_readiness_issue_not_open');
        if(!empty($row['board_task_id']))return array('status'=>'already_linked','task_id'=>(int)$row['board_task_id']);
        // Native Fluent Boards validates ownership of stage and board.
        $subject='['.strtoupper($row['severity']).'] '.$row['code'].' #'.$row['object_id'];
        $link=$row['object_id']?get_edit_post_link($row['object_id'],'raw'):'';
        $taskData=array('title'=>$subject,'stage_id'=>$stage,
            'description'=>esc_url_raw((string)$link).'  '.sanitize_text_field($row['label']));
        if($rules['assignee_id'] && get_user_by('id',$rules['assignee_id']))
            $taskData['assignees']=array($rules['assignee_id']);
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
        echo '<p>Source-backed issue ledger. Scan runs only when an administrator requests it. No external notifications or tasks are created automatically.</p>';
        if($flash)echo '<div class="notice notice-info"><pre>'.esc_html(wp_json_encode($flash)).'</pre></div>';
        echo '<h2>Open: '.(int)$counts['open'].' | Critical: '.(int)$counts['critical'].' | High: '.(int)$counts['high'].' | Resolved: '.(int)$counts['resolved'].'</h2>';
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
        foreach(array('media_reuse_threshold'=>'Shared gallery review threshold (0 disables)', 'board_id'=>'Fluent Boards ID','stage_id'=>'Board stage ID','assignee_id'=>'Assigned user ID', 'form_id'=>'Fluent Forms ID', 'form_unread_days'=>'Unread follow-up threshold (days)') as $field=>$label)
            echo '<p><label>'.esc_html($label).' <input type="number" min="0" name="'.esc_attr($field).'" value="'.(int)$rules[$field].'"></label></p>';
        foreach(array('check_wpml'=>'Detect missing WPML language','check_cron'=>'Detect overdue WP-Cron') as $field=>$label)
            echo '<p><label><input type="checkbox" name="'.esc_attr($field).'" '.checked($rules[$field],true,false).'>'.esc_html($label).'</label></p>';
        submit_button('Save rules');echo '</form>';
        }
        echo '<h2>Issues</h2><table class="widefat striped"><thead><tr><th>Severity</th><th>Source</th><th>Issue</th><th>Status</th><th>Fluent Boards</th></tr></thead><tbody>';
        $rows=array_values($issues);
        // Operational priorities must not be hidden behind hundreds of
        // low-severity media reviews in the first 100 rows.
        usort($rows,array(__CLASS__,'priority_compare'));
        foreach(array_slice($rows,0,100) as $row) {
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
            echo '<tr><td>'.esc_html($row['severity']).'</td><td>'.$label.'</td><td>'.esc_html($row['label']).'</td><td>'.esc_html($row['status']).'</td><td>';
            if(!empty($row['board_task_id']))echo 'Task #'.(int)$row['board_task_id'];
            elseif(($row['status']??'')!=='resolved' && current_user_can('manage_options')) {
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                wp_nonce_field('mad4b_readiness_board_sync');
                echo '<input type="hidden" name="action" value="mad4b_readiness_board_sync">';
                echo '<input type="hidden" name="issue_id" value="'.esc_attr($row['id']).'">';
                echo '<button class="button" '.disabled(!class_exists('MAD4B_SCP_Policy')||!MAD4B_SCP_Policy::can_mutate(),true,false).'>Create Board Task</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
