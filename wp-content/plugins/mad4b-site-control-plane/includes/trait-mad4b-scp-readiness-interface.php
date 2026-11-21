<?php
defined( 'ABSPATH' ) || exit;

/**
 * Original Content Readiness REST, admin page, and board-sync methods.
 * Keeps existing permission callbacks, nonces and source-scoped mutations.
 */
trait MAD4B_SCP_Readiness_Interface_Trait {
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
