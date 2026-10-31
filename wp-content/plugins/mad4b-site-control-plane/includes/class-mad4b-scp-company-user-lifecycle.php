<?php
/** Company-owned WP user lifecycle; never grants core user-admin capabilities. */
defined('ABSPATH') || exit;
final class MAD4B_SCP_Company_User_Lifecycle {
    const SUSPEND='mad4b_company_suspension_v1';
    const AUDIT='mad4b_company_user_lifecycle_audit_v1';
    private static function denied(string $code,string $msg,int $status=403):WP_Error {
        return new WP_Error($code,$msg,['status'=>$status]);
    }
    private static function owner():bool {return current_user_can('mad4b_manage_company_operations');}
    private static function user(int $id){
        $u=get_userdata($id);
        if(!$u||$id===get_current_user_id()||array_intersect(['administrator','master_admin'],(array)$u->roles))
            return self::denied('company_user_protected','Not a company-managed subordinate.',403);
        $own=get_user_meta($id,MAD4B_SCP_Company_Master_Admin::STAFF_OWNER,true);
        $s=get_user_meta($id,self::SUSPEND,true);
        if(!$own && !$s && !MAD4B_SCP_Company_Master_Admin::managedStaff($id))
            return self::denied('company_user_unmanaged','Not an approved internal team user.',403);
        return $u;
    }
    private static function taskDependencies(int $id):array {
        $result=['active_assignee_tasks'=>0,'pending_handoffs'=>0,'board_relations'=>0,
            'evidence_complete'=>false];
        if(!class_exists('FluentBoards\\App\\Models\\Relation') ||
            !class_exists('FluentBoards\\App\\Models\\Task') ||
            !class_exists('MAD4B_SCP_Readiness_Workflow_Options'))return $result;
        $result['active_assignee_tasks']=(int)\FluentBoards\App\Models\Relation::where('foreign_id',$id)->where('object_type','task_assignee')->count();
        $result['board_relations']=(int)\FluentBoards\App\Models\Relation::where('foreign_id',$id)->count();
        $settings=MAD4B_SCP_Readiness_Workflow_Options::settings();
        $board=absint($settings['board_id']??0);
        if(!$board)return $result;
        $tasks=\FluentBoards\App\Models\Task::where('board_id',$board)->get();
        // Avoid accepting a partial inventory as proof of no outstanding handoff.
        if(count($tasks)>1000)return $result;
        foreach($tasks as$task){
            $data=$task->getMeta('mad4b_company_task_handoff_v1',[]);
            $pending=is_array($data)?($data['pending']??null):null;
            if(is_array($pending) && (absint($pending['to']??0)===$id || absint($pending['from']??0)===$id))
                $result['pending_handoffs']++;
        }
        $result['evidence_complete']=true;
        return $result;
    }
    public static function preflight(int $id) {
        if(!self::owner())return self::denied('company_user_forbidden','Company management required.');
        $u=self::user($id);if(is_wp_error($u))return $u;
        global $wpdb;
        $tasks=self::taskDependencies($id);
        $posts=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author=%d",$id));
        $refs=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ('tour_order_agent','tour_followup_agent','tour_booking_operator_agent') AND meta_value=%s",(string)$id));
        $suspended=is_array(get_user_meta($id,self::SUSPEND,true));
        $owner=(bool)get_user_meta($id,MAD4B_SCP_Company_Master_Admin::STAFF_OWNER,true);
        $manager=user_can($id,'manage_translations') ||
            in_array('company_translation_manager',(array)(get_user_meta($id,self::SUSPEND,true)['previous_roles']??[]),true);
        $blocks=[];
        if(!$suspended)$blocks[]='suspend_first';
        if(!$owner)$blocks[]='staff_origin_not_certified';
        if(!$tasks['evidence_complete'])$blocks[]='task_dependency_scan_incomplete';
        if($tasks['active_assignee_tasks'])$blocks[]='active_task_assignments';
        if($tasks['pending_handoffs'])$blocks[]='pending_task_handoffs';
        if($tasks['board_relations'])$blocks[]='board_memberships_or_watches';
        if($posts)$blocks[]='owned_wp_posts';
        if($refs)$blocks[]='assigned_orders_or_bookings';
        if($manager)$blocks[]='wpml_assignment_review';
        if(!class_exists('MAD4B_SCP_Company_Operations_Policy') ||
            MAD4B_SCP_Company_Operations_Policy::get()['hard_delete_policy']!=='guarded')$blocks[]='hard_delete_policy_disabled';
        return ['contract'=>'mad4b.company.user-delete-preflight.v1','user_id'=>$id,
            'can_delete'=>empty($blocks),'blockers'=>$blocks,'posts_owned'=>$posts,
            'order_booking_refs'=>$refs,'task_dependencies'=>$tasks,
            'destructive_action_requires_exact_username'=>true];
    }
    private static function record(string $event,int $id,array $detail=[]):void {
        $events=get_option(self::AUDIT,[]);if(!is_array($events))$events=[];
        $events[]=array_merge(['event'=>$event,'user_id'=>$id,'actor'=>get_current_user_id(),
            'at'=>current_time('mysql',true)],$detail);
        update_option(self::AUDIT,array_slice($events,-150),false);
    }
    public static function suspend(int $id){
        if(!self::owner())return self::denied('company_user_forbidden','Company management required.');
        $u=self::user($id);if(is_wp_error($u))return $u;
        $old=get_user_meta($id,self::SUSPEND,true);
        if(is_array($old))return ['user_id'=>$id,'state'=>'suspended','replay'=>true];
        if(!MAD4B_SCP_Company_Master_Admin::allowedStaff($u))return self::denied('company_user_role_invalid','User has an unmanaged role.');
        $deps=self::taskDependencies($id);
        if(!$deps['evidence_complete'] || $deps['active_assignee_tasks'] || $deps['pending_handoffs'])
            return self::denied('company_user_has_task_dependencies','Finish all task transfers and verify dependency inventory before suspension.',409);
        $roles=array_values((array)$u->roles);
        update_user_meta($id,self::SUSPEND,['previous_roles'=>$roles,'actor'=>get_current_user_id(),'at'=>current_time('mysql',true)]);
        $u->set_role('subscriber');
        if(class_exists('WP_Session_Tokens'))\WP_Session_Tokens::get_instance($id)->destroy_all();
        self::record('suspended',$id);
        return ['user_id'=>$id,'state'=>'suspended','auth_sessions_revoked'=>true];
    }
    public static function restore(int $id){
        if(!self::owner())return self::denied('company_user_forbidden','Company management required.');
        $u=self::user($id);if(is_wp_error($u))return $u;
        $record=get_user_meta($id,self::SUSPEND,true);
        if(!is_array($record))return self::denied('company_user_not_suspended','No suspension to restore.',409);
        $roles=array_values(array_filter((array)($record['previous_roles']??[]),
            fn($r)=>in_array($r,MAD4B_SCP_Company_Master_Admin::ALLOWED_ROLES,true)));
        if(count($roles)!==1||count((array)$record['previous_roles'])!==1)
            return self::denied('company_role_recovery_unsafe','Stored role set requires administrator review.',409);
        $u->set_role($roles[0]);delete_user_meta($id,self::SUSPEND);
        self::record('restored',$id,['role'=>$roles[0]]);
        return ['user_id'=>$id,'state'=>'active','role'=>$roles[0]];
    }
    public static function permanentDelete(int $id,string $confirm){
        if(!self::owner())return self::denied('company_user_forbidden','Company management required.');
        $u=self::user($id);if(is_wp_error($u))return $u;
        if($confirm!==$u->user_login)return self::denied('company_user_confirmation_mismatch','Exact username confirmation required.',422);
        if(class_exists('MAD4B_SCP_Company_Operations_Policy') &&
            MAD4B_SCP_Company_Operations_Policy::get()['hard_delete_policy']!=='guarded')
            return self::denied('company_user_delete_policy_closed','Company permanent deletion is disabled by operational policy.',403);
        $s=get_user_meta($id,self::SUSPEND,true);
        if(!is_array($s))return self::denied('company_user_suspend_first','Suspend employee before deletion.',409);
        if(!get_user_meta($id,MAD4B_SCP_Company_Master_Admin::STAFF_OWNER,true))
            return self::denied('company_user_deletion_owner_missing','Legacy or imported staff must be reviewed by administrator.',403);
        $preflight=self::preflight($id);
        if(is_wp_error($preflight))return $preflight;
        if(!$preflight['can_delete'])return self::denied('company_user_delete_blocked',
            'Deletion has dependencies; check GET /preflight for individual blockers.',409);
        if(!function_exists('wp_delete_user'))require_once ABSPATH.'wp-admin/includes/user.php';
        if(!wp_delete_user($id))return self::denied('company_user_delete_failed','Core WordPress delete failed.',502);
        self::record('deleted',$id);
        return ['user_id'=>$id,'state'=>'deleted','core_wp_delete'=>true];
    }
    public static function profile($req){
        if(!self::owner())return self::denied('company_user_forbidden','Company management required.');
        $id=absint($req['id']);$u=self::user($id);if(is_wp_error($u))return $u;
        $isSuspended=is_array(get_user_meta($id,self::SUSPEND,true));
        $revision=absint(get_user_meta($id,'mad4b_company_user_revision_v1',true));
        if($req->get_method()==='GET'){
            $r=rest_ensure_response(['contract'=>'mad4b.company.user-profile.v1','user_id'=>$id,
                'display_name'=>$u->display_name,'work_email'=>$u->user_email,
                'roles'=>array_values($u->roles),'suspended'=>$isSuspended,'revision'=>$revision]);
            $r->header('Cache-Control','private, no-store');return $r;
        }
        if($isSuspended)return self::denied('company_user_suspended','Restore user before editing.',409);
        $revisionIn=$req->get_param('expected_revision');
        if($revisionIn===null||(int)$revisionIn!==$revision)return self::denied('company_user_revision_conflict','Profile changed; refresh first.',409);
        $patch=$req->get_json_params();
        if(!is_array($patch)||array_diff(array_keys($patch),['display_name','work_email','role','expected_revision']))
            return self::denied('company_user_patch_invalid','Only staff name, email or approved role may change.',422);
        $input=['ID'=>$id];$changes=[];
        if(isset($patch['display_name'])){
            $name=sanitize_text_field((string)$patch['display_name']);
            if(!$name||mb_strlen($name)>80)return self::denied('company_user_name_invalid','Invalid display name.',422);
            $input['display_name']=$name;$changes[]='display_name';
        }
        if(isset($patch['work_email'])){
            $email=sanitize_email((string)$patch['work_email']);
            $other=email_exists($email);
            if(!is_email($email)||($other && (int)$other!==$id))
                return self::denied('company_user_email_invalid','Email missing, invalid or already in use.',422);
            $input['user_email']=$email;$changes[]='work_email';
        }
        if(isset($patch['role'])){
            $role=sanitize_key((string)$patch['role']);
            if(!in_array($role,MAD4B_SCP_Company_Master_Admin::ALLOWED_ROLES,true)||!get_role($role))
                return self::denied('company_user_role_denied','Role outside company staff allowlist.',403);
            if($role==='company_translation_manager'||user_can($id,'manage_translations'))
                return self::denied('company_user_wpml_review','Reconcile WPML manager rights before changing role.',409);
            $input['role']=$role;$changes[]='role';
        }
        if(!$changes)return self::denied('company_user_patch_empty','No profile fields supplied.',422);
        $updated=wp_update_user($input);
        if(is_wp_error($updated))return $updated;
        update_user_meta($id,'mad4b_company_user_revision_v1',$revision+1);
        self::record('profile_updated',$id,['changed_keys'=>$changes]);
        $read=new WP_REST_Request('GET','/mad4b/v1/admin/company-team/users/'.$id);
        $read->set_param('id',$id);
        return self::profile($read);
    }
    public static function guardREST($result){
        if(is_wp_error($result))return $result;
        $id=get_current_user_id();
        if($id && is_array(get_user_meta($id,self::SUSPEND,true)))
            return new WP_Error('company_user_suspended','This staff account is suspended.', ['status'=>403]);
        return $result;
    }
    public static function guardApplicationPassword($error,$user,$item,$password):void {
        if($user instanceof WP_User && is_array(get_user_meta($user->ID,self::SUSPEND,true)))
            $error->add('company_user_suspended','This company staff account is suspended.');
    }
    public static function authenticate($user,$username,$password){
        if($user instanceof WP_User && get_user_meta($user->ID,self::SUSPEND,true))
            return new WP_Error('company_user_suspended','Account suspended by company management.');
        return $user;
    }
    public static function route($r){
        $id=absint($r['id']);$act=(string)$r->get_param('action');
        if($act==='suspend')$result=self::suspend($id);
        elseif($act==='restore')$result=self::restore($id);
        elseif($act==='delete')$result=self::permanentDelete($id,(string)$r->get_param('confirm_username'));
        else return self::denied('company_user_action_invalid','Expected suspend, restore or delete.',422);
        return is_wp_error($result)?$result:rest_ensure_response(['contract'=>'mad4b.company.user-lifecycle.v1','result'=>$result]);
    }
    public static function preflightREST($r){
        $data=self::preflight(absint($r['id']));
        if(is_wp_error($data))return $data;
        $response=rest_ensure_response($data);
        $response->header('Cache-Control','private, no-store');
        return $response;
    }
    public static function rest():void {
        register_rest_route('mad4b/v1','/admin/company-team/users/(?P<id>\\d+)',[
            ['methods'=>'GET','callback'=>[__CLASS__,'profile'],
                'permission_callback'=>static fn()=>current_user_can('mad4b_manage_company_operations')],
            ['methods'=>'PATCH','callback'=>[__CLASS__,'profile'],
                'permission_callback'=>static fn()=>current_user_can('mad4b_manage_company_operations')]
        ]);
        register_rest_route('mad4b/v1','/admin/company-team/users/(?P<id>\\d+)/preflight',[
            'methods'=>'GET','callback'=>[__CLASS__,'preflightREST'],
            'permission_callback'=>static fn()=>current_user_can('mad4b_manage_company_operations')]);
        register_rest_route('mad4b/v1','/admin/company-team/users/(?P<id>\\d+)/lifecycle',[
            'methods'=>'POST','callback'=>[__CLASS__,'route'],
            'permission_callback'=>static fn()=>current_user_can('mad4b_manage_company_operations'),
            'args'=>['action'=>['type'=>'string','required'=>true,'enum'=>['suspend','restore','delete']]]]);
    }
    public static function register():void {
        add_filter('authenticate',[__CLASS__,'authenticate'],95,3);
        // Enforce the suspension on every REST dispatch before its endpoint
        // callback, without intercepting WordPress's global authentication
        // handshake (including independent WPML, Woo and OAuth consumers).
        // WP application passwords and normal login remain independently
        // denied by the authentication hooks below.
        add_filter('rest_pre_dispatch',[__CLASS__,'guardREST'],95,1);
        add_action('wp_authenticate_application_password_errors',[__CLASS__,'guardApplicationPassword'],95,4);
        add_action('rest_api_init',[__CLASS__,'rest'],30);
    }
}
MAD4B_SCP_Company_User_Lifecycle::register();
