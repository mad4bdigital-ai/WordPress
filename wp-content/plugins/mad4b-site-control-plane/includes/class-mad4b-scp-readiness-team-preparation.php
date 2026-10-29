<?php
/**
 * Company preparation worksheet. Draft assignments are NEVER execution owners.
 * All user/role reads are native WordPress; no create/update user or task calls.
 */
defined('ABSPATH') || exit;

final class MAD4B_SCP_Readiness_Team_Preparation {
    const OPTION='mad4b_readiness_team_preparation_v1';
    const AUDIT='mad4b_readiness_team_preparation_audit_v1';
    const NS='mad4b-content-readiness/v1';
    const CAP='mad4b_manage_company_operations';
    const ROLES=array('editor','shop_manager','operator','company_translation_manager');

    public static function boot() {
        add_action('rest_api_init',array(__CLASS__,'routes'));
        add_action('admin_post_mad4b_readiness_team_draft_save',array(__CLASS__,'handle_save'));
    }
    public static function can_manage() {
        return MAD4B_SCP_Readiness_PreActivation::can_manage();
    }
    public static function defaults() {
        $roles=array(
          'media'=>'editor','origin'=>'editor','wpml'=>'company_translation_manager',
          'pricing'=>'shop_manager','destinations'=>'editor','content'=>'editor',
          'operations'=>'operator','seo'=>'editor','general'=>'editor');
        $plan=array();
        foreach(MAD4B_SCP_Readiness_Workflow_Options::GROUPS as $key=>$name)
            $plan[$key]=array('proposed_role'=>$roles[$key]??'editor','proposed_user_id'=>0);
        return array('schema'=>1,'state'=>'draft','assignments'=>$plan,
            'handoff'=>'manual_after_human_approval','notification_mode'=>'one_digest_per_batch',
            'provider_mutations_enabled'=>false);
    }
    private static function error($code,$message,$status=422) {
        return new WP_Error($code,$message,array('status'=>$status));
    }
    public static function normalize($source) {
        $default=self::defaults();
        if(!is_array($source)||count($source)!==count($default)||array_diff(array_keys($source),array_keys($default))||
           $source['schema']!==1||$source['state']!=='draft'||
           $source['handoff']!=='manual_after_human_approval'||
           $source['notification_mode']!=='one_digest_per_batch'||
           $source['provider_mutations_enabled']!==false||
           !is_array($source['assignments'])||
           count($source['assignments'])!==count($default['assignments'])||array_diff(array_keys($source['assignments']),array_keys($default['assignments'])))
            return self::error('team_draft_schema','Invalid or executable team plan.');
        foreach($default['assignments'] as $group=>$row) {
            $a=$source['assignments'][$group];
            if(!is_array($a)||count($a)!==2||array_diff(array_keys($a),array('proposed_role','proposed_user_id'))||
               !is_string($a['proposed_role'])||!in_array($a['proposed_role'],self::ROLES,true)||
               !get_role($a['proposed_role']) || !is_int($a['proposed_user_id']) ||
               $a['proposed_user_id']<0)
                return self::error('team_draft_invalid_role','Role or proposed assignee is invalid.');
            if($a['proposed_user_id']>0) {
                $person=get_user_by('id',$a['proposed_user_id']);
                if(!$person||!in_array($a['proposed_role'],(array)$person->roles,true)||
                   !user_can($person,'edit_others_posts')||
                   !class_exists('MAD4B_SCP_Company_Master_Admin')||
                   !MAD4B_SCP_Company_Master_Admin::managedStaff($a['proposed_user_id']))
                    return self::error('team_draft_outside_company','Only managed company staff with matching roles may be proposed.',403);
            }
        }
        return $source;
    }
    public static function settings() {
        $stored=get_option(self::OPTION,null);
        if(!is_array($stored))return self::defaults();
        $checked=self::normalize($stored);
        return is_wp_error($checked)?self::defaults():$checked;
    }
    public static function revision($value=null) {
        return hash('sha256',wp_json_encode($value??self::settings(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }
    public static function candidates($role=null) {
        $rows=array();
        foreach(get_users(array('number'=>300,'orderby'=>'ID','order'=>'ASC')) as $u) {
            if($role && !in_array($role,(array)$u->roles,true))continue;
            if(!user_can($u,'edit_others_posts')||
               !class_exists('MAD4B_SCP_Company_Master_Admin')||
               !MAD4B_SCP_Company_Master_Admin::managedStaff((int)$u->ID))continue;
            $rows[]=array('user_id'=>(int)$u->ID,'display_name'=>(string)$u->display_name,
                'roles'=>array_values($u->roles));
        }
        return $rows;
    }
    public static function plan() {
        $draft=self::settings();
        $work=MAD4B_SCP_Readiness_Scopes::build();
        $groups=array();
        $managed=self::candidates();
        foreach(MAD4B_SCP_Readiness_Workflow_Options::GROUPS as $key=>$name) {
            $suggest=$draft['assignments'][$key];
            $role=$suggest['proposed_role'];
            $staff=array_values(array_filter($managed,static fn($u)=>in_array($role,$u['roles'],true)));
            $groups[$key]=array('label'=>$name,'proposed_role'=>$role,
                'proposed_user_id'=>$suggest['proposed_user_id'],
                'eligible_managed_staff_count'=>count($staff),
                'packet_count'=>0,'issue_count'=>0,'languages'=>array(),
                'status'=>$suggest['proposed_user_id']?'draft_needs_human_approval':
                    ($staff?'needs_owner_selection':'needs_company_staff'));
        }
        foreach($work as $scope){
            $g=$scope['group'];if(!isset($groups[$g]))continue;
            $groups[$g]['packet_count']++;
            $groups[$g]['issue_count']+=$scope['total'];
            $lang=$scope['language'];
            $groups[$g]['languages'][$lang]=($groups[$g]['languages'][$lang]??0)+$scope['total'];
        }
        return array('contract'=>'mad4b.readiness.team-preparation.v1',
            'mode'=>'draft_only','revision'=>self::revision(),
            'company_role_policy'=>'managed_staff_only',
            'proposals'=>$groups,'draft'=>$draft,
            'eligible_managed_staff'=>$managed,
            'actions_performed'=>array('users_created'=>0,'users_modified'=>0,
                'scopes_assigned'=>0,'tasks_created'=>0,'messages_sent'=>0));
    }
    public static function blueprints() {
        $draft=self::settings();
        $workflow=MAD4B_SCP_Readiness_Workflow_Options::settings();
        $batches=MAD4B_SCP_Readiness_Scopes::batches();
        $scope=MAD4B_SCP_Readiness_Scopes::build();
        $examples=array();
        foreach(array_slice(array_values($scope),0,3) as $row) {
            $task=MAD4B_SCP_Readiness_PreActivation::mock_task($row);
            $task['proposed_role']=$draft['assignments'][$row['group']]['proposed_role']??'';
            $task['assigned_to_real_user']=false;
            $examples[]=$task;
        }
        $digests=array();
        foreach(array_slice(array_values($batches),0,3) as $batch)
            $digests[]=MAD4B_SCP_Readiness_PreActivation::mock_digest($batch);
        return array('contract'=>'mad4b.readiness.provider-blueprints.v1',
            'mode'=>'read_only','all_providers_execution_disabled'=>true,
            'fluent_boards'=>array('board_id'=>$workflow['board_id'],
                'stage_id'=>$workflow['stage_id'],'task_source'=>MAD4B_SCP_Readiness_Scopes::TASK_SOURCE,
                'template_examples'=>$examples,'native_task_created'=>false),
            'bitflows'=>array('trigger'=>'WordPress Action Hooks',
                'hook'=>MAD4B_SCP_Readiness_PreActivation::HOOK,
                'digest_count_expected'=>count($batches),
                'samples'=>$digests,'flow_created'=>false,'messages_sent'=>false),
            'smtp'=>array('mode'=>'inspect_only','test_message_sent'=>false),
            'limits'=>MAD4B_SCP_Readiness_PreActivation::settings());
    }
    public static function routes() {
        $guard=array('permission_callback'=>array(__CLASS__,'can_manage'));
        register_rest_route(self::NS,'/admin/preactivation/team-plan',array(
            array_merge($guard,array('methods'=>'GET','callback'=>array(__CLASS__,'rest_get'))),
            array_merge($guard,array('methods'=>'PATCH','callback'=>array(__CLASS__,'rest_patch'))),
        ));
        register_rest_route(self::NS,'/admin/preactivation/blueprints',array_merge($guard,
            array('methods'=>'GET','callback'=>array(__CLASS__,'rest_blueprints'))));
    }
    public static function rest_get() {return rest_ensure_response(self::plan());}
    public static function rest_blueprints() {return rest_ensure_response(self::blueprints());}
    private static function write($input) {
        if(!self::can_manage())return self::error('team_draft_denied','Company administrator required.',403);
        if(!is_array($input)||!is_string($input['if_match']??null)||
           !hash_equals(self::revision(),$input['if_match']))
            return self::error('team_draft_stale','Refresh current team draft.',409);
        $candidate=self::normalize($input['settings']??null);
        if(is_wp_error($candidate))return $candidate;
        $before=self::settings();
        update_option(self::OPTION,$candidate,false);
        if(self::revision()!==self::revision($candidate))
            return self::error('team_draft_readback','Team draft persistence not confirmed.',500);
        if($before!==$candidate){
            $history=(array)get_option(self::AUDIT,array());
            $history[]=array('actor'=>get_current_user_id(),'at'=>gmdate('c'),
                'previous'=>self::revision($before),'current'=>self::revision($candidate),
                'effect'=>'draft_only');
            update_option(self::AUDIT,array_slice($history,-50),false);
        }
        return self::rest_get();
    }
    public static function rest_patch($request) {return self::write($request->get_json_params());}
    public static function handle_save() {
        if(!self::can_manage())wp_die('Forbidden',403);
        check_admin_referer('mad4b_readiness_team_draft_save');
        $raw=wp_unslash($_POST);
        $draft=self::defaults();
        foreach($draft['assignments'] as $group=>$row){
            $proposedRole=sanitize_key((string)($raw['proposed_role'][$group]??$row['proposed_role']));
            $draft['assignments'][$group]=array(
                'proposed_role'=>$proposedRole,'proposed_user_id'=>absint($raw['proposed_user_id'][$group]??0));
        }
        $r=self::write(array('if_match'=>(string)($raw['revision']??''),'settings'=>$draft));
        if(is_wp_error($r))wp_die(esc_html($r->get_error_code()),$r->get_error_data()['status']??400);
        wp_safe_redirect(add_query_arg(array('page'=>MAD4B_SCP_Readiness_PreActivation::PAGE,'team_saved'=>'1'),
            admin_url('admin.php')));exit;
    }
    public static function render_section() {
        if(!self::can_manage())return;
        $info=self::plan();
        echo '<h2>Team preparation — proposed roles, not assignments</h2>';
        echo '<p>Only company-managed staff may be proposed. Existing WordPress administrators and Master Admins are not automatically selected. No live task handoff or user change occurs here.</p>';
        if(isset($_GET['team_saved']))echo '<div class="notice notice-success"><p>Team draft saved only.</p></div>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="mad4b_readiness_team_draft_save">';
        echo '<input type="hidden" name="revision" value="'.esc_attr($info['revision']).'">';
        wp_nonce_field('mad4b_readiness_team_draft_save');
        echo '<table class="widefat striped"><thead><tr><th>Group</th><th>Gaps</th><th>Packets</th><th>Proposed role</th><th>Proposed employee</th><th>Readiness</th></tr></thead><tbody>';
        foreach($info['proposals'] as $key=>$row){
            echo '<tr><td>'.esc_html($row['label']).'</td><td>'.(int)$row['issue_count'].
                '</td><td>'.(int)$row['packet_count'].'</td><td>';
            echo '<select name="proposed_role['.esc_attr($key).']">';
            foreach(self::ROLES as $role)
                if(get_role($role))echo '<option value="'.esc_attr($role).'" '.selected($row['proposed_role'],$role,false).'>'.esc_html($role).'</option>';
            echo '</select></td><td><select name="proposed_user_id['.esc_attr($key).']"><option value="0">Unassigned (draft)</option>';
            foreach($info['eligible_managed_staff'] as $user){
                if(!in_array($row['proposed_role'],$user['roles'],true))continue;
                echo '<option value="'.(int)$user['user_id'].'" '.
                    selected($row['proposed_user_id'],$user['user_id'],false).'>'.esc_html($user['display_name']).'</option>';
            }
            echo '</select></td><td>'.esc_html(str_replace('_',' ',$row['status'])).'</td></tr>';
        }
        echo '</tbody></table>';
        submit_button('Save team proposal draft only');
        echo '</form><h2>Provider blueprints</h2><p>Fluent Boards: one task per approved packet. Bit Flows: one digest per approved batch. Fluent SMTP: inspection only. No provider action is executed.</p>';
        echo '<p>Read the complete draft and sample payloads from <code>/wp-json/mad4b-content-readiness/v1/admin/preactivation/blueprints</code> (authenticated company administrator).</p>';
    }
}
