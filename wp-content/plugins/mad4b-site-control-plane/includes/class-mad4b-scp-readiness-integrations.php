<?php
/**
 * Readiness operational integration: company-local event journal, scoped WP users,
 * dashboard inbox, and an explicitly gated Bit Flows WordPress action hook.
 *
 * This module does not send mail, call webhooks or create Fluent Boards tasks.
 * Those remain native, separately governed provider operations.
 */
defined('ABSPATH') || exit;

final class MAD4B_SCP_Readiness_Integrations {
    const STORE='mad4b_content_readiness_events_v1';
    const HOOK='mad4b/readiness/issue_transition';
    const NAMESPACE='mad4b-content-readiness/v1';
    const CAP='mad4b_manage_company_operations';

    public static function boot() {
        add_action('rest_api_init',array(__CLASS__,'routes'));
        add_action('admin_bar_menu',array(__CLASS__,'admin_bar'),97);
        add_action('admin_menu',array(__CLASS__,'company_menu'),34);
    }
    public static function company_menu() {
        add_submenu_page('mad4b-company','Content Readiness','Content Readiness',self::CAP,
            MAD4B_SCP_Content_Readiness_Alerts::PAGE,array('MAD4B_SCP_Content_Readiness_Alerts','render'));
        add_submenu_page('mad4b-company','Content Follow-up','Content Follow-up',self::CAP,
            MAD4B_SCP_Readiness_Workflow_Options::PAGE,array('MAD4B_SCP_Readiness_Workflow_Options','render'));
        add_submenu_page('mad4b-company','Completeness & SEO','Completeness & SEO',self::CAP,
            MAD4B_SCP_Content_Completeness::PAGE,array('MAD4B_SCP_Content_Completeness','render'));
    }
    public static function can_manage() {
        return current_user_can('manage_options') || current_user_can(self::CAP);
    }
    public static function can_view() {
        return current_user_can('edit_others_posts') || self::can_manage();
    }
    public static function can_see_all() {
        return self::can_manage();
    }
    public static function is_bitflows_present() {
        return class_exists('BitApps\Pi\Services\FlowService');
    }
    public static function bitflows_flow_status($hook=self::HOOK) {
        if(!self::is_bitflows_present())
            return array('installed'=>false,'configured'=>false,'matching_active_flows'=>0);
        global $wpdb;
        $flows=$wpdb->prefix.'bit_pi_flows';
        $nodes=$wpdb->prefix.'bit_pi_flow_nodes';
        $matching=0;
        // Provider-owned node mapping; nothing is changed in Bit Flows.
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT n.field_mapping FROM {$nodes} n INNER JOIN {$flows} f ON f.id=n.flow_id
             WHERE n.app_slug=%s AND n.machine_slug=%s AND f.is_active=%d LIMIT 100",
            'wordPressActionHooks','addAction',1),ARRAY_A);
        if(!is_array($rows))$rows=array();
        foreach($rows as $row) {
            $mapping=json_decode((string)($row['field_mapping']??''),true);
            if(($mapping['configs']['hook-name']['value']??'')===$hook)$matching++;
        }
        return array('installed'=>true,'configured'=>$matching>0,'matching_active_flows'=>$matching);
    }
    public static function event_payload($issue,$transition,$assignee_id=0) {
        $id=(string)($issue['id']??'');
        return array(
            'contract'=>'mad4b.readiness.issue-event.v1',
            'event'=>$transition,
            'issue_id'=>$id,
            'source'=>sanitize_key((string)($issue['source']??'readiness')),
            'code'=>sanitize_key((string)($issue['code']??'')),
            'severity'=>sanitize_key((string)($issue['severity']??'medium')),
            'group'=>MAD4B_SCP_Readiness_Workflow_Options::group_for_issue($issue),
            'object_type'=>sanitize_text_field((string)($issue['post_type']??'site')),
            'object_id'=>absint($issue['object_id']??0),
            'assignee_id'=>absint($assignee_id),
            'board_task_id'=>absint($issue['board_task_id']??0),
            'site_id'=>get_current_blog_id(),
        );
    }
    public static function record_changes($before,$after) {
        $before=is_array($before)?$before:array();
        $after=is_array($after)?$after:array();
        $saved=get_option(self::STORE,null);
        if(!is_array($saved)) {
            // Deliberately initialize after install without replaying old gaps.
            add_option(self::STORE,array('version'=>1,'events'=>array(),'created_at'=>gmdate('c')),'','no');
            return array('baseline_created'=>true,'recorded'=>0,'emitted'=>0);
        }
        $events=(array)($saved['events']??array());
        $known=array_fill_keys(array_column($events,'event_id'),true);
        $recorded=0;$dispatched=0;
        foreach($after as $id=>$row) {
            if(!is_array($row))continue;
            $old=$before[$id]??null;
            $state=(string)($row['status']??'open');
            $prior=is_array($old)?(string)($old['status']??'open'):'';
            $transition='';
            if(!$old && $state==='open')$transition='discovered';
            elseif($prior==='resolved'&&$state==='open')$transition='reopened';
            elseif($prior==='open'&&$state==='resolved')$transition='resolved';
            if(!$transition)continue;
            $cycle=absint($row['reopens']??0);
            $eventId=hash('sha256',$id.'|'.$transition.'|'.$cycle);
            if(isset($known[$eventId]))continue;
            $assignee=MAD4B_SCP_Readiness_Workflow_Options::assigned_user($row);
            $payload=self::event_payload($row,$transition,$assignee);
            $payload['event_id']=$eventId;
            $payload['at']=gmdate('c');
            $payload['dispatch_state']='not_requested';
            $events[]=$payload;
            $known[$eventId]=true;$recorded++;
            // Never dispatch externally from a discovery or scheduler scan.
            // An external Bit Flows delivery needs a separate governed agent action.
        }
        if($recorded) {
            $saved['events']=array_slice($events,-300);
            update_option(self::STORE,$saved,false);
        }
        return array('baseline_created'=>false,'recorded'=>$recorded,'emitted'=>$dispatched);
    }
    public static function inbox_rows($user_id,$all=false) {
        $store=get_option(self::STORE,array());
        $rows=array_reverse((array)($store['events']??array()));
        if($all)return $rows;
        return array_values(array_filter($rows,static function($row)use($user_id){
            return absint($row['assignee_id']??0)===$user_id;
        }));
    }
    private static function response($data) {
        return rest_ensure_response(array_merge(array('contract'=>'mad4b.readiness.integrations.v1'),$data));
    }
    public static function routes() {
        register_rest_route(self::NAMESPACE,'/admin/integrations',array(
            'methods'=>'GET','callback'=>array(__CLASS__,'rest_status'),
            'permission_callback'=>array(__CLASS__,'can_manage')));
        register_rest_route(self::NAMESPACE,'/admin/routing',array(
            array('methods'=>'GET','callback'=>array(__CLASS__,'rest_routing'),
                'permission_callback'=>array(__CLASS__,'can_manage')),
            array('methods'=>'PATCH','callback'=>array(__CLASS__,'rest_save_routing'),
                'permission_callback'=>array(__CLASS__,'can_manage')),
        ));
        register_rest_route(self::NAMESPACE,'/admin/team',array(
            'methods'=>'GET','callback'=>array(__CLASS__,'rest_team'),
            'permission_callback'=>array(__CLASS__,'can_manage')));
        register_rest_route(self::NAMESPACE,'/notifications/backlog',array(
            'methods'=>'GET','callback'=>array(__CLASS__,'rest_backlog'),
            'permission_callback'=>array(__CLASS__,'can_view')));
        register_rest_route(self::NAMESPACE,'/notifications/inbox',array(
            'methods'=>'GET','callback'=>array(__CLASS__,'rest_inbox'),
            'permission_callback'=>array(__CLASS__,'can_view')));
        register_rest_route(self::NAMESPACE,'/admin/integrations/dispatch',array(
            'methods'=>'POST','callback'=>array(__CLASS__,'dispatch_event'),
            'permission_callback'=>array('MAD4B_SCP_Content_Readiness_Alerts','rest_local_client_write_permission')));
        register_rest_route(self::NAMESPACE,'/admin/integrations/preview',array(
            'methods'=>'GET','callback'=>array(__CLASS__,'rest_preview'),
            'permission_callback'=>array(__CLASS__,'can_manage')));
    }
    public static function rest_status() {
        $cfg=MAD4B_SCP_Readiness_Workflow_Options::settings();
        $flow=self::bitflows_flow_status();
        $groupOwners=array();
        foreach(MAD4B_SCP_Readiness_Workflow_Options::GROUPS as $name=>$label) {
            $id=absint($cfg['owners'][$name]??0);
            $role=(string)($cfg['owner_roles'][$name]??'');
            $eligible=$id?get_user_by('id',$id):null;
            $candidates=(!$id&&$role)?get_users(array('role'=>$role,'number'=>2)):array();
            $resolved=$eligible&&user_can($eligible,'edit_others_posts')?$id:
                (count($candidates)===1&&user_can($candidates[0],'edit_others_posts')?(int)$candidates[0]->ID:0);
            $groupOwners[$name]=array('assignee_id'=>$resolved,
                'configured_owner_id'=>$id,'configured_role'=>$role,
                'status'=>$resolved?'ready':'needs_owner');
        }
        return self::response(array(
            'site_id'=>get_current_blog_id(),
            'board_id'=>$cfg['board_id'],'stage_id'=>$cfg['stage_id'],
            'board_valid'=>MAD4B_SCP_Readiness_Workflow_Options::validate_board_stage($cfg['board_id'],$cfg['stage_id']),
            'board_tasks_enabled'=>$cfg['board_tasks'],
            'outbound'=>array('bitflows'=>$flow,'opt_in'=>$cfg['bitflows_notifications'],
                'ready'=>!empty($cfg['bitflows_notifications'])&&$flow['configured'],
                'hook'=>self::HOOK,'email_enabled'=>$cfg['email_notifications']),
            'event_history_count'=>count((array)(get_option(self::STORE,array())['events']??array())),
            'owners'=>$groupOwners,'task_write_authorized'=>MAD4B_SCP_Content_Readiness_Alerts::rest_local_client_write_permission(),
        ));
    }
    public static function rest_routing() {
        $cfg=MAD4B_SCP_Readiness_Workflow_Options::settings();
        $payload=array('owners'=>$cfg['owners'],'owner_roles'=>$cfg['owner_roles']);
        return self::response(array('routing'=>$payload,
            'revision'=>hash('sha256',wp_json_encode($payload))));
    }
    public static function rest_save_routing($request) {
        $input=$request->get_json_params();
        $current=MAD4B_SCP_Readiness_Workflow_Options::settings();
        $payload=array('owners'=>$current['owners'],'owner_roles'=>$current['owner_roles']);
        $revision=hash('sha256',wp_json_encode($payload));
        if(!is_array($input)||!is_string($input['if_match']??null)||
            !hash_equals($revision,$input['if_match']))
            return new WP_Error('readiness_routing_conflict','Refresh company routing revision.',array('status'=>409));
        $incoming=$input['routing']??null;
        if(!is_array($incoming)||array_diff(array_keys($incoming),array('owners','owner_roles'))||
            !is_array($incoming['owners']??null)||!is_array($incoming['owner_roles']??null))
            return new WP_Error('readiness_routing_invalid','Unknown fields.',array('status'=>422));
        $groups=MAD4B_SCP_Readiness_Workflow_Options::GROUPS;
        if(array_diff(array_keys($incoming['owners']),array_keys($groups))||
           array_diff(array_keys($incoming['owner_roles']),array_keys($groups)))
            return new WP_Error('readiness_routing_group_invalid','Unknown company group.',array('status'=>422));
        foreach($groups as $key=>$label) {
            $id=absint($incoming['owners'][$key]??0);
            $role=sanitize_key((string)($incoming['owner_roles'][$key]??''));
            $user=$id?get_user_by('id',$id):null;
            if(($id&&(!$user||!user_can($user,'edit_others_posts')))||
                ($role&&!get_role($role)))
                return new WP_Error('readiness_routing_owner_invalid','Owner or role not eligible.',array('status'=>422));
            if($id&&!current_user_can('manage_options') &&
                (!class_exists('MAD4B_SCP_Company_Master_Admin')||
                 !MAD4B_SCP_Company_Master_Admin::managedStaff($id)))
                return new WP_Error('readiness_routing_owner_outside_team','Master Admin can assign only managed internal staff.',array('status'=>403));
            $payload['owners'][$key]=$id;
            $payload['owner_roles'][$key]=$role;
        }
        $next=$current;
        $next['owners']=$payload['owners'];
        $next['owner_roles']=$payload['owner_roles'];
        update_option(MAD4B_SCP_Readiness_Workflow_Options::OPTION,$next,false);
        return self::rest_routing();
    }
    public static function rest_team() {
        $users=array();
        foreach(get_users(array('number'=>300,'orderby'=>'ID','order'=>'ASC')) as $user) {
            if(!user_can($user,'edit_others_posts'))continue;
            if(!current_user_can('manage_options') && $user->ID!==get_current_user_id() &&
                (!class_exists('MAD4B_SCP_Company_Master_Admin')||
                 !MAD4B_SCP_Company_Master_Admin::managedStaff($user->ID)))continue;
            $users[]=array('user_id'=>(int)$user->ID,'display_name'=>$user->display_name,
                'roles'=>array_values($user->roles),'readiness_manager'=>user_can($user,self::CAP)||
                    user_can($user,'manage_options'));
        }
        return self::response(array('users'=>$users,'groups'=>MAD4B_SCP_Readiness_Workflow_Options::GROUPS));
    }
    public static function rest_backlog($request) {
        $all=$request->get_param('scope')==='all' && self::can_see_all();
        $rows=array();$issues=(array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,array());
        foreach($issues as $issue) {
            if(!is_array($issue)||($issue['status']??'')!=='open')continue;
            $assignee=MAD4B_SCP_Readiness_Workflow_Options::assigned_user($issue);
            if(!$all && (!$assignee || $assignee!==get_current_user_id()))continue;
            $rows[]=array('issue_id'=>(string)($issue['id']??''),
                'code'=>(string)($issue['code']??''),'severity'=>(string)($issue['severity']??'medium'),
                'group'=>MAD4B_SCP_Readiness_Workflow_Options::group_for_issue($issue),
                'assignee_id'=>$assignee,'board_task_id'=>absint($issue['board_task_id']??0));
        }
        $page=max(1,absint($request->get_param('page')??1));
        $size=max(1,min(50,absint($request->get_param('per_page')??25)));
        return self::response(array('scope'=>$all?'all':'assigned','total'=>count($rows),
            'page'=>$page,'per_page'=>$size,'issues'=>array_slice($rows,($page-1)*$size,$size)));
    }
    public static function rest_inbox($request) {
        $page=max(1,absint($request->get_param('page')??1));
        $size=max(1,min(50,absint($request->get_param('per_page')??25)));
        $all=$request->get_param('scope')==='all' && self::can_see_all();
        $rows=self::inbox_rows(get_current_user_id(),$all);
        $total=count($rows);
        return self::response(array('scope'=>$all?'all':'assigned','total'=>$total,
            'page'=>$page,'per_page'=>$size,
            'events'=>array_slice($rows,($page-1)*$size,$size)));
    }
    public static function rest_preview($request) {
        $issueId=(string)$request->get_param('issue_id');
        $rows=(array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,array());
        $issue=$rows[$issueId]??null;
        if(!is_array($issue)||!preg_match('/^[a-f0-9]{64}$/D',$issueId))
            return new WP_Error('mad4b_readiness_issue_unknown','Unknown issue fingerprint.',array('status'=>404));
        return self::response(array('dry_run'=>true,'emitted'=>false,
            'bitflows_configured'=>self::bitflows_flow_status()['configured'],
            'payload'=>self::event_payload($issue,'discovered',
                MAD4B_SCP_Readiness_Workflow_Options::assigned_user($issue))));
    }
    /** Per-issue outbound is permanently retired in favor of bounded work-batch digests. */
    public static function dispatch_event($request) {
        return new WP_Error('readiness_use_scope_digest',
            'Per-issue external alerts are disabled. Use a governed work-batch digest.',
            array('status'=>410));
    }
    public static function admin_bar($bar) {
        if(!self::can_view())return;
        $count=count(self::inbox_rows(get_current_user_id(),self::can_see_all()));
        if(!$count)return;
        $bar->add_node(array('id'=>'mad4b-readiness-events',
            'title'=>'Readiness Events: '.(int)$count,
            'href'=>admin_url('admin.php?page=mad4b-content-readiness-alerts')));
    }
}
