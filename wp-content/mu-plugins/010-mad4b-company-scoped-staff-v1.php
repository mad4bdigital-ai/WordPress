<?php
/**
 * Plugin Name: MAD4B Scoped Staff Management
 * Description: Create and change company staff roles only within the current operator's capability ceiling.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

final class MAD4B_Scoped_Staff_Management_V1 {
    const OWNER_META = 'mad4b_company_team_manager';
    const ROLE = 'mad4b_company_content_editor';
    const PAGE = 'mad4b-company-staff-roles';
    const VALID_ROLES = ['subscriber', 'contributor', 'author', 'editor', 'company_translation_manager', self::ROLE];
    const FORBIDDEN = ['administrator','master_admin','shop_manager','super_admin','operator'];
    const SYSTEM_CAPS = [
        'manage_options','manage_network','manage_sites','manage_network_users','manage_network_plugins',
        'activate_plugins','install_plugins','update_plugins','delete_plugins','edit_plugins',
        'edit_themes','install_themes','switch_themes','update_themes','delete_themes','edit_theme_options',
        'create_users','edit_users','promote_users','delete_users','remove_users',
        'manage_woocommerce','manage_links','unfiltered_upload','unfiltered_html','update_core'
    ];

    public static function boot():void {
        add_action('init', [__CLASS__, 'register_limited_editor'], 115);
        add_action('admin_menu', [__CLASS__, 'register_menu'], 155);
        add_action('admin_post_mad4b_scoped_staff', [__CLASS__, 'handle_post']);
    }

    public static function can_operate():bool {
        return current_user_can('mad4b_manage_company_operations')
            && current_user_can('mad4b_work_company_tasks')
            && current_user_can('edit_others_posts');
    }

    public static function register_limited_editor():void {
        if ($existing=get_role(self::ROLE)) {
            if ($existing->has_cap('manage_links')) $existing->remove_cap('manage_links');
            return;
        }
        $manager = get_role('master_admin');
        $editor = get_role('editor');
        if (!$manager || !$editor) return;
        $caps = ['read' => true];
        foreach ((array)$editor->capabilities as $cap=>$enabled) {
            if ($enabled && $manager->has_cap($cap) && !in_array($cap,self::SYSTEM_CAPS,true)) {
                $caps[$cap] = true;
            }
        }
        foreach (['mad4b_work_company_tasks','mad4b_propose_company_content'] as $cap) {
            if ($manager->has_cap($cap)) $caps[$cap]=true;
        }
        add_role(self::ROLE,'MAD4B Company Content Editor',$caps);
    }

    public static function role_allowed(string $name,?int $actorId=null):bool {
        if (!in_array($name,self::VALID_ROLES,true) || in_array($name,self::FORBIDDEN,true)) return false;
        $role = get_role($name);
        $id = $actorId ?? get_current_user_id();
        if (!$role || !$id || !user_can($id,'mad4b_manage_company_operations') ||
            !user_can($id,'mad4b_work_company_tasks')) return false;
        foreach ($role->capabilities as $cap=>$on) {
            if (!$on) continue;
            if (in_array($cap,self::SYSTEM_CAPS,true) || !user_can($id,$cap)) return false;
        }
        return true;
    }

    private static function staff_record(int $id):?WP_User {
        if ($id<=0 || $id===get_current_user_id()) return null;
        $u = get_userdata($id);
        if (!$u || !($u instanceof WP_User)) return null;
        $roles = (array)$u->roles;
        if (count($roles)!==1 || in_array($roles[0],self::FORBIDDEN,true)
            || !in_array($roles[0],self::VALID_ROLES,true)) return null;
        if (!get_user_meta($id,self::OWNER_META,true)) return null;
        if (get_user_meta($id,'mad4b_company_suspension_v1',true)) return null;
        // An existing user with direct capabilities beyond this actor is not safe to alter.
        foreach ((array)$u->caps as $cap=>$on) {
            if (in_array($cap,$roles,true)) continue;
            if ($on && (!user_can(get_current_user_id(),$cap) || in_array($cap,self::SYSTEM_CAPS,true))) return null;
        }
        if (!self::role_allowed($roles[0])) return null;
        return $u;
    }

    public static function promote(int $id,string $role) {
        if (!self::can_operate()) return new WP_Error('mad4b_staff_permission_denied','Company staff operation denied',['status'=>403]);
        $role=sanitize_key($role);
        if (!self::role_allowed($role)) return new WP_Error('mad4b_staff_role_exceeds_actor','Target role exceeds operator permissions',['status'=>403]);
        $u = self::staff_record($id);
        if (!$u) return new WP_Error('mad4b_staff_not_owned_or_privileged','Only owned lower-scope staff can be reassigned',['status'=>403]);
        $before = $u->roles[0];
        if ($before===$role) return ['state'=>'ALREADY_SAME_ROLE','id'=>$id,'role'=>$role];
        $u->set_role($role);
        clean_user_cache($id);
        $read = get_userdata($id);
        if (!$read || $read->roles!==[$role]) {
            $u->set_role($before);
            return new WP_Error('mad4b_staff_readback_mismatch','Role not verified; restored prior role',['status'=>502]);
        }
        return ['state'=>'ROLE_UPDATED_VERIFIED','id'=>$id,'before'=>$before,'role'=>$role];
    }

    public static function create(array $args) {
        if (!self::can_operate()) return new WP_Error('mad4b_staff_permission_denied','Company staff operation denied',['status'=>403]);
        $role=sanitize_key((string)($args['role']??''));
        if (!self::role_allowed($role)) return new WP_Error('mad4b_staff_role_exceeds_actor','Target role exceeds operator permissions',['status'=>403]);
        // Reuse first-party staff account validation, quotas, strong random password,
        // and owner binding for the original roles it explicitly supports.
        if (class_exists('MAD4B_SCP_Company_Master_Admin')
            && in_array($role, MAD4B_SCP_Company_Master_Admin::ALLOWED_ROLES, true)
            && (!method_exists('MAD4B_SCP_Company_Master_Admin','roleWithinCaller')
                || MAD4B_SCP_Company_Master_Admin::roleWithinCaller($role))) {
            return MAD4B_SCP_Company_Master_Admin::createStaff($args);
        }
        $login=sanitize_user((string)($args['username']??''),true);
        $email=sanitize_email((string)($args['email']??''));
        if (!preg_match('/^[a-zA-Z0-9_.-]{4,48}$/',$login) || username_exists($login))
            return new WP_Error('mad4b_staff_login_invalid','Unique username required',['status'=>422]);
        if (!is_email($email) || email_exists($email))
            return new WP_Error('mad4b_staff_email_invalid','Unique valid email required',['status'=>422]);
        if (class_exists('MAD4B_SCP_Company_Operations_Policy')) {
            $limit=(int)(MAD4B_SCP_Company_Operations_Policy::get()['max_staff_accounts']??0);
            $count=count(get_users(['meta_key'=>self::OWNER_META,'fields'=>'ID','number'=>max(1,$limit+1)]));
            if (!$limit || $count >= $limit) return new WP_Error('mad4b_staff_limit_reached','Company quota reached',['status'=>409]);
        }
        $id=wp_insert_user(['user_login'=>$login,'user_email'=>$email,
            'user_pass'=>wp_generate_password(40,true,true),'role'=>$role,
            'display_name'=>sanitize_text_field((string)($args['display_name']??$login))]);
        if (is_wp_error($id)) return $id;
        update_user_meta((int)$id,self::OWNER_META,get_current_user_id());
        return ['state'=>'USER_CREATED','id'=>(int)$id,'role'=>$role,'invite_status'=>'NOT_SENT'];
    }

    public static function register_menu():void {
        if (!self::can_operate()) return;
        $parent=class_exists('MAD4B_Company_Workspaces_V1')
            ? MAD4B_Company_Workspaces_V1::menu_parent() : 'mad4b-company';
        add_submenu_page($parent,'Scoped Staff & Roles','Staff & Roles',
            'mad4b_manage_company_operations',self::PAGE,[__CLASS__,'screen']);
    }

    public static function screen():void {
        if (!self::can_operate()) wp_die('Not authorized',403);
        $eligible=[];
        foreach (self::VALID_ROLES as $role) if (self::role_allowed($role)) {
            $r=get_role($role);$eligible[$role]=$r->name;
        }
        echo '<div class="wrap"><h1>MAD4B Staff &amp; Roles</h1>';
        echo '<p>Only staff accounts owned by this company can be managed. A role must be entirely within your own effective capabilities; Administrator, Master Admin, system settings and independent host permissions cannot be delegated here.</p>';
        if(isset($_GET['result'])) echo '<div class="notice notice-info"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['result']))).'</p></div>';
        echo '<h2>Create staff</h2><form action="'.esc_url(admin_url('admin-post.php')).'" method="post">';
        wp_nonce_field('mad4b_scoped_staff');
        echo '<input type="hidden" name="action" value="mad4b_scoped_staff"><input type="hidden" name="op" value="create">';
        echo '<p><label>Username <input name="username" required maxlength="48"></label> ';
        echo '<label>Email <input type="email" name="email" required></label> ';
        echo '<label>Display name <input name="display_name"></label> ';
        echo '<label>Role <select name="role">';
        foreach($eligible as $key=>$label)echo '<option value="'.esc_attr($key).'">'.esc_html($label).'</option>';
        echo '</select></label> <button class="button button-primary">Create account</button></p></form>';
        echo '<p>Strong random password; invitations are not automatically sent.</p>';
        echo '<h2>Promote or reassign owned staff</h2><table class="widefat striped"><thead><tr><th>User</th><th>Current role</th><th>Allowed new role</th></tr></thead><tbody>';
        $owned=get_users(['meta_key'=>self::OWNER_META,'number'=>100,'orderby'=>'registered','order'=>'DESC']);
        foreach($owned as $u) {
            $user=self::staff_record((int)$u->ID);
            if (!$user) continue;
            echo '<tr><td>'.esc_html($user->display_name).' (#'.esc_html((string)$user->ID).')</td><td>'.esc_html(implode(', ',$user->roles)).'</td><td>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('mad4b_scoped_staff');
            echo '<input type="hidden" name="action" value="mad4b_scoped_staff"><input type="hidden" name="op" value="promote">';
            echo '<input type="hidden" name="user_id" value="'.esc_attr((string)$user->ID).'"><select name="role">';
            foreach($eligible as $key=>$label)echo '<option value="'.esc_attr($key).'">'.esc_html($label).'</option>';
            echo '</select> <button class="button">Change role</button></form></td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public static function handle_post():void {
        if (!self::can_operate()) wp_die('Company staff permission denied',403);
        check_admin_referer('mad4b_scoped_staff');
        if (strtoupper((string)($_SERVER['REQUEST_METHOD']??''))!=='POST') wp_die('POST required',405);
        $op=sanitize_key((string)($_POST['op']??''));
        if ($op==='create') {
            $r=self::create([
                'username'=>wp_unslash($_POST['username']??''),
                'email'=>wp_unslash($_POST['email']??''),
                'display_name'=>wp_unslash($_POST['display_name']??''),
                'role'=>wp_unslash($_POST['role']??''),
            ]);
        }elseif($op==='promote'){
            $r=self::promote(absint($_POST['user_id']??0),(string)wp_unslash($_POST['role']??''));
        }else{
            $r=new WP_Error('mad4b_staff_operation_invalid','Unsupported action',['status'=>422]);
        }
        $result=is_wp_error($r)?$r->get_error_code():($r['state']??'ok');
        wp_safe_redirect(add_query_arg(['page'=>self::PAGE,'result'=>$result],admin_url('admin.php')));
        exit;
    }
}
MAD4B_Scoped_Staff_Management_V1::boot();
