<?php
/**
 * Per-site task routing and channel policy. No write or message on bootstrap/read.
 */
defined('ABSPATH') || exit;
final class MAD4B_SCP_Readiness_Workflow_Options {
    const OPTION = 'mad4b_content_readiness_workflow_v1';
    const PAGE = 'mad4b-content-readiness-workflow';
    const GROUPS = array(
        'media'=>'Media & Licensing','origin'=>'Tour Origin / Pickup',
        'wpml'=>'Translations (WPML)','pricing'=>'Rates & Pricing',
        'destinations'=>'Destination Taxonomy','operations'=>'Operations / Forms / Cron',
        'seo'=>'SEO / Dynamic Filters','general'=>'Other Content'
    );
    public static function boot() {
        add_action('admin_menu',array(__CLASS__,'menu'),32);
        add_action('admin_post_mad4b_readiness_workflow_save',array(__CLASS__,'save'));
    }
    public static function group_for_issue($issue) {
        $code=sanitize_key((string)($issue['code']??''));
        if(str_starts_with($code,'media_'))return 'media';
        if(str_starts_with($code,'origin_'))return 'origin';
        if(str_starts_with($code,'wpml_'))return 'wpml';
        if(str_starts_with($code,'expired_rate_')||str_starts_with($code,'rate_')||str_starts_with($code,'owner_reference_'))return 'pricing';
        if(str_starts_with($code,'location_'))return 'destinations';
        if(str_starts_with($code,'dfsb_'))return 'seo';
        if(str_starts_with($code,'form_')||str_starts_with($code,'cron_'))return 'operations';
        return 'general';
    }
    public static function settings() {
        $stored=(array)get_option(self::OPTION,array());
        $owners=array();$roles=array();
        foreach(self::GROUPS as $group=>$label){
            $owners[$group]=absint($stored['owners'][$group]??0);
            $roles[$group]=sanitize_key((string)($stored['owner_roles'][$group]??''));
        }
        return array(
            'board_id'=>absint($stored['board_id']??0),
            'stage_id'=>absint($stored['stage_id']??0),
            'owners'=>$owners,'owner_roles'=>$roles,
            'dashboard_alerts'=>!array_key_exists('dashboard_alerts',$stored)||!empty($stored['dashboard_alerts']),
            'board_tasks'=>!empty($stored['board_tasks']),
            'email_notifications'=>!empty($stored['email_notifications']),
            'bitflows_notifications'=>!empty($stored['bitflows_notifications']),
            'minimum_severity'=>in_array($stored['minimum_severity']??'high',array('critical','high','medium'),true)?($stored['minimum_severity']??'high'):'high',
            'batch_limit'=>max(1,min(25,absint($stored['batch_limit']??5))),
        );
    }
    public static function assigned_user($issue) {
        $settings=self::settings();
        $id=absint($settings['owners'][self::group_for_issue($issue)]??0);
        if($id){
            $user=get_user_by('id',$id);
            return $user && user_can($user,'edit_others_posts')?$id:0;
        }
        $role=$settings['owner_roles'][self::group_for_issue($issue)]??'';
        if(!$role)return 0;
        $candidates=get_users(array('role'=>$role,'number'=>2));
        if(count($candidates)!==1)return 0;
        return user_can($candidates[0],'edit_others_posts')?(int)$candidates[0]->ID:0;
    }
    public static function validate_board_stage($board,$stage) {
        global $wpdb;
        if(!$board||!$stage||!class_exists('FluentBoards\\App\\Models\\Board'))return false;
        $exists=\FluentBoards\App\Models\Board::find($board);
        if(!$exists)return false;
        $s=\FluentBoards\App\Models\Stage::find($stage);
        return $s && (int)$s->board_id===(int)$board && !$s->archived_at;
    }
    public static function menu() {
        add_submenu_page('mad4b-control-plane','Content Follow-up Settings',
            'Content Follow-up','manage_options',self::PAGE,array(__CLASS__,'render'));
    }
    public static function save() {
        if(!current_user_can('manage_options'))wp_die('Forbidden',403);
        check_admin_referer('mad4b_readiness_workflow_save');
        $in=wp_unslash($_POST);
        $board=absint($in['board_id']??0);
        $stage=absint($in['stage_id']??0);
        if(($board||$stage)&&!self::validate_board_stage($board,$stage))
            wp_die('Board/stage mismatch: select an existing stage in this board.',400);
        $users=array();$roles=array();
        $registered_roles=array_keys(wp_roles()->roles);
        foreach(self::GROUPS as $group=>$label) {
            $role=sanitize_key((string)($in['owner_roles'][$group]??''));
            $roles[$group]=in_array($role,$registered_roles,true)?$role:'';
            $id=absint($in['owners'][$group]??0);
            $user=$id?get_user_by('id',$id):null;
            $users[$group]=$user&&user_can($user,'edit_others_posts')?$id:0;
        }
        $channels=array(
            'dashboard_alerts'=>!empty($in['dashboard_alerts']),
            'board_tasks'=>!empty($in['board_tasks']),
            'email_notifications'=>!empty($in['email_notifications']),
            'bitflows_notifications'=>!empty($in['bitflows_notifications']),
        );
        // Enabling external dispatch requires the current governed mutation authority.
        if($channels['email_notifications']||$channels['bitflows_notifications'])
            wp_die('External notification adapters are not yet certified. Use Fluent Boards native notifications and dashboard alerts.',403);
        $settings=array_merge(array(
            'board_id'=>$board,'stage_id'=>$stage,'owners'=>$users,'owner_roles'=>$roles,
            'minimum_severity'=>in_array($in['minimum_severity']??'',array('critical','high','medium'),true)?$in['minimum_severity']:'high',
            'batch_limit'=>max(1,min(25,absint($in['batch_limit']??5))),
        ),$channels);
        if($channels['board_tasks']&&!self::validate_board_stage($board,$stage))
            wp_die('A valid Fluent Boards stage must be configured first.',400);
        update_option(self::OPTION,$settings,false);
        $readback=get_option(self::OPTION,array());
        if(serialize($readback)!==serialize($settings))wp_die('Readback mismatch; settings not confirmed.',500);
        wp_safe_redirect(add_query_arg(array('page'=>self::PAGE,'saved'=>'1'),admin_url('admin.php')));
        exit;
    }
    private static function select($name,$value,$options) {
        echo '<select name="'.esc_attr($name).'">';
        foreach($options as $id=>$label)echo '<option value="'.esc_attr((string)$id).'" '.selected((string)$value,(string)$id,false).'>'.esc_html($label).'</option>';
        echo '</select>';
    }
    public static function render() {
        if(!current_user_can('manage_options'))wp_die('Forbidden',403);
        $s=self::settings();
        $gate=class_exists('MAD4B_SCP_Policy')?MAD4B_SCP_Policy::mutation_gate_status():array();
        $authorized=class_exists('MAD4B_SCP_Policy')&&MAD4B_SCP_Policy::can_mutate();
        $boards=array(0=>'— Select board —');$stages=array(0=>'— Select stage —');
        if(class_exists('FluentBoards\\App\\Models\\Board')) {
            foreach(\FluentBoards\App\Models\Board::whereNull('archived_at')->get() as $board) {
                $boards[(int)$board->id]=$board->title.' (#'.$board->id.')';
                foreach(\FluentBoards\App\Models\Stage::where('board_id',$board->id)->whereNull('archived_at')->orderBy('position')->get() as $stage)
                    $stages[(int)$stage->id]=$board->title.' / '.$stage->title.' (#'.$stage->id.')';
            }
        }
        $users=array(0=>'— Unassigned —');
        foreach(get_users(array('number'=>300)) as $user)
            if(user_can($user,'edit_others_posts'))
                $users[(int)$user->ID]=$user->display_name.' (#'.$user->ID.')';
        echo '<div class="wrap"><h1>Content Follow-up &amp; Notifications</h1>';
        echo '<p>Company-local configuration. Source scans and dashboard alerts are independent of external task creation and outbound notifications.</p>';
        if(isset($_GET['saved']))echo '<div class="notice notice-success"><p>Settings saved and verified.</p></div>';
        echo '<p><strong>Current site:</strong> '.esc_html(home_url()).' | <strong>Governed task write:</strong> '.($authorized?'Ready':'Blocked').'</p>';
        $localBound=class_exists('MAD4B_SCP_Local_Client')&&MAD4B_SCP_Local_Client::bound();
        echo '<p><strong>VPS Local Client:</strong> '.($localBound?'Origin-bound':'Not bound').' | <a href="'.
            esc_url(admin_url('tools.php?page=mad4b-local-client')).'">Manage local client credential</a></p>';
        echo '<p class="description">Verified local-agent task writes use POST /wp-json/mad4b-content-readiness/v1/sync-one or /sync-batch with approved WordPress Application Password; a WordPress admin cookie alone does not grant Agent authority.</p>';
        if(!$authorized)echo '<div class="notice notice-warning"><p>Site Profile / identity authority is not ready. Board setup and dashboard alerts work, but task creation and outbound notifications remain blocked.</p></div>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('mad4b_readiness_workflow_save');
        echo '<input type="hidden" name="action" value="mad4b_readiness_workflow_save">';
        echo '<h2>Fluent Boards</h2><table class="form-table"><tbody><tr><th>Board</th><td>';
        self::select('board_id',$s['board_id'],$boards);
        echo '<p class="description">Select a Board and one of its stages; mismatched combinations are rejected.</p></td></tr><tr><th>New issue stage</th><td>';
        self::select('stage_id',$s['stage_id'],$stages);
        echo '</td></tr></tbody></table>';
        echo '<h2>Responsible team members</h2><p>Select a role, then optionally a named owner. A role is auto-resolved only when there is exactly one eligible member; otherwise the task remains unassigned.</p><table class="form-table"><tbody>';
        $roles=array(''=>'— No role —');
        foreach(wp_roles()->roles as $slug=>$details)$roles[$slug]=$details['name'].' ('. $slug .')';
        foreach(self::GROUPS as $group=>$label){
            echo '<tr><th>'.esc_html($label).'</th><td>';
            self::select('owner_roles['.$group.']',$s['owner_roles'][$group],$roles);
            echo ' &nbsp; Specific user: ';
            self::select('owners['.$group.']',$s['owners'][$group],$users);
            echo '</td></tr>';
        }
        echo '</tbody></table><h2>Channels and execution controls</h2><table class="form-table"><tbody>';
        foreach(array(
            'dashboard_alerts'=>'WordPress dashboard / admin notices',
            'board_tasks'=>'Allow tasks after governed mutation approval',
            'email_notifications'=>'Extra direct Email (blocked until separate adapter certification)',
            'bitflows_notifications'=>'Bit Flows / WhatsApp (blocked until adapter certification)'
        ) as $key=>$label) {
            echo '<tr><th>'.esc_html($label).'</th><td><label><input type="checkbox" name="'.esc_attr($key).'" value="1" '.checked($s[$key],true,false).(in_array($key,array('email_notifications','bitflows_notifications'),true)?' disabled':'').'> Enabled</label></td></tr>';
        }
        echo '<tr><th>Minimum alert severity</th><td>';
        self::select('minimum_severity',$s['minimum_severity'],array('critical'=>'Critical only','high'=>'High and critical','medium'=>'Medium and above'));
        echo '</td></tr><tr><th>Maximum tasks per approved run</th><td><input type="number" min="1" max="25" name="batch_limit" value="'.(int)$s['batch_limit'].'"></td></tr>';
        echo '</tbody></table>';
        submit_button('Save company follow-up settings');
        echo '</form>';
        echo '<h2>Governed issue synchronization</h2><p>Manual batch only. Tasks are created through the native Fluent Boards service after site identity and Control Plane write permission are approved. The operation cannot modify customer orders or rates.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('mad4b_readiness_batch_sync');
        echo '<input type="hidden" name="action" value="mad4b_readiness_batch_sync">';
        echo '<button class="button button-secondary" '.disabled(!$authorized||!$s['board_tasks'],true,false).'>Sync eligible issues (up to '.(int)$s['batch_limit'].')</button></form>';
        echo '<p><a href="'.esc_url(admin_url('admin.php?page=mad4b-content-readiness-alerts')).'">View Content Issues</a></p></div>';
    }
}
