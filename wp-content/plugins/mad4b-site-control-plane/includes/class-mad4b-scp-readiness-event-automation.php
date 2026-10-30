<?php
/**
 * MAD4B event-driven Readiness Outbox, independent of Fluent Boards/Bit Flows/SMTP.
 * Staging preparation mode: source-event capture is automatic, external dispatch
 * is *always locked*. No historical issue replay is ever performed.
 */
defined('ABSPATH') || exit;

final class MAD4B_SCP_Readiness_Event_Automation {
    const NS='mad4b-content-readiness/v1';
    const STORE='mad4b_readiness_email_policy_v1';
    const AUDIT='mad4b_readiness_email_policy_audit_v1';
    const TABLE='mad4b_readiness_event_outbox';
    const EVENT_HOOK='mad4b/readiness/events_recorded';
    const CAP='mad4b_manage_company_operations';

    public static function boot() {
        add_action(self::EVENT_HOOK,array(__CLASS__,'capture'),10,1);
        add_action('rest_api_init',array(__CLASS__,'routes'));
        add_action('admin_post_mad4b_readiness_email_draft_save',array(__CLASS__,'handle_save'));
    }
    public static function allowed() {
        return current_user_can('manage_options') || current_user_can(self::CAP);
    }
    public static function disabled() {return true;}
    public static function table() {
        global $wpdb;
        return $wpdb->prefix.self::TABLE;
    }
    public static function installed() {
        global $wpdb;
        $table=self::table();
        return $table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table));
    }
    public static function schema_prepare() {
        if(wp_get_environment_type()!=='staging')
            return new WP_Error('readiness_schema_staging_only','Staging only.',array('status'=>403));
        if(self::installed())return array('status'=>'existing','table'=>self::table());
        global $wpdb;
        $table=self::table();
        $charset=$wpdb->get_charset_collate();
        $sql="CREATE TABLE `$table` (
          id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
          event_id char(64) NOT NULL,
          site_id bigint(20) unsigned NOT NULL,
          event_type varchar(24) NOT NULL,
          group_key varchar(32) NOT NULL,
          language_code varchar(16) NOT NULL,
          language_status varchar(24) NOT NULL,
          severity varchar(16) NOT NULL,
          batch_key char(64) NOT NULL,
          issue_id char(64) NOT NULL,
          occurred_at datetime NOT NULL,
          captured_at datetime NOT NULL,
          status varchar(16) NOT NULL DEFAULT 'held',
          PRIMARY KEY  (id),
          UNIQUE KEY event_id (event_id),
          KEY batch_status (batch_key,status),
          KEY site_status (site_id,status),
          KEY status_time (status,captured_at)
        ) $charset;";
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        return self::installed()?array('status'=>'created','table'=>$table):
            new WP_Error('readiness_schema_failure','Outbox table creation not confirmed.',array('status'=>500));
    }
    public static function event_language($issue) {
        $e=is_array($issue['source_evidence']??null)?$issue['source_evidence']:array();
        $l=sanitize_key((string)($e['language']??''));
        if($l && $l!=='und')return array('code'=>$l,'status'=>'source_evidence');
        $id=absint($issue['object_id']??0);
        $type=(string)($issue['post_type']??'site');
        if(!$id||$type==='site')return array('code'=>'site','status'=>'site_wide');
        if(defined('ICL_SITEPRESS_VERSION')) {
            if(str_starts_with($type,'term:')){
                $l=apply_filters('wpml_element_language_code',null,
                    array('element_id'=>$id,'element_type'=>'tax_'.substr($type,5)));
            } else {
                $details=apply_filters('wpml_post_language_details',null,$id);
                $l=is_array($details)?($details['language_code']??''):null;
            }
            $l=is_string($l)?sanitize_key($l):'';
            if($l)return array('code'=>$l,'status'=>'wpml_resolved');
        }
        return array('code'=>'und','status'=>'needs_source_review');
    }
    public static function normalize_event($e) {
        if(!is_array($e))return null;
        $id=(string)($e['event_id']??'');
        $issue=(string)($e['issue_id']??'');
        $kind=sanitize_key((string)($e['event']??''));
        $group=sanitize_key((string)($e['group']??''));
        $severity=sanitize_key((string)($e['severity']??''));
        if(!preg_match('/^[a-f0-9]{64}$/D',$id)||
           !preg_match('/^[a-f0-9]{64}$/D',$issue)||
           !in_array($kind,array('discovered','resolved','reopened'),true)||
           !array_key_exists($group,MAD4B_SCP_Readiness_Workflow_Options::GROUPS)||
           !in_array($severity,array('critical','high','medium','low'),true))return null;
        $journal=(array)get_option(MAD4B_SCP_Readiness_Integrations::STORE,array());
        $known=false;
        foreach((array)($journal['events']??array()) as $receipt){
            if(($receipt['event_id']??'')===$id && ($receipt['issue_id']??'')===$issue &&
               ($receipt['event']??'')===$kind){$known=true;break;}
        }
        $sources=(array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,array());
        $source=$sources[$issue]??array();
        if(!is_array($source))$source=array();
        if(!$known && $source) {
            // The UI event journal intentionally keeps only its last 300 rows.
            // Never drop other verified transitions from a high-volume scan:
            // source revision plus transition fingerprint is authoritative.
            $cycle=absint($source['reopens']??0);
            $expected=hash('sha256',$issue.'|'.$kind.'|'.$cycle);
            $status=(string)($source['status']??'');
            $compatible=$kind==='resolved' ? $status==='resolved' : $status==='open';
            $known=$compatible && hash_equals($expected,$id);
        }
        if(!$known)return null;
        if(!$source)$source=array('object_id'=>$e['object_id']??0,'post_type'=>$e['object_type']??'site');
        $lang=self::event_language($source);
        $when=strtotime((string)($e['at']??'')) ?: time();
        // Date-bound site/group/severity/language digest, capped at one planned
        // notification per cohort and day; never one outgoing email per gap.
        $utc=gmdate('Y-m-d',$when);
        $site=get_current_blog_id();
        $key=hash('sha256',$site.'|'.$utc.'|'.$group.'|'.$severity.'|'.$lang['code']);
        return array('event_id'=>$id,'site_id'=>$site,'event_type'=>$kind,
            'group_key'=>$group,'language_code'=>$lang['code'],
            'language_status'=>$lang['status'],'severity'=>$severity,
            'batch_key'=>$key,'issue_id'=>$issue,
            'occurred_at'=>gmdate('Y-m-d H:i:s',$when),
            'captured_at'=>gmdate('Y-m-d H:i:s'),'status'=>'held');
    }
    public static function capture($events) {
        if(!self::installed()||!is_array($events)||!$events)return array('inserted'=>0,'skipped'=>0);
        global $wpdb;
        $written=0;$skipped=0;$table=self::table();
        foreach(array_slice($events,0,3000) as $event) {
            $row=self::normalize_event($event);
            if(!$row){$skipped++;continue;}
            // Source event ID is globally unique within the company/site ledger.
            // INSERT IGNORE is an idempotent claim; no existing receipt is changed.
            $fields=array_keys($row);
            $placeholders=implode(',',array_fill(0,count($fields),'%s'));
            $names=implode(',',array_map(static fn($s)=>'`'.$s.'`',$fields));
            $sql="INSERT IGNORE INTO `$table` ($names) VALUES ($placeholders)";
            $result=$wpdb->query($wpdb->prepare($sql,...array_values($row)));
            if($result===1)$written++;else $skipped++;
        }
        return array('inserted'=>$written,'skipped'=>$skipped,'email_sent'=>0);
    }
    public static function defaults() {
        return array('schema'=>1,'state'=>'draft',
            'channel'=>'fluent_smtp_via_wp_mail','delivery_enabled'=>false,
            'recipient_user_ids'=>array(),'sender'=>'configured_wordpress_mailer',
            'subject'=>'[{severity}] {group} — {event_count} readiness changes',
            'body'=>"MAD4B Content Readiness alert\nSite: {site}\nScope: {group}\nLanguage: {language}\nSeverity: {severity}\nEvents: {event_count}\nReview: {dashboard_url}\n\nNo customer information is included.",
            'digest_window_minutes'=>60,'cooldown_minutes'=>60,'max_recipients'=>5,
            'max_messages_per_cycle'=>1,'first_cycle'=>'quarantine',
            'require_approved_staff'=>true);
    }
    public static function normalize_policy($input) {
        $default=self::defaults();
        if(!is_array($input)||count($input)!==count($default)||
           array_diff(array_keys($input),array_keys($default))||
           ($input['schema']??null)!==1||$input['state']!=='draft'||
           $input['channel']!=='fluent_smtp_via_wp_mail'||$input['delivery_enabled']!==false||
           $input['sender']!=='configured_wordpress_mailer'||
           $input['max_recipients']!==5||$input['max_messages_per_cycle']!==1||
           $input['first_cycle']!=='quarantine'||$input['require_approved_staff']!==true||
           !is_int($input['digest_window_minutes'])||$input['digest_window_minutes']<15||
           $input['digest_window_minutes']>1440||!is_int($input['cooldown_minutes'])||
           $input['cooldown_minutes']<60||$input['cooldown_minutes']>10080||
           !is_array($input['recipient_user_ids'])||count($input['recipient_user_ids'])>5)
            return new WP_Error('readiness_email_policy_invalid','Invalid or unsafe email policy.',array('status'=>422));
        if(!is_string($input['subject'])||strlen($input['subject'])>180||$input['subject']===''||
           !is_string($input['body'])||strlen($input['body'])>1600||$input['body']==='')
            return new WP_Error('readiness_email_template_invalid','Email template invalid.',array('status'=>422));
        $tokens=array('severity','group','event_count','site','language','dashboard_url');
        foreach(array($input['subject'],$input['body']) as $s) {
            if(preg_match('/<[^>]+>|[\x00-\x08\x0B\x0C\x0E-\x1F]/',$s))
                return new WP_Error('readiness_email_html_forbidden','HTML and controls are not allowed.',array('status'=>422));
            preg_match_all('/\{([^}]+)\}/',$s,$parts);
            foreach($parts[1] as $name)if(!in_array($name,$tokens,true))
                return new WP_Error('readiness_email_token_unknown','Unknown template token.',array('status'=>422));
        }
        $seen=array();
        foreach($input['recipient_user_ids'] as $id){
            if(!is_int($id)||$id<=0||isset($seen[$id]))
                return new WP_Error('readiness_email_recipient_invalid','Invalid recipient.',array('status'=>422));
            $seen[$id]=true;
            $user=get_user_by('id',$id);
            if(!$user||!is_email($user->user_email)||
               !class_exists('MAD4B_SCP_Company_Master_Admin')||
               !MAD4B_SCP_Company_Master_Admin::managedStaff($id))
                return new WP_Error('readiness_email_recipient_outside_company','Recipient must be enrolled company staff.',array('status'=>403));
        }
        return $input;
    }
    public static function policy() {
        $saved=get_option(self::STORE,null);
        if(!is_array($saved))return self::defaults();
        $valid=self::normalize_policy($saved);
        return is_wp_error($valid)?self::defaults():$valid;
    }
    public static function revision($policy=null) {
        return hash('sha256',wp_json_encode($policy??self::policy(),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
    public static function cohort_rows($limit=30) {
        if(!self::installed())return array();
        global $wpdb;
        $limit=max(1,min(100,absint($limit)));
        $table=self::table();
        return (array)$wpdb->get_results($wpdb->prepare(
          "SELECT batch_key, group_key, language_code, language_status, severity,
           event_type, COUNT(*) AS event_count, MAX(captured_at) AS last_event
           FROM `$table` WHERE site_id=%d AND status=%s
           GROUP BY batch_key,group_key,language_code,language_status,severity,event_type
           ORDER BY last_event DESC LIMIT %d",get_current_blog_id(),'held',$limit),ARRAY_A);
    }
    public static function inactive_flow_info() {
        if(!class_exists('BitApps\\Pi\\Services\\FlowService'))
            return array('found'=>false,'flow_id'=>0,'count'=>0);
        global $wpdb;
        $flows=$wpdb->prefix.'bit_pi_flows';
        $nodes=$wpdb->prefix.'bit_pi_flow_nodes';
        $rows=$wpdb->get_results($wpdb->prepare(
           "SELECT f.id,n.field_mapping FROM {$nodes} n JOIN {$flows} f ON f.id=n.flow_id
             WHERE n.app_slug=%s AND n.machine_slug=%s AND f.is_active=%d LIMIT 100",
           'wordPressActionHooks','addAction',0),ARRAY_A);
        $ids=array();
        foreach((array)$rows as $row){
            $mapping=json_decode((string)($row['field_mapping']??''),true);
            if(($mapping['configs']['hook-name']['value']??'')===MAD4B_SCP_Readiness_PreActivation::HOOK)
                $ids[]=(int)$row['id'];
        }
        return array('found'=>count($ids)>0,'flow_id'=>$ids?min($ids):0,'count'=>count($ids));
    }
    public static function stats() {
        global $wpdb;
        $db=array('total'=>0,'held'=>0,'delivered'=>0);
        if(self::installed()) {
            $table=self::table();
            $rows=$wpdb->get_results($wpdb->prepare(
              "SELECT status,COUNT(*) AS amount FROM `$table` WHERE site_id=%d GROUP BY status",
              get_current_blog_id()),ARRAY_A);
            foreach((array)$rows as $row){
                $n=(int)$row['amount'];$db['total']+=$n;$db[$row['status']]=$n;
            }
        }
        $scopes=MAD4B_SCP_Readiness_Scopes::build();
        $und=array();$siteWide=0;$missingSource=0;
        foreach($scopes as $scope)if($scope['language']==='und'){
            $group=$scope['group'];
            $und[$group]=($und[$group]??0)+$scope['total'];
            if($scope['content_type']==='site')$siteWide+=$scope['total'];
            else $missingSource+=$scope['total'];
        }
        return array('contract'=>'mad4b.readiness.event-automation.v1',
            'mode'=>'capture_and_preview','schema_installed'=>self::installed(),
            'external_mail_locked'=>true,'bitflows_locked'=>true,
            'event_outbox'=>$db,'email_policy_revision'=>self::revision(),
            'email_policy'=>self::policy(),'language_review_by_group'=>$und,
            'site_wide_not_language_specific'=>$siteWide,
            'missing_wpml_source_language'=>$missingSource,
            'bitflows_blueprint'=>array('trigger'=>'WordPress Action Hooks',
                'hook'=>MAD4B_SCP_Readiness_PreActivation::HOOK,
                'native_inactive_flow'=>self::inactive_flow_info(),
                'activation'=>'not_allowed'),
            'fluent_smtp'=>array('active'=>in_array('fluent-smtp/fluent-smtp.php',(array)get_option('active_plugins',array()),true),
                'test_email_sent'=>false,'smtp_credentials_exposed'=>false,
                'configuration_present'=>get_option('fluentmail-settings',null)!==null),
            'pending_recipients'=>count(self::policy()['recipient_user_ids'])===0,
            'delivery_attempts'=>0,
            'certification'=>array(
                'event_capture_schema'=>self::installed(),
                'source_issue_baseline_protected'=>true,
                'wpml_language_audit_complete'=>$missingSource===0,
                'bitflows_dormant_draft_present'=>self::inactive_flow_info()['found'],
                'provider_board_valid'=>MAD4B_SCP_Readiness_Workflow_Options::validate_board_stage(
                    MAD4B_SCP_Readiness_Workflow_Options::settings()['board_id'],
                    MAD4B_SCP_Readiness_Workflow_Options::settings()['stage_id']),
                'smtp_configured'=>get_option('fluentmail-settings',null)!==null,
                'company_recipients_approved'=>count(self::policy()['recipient_user_ids'])>0,
                'smtp_external_send_authorized'=>false,
                'activation_ready'=>false));
    }
    public static function preview_mail($limit=15) {
        $rows=self::cohort_rows($limit);
        $policy=self::policy();$drafts=array();
        foreach($rows as $row){
            $values=array('severity'=>strtoupper($row['severity']),
                'group'=>$row['group_key'],'event_count'=>$row['event_count'],
                'site'=>home_url(),'language'=>$row['language_code'],
                'dashboard_url'=>admin_url('admin.php?page=mad4b-readiness-preactivation'));
            $replacement=array();foreach($values as $name=>$value)$replacement['{'.$name.'}']=(string)$value;
            $drafts[]=array('batch_id'=>$row['batch_key'],
                'event_type'=>$row['event_type'],'event_count'=>(int)$row['event_count'],
                'language_status'=>$row['language_status'],
                'subject'=>strtr($policy['subject'],$replacement),
                'body'=>strtr($policy['body'],$replacement),
                'recipient_user_ids'=>$policy['recipient_user_ids'],
                'delivery_state'=>'held','would_send'=>false);
        }
        return array('contract'=>'mad4b.readiness.email-preview.v1','dry_run'=>true,
            'smtp_delivery_invoked'=>false,'notification_count'=>count($drafts),
            'rows'=>$drafts,'policy_revision'=>self::revision());
    }
    public static function routes() {
        $guard=array('permission_callback'=>array(__CLASS__,'allowed'));
        register_rest_route(self::NS,'/admin/preactivation/event-automation',array_merge($guard,
            array('methods'=>'GET','callback'=>array(__CLASS__,'rest_status'))));
        register_rest_route(self::NS,'/admin/preactivation/email-preview',array_merge($guard,
            array('methods'=>'GET','callback'=>array(__CLASS__,'rest_preview'))));
        register_rest_route(self::NS,'/admin/preactivation/email-policy',array(
            array_merge($guard,array('methods'=>'GET','callback'=>array(__CLASS__,'rest_policy'))),
            array_merge($guard,array('methods'=>'PATCH','callback'=>array(__CLASS__,'rest_save_policy'))),
        ));
        register_rest_route(self::NS,'/admin/preactivation/email-dispatch',array_merge($guard,
            array('methods'=>'POST','callback'=>array(__CLASS__,'rest_dispatch'))));
    }
    public static function rest_status() {return rest_ensure_response(self::stats());}
    public static function rest_preview(){return rest_ensure_response(self::preview_mail());}
    public static function rest_policy(){return rest_ensure_response(array(
        'mode'=>'draft_only','policy'=>self::policy(),'revision'=>self::revision()));}
    public static function rest_save_policy($request) {
        $body=$request->get_json_params();
        if(!is_array($body)||!is_string($body['if_match']??null)||
           !hash_equals(self::revision(),$body['if_match']))
            return new WP_Error('readiness_email_revision_conflict','Refresh the email draft.',array('status'=>409));
        $next=self::normalize_policy($body['policy']??null);
        if(is_wp_error($next))return $next;
        $old=self::policy();
        update_option(self::STORE,$next,false);
        if(self::revision()!==self::revision($next))
            return new WP_Error('readiness_email_readback_error','Email draft not confirmed.',array('status'=>500));
        if($old!==$next) {
            $history=(array)get_option(self::AUDIT,array());
            $history[]=array('at'=>gmdate('c'),'actor'=>get_current_user_id(),
                'old'=>self::revision($old),'new'=>self::revision($next),
                'effect'=>'draft_only');
            update_option(self::AUDIT,array_slice($history,-50),false);
        }
        return self::rest_policy();
    }
    public static function rest_dispatch() {
        return new WP_Error('readiness_external_mail_locked',
            'Email delivery requires separate owner-approved activation certification.',
            array('status'=>423));
    }
    public static function handle_save() {
        if(!self::allowed())wp_die('Forbidden',403);
        check_admin_referer('mad4b_readiness_email_draft_save');
        $raw=wp_unslash($_POST);
        $policy=self::defaults();
        $policy['subject']=(string)($raw['subject']??$policy['subject']);
        $policy['body']=(string)($raw['body']??$policy['body']);
        $policy['digest_window_minutes']=absint($raw['digest_window_minutes']??60);
        $policy['cooldown_minutes']=absint($raw['cooldown_minutes']??60);
        $ids=$raw['recipient_user_ids']??array();
        if(!is_array($ids))wp_die('Invalid recipients',422);
        $policy['recipient_user_ids']=array_map('absint',$ids);
        $request=new WP_REST_Request('PATCH','/'.self::NS.'/admin/preactivation/email-policy');
        $request->set_header('content-type','application/json');
        $request->set_body(wp_json_encode(array('if_match'=>(string)($raw['revision']??''),
            'policy'=>$policy)));
        $response=self::rest_save_policy($request);
        if(is_wp_error($response))
            wp_die(esc_html($response->get_error_code()),$response->get_error_data()['status']??400);
        wp_safe_redirect(add_query_arg(array('page'=>MAD4B_SCP_Readiness_PreActivation::PAGE,
            'email_draft_saved'=>'1'),admin_url('admin.php')));exit;
    }
    public static function render_section() {
        if(!self::allowed())return;
        $s=self::stats();
        echo '<h2>Automated events & email — preparation only</h2>';
        echo '<p>New discovered/resolved/reopened source events are recorded in a deduplicated local outbox. Daily cohorts by group, language and severity prevent hundreds of individual emails. Outbound SMTP and Bit Flows remain locked.</p>';
        echo '<table class="widefat striped"><tbody>';
        foreach(array('Outbox events'=>$s['event_outbox']['total'],
            'Held for review'=>$s['event_outbox']['held'],
            'Unresolved language (source issues)'=>array_sum($s['language_review_by_group']),
            'Confirmed email recipients'=>count($s['email_policy']['recipient_user_ids'])) as $key=>$count)
            echo '<tr><th>'.esc_html($key).'</th><td>'.(int)$count.'</td></tr>';
        echo '</tbody></table>';
        if(isset($_GET['email_draft_saved']))echo '<p>Saved email draft; delivery is still disabled.</p>';
        $policy=self::policy();
        echo '<h3>Fluent SMTP email draft — not active</h3>';
        echo '<p>Fluent SMTP configuration: '.($s['fluent_smtp']['configuration_present']?'present':'missing').'. Approve a company staff recipient before sending. Emails are not tested or sent here.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="mad4b_readiness_email_draft_save">';
        echo '<input type="hidden" name="revision" value="'.esc_attr(self::revision()).'">';
        wp_nonce_field('mad4b_readiness_email_draft_save');
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>Subject template</th><td><input class="large-text" name="subject" value="'.esc_attr($policy['subject']).'"></td></tr>';
        echo '<tr><th>Plain-text body</th><td><textarea class="large-text code" name="body" rows="8">'.esc_textarea($policy['body']).'</textarea></td></tr>';
        echo '<tr><th>Event aggregation window (minutes)</th><td><input type="number" name="digest_window_minutes" min="15" max="1440" value="'.(int)$policy['digest_window_minutes'].'"></td></tr>';
        echo '<tr><th>Cooldown (minutes)</th><td><input type="number" name="cooldown_minutes" min="60" max="10080" value="'.(int)$policy['cooldown_minutes'].'"></td></tr>';
        echo '<tr><th>Approved company staff recipients</th><td><select name="recipient_user_ids[]" multiple size="4">';
        foreach(MAD4B_SCP_Readiness_Team_Preparation::candidates() as $user) {
            $id=(int)$user['user_id'];
            echo '<option value="'.$id.'" '.selected(in_array($id,$policy['recipient_user_ids'],true),true,false).'>'.esc_html($user['display_name']).'</option>';
        }
        echo '</select><p class="description">Only enrolled company staff are allowed; no new users are created.</p></td></tr>';
        echo '</tbody></table>';
        submit_button('Save email automation draft — do not send');
        echo '</form>';
        echo '<p>Authenticated REST: <code>/wp-json/mad4b-content-readiness/v1/admin/preactivation/event-automation</code> and <code>/admin/preactivation/email-preview</code>. The send route remains hard-locked.</p>';
    }
}
