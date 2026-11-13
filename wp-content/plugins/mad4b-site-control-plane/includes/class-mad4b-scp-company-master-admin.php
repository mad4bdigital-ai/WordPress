<?php
/** Local tenant-bound Company Master Admin management, never a WP administrator. */
defined('ABSPATH') || exit;
final class MAD4B_SCP_Company_Master_Admin {
    const CAP='mad4b_manage_company_operations';
    const VERSION='20261028_v3';
    const MIGRATION='mad4b_company_master_admin_role_version';
    const STAFF_OWNER='mad4b_company_team_manager';
    const ALLOWED_ROLES=array('editor','author','operator','company_translation_manager');
    const BOARD_OWNER='mad4b_readiness';
    public static function migrate():void {
        if(wp_get_environment_type()!=='local')return;
        self::applyOwnerApprovedMigration();
    }
    /** Explicit native company-admin setup. On Staging requires real owner browser approval. */
    public static function applyOwnerApprovedMigration():void {
        if(get_option(self::MIGRATION)==self::VERSION)return;
        if(wp_get_environment_type()==='staging' && (
            !class_exists('MAD4B_SCP_Company_Owner_Activation') ||
            !MAD4B_SCP_Company_Owner_Activation::browserOwnerAuthorized()))
            throw new RuntimeException('Authenticated Staging administrator with verified nonce required.');
        if(!in_array(wp_get_environment_type(),['local','staging'],true))
            throw new RuntimeException('Company setup only allowed on Local or Staging.');
        $master=get_role('master_admin');
        if(!$master)return;
        $allow=array(self::CAP,'mad4b_work_company_tasks','fluentform_forms_manager','edit_others_pages',
            'edit_others_posts','edit_published_pages','publish_pages','edit_pages',
            'edit_posts','upload_files','wpml_manage_translation_management',
            // WooCommerce commerce OPERATION only: no manage_woocommerce,
            // no payment/system configuration and no permanent order deletion.
            'edit_product','read_product','edit_products','edit_others_products',
            'publish_products','read_private_products','edit_private_products',
            'edit_published_products','manage_product_terms','edit_product_terms',
            'assign_product_terms','edit_shop_order','read_shop_order',
            'edit_shop_orders','edit_others_shop_orders','publish_shop_orders',
            'read_private_shop_orders','edit_private_shop_orders','edit_published_shop_orders',
            'edit_shop_coupon','read_shop_coupon','edit_shop_coupons',
            'edit_others_shop_coupons','publish_shop_coupons',
            'edit_published_shop_coupons','view_woocommerce_reports',
            'wpml_operate_woocommerce_multilingual');
        foreach($allow as$cap)$master->add_cap($cap,true);
        $remove=array('create_users','edit_users','promote_users','install_languages',
            'rank_math_role_manager','wpml_manage_languages','wpml_manage_troubleshooting');
        foreach($remove as$cap)$master->remove_cap($cap);
        foreach(array('manage_options','activate_plugins','install_plugins','update_plugins',
            'delete_plugins','edit_plugins','edit_theme_options','delete_users',
            'remove_users','fluentform_full_access','fluentform_settings_manager')as$cap)$master->remove_cap($cap);
        $admin=get_role('administrator');if($admin){$admin->add_cap(self::CAP,true);$admin->add_cap('mad4b_work_company_tasks',true);}
        foreach(['editor','author','operator','company_translation_manager']as$staffRole){
            $staff=get_role($staffRole);
            if($staff)$staff->add_cap('mad4b_work_company_tasks',true);
        }
        $translator=get_role('company_translation_manager');
        if(!$translator){
            $editor=get_role('editor');$caps=$editor?(array)$editor->capabilities:array('read'=>true,'edit_posts'=>true);
            foreach(array('manage_options','create_users','edit_users','promote_users',
                'delete_users','install_plugins','activate_plugins','edit_theme_options')as$key)unset($caps[$key]);
            $caps['mad4b_work_company_tasks']=true;
            $caps['manage_translations']=true;
            $caps['wpml_manage_translation_management']=true;
            $caps['edit_private_posts']=true;
            $caps['read']=true;
            add_role('company_translation_manager','Company Translation Manager',$caps);
        }
        update_option(self::MIGRATION,self::VERSION,false);
    }
    public static function can():bool {return current_user_can(self::CAP);}
    private static function forbidden():WP_Error {
        return new WP_Error('mad4b_company_access_denied','Company management permission required.',array('status'=>403));
    }
    private static function blocked(string $code,string $message,int $status=422):WP_Error {
        return new WP_Error($code,$message,array('status'=>$status));
    }
    public static function allowedStaff(WP_User $u):bool {
        if(in_array('administrator',$u->roles,true)||in_array('master_admin',$u->roles,true))return false;
        foreach((array)$u->roles as$role)if(!in_array($role,self::ALLOWED_ROLES,true))return false;
        return !empty($u->roles);
    }
    public static function managedStaff(int $userId):bool {
        $u=get_userdata($userId);if(!$u||!self::allowedStaff($u))return false;
        if(get_user_meta($userId,'mad4b_company_suspension_v1',true))return false;
        if(get_user_meta($userId,self::STAFF_OWNER,true))return true;
        if(!class_exists('MAD4B_SCP_Company_Communication_Team'))return false;
        foreach(MAD4B_SCP_Company_Communication_Team::members('internal')as$item){
            if(!empty($item['enabled'])&&!empty($item['valid'])&&(int)$item['wp_user_id']===$userId)return true;
        }
        return false;
    }
    public static function createStaff(array $params){
        if(!self::can())return self::forbidden();
        $role=sanitize_key((string)($params['role']??''));
        if(!in_array($role,self::ALLOWED_ROLES,true)||!get_role($role))return self::blocked('mad4b_staff_role_denied','Only approved internal staff roles may be created.');
        $email=sanitize_email((string)($params['email']??''));
        if(!is_email($email)||email_exists($email))return self::blocked('mad4b_staff_email_invalid','Provide a unique valid work email.');
        $login=sanitize_user((string)($params['username']??''),true);
        if(!preg_match('/^[a-zA-Z0-9_.-]{4,48}$/',$login)||username_exists($login))
            return self::blocked('mad4b_staff_login_invalid','Provide a unique safe username (4-48 characters).');
        // Never create a user on page load. This routine requires an explicit
        // authenticated POST, checked by the REST host or wp-admin nonce.
        if(class_exists('MAD4B_SCP_Company_Operations_Policy')){
            $max=(int)MAD4B_SCP_Company_Operations_Policy::get()['max_staff_accounts'];
            $existing=count(get_users(['meta_key'=>self::STAFF_OWNER,'fields'=>'ID','number'=>$max+1]));
            if($existing >= $max)return self::blocked('mad4b_staff_limit_reached','Company staff account limit reached.',409);
        }
        $newId=wp_insert_user(array('user_login'=>$login,'user_email'=>$email,
            'user_pass'=>wp_generate_password(40,true,true),'role'=>$role,
            'display_name'=>sanitize_text_field((string)($params['display_name']??$login))));
        if(is_wp_error($newId))return $newId;
        update_user_meta($newId,self::STAFF_OWNER,get_current_user_id());
        // WPML manager discovery reads the user's actual capability metadata,
        // not just role-level capabilities. Register it directly on creation.
        if($role==='company_translation_manager'){
            $created=get_userdata((int)$newId);
            if($created)$created->add_cap('manage_translations',true);
        }
        // Do not email a credential or welcome notification from the Local sandbox.
        return array('user_id'=>(int)$newId,'role'=>$role,'invitation_status'=>'held_local_lab','source'=>'wordpress_user');
    }
    public static function setTranslationManager(int $userId,bool $enabled){
        if(!self::can())return self::forbidden();
        if($userId===get_current_user_id())return self::blocked('mad4b_staff_self_elevation_denied','Self-elevation is forbidden.',403);
        if(!self::managedStaff($userId))return self::blocked('mad4b_staff_not_managed','Only approved internal staff can become translation managers.',403);
        $u=get_userdata($userId);
        if(!$u)return self::blocked('mad4b_staff_not_found','Unknown WP User.',404);
        // WPML's own capability is the actual translator-manager authority.
        if($enabled){
            if(!class_exists('WPML_Translation_Manager_Records'))return self::blocked('mad4b_wpml_not_ready','WPML translation roles not available.',503);
            $u->add_cap('manage_translations',true);
        }else{
            // A translation-specific ROLE also supplies this capability.
            // Revoking a per-user override alone would leave that role active.
            if(in_array('company_translation_manager',(array)$u->roles,true))$u->set_role('editor');
            $u->remove_cap('manage_translations');
            $u->add_cap('manage_translations',false);
        }
        $checked=user_can($userId,'manage_translations');
        if($checked!==$enabled)return self::blocked('mad4b_wpml_manager_unconfirmed','WPML capability readback failed.',502);
        return array('user_id'=>$userId,'wpml_translation_manager'=>$checked,'source'=>'WPML manage_translations capability');
    }
    public static function assignTask(int $taskId,int $userId){
        if(!self::can())return self::forbidden();
        if(!self::managedStaff($userId))return self::blocked('mad4b_task_assignee_not_managed','Assignee must be an approved internal staff member.',403);
        if(!class_exists('MAD4B_SCP_Readiness_Workflow_Options') ||
           !class_exists('FluentBoards\\App\\Models\\Task'))return self::blocked('mad4b_task_provider_unavailable','Fluent Boards not available.',503);
        $settings=MAD4B_SCP_Readiness_Workflow_Options::settings();
        $boardId=absint($settings['board_id']??0);
        if(!$boardId)return self::blocked('mad4b_company_board_not_configured','Company task board must be configured.',409);
        $task=\FluentBoards\App\Models\Task::find($taskId);
        if(!$task||(int)$task->board_id!==$boardId)return self::blocked('mad4b_task_outside_company_board','Task is not in the authorized company board.',403);
        // Changes are initiated only through authenticated POST; the recipient
        // and board are both checked against tenant-owned source records.
        try {
            $assignedBefore=array_map(static fn($u)=>(int)($u->ID??$u->id??0),
                iterator_to_array($task->assignees));
            if(in_array($userId,$assignedBefore,true))return array(
                'task_id'=>$taskId,'assignee_user_id'=>$userId,'board_id'=>$boardId,
                'confirmed'=>true,'already_assigned'=>true);
            $boardSvc=new \FluentBoards\App\Services\BoardService();
            if(!$boardSvc->isAlreadyMember($boardId,$userId)){
                $joined=$boardSvc->addMembersInBoard($boardId,$userId);
                if(!$joined)return self::blocked('mad4b_board_membership_failed','Could not add staff member to the company board.',502);
            }
            $svc=new \FluentBoards\App\Services\TaskService();
            $svc->updateAssignee($userId,$task);
            $task->refresh();
            $assigned=array_map(static fn($u)=>(int)($u->ID??$u->id??0),iterator_to_array($task->assignees));
            if(!in_array($userId,$assigned,true))return self::blocked('mad4b_task_assignment_unconfirmed','Native board task readback failed.',502);
        }catch(Throwable $e){return self::blocked('mad4b_task_native_error','Fluent Boards native assignment failed.',502);}
        return array('task_id'=>$taskId,'assignee_user_id'=>$userId,'board_id'=>$boardId,'confirmed'=>true);
    }
    public static function ensureBoardAccess():void {
        if(!self::can() || !in_array('master_admin',(array)wp_get_current_user()->roles,true))return;
        if(!class_exists('MAD4B_SCP_Readiness_Workflow_Options') ||
           !class_exists('FluentBoards\\App\\Services\\BoardService'))return;
        $settings=MAD4B_SCP_Readiness_Workflow_Options::settings();
        $boardId=absint($settings['board_id']??0);
        if(!$boardId || !MAD4B_SCP_Readiness_Workflow_Options::validate_board_stage(
            $boardId,absint($settings['stage_id']??0)))return;
        $service=new \FluentBoards\App\Services\BoardService();
        $member=$service->isAlreadyMember($boardId,get_current_user_id());
        if($member && !empty(((array)$member->settings)['is_admin']))return;
        // Native Fluent Boards membership (not WordPress admin privileges).
        $service->syncBoardUserRole($boardId,get_current_user_id(),'admin');
    }
    public static function screen():void {
        if(!self::can())wp_die('Not authorized');
        $team=class_exists('MAD4B_SCP_Company_Communication_Team')?MAD4B_SCP_Company_Communication_Team::members('internal'):array();
        $users=array();foreach($team as$item){
            $id=absint($item['wp_user_id']??0);
            if($id && self::managedStaff($id))$users[$id]=$item;
        }
        $s=class_exists('MAD4B_SCP_Readiness_Workflow_Options')?MAD4B_SCP_Readiness_Workflow_Options::settings():array();
        $boardId=absint($s['board_id']??0);
        echo '<div class="wrap"><h1>Company Team &amp; Translation Operations</h1>';
        $status=sanitize_key((string)($_GET['company_action_status']??''));
        if($status)echo '<div class="notice '.($status==='success'?'notice-success':'notice-warning').'"><p>Team operation: '.esc_html($status).'. See operational audit for details.</p></div>';
        echo '<p>Staff identity is inherited from WordPress Users. Account creation, translation assignment and board tasks are restricted to approved staff roles. WordPress Settings and plugin management remain unavailable.</p>';
        echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=company-communication-operations')).'">Communication &amp; Operations</a> ';
        echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=company-operations-policies')).'">Operations Policies</a> ';
        echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=fluent-boards')).'">Fluent Boards</a> ';
        echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=fluent_forms')).'">Fluent Forms</a> ';
        echo '<a class="button" href="'.esc_url(admin_url('edit.php?post_type=shop_order')).'">Orders</a> ';
        echo '<a class="button" href="'.esc_url(admin_url('edit.php?post_type=product')).'">Products</a></p>';
        echo '<h2>Create an internal staff account</h2>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('mad4b_company_staff_operation');
        echo '<input type="hidden" name="action" value="mad4b_company_staff_operation"><input type="hidden" name="operation" value="create">';
        echo '<p><label>Username <input name="username" required maxlength="48" autocomplete="off"></label> ';
        echo '<label>Work email <input type="email" name="email" required></label> ';
        echo '<label>Display name <input name="display_name" maxlength="80"></label> ';
        echo '<label>Role <select name="role">';
        foreach(self::ALLOWED_ROLES as$role)if(get_role($role))echo '<option value="'.esc_attr($role).'">'.esc_html(get_role($role)->name).'</option>';
        echo '</select></label> <button class="button button-primary">Create staff account</button></p>';
        echo '<p class="description">A strong random password is generated. Local Lab does not send welcome mail. Assign the new WP User under Communication &amp; Operations to include them in the internal team.</p></form>';
        echo '<h2>Registered internal team</h2><table class="widefat striped"><thead><tr><th>User ID</th><th>Name</th><th>Responsibility</th><th>WPML Translation Manager</th></tr></thead><tbody>';
        foreach($users as$uid=>$item)echo '<tr><td>'.esc_html((string)$uid).'</td><td>'.esc_html((string)$item['display_name']).'</td><td>'.esc_html((string)$item['responsibility']).'</td><td>'.(user_can($uid,'manage_translations')?'Yes':'No').'</td></tr>';
        echo '</tbody></table>';
        echo '<h2>Company-owned user lifecycle</h2>';
        echo '<p>Disabling a staff member revokes sessions. Permanent removal is only available if explicitly enabled in Company Policies, after suspension and source-reference checks.</p>';
        $owned=get_users(['meta_key'=>self::STAFF_OWNER,'number'=>100,'orderby'=>'registered','order'=>'DESC']);
        echo '<table class="widefat striped"><thead><tr><th>Staff</th><th>Role/state</th><th>Action</th></tr></thead><tbody>';
        foreach($owned as $person){
            if(!($person instanceof WP_User) || in_array('administrator',$person->roles,true) || in_array('master_admin',$person->roles,true))continue;
            $uid=(int)$person->ID;
            $suspended=is_array(get_user_meta($uid,'mad4b_company_suspension_v1',true));
            echo '<tr><td>'.esc_html($person->display_name).' (#'.esc_html((string)$uid).')</td><td>'.esc_html(implode(',',$person->roles)).($suspended?' — Suspended':' — Active').'</td><td>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('mad4b_company_staff_operation');
            echo '<input type="hidden" name="action" value="mad4b_company_staff_operation">';
            echo '<input type="hidden" name="operation" value="user_lifecycle">';
            echo '<input type="hidden" name="user_id" value="'.esc_attr((string)$uid).'">';
            echo '<select name="user_action">';
            if(!$suspended)echo '<option value="suspend">Suspend &amp; revoke sessions</option>';
            else{
                echo '<option value="restore">Restore staff</option>';
                echo '<option value="delete">Permanently delete (policy governed)</option>';
            }
            echo '</select> <input name="confirm_username" placeholder="Exact username for deletion" autocomplete="off"> <button class="button">Apply</button></form></td></tr>';
        }
        echo '</tbody></table>';
        echo '<h2>Translation and task assignment</h2>';
        if(!$users)echo '<p>No enabled internal team members yet. Add a WP User in Communication &amp; Operations first.</p>';
        else{
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('mad4b_company_staff_operation');
            echo '<input type="hidden" name="action" value="mad4b_company_staff_operation"><input type="hidden" name="operation" value="translation">';
            echo '<label>Internal staff <select name="user_id">';
            foreach($users as$uid=>$u)echo '<option value="'.esc_attr((string)$uid).'">'.esc_html($u['display_name']).'</option>';
            echo '</select></label> <label>WPML manager <select name="enabled"><option value="1">Assign</option><option value="0">Revoke</option></select></label> <button class="button">Apply</button></form>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('mad4b_company_staff_operation');
            echo '<input type="hidden" name="action" value="mad4b_company_staff_operation"><input type="hidden" name="operation" value="task">';
            echo '<p><label>Fluent Boards task ID <input type="number" name="task_id" min="1" required></label> ';
            echo '<label>Assignee <select name="user_id">';
            foreach($users as$uid=>$u)echo '<option value="'.esc_attr((string)$uid).'">'.esc_html($u['display_name']).'</option>';
            echo '</select></label> <button class="button">Assign task</button> <small>Approved board #'.esc_html((string)$boardId).' only.</small></p></form>';
        }
        echo '</div>';
    }
    public static function adminPost():void {
        if(!self::can())wp_die('Not authorized',403);
        check_admin_referer('mad4b_company_staff_operation');
        $op=sanitize_key((string)($_POST['operation']??''));
        if($op==='create'){
            $r=self::createStaff(array(
                'role'=>wp_unslash($_POST['role']??''),'email'=>wp_unslash($_POST['email']??''),
                'username'=>wp_unslash($_POST['username']??''),
                'display_name'=>wp_unslash($_POST['display_name']??'')));
        }elseif($op==='translation'){
            $r=self::setTranslationManager(absint($_POST['user_id']??0),($_POST['enabled']??'0')==='1');
        }elseif($op==='task'){
            $r=self::assignTask(absint($_POST['task_id']??0),absint($_POST['user_id']??0));
        }elseif($op==='user_lifecycle'){
            $id=absint($_POST['user_id']??0);$cmd=sanitize_key((string)($_POST['user_action']??''));
            if($cmd==='suspend')$r=MAD4B_SCP_Company_User_Lifecycle::suspend($id);
            elseif($cmd==='restore')$r=MAD4B_SCP_Company_User_Lifecycle::restore($id);
            elseif($cmd==='delete')$r=MAD4B_SCP_Company_User_Lifecycle::permanentDelete($id,
                sanitize_user((string)wp_unslash($_POST['confirm_username']??''),true));
            else $r=self::blocked('company_user_action_invalid','Unsupported staff action');
        }else{$r=self::blocked('mad4b_company_operation_invalid','Unsupported operation');}
        $status=is_wp_error($r)?'blocked':'success';
        wp_safe_redirect(add_query_arg('company_action_status',$status,
            admin_url('admin.php?page=mad4b-company')));exit;
    }
    public static function menus():void {
        add_menu_page('Company Operations','Company',self::CAP,'mad4b-company',array(__CLASS__,'screen'),'dashicons-groups',33);
        add_submenu_page('mad4b-company','Team & Translation','Team & Translation',self::CAP,'mad4b-company',array(__CLASS__,'screen'));
    }
    public static function routes():void {
        register_rest_route('mad4b/v1','/admin/company-team',array('methods'=>'GET','callback'=>array(__CLASS__,'overview'),
            'permission_callback'=>array(__CLASS__,'can')));
        register_rest_route('mad4b/v1','/admin/company-team/create',array('methods'=>'POST','callback'=>static function($r){
            $res=self::createStaff((array)$r->get_json_params());return is_wp_error($res)?$res:rest_ensure_response($res);
        },'permission_callback'=>array(__CLASS__,'can')));
        register_rest_route('mad4b/v1','/admin/company-team/translation-manager',array('methods'=>'POST','callback'=>static function($r){
            $res=self::setTranslationManager(absint($r['user_id']),$r['enabled']===true);
            return is_wp_error($res)?$res:rest_ensure_response($res);
        },'permission_callback'=>array(__CLASS__,'can')));
        register_rest_route('mad4b/v1','/admin/company-team/assign-task',array('methods'=>'POST','callback'=>static function($r){
            $res=self::assignTask(absint($r['task_id']),absint($r['user_id']));
            return is_wp_error($res)?$res:rest_ensure_response($res);
        },'permission_callback'=>array(__CLASS__,'can')));
    }
    public static function overview($req){
        $c=class_exists('MAD4B_SCP_Company_Communication_Team')?
            MAD4B_SCP_Company_Communication_Team::members('internal'):array();
        $staff=array();foreach($c as$u)if(!empty($u['wp_user_id'])&&self::managedStaff((int)$u['wp_user_id'])){
            $staff[]=array('user_id'=>(int)$u['wp_user_id'],'display_name'=>$u['display_name'],
            'role'=>$u['responsibility'],'enabled'=>$u['enabled'],
            'translation_manager'=>user_can((int)$u['wp_user_id'],'manage_translations'));
        }
        $s=class_exists('MAD4B_SCP_Readiness_Workflow_Options')?MAD4B_SCP_Readiness_Workflow_Options::settings():array();
        $res=rest_ensure_response(array('contract'=>'mad4b.company-operations.v1','source'=>'WordPress Users',
            'team'=>$staff,'board_id'=>absint($s['board_id']??0),
            'allowed_staff_roles'=>self::ALLOWED_ROLES,
            'can_create_staff'=>true,
            'can_assign_task'=>true,
            'global_settings_cap'=>current_user_can('manage_options'),
            'plugin_install_cap'=>current_user_can('install_plugins')));
        $res->header('Cache-Control','private, no-store');return $res;
    }
    public static function register():void {
        add_action('init',array(__CLASS__,'migrate'),99);
        add_action('admin_menu',array(__CLASS__,'menus'),12);
        add_action('admin_init',array(__CLASS__,'ensureBoardAccess'),80);
        add_action('rest_api_init',array(__CLASS__,'routes'),30);
        add_action('admin_post_mad4b_company_staff_operation',array(__CLASS__,'adminPost'));
    }
}
MAD4B_SCP_Company_Master_Admin::register();
