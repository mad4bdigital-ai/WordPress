<?php
/**
 * Plugin Name: MAD4B Company Portfolio Manager (Staging)
 * Description: Separate manager identity and company-scoped delegation lifecycle. No remote writes or implicit ownership.
 * Version: 0.2.0-central-admin-proxy-staging
 * Requires PHP: 8.0
 */
if (!defined('ABSPATH')) { exit; }

final class MAD4B_Company_Portfolio_Manager_V1 {
    const VERSION = 'mad4b.company-portfolio-manager.v1';
    const REGISTRY = 'mad4b_tourism_company_site_registry_v1';
    const MANAGERS = 'mad4b_company_manager_registry_v1';
    const MEMBERS = 'mad4b_company_manager_memberships_v1';
    const ASSIGNMENTS = 'mad4b_manager_company_assignments_v1';
    const AUDIT = 'mad4b_company_manager_audit_v1';
    const RIGHTS = ['view_company', 'strategy_read', 'strategy_manage', 'operations_read', 'operations_manage', 'content_manage', 'commerce_manage'];
    const STATES = ['proposed','approved','active','suspended','revoked'];

    public static function staging(): bool {
        return function_exists('wp_get_environment_type') && wp_get_environment_type() === 'staging'
            && defined('DB_NAME') && DB_NAME === 'etg_commerce_staging'
            && strtolower((string)wp_parse_url(home_url(), PHP_URL_HOST)) === 'tours.dev.mad4b.com';
    }
    private static function error(string $code, string $message, int $http=409): WP_Error {
        return new WP_Error('mad4b_manager_'.$code, $message, ['status'=>$http]);
    }
    private static function admin() {
        return self::staging() && current_user_can('manage_options') && current_user_can('activate_plugins');
    }
    public static function admin_permission() {
        return self::admin() ? true : self::error('admin_required', 'Central platform admin on Staging required', 403);
    }
    /** Browser-managed REST writes require a live WP nonce, not only an API password. */
    public static function rest_write_permission($request) {
        $owner=self::admin_permission();
        if(is_wp_error($owner))return $owner;
        if(!($request instanceof WP_REST_Request) || !function_exists('wp_verify_nonce'))
            return self::error('nonce_required','Authenticated browser REST nonce required',403);
        $nonce=(string)$request->get_header('X-WP-Nonce');
        if($nonce==='' || !wp_verify_nonce($nonce,'wp_rest'))
            return self::error('nonce_invalid','Verified WordPress REST nonce required',403);
        return true;
    }
    public static function read_permission() {
        return self::staging() && is_user_logged_in() ? true : self::error('login_required','Authentication required',403);
    }
    private static function data(string $key): array {
        $value = get_option($key, []);
        return is_array($value) ? $value : [];
    }
    private static function manager(string $id) {
        if (!wp_is_uuid($id)) return self::error('manager_id_invalid', 'Manager UUID required',422);
        $m=self::data(self::MANAGERS)[$id]??null;
        if (!is_array($m) || ($m['manager_id']??'')!==$id) return self::error('manager_unknown','Manager not registered',404);
        return $m;
    }
    public static function company_binding(string $code) {
        if (!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$code)) return self::error('company_invalid','Company code invalid',422);
        $row=self::data(self::REGISTRY)[$code]??null;
        if (!is_array($row) || ($row['company_code']??'')!==$code || empty($row['host_binding_verified'])
            || !wp_is_uuid((string)($row['company_site_uuid']??''))
            || !wp_is_uuid((string)($row['native_site_profile_uuid']??''))
            || !preg_match('/^[a-f0-9]{64}$/D',(string)($row['native_profile_digest']??'')))
            return self::error('company_unverified','Verified Company Registry site binding required',409);
        $identity=[$code,$row['company_site_uuid'],$row['native_site_profile_uuid'],$row['native_profile_digest']];
        return ['company_code'=>$code, 'company_site_uuid'=>$row['company_site_uuid'],
                'binding_sha256'=>hash('sha256', implode('|',$identity))];
    }
    public static function normalize_rights($value) {
        if (!is_array($value) || !array_is_list($value) || !$value) return self::error('rights_required','Rights array required',422);
        $rights=array_values(array_unique(array_map('sanitize_key',$value)));
        if (array_diff($rights,self::RIGHTS)) return self::error('rights_invalid','Unrecognized right',422);
        if (!in_array('view_company',$rights,true)) array_unshift($rights,'view_company');
        return $rights;
    }
    /** A pure deterministic state transition, suitable for audit and offline regression tests. */
    public static function transition_row(array $row, string $action, int $expected, string $current_binding, int $actor, string $expected_proposal_sha='') {
        if (!in_array($action,['approve','activate','suspend','revoke'],true)) return self::error('action_invalid','Invalid action',422);
        if (($row['revision']??-1)!==$expected) return self::error('revision_stale','Exact revision mismatch',409);
        $old=$row['state']??null;
        $transitions=[
            'approve'=>['from'=>'proposed','to'=>'approved'],
            'activate'=>['from'=>'approved','to'=>'active'],
            'suspend'=>['from'=>'active','to'=>'suspended'],
            'revoke'=>['from'=>['proposed','approved','active','suspended'],'to'=>'revoked']
        ];
        $allowed=(array)$transitions[$action]['from'];
        if (!in_array($old,$allowed,true)) return self::error('transition_invalid','Current state does not permit transition',409);
        if (!is_string($row['binding_sha256']??null) || !hash_equals($row['binding_sha256'],$current_binding))
            return self::error('binding_changed','Company binding drift',409);
        if ($action==='approve') {
            if (!preg_match('/^[a-f0-9]{64}$/D',$expected_proposal_sha) ||
                !hash_equals((string)($row['proposal_sha256']??''),$expected_proposal_sha))
                return self::error('proposal_evidence_stale','Exact proposal hash required',409);
        }
        if ($action==='activate' && (($row['approval_sha256']??'')!==($row['proposal_sha256']??null)))
            return self::error('approval_unbound','Approval does not bind exact proposal',409);
        $row['state']=$transitions[$action]['to'];
        $row['revision']=$expected+1;
        $row['last_action']=$action;
        $row['last_action_by']=$actor;
        $row['last_action_at']=gmdate('c');
        if ($action==='approve') {
            $row['approval_sha256']=$row['proposal_sha256'];
            $row['approved_by']=$actor;
            $row['approved_at']=gmdate('c');
        }
        return $row;
    }
    private static function write_record(string $option, string $key, callable $transform) {
        global $wpdb;
        $lock='mad4b:cm:'.substr(hash('sha256',$option.'|'.$key),0,34);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1)
            return self::error('busy','Manager operation locked',423);
        try {
            $all=get_option($option, []);
            if (!is_array($all)) return self::error('store_invalid','Manager registry malformed',503);
            $next=$transform($all[$key]??null,$all);
            if (is_wp_error($next)) return $next;
            $all[$key]=$next;
            if (!update_option($option,$all,false)) return self::error('write_failed','Write not persisted',503);
            $check=self::data($option)[$key]??null;
            if (!is_array($check) || ($check['revision']??-1)!==($next['revision']??-2))
                return self::error('readback_failed','Revision readback mismatch',503);
            return $check;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }
    private static function audit(string $event, array $record): void {
        $a=self::data(self::AUDIT);
        $a[]=['event'=>$event,'at'=>gmdate('c'),'actor_id'=>get_current_user_id(),
            'subject'=>$record,'sha256'=>hash('sha256',wp_json_encode($record, JSON_UNESCAPED_SLASHES))];
        update_option(self::AUDIT,array_slice($a,-300),false);
    }
    public static function create_manager(array $input) {
        $p=self::admin_permission();if(is_wp_error($p))return $p;
        $code=sanitize_key((string)($input['manager_code']??''));
        $name=trim(sanitize_text_field((string)($input['display_name']??'')));
        if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$code) || strlen($name)<3 || strlen($name)>120)
            return self::error('identity_invalid','Manager code/name invalid',422);
        $brand=(string)($input['reference_brand_id']??'');
        $site=(string)($input['reference_site_uuid']??'');
        if ($brand!=='' && !preg_match('/^[a-f0-9]{32}$/D',$brand))return self::error('brand_reference_invalid','Brand ID invalid',422);
        if ($site!=='' && !wp_is_uuid($site))return self::error('site_reference_invalid','Site UUID invalid',422);
        if (($brand==='')!==($site===''))return self::error('reference_incomplete','Provide both reference identifiers',422);
        foreach(self::data(self::MANAGERS) as $m) if(($m['manager_code']??'')===$code)
            return self::error('manager_exists','Manager code already exists',409);
        // Derive a stable UUID per manager code to serialize concurrent registrations.
        $digest=hash('sha256','mad4b.company-portfolio-manager.v1|'.$code);
        $id=substr($digest,0,8).'-'.substr($digest,8,4).'-5'.substr($digest,13,3).'-a'.substr($digest,17,3).'-'.substr($digest,20,12);
        $row=self::write_record(self::MANAGERS,$id,static function($old,$all)use($id,$code,$name,$brand,$site){
            if($old!==null)return self::error('uuid_exists','Manager UUID collision');
            foreach($all as $m)if(($m['manager_code']??'')===$code)return self::error('manager_exists','Manager code already exists');
            return ['contract'=>self::VERSION,'manager_id'=>$id,'manager_code'=>$code,'display_name'=>$name,
                'reference_brand_id'=>$brand?:null,'reference_site_uuid'=>$site?:null,'state'=>'registered',
                'revision'=>1,'created_at'=>gmdate('c'),'company_rights_conferred'=>false,
                'brand_context_ownership_conferred'=>false];
        });
        if (!is_wp_error($row))self::audit('manager_registered',['manager_id'=>$id]);
        return $row;
    }
    public static function propose(array $input) {
        $p=self::admin_permission();if(is_wp_error($p))return $p;
        $id=(string)($input['manager_id']??'');$code=sanitize_key((string)($input['company_code']??''));
        $m=self::manager($id);if(is_wp_error($m))return $m;
        $company=self::company_binding($code);if(is_wp_error($company))return $company;
        $rights=self::normalize_rights($input['rights']??null);if(is_wp_error($rights))return $rights;
        $reason=trim(sanitize_textarea_field((string)($input['reason']??'')));
        if(strlen($reason)<12||strlen($reason)>1200)return self::error('reason_invalid','Reason must be 12-1200 characters',422);
        $expected=$input['expected_revision']??null;
        if(!is_int($expected)||$expected<0)return self::error('revision_required','Exact revision required',422);
        $row=self::write_record(self::ASSIGNMENTS,$id.'|'.$code,
            static function($old)use($id,$code,$company,$rights,$reason,$expected){
            $revision=(int)($old['revision']??0);
            if($revision!==$expected)return self::error('revision_stale','Assignment changed',409);
            if(is_array($old)&&($old['state']??'')!=='revoked')
                return self::error('assignment_open','Existing assignment must first be revoked',409);
            $proposal=['manager_id'=>$id,'company_code'=>$code,'company_site_uuid'=>$company['company_site_uuid'],
                'binding_sha256'=>$company['binding_sha256'],'rights'=>$rights,'reason'=>$reason];
            return ['contract'=>'mad4b.manager-company-assignment.v1']+$proposal+[
                'proposal_sha256'=>hash('sha256',wp_json_encode($proposal,JSON_UNESCAPED_SLASHES)),
                'state'=>'proposed','revision'=>$revision+1,'proposed_at'=>gmdate('c'),
                'proposed_by'=>get_current_user_id(),'approval_sha256'=>null,
                'remote_writes_enabled'=>false,'company_assets_transferred'=>false];
        });
        if(!is_wp_error($row)) {
            self::audit('assignment_proposed',['manager_id'=>$id,'company_code'=>$code,'revision'=>$row['revision']]);
            // Post-commit native lifecycle event, no new company write authority.
            do_action('mad4b_company_portfolio_transitioned',[
                'manager_id'=>$id,'company_code'=>$code,
                'revision'=>(int)$row['revision'],'state'=>(string)$row['state']]);
        }
        return $row;
    }
    public static function change_assignment(array $input) {
        $p=self::admin_permission();if(is_wp_error($p))return $p;
        $id=(string)($input['manager_id']??'');$code=sanitize_key((string)($input['company_code']??''));
        $manager=self::manager($id);if(is_wp_error($manager))return $manager;
        $company=self::company_binding($code);if(is_wp_error($company))return $company;
        $action=sanitize_key((string)($input['action']??''));
        $expected=$input['expected_revision']??null;
        if(!is_int($expected)||$expected<1)return self::error('revision_required','Exact revision required',422);
        $proof=(string)($input['expected_proposal_sha256']??'');
        $row=self::write_record(self::ASSIGNMENTS,$id.'|'.$code,
            static function($old)use($action,$expected,$company,$proof){
                if(!is_array($old))return self::error('assignment_missing','Proposal not found',404);
                return self::transition_row($old,$action,$expected,$company['binding_sha256'],get_current_user_id(),$proof);
        });
        if(!is_wp_error($row)) {
            self::audit('assignment_'.$action,['manager_id'=>$id,'company_code'=>$code,'revision'=>$row['revision']]);
            do_action('mad4b_company_portfolio_transitioned',[
                'manager_id'=>$id,'company_code'=>$code,
                'revision'=>(int)$row['revision'],'state'=>(string)$row['state']]);
        }
        return $row;
    }
    public static function member_action(array $input) {
        $p=self::admin_permission();if(is_wp_error($p))return $p;
        $id=(string)($input['manager_id']??'');$user=absint($input['user_id']??0);
        $manager=self::manager($id);if(is_wp_error($manager))return $manager;
        if(!$user || !get_userdata($user))return self::error('user_unknown','Existing user ID required',404);
        $action=sanitize_key((string)($input['action']??''));
        if(!in_array($action,['enroll','revoke'],true))return self::error('member_action_invalid','Invalid member action',422);
        $expected=$input['expected_revision']??null;
        if(!is_int($expected)||$expected<0)return self::error('revision_required','Member revision required',422);
        $row=self::write_record(self::MEMBERS,$id.'|'.$user,static function($old)use($id,$user,$action,$expected){
            $rev=(int)($old['revision']??0);
            if($rev!==$expected)return self::error('revision_stale','Membership has changed',409);
            if($action==='revoke' && ($old['state']??'')!=='active')return self::error('member_not_active','Active membership required',409);
            if($action==='enroll' && ($old['state']??'')==='active')return self::error('member_exists','Membership already active',409);
            return ['manager_id'=>$id,'user_id'=>$user,'state'=>$action==='enroll'?'active':'revoked',
                'revision'=>$rev+1,'updated_at'=>gmdate('c'),'actor_id'=>get_current_user_id(),
                'wp_roles_modified'=>false,'remote_write_granted'=>false];
        });
        if(!is_wp_error($row))self::audit('member_'.$action,['manager_id'=>$id,'user_id'=>$user,'revision'=>$row['revision']]);
        return $row;
    }
    public static function has_delegation(string $manager_id, string $company, string $right='view_company', int $user_id=0): bool {
        if(!self::staging() || !in_array($right,self::RIGHTS,true))return false;
        $id=$user_id ?: get_current_user_id();
        if(!$id)return false;
        $m=self::data(self::MANAGERS)[$manager_id]??null;
        if(!is_array($m) || ($m['state']??'')!=='registered')return false;
        $member=self::data(self::MEMBERS)[$manager_id.'|'.$id]??null;
        if(!is_array($member) || ($member['state']??'')!=='active')return false;
        $a=self::data(self::ASSIGNMENTS)[$manager_id.'|'.$company]??null;
        if(!is_array($a)||($a['state']??'')!=='active'||!in_array($right,$a['rights']??[],true))return false;
        $current=self::company_binding($company);
        return !is_wp_error($current) && ($a['company_site_uuid']??'')===$current['company_site_uuid']
            && is_string($a['binding_sha256']??null)
            && hash_equals($a['binding_sha256'],$current['binding_sha256']);
    }
    public static function inventory(string $manager_id) {
        $p=self::read_permission();if(is_wp_error($p))return $p;
        $m=self::manager($manager_id);if(is_wp_error($m))return $m;
        $is_admin=self::admin();
        $membership=self::data(self::MEMBERS)[$manager_id.'|'.get_current_user_id()]??null;
        if(!$is_admin && ($membership['state']??'')!=='active')
            return self::error('membership_required','Manager membership required',403);
        $registered=self::data(self::REGISTRY);
        $portfolio=self::data('mad4b_portfolio_auto_activation_status_v1');
        $assigned=self::data(self::ASSIGNMENTS);
        $codes=array_unique(array_merge(array_keys($registered),array_keys($portfolio)));
        sort($codes,SORT_STRING);
        $rows=[];
        foreach($codes as $code) {
            if(!$is_admin && !self::has_delegation($manager_id,$code))continue;
            $verified=self::company_binding($code);
            $ok=!is_wp_error($verified);
            $a=$assigned[$manager_id.'|'.$code]??null;
            $rows[]=['company_code'=>$code,'enrolled'=>$ok,
                'company_site_uuid'=>$ok?$verified['company_site_uuid']:null,
                'portfolio_state'=>$portfolio[$code]['state']??'untracked',
                'assignment_state'=>$a['state']??'none',
                'assignment_revision'=>$a['revision']??0,
                'assigned_rights'=>($ok && ($a['state']??'')==='active' && ($a['binding_sha256']??'')===$verified['binding_sha256'])
                    ?$a['rights']:[],
                'remote_writes_enabled'=>false,'registration_required'=>!$ok];
        }
        return ['contract'=>'mad4b.manager-portfolio-inventory.v1',
            'manager_id'=>$manager_id,'manager_code'=>$m['manager_code'],
            'items'=>$rows,'count'=>count($rows),
            'inventory_visibility_is_not_ownership'=>true,'remote_writes_enabled'=>false];
    }
    public static function list_managers() {
        $p=self::read_permission();if(is_wp_error($p))return $p;
        $rows=[];$all_members=self::data(self::MEMBERS);
        foreach(self::data(self::MANAGERS) as $id=>$m){
            if(!self::admin() && ($all_members[$id.'|'.get_current_user_id()]['state']??'')!=='active')continue;
            $rows[]=['manager_id'=>$id,'manager_code'=>$m['manager_code'],'display_name'=>$m['display_name'],
                'state'=>$m['state'],'reference_brand_id'=>$m['reference_brand_id']??null,
                'reference_site_uuid'=>$m['reference_site_uuid']??null,'company_ownership_conferred'=>false];
        }
        return ['contract'=>self::VERSION,'items'=>$rows,'count'=>count($rows)];
    }
    public static function status(string $manager_id,string $company_code) {
        $p=self::read_permission();if(is_wp_error($p))return $p;
        $m=self::manager($manager_id);if(is_wp_error($m))return $m;
        if(!self::admin() && !self::has_delegation($manager_id,$company_code))return self::error('not_assigned','Not assigned to company',403);
        $current=self::company_binding($company_code);
        if(is_wp_error($current))return $current;
        $row=self::data(self::ASSIGNMENTS)[$manager_id.'|'.$company_code]??null;
        if(!is_array($row))return ['contract'=>'mad4b.manager-company-assignment.v1','state'=>'not_assigned','revision'=>0];
        $row['binding_current']=($row['binding_sha256']??'')===$current['binding_sha256'];
        $row['remote_writes_enabled']=false;
        return $row;
    }
    private static function reply($result) {
        $response=rest_ensure_response($result);
        if($response instanceof WP_REST_Response)$response->header('Cache-Control','no-store, private');
        return $response;
    }
    public static function routes(): void {
        if(!self::staging())return;
        $prefix='mad4b/v1';
        register_rest_route($prefix,'/company-managers',[
            ['methods'=>'GET','permission_callback'=>[__CLASS__,'read_permission'],'callback'=>static fn()=>self::reply(self::list_managers())],
            ['methods'=>'POST','permission_callback'=>[__CLASS__,'rest_write_permission'],
             'callback'=>static fn($r)=>self::reply(self::create_manager($r->get_json_params()??[]))]
        ]);
        $manager='/company-managers/(?P<manager_id>[a-z0-9-]{36})';
        register_rest_route($prefix,$manager.'/inventory',[
            'methods'=>'GET','permission_callback'=>[__CLASS__,'read_permission'],
            'callback'=>static fn($r)=>self::reply(self::inventory($r['manager_id']))
        ]);
        register_rest_route($prefix,$manager.'/members',[
            'methods'=>'POST','permission_callback'=>[__CLASS__,'rest_write_permission'],
            'callback'=>static function($r){$v=$r->get_json_params()??[];$v['manager_id']=$r['manager_id'];return self::reply(self::member_action($v));}
        ]);
        $company=$manager.'/companies/(?P<company_code>[a-z][a-z0-9-]{2,47})';
        register_rest_route($prefix,$company.'/delegation',[
            ['methods'=>'GET','permission_callback'=>[__CLASS__,'read_permission'],
             'callback'=>static fn($r)=>self::reply(self::status($r['manager_id'],$r['company_code']))],
            ['methods'=>'POST','permission_callback'=>[__CLASS__,'rest_write_permission'],
             'callback'=>static function($r){$v=$r->get_json_params()??[];$v['manager_id']=$r['manager_id'];$v['company_code']=$r['company_code'];return self::reply(self::propose($v));}]
        ]);
        register_rest_route($prefix,$company.'/delegation/transition',[
            'methods'=>'POST','permission_callback'=>[__CLASS__,'rest_write_permission'],
            'callback'=>static function($r){$v=$r->get_json_params()??[];$v['manager_id']=$r['manager_id'];$v['company_code']=$r['company_code'];return self::reply(self::change_assignment($v));}
        ]);
    }
    /** Discovery only: Control Plane still must approve each Ability descriptor and authority lane. */
    public static function abilities(): void {
        if(!self::staging() || !function_exists('wp_register_ability'))return;
        $spec=[
            'mad4b/company-managers-list'=>[[],[],static fn($a)=>self::list_managers()],
            'mad4b/company-manager-inventory'=>[['manager_id'],['manager_id'=>['type'=>'string']],static fn($a)=>self::inventory((string)$a['manager_id'])],
            'mad4b/company-manager-delegation-status'=>[['manager_id','company_code'],['manager_id'=>['type'=>'string'],'company_code'=>['type'=>'string']],static fn($a)=>self::status((string)$a['manager_id'],(string)$a['company_code'])]
        ];
        foreach($spec as $name=>$v) {
            if(function_exists('wp_has_ability')&&wp_has_ability($name))continue;
            wp_register_ability($name,[
                'label'=>str_replace('-',' ',str_replace('mad4b/','',$name)),
                'description'=>'Read-only manager inventory and exact company delegation. Discovery does not grant authority.',
                'category'=>'mad4b-read',
                'permission_callback'=>static fn($args)=>self::read_permission(),
                'execute_callback'=>$v[2],
                'input_schema'=>['type'=>'object','properties'=>$v[1],'required'=>$v[0],'additionalProperties'=>false],
                'output_schema'=>['type'=>'object','additionalProperties'=>true],
                'meta'=>['show_in_rest'=>false,'mcp'=>['public'=>false,'surface'=>'read','type'=>'tool'],
                    'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]]
            ]);
        }
    }
    public static function admin_menu(): void {
        if(!self::staging())return;
        add_submenu_page('mad4b-company-brand-readiness','Company Portfolio Managers','Portfolio Managers',
            'manage_options','mad4b-company-portfolio-managers',[__CLASS__,'admin_screen']);
    }
    public static function admin_screen(): void {
        if(!self::admin())wp_die('Central Staging administrator required',403);
        echo '<div class="wrap"><h1>MAD4B · Company Portfolio Managers</h1>';
        echo '<p>Company Manager identity is independent of Brand Context. Company enrollment and explicit delegation are separate. No company ownership or remote write authority is inherited.</p>';
        echo '<p>Read/write APIs are limited to central Staging platform administrators. Native Control Plane trust approval is required before exposing new MCP tools.</p>';
        $all=self::list_managers();
        echo '<table class="widefat striped"><thead><tr><th>Manager</th><th>Identity</th><th>Assigned Companies</th></tr></thead><tbody>';
        foreach($all['items'] as $manager){
            $inventory=self::inventory($manager['manager_id']);
            echo '<tr><td>'.esc_html($manager['display_name']).'</td><td><code>'.esc_html($manager['manager_id']).'</code></td><td>';
            foreach($inventory['items'] as $c)echo '<p>'.esc_html($c['company_code']).' · '.esc_html($c['portfolio_state']).' · '.esc_html($c['assignment_state']).'</p>';
            echo '</td></tr>';
        }
        echo '</tbody></table><p>Use the governed REST lifecycle or approved native Control Plane adapter for proposals and transitions. No automatic enrollment from Brand Context.</p></div>';
    }
    public static function boot(): void {
        add_action('rest_api_init',[__CLASS__,'routes'],70);
        add_action('wp_abilities_api_init',[__CLASS__,'abilities'],70);
        add_action('admin_menu',[__CLASS__,'admin_menu'],90);
    }
}
MAD4B_Company_Portfolio_Manager_V1::boot();
require_once __DIR__ . '/class-mad4b-central-admin-proxy.php';
