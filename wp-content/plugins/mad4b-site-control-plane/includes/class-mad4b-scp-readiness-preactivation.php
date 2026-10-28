<?php
/**
 * Pre-Activation Console for MAD4B Content Readiness.
 *
 * Draft configuration, deterministic previews and read-only certification.
 * It never creates provider tasks, Bit Flows flows, users, mail, or webhooks.
 * "disabled" is a constant and not a user-controlled setting.
 */
defined('ABSPATH') || exit;
require_once __DIR__.'/class-mad4b-scp-readiness-team-preparation.php';

final class MAD4B_SCP_Readiness_PreActivation {
    const OPTION='mad4b_readiness_preactivation_v1';
    const AUDIT='mad4b_readiness_preactivation_audit_v1';
    const PAGE='mad4b-readiness-preactivation';
    const NS='mad4b-content-readiness/v1';
    const CAP='mad4b_manage_company_operations';
    const HOOK='mad4b/readiness/scope_digest';
    const DEFAULT_TITLE='[{severity}] {group} / {language} / {content_type} — {issue_count} issues';
    const DEFAULT_BODY="Work scope: {scope_id}\nReview the source issues in MAD4B Content Work Scopes.\nResponsible WordPress user: {assignee_id}\nDo not edit a record without checking its source.";
    const DEFAULT_DIGEST="Content readiness digest — {group} ({language})\n{issue_count} gaps across {packet_count} work packets.\nBatch reference: {batch_id}";
    const TOKENS=array('group','severity','language','content_type','issue_count','scope_id','assignee_id',
                       'batch_id','packet_count');

    public static function boot() {
        add_action('rest_api_init',array(__CLASS__,'routes'));
        add_action('admin_menu',array(__CLASS__,'menu'),36);
        add_action('admin_post_mad4b_readiness_preactivation_save',array(__CLASS__,'save'));
        MAD4B_SCP_Readiness_Team_Preparation::boot();
    }
    public static function can_manage() {
        return current_user_can('manage_options')||current_user_can(self::CAP);
    }
    /** Only a future, separately certified activation contract may lift this lock. */
    public static function execution_locked() {return true;}
    public static function defaults() {
        return array(
            'revision_schema'=>1,
            'scope'=>array('max_items_per_packet'=>50,'min_severity'=>'medium','include_legacy_backlog'=>true),
            'tasks'=>array('template_title'=>self::DEFAULT_TITLE,'template_body'=>self::DEFAULT_BODY,
                'max_per_cycle'=>5,'require_human_approval'=>true),
            'digest'=>array('template'=>self::DEFAULT_DIGEST,'cooldown_minutes'=>60,
                'max_per_cycle'=>1,'first_cycle'=>'quarantine','require_all_packets_synced'=>true),
            'providers'=>array('fluent_boards'=>'preview_only','bitflows'=>'blueprint_only',
                'fluent_smtp'=>'inspection_only'),
        );
    }
    public static function settings() {
        $raw=get_option(self::OPTION,null);
        if(!is_array($raw))return self::defaults();
        $normalized=self::normalize($raw);
        return is_wp_error($normalized)?self::defaults():$normalized;
    }
    private static function error($id,$message,$status=422) {
        return new WP_Error($id,$message,array('status'=>$status));
    }
    private static function positive($value,$min,$max) {
        if(!is_int($value)||$value<$min||$value>$max)return null;
        return $value;
    }
    private static function template($value,$max) {
        if(!is_string($value)||strlen($value)>$max||$value==='')return null;
        if(preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$value))return null;
        preg_match_all('/\{([^}]+)\}/',$value,$matches);
        foreach($matches[1] as $name)if(!in_array($name,self::TOKENS,true))return null;
        if(preg_match('/%\w+%|\{\{|\}\}|<[^>]+>/',$value))return null;
        return $value;
    }
    public static function normalize($raw) {
        if(!is_array($raw)||array_diff(array_keys($raw),array('revision_schema','scope','tasks','digest','providers')))
            return self::error('preactivation_unknown_key','Unknown policy fields.');
        if(($raw['revision_schema']??null)!==1)return self::error('preactivation_schema','Unsupported policy schema.');
        foreach(array('scope','tasks','digest','providers') as $group) {
            if(!is_array($raw[$group]??null))return self::error('preactivation_missing_group','Missing policy group.');
        }
        $allowed=array(
            'scope'=>array('max_items_per_packet','min_severity','include_legacy_backlog'),
            'tasks'=>array('template_title','template_body','max_per_cycle','require_human_approval'),
            'digest'=>array('template','cooldown_minutes','max_per_cycle','first_cycle','require_all_packets_synced'),
            'providers'=>array('fluent_boards','bitflows','fluent_smtp'),
        );
        foreach($allowed as $group=>$keys)
            if(array_diff(array_keys($raw[$group]),$keys)||array_diff($keys,array_keys($raw[$group])))
                return self::error('preactivation_group_invalid','Policy group requires its exact field set.');
        $s=$raw['scope'];$t=$raw['tasks'];$d=$raw['digest'];$p=$raw['providers'];
        if($s['max_items_per_packet']!==50||
           !in_array($s['min_severity'],array('low','medium','high','critical'),true)||
           !is_bool($s['include_legacy_backlog']))
            return self::error('preactivation_scope_invalid','Invalid scope configuration.');
        if(self::positive($t['max_per_cycle'],1,5)===null||$t['require_human_approval']!==true||
           self::template($t['template_title'],180)===null||self::template($t['template_body'],1800)===null)
            return self::error('preactivation_tasks_invalid','Invalid or unsafe task draft.');
        if(self::positive($d['cooldown_minutes'],15,10080)===null||
           self::positive($d['max_per_cycle'],1,1)===null||
           $d['first_cycle']!=='quarantine' || $d['require_all_packets_synced']!==true||
           self::template($d['template'],1200)===null)
            return self::error('preactivation_digest_invalid','Digest safety restrictions are immutable.');
        if($p!==self::defaults()['providers'])
            return self::error('preactivation_providers_immutable','Providers cannot be activated in preparation mode.');
        return $raw;
    }
    public static function revision($settings=null) {
        return hash('sha256',wp_json_encode($settings??self::settings(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
    public static function render_template($template,$vars) {
        $replacement=array();
        foreach(self::TOKENS as $key)
            $replacement['{'.$key.'}']=is_scalar($vars[$key]??null)?(string)$vars[$key]:'';
        return strtr($template,$replacement);
    }
    public static function mock_task($scope) {
        $policy=self::settings();$vars=array(
            'group'=>$scope['group'],'severity'=>strtoupper($scope['severity']),
            'language'=>$scope['language'],'content_type'=>$scope['content_type'],
            'issue_count'=>$scope['total'],'scope_id'=>$scope['scope_id'],
            'assignee_id'=>$scope['assignee_id'],'batch_id'=>$scope['batch_id'],
            'packet_count'=>1);
        $priority=array('low'=>0,'medium'=>1,'high'=>2,'critical'=>3);
        $eligible=$policy['scope']['include_legacy_backlog'] &&
            ($priority[$scope['severity']]??0)>=($priority[$policy['scope']['min_severity']]??0);
        return array('provider'=>'fluent_boards','preview_only'=>true,
            'eligible_under_draft_policy'=>$eligible,
            'source'=>MAD4B_SCP_Readiness_Scopes::TASK_SOURCE,
            'source_id'=>$scope['scope_id'],
            'title'=>self::render_template($policy['tasks']['template_title'],$vars),
            'description'=>self::render_template($policy['tasks']['template_body'],$vars),
            'assignee_id'=>absint($scope['assignee_id']),
            'board_id'=>absint(MAD4B_SCP_Readiness_Workflow_Options::settings()['board_id']),
            'max_tasks_per_cycle'=>$policy['tasks']['max_per_cycle'],'would_create'=>false);
    }
    public static function mock_digest($batch) {
        $policy=self::settings();$vars=array(
            'group'=>$batch['group'],'severity'=>strtoupper($batch['severity']),
            'language'=>$batch['language'],'content_type'=>$batch['content_type'],
            'issue_count'=>$batch['issue_count'],'packet_count'=>$batch['work_packet_count'],
            'batch_id'=>$batch['batch_id'],'scope_id'=>'','assignee_id'=>'');
        return array('provider'=>'bitflows','event_hook'=>self::HOOK,
            'preview_only'=>true,'would_invoke_hook'=>false,'would_send_message'=>false,
            'schema'=>'mad4b.readiness.batch-digest.v1',
            'body'=>self::render_template($policy['digest']['template'],$vars),
            'payload'=>array('batch_id'=>$batch['batch_id'],'group'=>$batch['group'],
                'language'=>$batch['language'],'severity'=>$batch['severity'],
                'issue_count'=>$batch['issue_count'],'packet_count'=>$batch['work_packet_count'],
                'site_id'=>get_current_blog_id()),
            'max_digests_per_cycle'=>$policy['digest']['max_per_cycle'],
            'first_cycle'=>$policy['digest']['first_cycle']);
    }
    public static function report() {
        $scopeClass='MAD4B_SCP_Readiness_Scopes';
        $scopes=$scopeClass::build();
        $batches=$scopeClass::batches($scopes);
        $workflow=MAD4B_SCP_Readiness_Workflow_Options::settings();
        $sync=MAD4B_SCP_Readiness_Integrations::bitflows_flow_status(self::HOOK);
        $board=MAD4B_SCP_Readiness_Workflow_Options::validate_board_stage(
            $workflow['board_id'],$workflow['stage_id']);
        $owners=count(array_filter($scopes,static fn($s)=>absint($s['assignee_id'])>0));
        $approved=count(array_filter($scopes,static fn($s)=>in_array($s['approval'],array('approved','synced'),true)));
        $linked=count(array_filter($scopes,static fn($s)=>$s['task_id']>0));
        $users=array();
        foreach(get_users(array('number'=>300,'fields'=>'all')) as $user)
            if(user_can($user,'edit_others_posts')) {
                if(!current_user_can('manage_options')&&
                    (!class_exists('MAD4B_SCP_Company_Master_Admin')||
                    !MAD4B_SCP_Company_Master_Admin::managedStaff($user->ID)))continue;
                $users[]=array('user_id'=>(int)$user->ID,
                    'display_name'=>$user->display_name,'roles'=>array_values($user->roles),
                    'may_manage_company'=>user_can($user,self::CAP));
            }
        $total=array_sum(array_column($scopes,'total'));
        $priority=array('low'=>0,'medium'=>1,'high'=>2,'critical'=>3);
        $policy=self::settings();
        $eligibleIssues=0;$eligiblePackets=0;
        foreach($scopes as $scope) {
            if(!$policy['scope']['include_legacy_backlog'] ||
                ($priority[$scope['severity']]??0)<($priority[$policy['scope']['min_severity']]??0))continue;
            $eligibleIssues+=$scope['total'];$eligiblePackets++;
        }
        $failures=array();
        if(!$board)$failures[]='fluent_boards_board_unavailable';
        if($owners<count($scopes))$failures[]='work_packets_need_assignees';
        if($approved<count($scopes))$failures[]='work_packets_need_approval';
        if(!$sync['configured'])$failures[]='bitflows_digest_flow_missing';
        $failures[]='external_delivery_not_certified';
        $summary=MAD4B_SCP_Content_Readiness_Alerts::summary();
        return array(
            'contract'=>'mad4b.readiness.preactivation.report.v1',
            'site_id'=>get_current_blog_id(),
            'execution_mode'=>'disabled',
            'policy_revision'=>self::revision(),
            'configured'=>get_option(self::OPTION,null)!==null,
            'summary'=>$summary,
            'scopes'=>array('work_batches'=>count($batches),'work_packets'=>count($scopes),
                'open_issues_grouped'=>$total,'planned_eligible_existing_issues'=>$eligibleIssues,
                'planned_eligible_packets'=>$eligiblePackets,
                'minimum_severity'=>$policy['scope']['min_severity'],
                'legacy_included_in_preview'=>$policy['scope']['include_legacy_backlog'],
                'max_packet_size'=>count($scopes)?max(array_column($scopes,'total')):0,
                'with_eligible_owner'=>$owners,'already_approved'=>$approved,'with_board_task'=>$linked),
            'providers'=>array(
                'fluent_boards'=>array('installed'=>class_exists('FluentBoards\App\Models\Board'),
                    'board_id'=>$workflow['board_id'],'stage_id'=>$workflow['stage_id'],
                    'valid_stage'=>$board,'preview_only'=>true),
                'bitflows'=>array('installed'=>$sync['installed'],
                    'configured'=>$sync['configured'],'flow_blueprint'=>array(
                        'trigger_type'=>'WordPress Action Hooks','action_hook'=>self::HOOK,
                        'flow_status'=>'not_created_by_preactivation'),
                    'preview_only'=>true),
                'fluent_smtp'=>array('installed'=>class_exists('FluentMail\App\App'),
                    'preview_only'=>true),
            ),
            'team'=>array('eligible_candidates'=>$users,'changes_applied'=>false),
            'gates'=>array('scope_approved'=>false,'provider_tasks_enabled'=>false,
                'outbound_notifications_enabled'=>false,'external_certified'=>false),
            'blocking_reasons'=>$failures,
            'side_effects'=>array('new_board_tasks'=>0,'outbound_messages'=>0,
                'users_modified'=>0,'provider_flows_modified'=>0),
        );
    }
    public static function routes() {
        $p=array('permission_callback'=>array(__CLASS__,'can_manage'));
        register_rest_route(self::NS,'/admin/preactivation',array(
            array_merge($p,array('methods'=>'GET','callback'=>array(__CLASS__,'rest_get'))),
            array_merge($p,array('methods'=>'PATCH','callback'=>array(__CLASS__,'rest_patch'))),
        ));
        register_rest_route(self::NS,'/admin/preactivation/report',array_merge($p,
            array('methods'=>'GET','callback'=>array(__CLASS__,'rest_report'))));
        register_rest_route(self::NS,'/admin/preactivation/task-preview/(?P<scope_id>[a-f0-9]{64})',array_merge($p,
            array('methods'=>'GET','callback'=>array(__CLASS__,'rest_task_preview'))));
        register_rest_route(self::NS,'/admin/preactivation/digest-preview/(?P<batch_id>[a-f0-9]{64})',array_merge($p,
            array('methods'=>'GET','callback'=>array(__CLASS__,'rest_digest_preview'))));
    }
    public static function rest_get() {
        return rest_ensure_response(array('contract'=>'mad4b.readiness.preactivation.policy.v1',
            'execution_mode'=>'disabled','settings'=>self::settings(),'revision'=>self::revision()));
    }
    public static function rest_report() {return rest_ensure_response(self::report());}
    private static function write($input) {
        if(!is_array($input)||!is_string($input['if_match']??null)||
            !hash_equals(self::revision(),$input['if_match']))
            return self::error('preactivation_revision_conflict','Refresh current draft revision.',409);
        $candidate=self::normalize($input['settings']??null);
        if(is_wp_error($candidate))return $candidate;
        $current=self::settings();
        update_option(self::OPTION,$candidate,false);
        if(self::revision()!==self::revision($candidate))
            return self::error('preactivation_readback_failed','Draft readback mismatch.',500);
        if($current!==$candidate) {
            $audit=(array)get_option(self::AUDIT,array());
            $audit[]=array('at'=>gmdate('c'),'actor'=>get_current_user_id(),
                'previous_revision'=>self::revision($current),
                'next_revision'=>self::revision($candidate),
                'effect'=>'draft_only');
            update_option(self::AUDIT,array_slice($audit,-50),false);
        }
        return self::rest_get();
    }
    public static function rest_patch($request) {return self::write($request->get_json_params());}
    public static function rest_task_preview($request) {
        $scope=MAD4B_SCP_Readiness_Scopes::build()[(string)$request['scope_id']]??null;
        if(!$scope)return self::error('preactivation_scope_unknown','Unknown work packet.',404);
        return rest_ensure_response(array('contract'=>'mad4b.readiness.preactivation.task-preview.v1',
            'execution_mode'=>'disabled','task'=>self::mock_task($scope)));
    }
    public static function rest_digest_preview($request) {
        $batch=MAD4B_SCP_Readiness_Scopes::batches()[(string)$request['batch_id']]??null;
        if(!$batch)return self::error('preactivation_batch_unknown','Unknown work batch.',404);
        return rest_ensure_response(array('contract'=>'mad4b.readiness.preactivation.digest-preview.v1',
            'execution_mode'=>'disabled','digest'=>self::mock_digest($batch)));
    }
    public static function save() {
        if(!self::can_manage())wp_die('Forbidden',403);
        check_admin_referer('mad4b_readiness_preactivation_save');
        $raw=wp_unslash($_POST);
        $settings=self::defaults();
        $settings['scope']['min_severity']=sanitize_key((string)($raw['severity']??'medium'));
        $settings['scope']['include_legacy_backlog']=!empty($raw['legacy']);
        $settings['tasks']['template_title']=(string)($raw['title']??self::DEFAULT_TITLE);
        $settings['tasks']['template_body']=(string)($raw['body']??self::DEFAULT_BODY);
        $settings['digest']['template']=(string)($raw['digest']??self::DEFAULT_DIGEST);
        $settings['tasks']['max_per_cycle']=absint($raw['tasks_per_cycle']??5);
        $settings['digest']['cooldown_minutes']=absint($raw['cooldown_minutes']??60);
        $result=self::write(array('if_match'=>(string)($raw['revision']??''),'settings'=>$settings));
        if(is_wp_error($result))
            wp_die(esc_html($result->get_error_code()),$result->get_error_data()['status']??400);
        wp_safe_redirect(add_query_arg(array('page'=>self::PAGE,'saved'=>'1'),
            admin_url('admin.php')));exit;
    }
    public static function menu() {
        add_submenu_page('mad4b-company','Pre-Activation Console','Pre-Activation',
            self::CAP,self::PAGE,array(__CLASS__,'screen'));
    }
    public static function screen() {
        if(!self::can_manage())wp_die('Forbidden',403);
        $report=self::report();
        $policy=self::settings();
        echo '<div class="wrap"><h1>Content Readiness — Pre-Activation</h1>';
        echo '<div class="notice notice-info"><p><strong>Execution disabled.</strong> Save draft policies and preview scopes, provider task templates and one digest per batch. No tasks, emails, Bit Flows executions or user changes occur here.</p></div>';
        if(isset($_GET['saved']))echo '<div class="notice notice-success"><p>Policy draft saved and verified.</p></div>';
        echo '<h2>Company Readiness</h2><table class="widefat striped"><tbody>';
        $metrics=array('Open source gaps'=>$report['summary']['open'],
            'Work batches'=>$report['scopes']['work_batches'],
            'Bounded work packets'=>$report['scopes']['work_packets'],
            'Assigned packets'=>$report['scopes']['with_eligible_owner'],
            'Approved packets'=>$report['scopes']['already_approved'],
            'Linked Fluent Boards tasks'=>$report['scopes']['with_board_task']);
        foreach($metrics as $label=>$count)echo '<tr><th>'.esc_html($label).'</th><td>'.(int)$count.'</td></tr>';
        echo '</tbody></table><h2>Provider readiness</h2><ul>';
        echo '<li>Fluent Boards: '.($report['providers']['fluent_boards']['valid_stage']?'Configured board and stage':'Board/stage not confirmed').'</li>';
        echo '<li>Bit Flows: '.($report['providers']['bitflows']['configured']?'Dedicated trigger exists':'No dedicated digest trigger').'</li>';
        echo '<li>Fluent SMTP: inspection only; no test mail sent</li></ul>';
        echo '<h2>Activation blockers</h2><ul>';
        foreach($report['blocking_reasons'] as $reason)
            echo '<li>'.esc_html(str_replace('_',' ',$reason)).'</li>';
        echo '</ul><h2>Editable draft templates — nothing executes</h2>';
        echo '<form action="'.esc_url(admin_url('admin-post.php')).'" method="post">';
        echo '<input type="hidden" name="action" value="mad4b_readiness_preactivation_save">';
        echo '<input type="hidden" name="revision" value="'.esc_attr(self::revision()).'">';
        wp_nonce_field('mad4b_readiness_preactivation_save');
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>Minimum severity</th><td><select name="severity">';
        foreach(array('low','medium','high','critical') as $severity)
            echo '<option value="'.esc_attr($severity).'" '.selected($policy['scope']['min_severity'],$severity,false).'>'.esc_html($severity).'</option>';
        echo '</select></td></tr>';
        echo '<tr><th>Legacy backlog</th><td><label><input type="checkbox" name="legacy" value="1" '.checked($policy['scope']['include_legacy_backlog'],true,false).'> Include in previews only</label></td></tr>';
        echo '<tr><th>Tasks per future cycle</th><td><input type="number" name="tasks_per_cycle" min="1" max="5" value="'.(int)$policy['tasks']['max_per_cycle'].'"></td></tr>';
        echo '<tr><th>Digest cooldown (minutes)</th><td><input type="number" name="cooldown_minutes" min="15" max="10080" value="'.(int)$policy['digest']['cooldown_minutes'].'"></td></tr>';
        echo '<tr><th>Fluent Boards task title</th><td><input class="regular-text" type="text" name="title" value="'.esc_attr($policy['tasks']['template_title']).'"></td></tr>';
        echo '<tr><th>Task description</th><td><textarea name="body" rows="5" class="large-text code">'.esc_textarea($policy['tasks']['template_body']).'</textarea></td></tr>';
        echo '<tr><th>Bit Flows digest text</th><td><textarea name="digest" rows="4" class="large-text code">'.esc_textarea($policy['digest']['template']).'</textarea></td></tr>';
        echo '</tbody></table>';
        submit_button('Save PRE-ACTIVATION draft only');
        echo '</form>';
        MAD4B_SCP_Readiness_Team_Preparation::render_section();
        echo '<p><a href="'.esc_url(admin_url('admin.php?page='.MAD4B_SCP_Readiness_Scopes::PAGE)).'">Review work scopes</a></p></div>';
    }
}
