<?php
/**
 * Plugin Name: MAD4B Company Management
 * Description: Company management add-on for MAD4B Control Plane; delegates capabilities and execution to the existing Core.
 * Version: 0.4.2-company-addon
 * Requires PHP: 8.0
 * Requires Plugins: mad4b-site-control-plane
 */
if (!defined('ABSPATH')) { exit; }
if (function_exists('wp_get_environment_type') && wp_get_environment_type() === 'staging') {
    // Register original company mail hooks as early as the Core did, even in
    // foreign REST/cron/AJAX contexts where Control Plane is lightweight.
    require_once __DIR__ . '/includes/class-mad4b-scp-company-communication.php';
    require_once __DIR__ . '/includes/class-mad4b-scp-company-email-routing.php';
    require_once __DIR__ . '/includes/class-mad4b-scp-company-sender-policy.php';
}


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
        // Central company-management admission follows the existing Core site
        // identity/host ledger, never a fixed database, domain or company slug.
        // Scope remains Staging until other environments have explicit authority.
        if (!function_exists('wp_get_environment_type')
            || wp_get_environment_type() !== 'staging'
            || !class_exists('MAD4B_SCP_Site_Profile', false)
            || !class_exists('MAD4B_Native_VPS_Principal_V1', false)) return false;
        try {
            $profile=MAD4B_SCP_Site_Profile::profile();
            $scope=MAD4B_Native_VPS_Principal_V1::scope();
            if (!is_array($profile) || !is_array($scope)) return false;
            $uuid=(string)($profile['site_uuid']??'');
            $origin=untrailingslashit(home_url('/'));
            if (!wp_is_uuid($uuid) || ($profile['environment']??'')!=='staging'
                || ($scope['contract']??'')!=='mad4b.central-host-scope.v1'
                || ($scope['scope_kind']??'')!=='central_native_host'
                || ($scope['state']??'')!=='CENTRAL_HOST_IDENTITY_VERIFIED'
                || ($scope['environment']??'')!=='staging'
                || ($scope['company_target']??true)!==false
                || ($scope['site_uuid']??'')!==$uuid
                || ($scope['canonical_origin']??'')!==$origin
                || ($profile['canonical_origin']??'')!==$origin
                || !MAD4B_SCP_Site_Profile::site_urls_match_enrollment()) return false;
            $principal=MAD4B_Native_VPS_Principal_V1::existing();
            return is_array($principal) && MAD4B_Native_VPS_Principal_V1::ledger($principal,$scope);
        } catch (Throwable $error) { return false; }
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
        if($nonce!=='' && wp_verify_nonce($nonce,'wp_rest')) return true;
        if(class_exists('MAD4B_Company_Manager_Unified_Administrator_Principal')
            && MAD4B_Company_Manager_Unified_Administrator_Principal::verified()) return true;
        return self::error('nonce_or_oauth_required',
            'Verified WordPress nonce or enrolled central administrator OAuth step-up required',403);
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
    /**
     * A company-specific Site Profile must not be claimed by another company.
     * Pure read-only invariant: no grants, option writes or identity promotion.
     */
    public static function unique_company_site_identity(string $code,string $site_uuid,string $native_uuid='',?array $registry=null) {
        if (!wp_is_uuid($site_uuid) || ($native_uuid!=='' && !wp_is_uuid($native_uuid)))
            return self::error('company_identity_invalid','Company Site Profile UUID is invalid',409);
        $rows=$registry??self::data(self::REGISTRY);
        foreach ($rows as $other_code=>$other) {
            if ((string)$other_code===$code || !is_array($other)) continue;
            if (strtolower((string)($other['company_site_uuid']??''))===strtolower($site_uuid)
                || ($native_uuid!=='' && strtolower((string)($other['native_site_profile_uuid']??''))===strtolower($native_uuid)))
                return self::error('company_identity_collision','A Site Profile UUID belongs to another company',409);
        }
        return true;
    }
    /**
     * Public customer-domain origin. Docker ports, private upstreams and
     * Coolify service ports are never public entrypoints.
     * This is a validator, not an authorization or a URL-repair shortcut.
     */
    public static function public_origin($value): string {
        if (!is_string($value) || $value!==trim($value)
            || preg_match('/[\\x00-\\x20\\x7f]/', $value)) return '';
        $parts=wp_parse_url($value);
        if (!is_array($parts) || ($parts['scheme']??'')!=='https'
            || !is_string($parts['host']??null)
            || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path']??'',['','/'],true)) return '';
        $host=strtolower((string)$parts['host']);
        if (!filter_var($host,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)
            || filter_var($host,FILTER_VALIDATE_IP) || $host==='localhost'
            || strlen($host)>253) return '';
        return 'https://'.$host;
    }
    /**
     * Company admin URLs always use the verified canonical public origin,
     * never the container's listen/target port. Read-only, scoped to a company.
     */
    public static function company_public_routes(string $code) {
        $binding=self::company_binding($code);
        if (is_wp_error($binding)) return $binding;
        $row=self::data(self::REGISTRY)[$code]??null;
        if (!is_array($row) || ($row['company_site_uuid']??'')!==$binding['company_site_uuid'])
            return self::error('public_origin_unbound','Company public URL requires the original exact site registry',409);
        $original=(string)($row['canonical_origin']??'');
        $origin=self::public_origin($original);
        if ($origin==='' || untrailingslashit($original)!==$origin)
            return self::error('public_origin_invalid','Company canonical origin must be a public HTTPS host with no port',409);
        return ['canonical_origin'=>$origin,
            'home_url'=>$origin.'/',
            'admin_url'=>$origin.'/wp-admin/',
            'login_url'=>$origin.'/wp-login.php',
            'public_port'=>443,
            'internal_port_exposed'=>false,
            'site_uuid'=>$binding['company_site_uuid']];
    }
    const COOLIFY_RESERVATION_PREFIX = 'mad4b_company_manager_coolify_service_v1_';
    public static function coolify_service_reservation(string $company) {
        if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company))return false;
        $row=get_option(self::COOLIFY_RESERVATION_PREFIX.$company,false);
        return is_array($row)&&($row['company_code']??null)===$company?$row:false;
    }
    /** Non-authorizing private service receipt. Never enrolls or activates a tenant. */
    public static function record_coolify_service_reservation(array $input) {
        if(!self::staging() || !class_exists('MAD4B_Tourism_Workflow_REST',false)
          || MAD4B_Tourism_Workflow_REST::write_permission()!==true)
            return self::error('coolify_owner_required','Original central owner session required',403);
        $company=(string)($input['company_code']??'');
        $environment=(string)($input['environment']??'');
        $uuid=(string)($input['site_uuid']??'');
        $origin=(string)($input['canonical_origin']??'');
        $service=(string)($input['coolify_resource_uuid']??'');
        $app=(int)($input['application_id']??0);
        if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company)
          || !in_array($environment,['production','staging'],true)
          || ($environment==='staging' && preg_match('/^qa-[a-z0-9-]{4,40}$/D',$company)!==1)
          || !wp_is_uuid($uuid)
          || $origin==='' || self::public_origin($origin)!==$origin
          || !preg_match('/^[a-z0-9]{16,36}$/D',$service) || $app<1)
            return self::error('coolify_service_scope_invalid','Exact service reservation scope required',422);
        if(isset(self::data(self::REGISTRY)[$company]))
            return self::error('coolify_site_already_registered','Existing tenant cannot be silently re-created',409);
        $receipt=['contract'=>'mad4b.company-manager-coolify-service.v1',
          'company_code'=>$company,'environment'=>$environment,'site_uuid'=>$uuid,
          'canonical_origin'=>$origin,'coolify_resource_uuid'=>$service,
          'application_id'=>$app,'resource_type'=>'service',
          'state'=>'PRIVATE_RESERVATION_REQUIRES_NATIVE_BOOTSTRAP',
          'resource_deployed'=>false,'site_profile_enrolled'=>false,
          'native_executor_authorized'=>false,'public_live'=>false];
        $key=self::COOLIFY_RESERVATION_PREFIX.$company;
        if(!add_option($key,$receipt,'',false)){
            $current=get_option($key,false);
            return is_array($current)&&$current===$receipt?$current:
              self::error('coolify_service_reservation_conflict','Reconcile existing provider resource; duplicate creation forbidden',409);
        }
        if(get_option($key,false)!==$receipt)
            return self::error('coolify_service_receipt_unverified','Readback failed',503);
        return $receipt;
    }
    public static function company_binding(string $code) {
        if (!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$code)) return self::error('company_invalid','Company code invalid',422);
        // A Central host may also act as an independent Staging Demo company.
        // Its signed Core identity is a site binding, NOT manager delegation,
        // executable SSH authority, or a company write grant. Never override
        // an existing explicit registration with this synthetic projection.
        $registered=self::data(self::REGISTRY);
        if (!array_key_exists($code,$registered) &&
            self::staging() &&
            class_exists('MAD4B_Native_VPS_Principal_V1',false) &&
            class_exists('MAD4B_Company_Environment_Registry_V2',false)) {
            $scope=MAD4B_Native_VPS_Principal_V1::scope();
            $principal=MAD4B_Native_VPS_Principal_V1::existing();
            $target=MAD4B_Company_Environment_Registry_V2::target($code,'staging');
            if (is_array($scope) && is_array($principal) && is_array($target) &&
                ($scope['contract']??'')==='mad4b.central-host-scope.v1' &&
                ($scope['scope_kind']??'')==='central_native_host' &&
                ($scope['company_target']??true)===false &&
                ($scope['environment']??'')==='staging' &&
                ($scope['workload']??'')===$code &&
                ($scope['state']??'')==='CENTRAL_HOST_IDENTITY_VERIFIED' &&
                MAD4B_Native_VPS_Principal_V1::ledger($principal,$scope) &&
                ($target['role']??'')==='self_hosted_demo_target' &&
                ($target['host_authority_reused_for_write']??true)===false &&
                ($target['write_authorized']??true)===false &&
                ($target['site_uuid']??'')===($scope['site_uuid']??null) &&
                ($target['canonical_origin']??'')===($scope['canonical_origin']??null) &&
                ($target['environment']??'')==='staging' &&
                untrailingslashit(home_url('/'))===($target['canonical_origin']??null) &&
                wp_is_uuid((string)$target['site_uuid'])) {
                $site_uuid=(string)$target['site_uuid'];
                $collision=self::unique_company_site_identity($code,$site_uuid,$site_uuid,$registered);
                if(is_wp_error($collision))return $collision;
                $origin=(string)$target['canonical_origin'];
                return [
                    'company_code'=>$code,'company_site_uuid'=>$site_uuid,
                    // Exact canonical identity, stable for an unchanged
                    // signed host ledger; approval still needs exact revision.
                    'binding_sha256'=>hash('sha256',implode('|',[
                        'mad4b.company-native-demo-binding.v1',
                        $code,$site_uuid,$origin,
                    ])),
                ];
            }
        }
        // Original Staging Signed V4 fallback for companies without an
        // independently enrolled legacy/Production company binding.
        // Never replace a registered identity, including a conflicting one.
        $registered=self::data(self::REGISTRY);
        if (!array_key_exists($code,$registered) && self::staging()
            && class_exists('MAD4B_Company_Environment_Portfolio_Evidence_V1',false)
            && class_exists('MAD4B_Company_Environment_Registry_V2',false)) {
            $proof=MAD4B_Company_Environment_Portfolio_Evidence_V1::readback($code,'staging');
            $site=MAD4B_Company_Environment_Registry_V2::target($code,'staging');
            if (is_array($proof) && is_array($site)
                && ($proof['contract']??'')==='mad4b.company-environment-portfolio-evidence.v1'
                && ($proof['company']??'')===$code
                && ($proof['environment']??'')==='staging'
                && ($proof['signed_identity_verified']??false)===true
                && ($proof['operations_delegation_approved']??false)===true
                && ($proof['portfolio_assignment_bound']??false)===true
                && ($proof['grant_issued']??true)===false
                && ($proof['production_mutation_allowed']??true)===false
                && ($site['environment']??'')==='staging'
                && ($site['company_id']??'')===$code
                && ($site['site_uuid']??null)===($proof['site_uuid']??null)
                && ($site['canonical_origin']??null)===($proof['canonical_origin']??null)
                && wp_is_uuid((string)($proof['site_uuid']??''))
                && preg_match('/^[a-f0-9]{64}$/D',
                    (string)($proof['portfolio_binding_sha256']??''))) {
                $collision=self::unique_company_site_identity($code,(string)$proof['site_uuid'],'',$registered);
                if(is_wp_error($collision))return $collision;
                return ['company_code'=>$code,
                    'company_site_uuid'=>$proof['site_uuid'],
                    'binding_sha256'=>$proof['portfolio_binding_sha256']];
            }
        }
        $row=self::data(self::REGISTRY)[$code]??null;
        if (!is_array($row) || ($row['company_code']??'')!==$code || empty($row['host_binding_verified'])
            || !wp_is_uuid((string)($row['company_site_uuid']??''))
            || !wp_is_uuid((string)($row['native_site_profile_uuid']??''))
            || !preg_match('/^[a-f0-9]{64}$/D',(string)($row['native_profile_digest']??''))
            || self::public_origin((string)($row['canonical_origin']??'')) === ''
            || untrailingslashit((string)$row['canonical_origin']) !== self::public_origin((string)$row['canonical_origin']))
            return self::error('company_unverified','Verified Company Registry site binding required',409);
        $collision=self::unique_company_site_identity($code,(string)$row['company_site_uuid'],(string)$row['native_site_profile_uuid'],$registered);
        if(is_wp_error($collision))return $collision;
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
            // A site replacement invalidates an old delegation by identity.
            // Its rights never carry forward; only a new owner proposal can
            // establish another assignment, with an auditable old snapshot.
            $stale=is_array($old) && ($old['state']??'')!=='revoked'
                && ($old['company_site_uuid']??'')!==$company['company_site_uuid']
                && ($old['binding_sha256']??'')!==$company['binding_sha256'];
            if(is_array($old)&&($old['state']??'')!=='revoked'&&!$stale)
                return self::error('assignment_open','Existing assignment must first be revoked',409);
            $proposal=['manager_id'=>$id,'company_code'=>$code,'company_site_uuid'=>$company['company_site_uuid'],
                'binding_sha256'=>$company['binding_sha256'],'rights'=>$rights,'reason'=>$reason];
            return ['contract'=>'mad4b.manager-company-assignment.v1']+$proposal+[
                'proposal_sha256'=>hash('sha256',wp_json_encode($proposal,JSON_UNESCAPED_SLASHES)),
                'state'=>'proposed','revision'=>$revision+1,'proposed_at'=>gmdate('c'),
                'proposed_by'=>get_current_user_id(),'approval_sha256'=>null,
                'remote_writes_enabled'=>false,'company_assets_transferred'=>false,
                'prior_site_assignment_superseded'=>$stale,
                'prior_site_assignment_history'=>$stale?[
                  'site_uuid'=>$old['company_site_uuid']??null,
                  'binding_sha256'=>$old['binding_sha256']??null,
                  'state'=>$old['state']??null,'revision'=>$old['revision']??null,
                  'rights'=>$old['rights']??[],
                  'retired_at'=>gmdate('c'),
                  'historical_only'=>true,
                ]:($old['prior_site_assignment_history']??null)];
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
    /** MCP/OAuth self-discovery: derive company rights from the logged-in WP identity. */
    public static function my_access() {
        $permission=self::read_permission();
        if(is_wp_error($permission))return $permission;
        $uid=get_current_user_id();
        if(!$uid)return self::error('login_required','Authenticated WordPress identity required',403);
        $members=self::data(self::MEMBERS);
        $assignments=self::data(self::ASSIGNMENTS);
        $all=self::data(self::MANAGERS);
        $results=array();
        foreach($all as $manager_id=>$manager) {
            if(($manager['state']??'')!=='registered' ||
               ($members[$manager_id.'|'.$uid]['state']??'')!=='active')continue;
            $companies=array();
            foreach($assignments as $assignment) {
                $code=(string)($assignment['company_code']??'');
                if(($assignment['manager_id']??'')!==$manager_id ||
                   ($assignment['state']??'')!=='active' || !$code)continue;
                $rights=array();
                foreach((array)($assignment['rights']??array()) as $right)
                    if(in_array($right,self::RIGHTS,true) &&
                       self::has_delegation($manager_id,$code,$right,$uid))$rights[]=$right;
                if(!$rights)continue;
                $binding=self::company_binding($code);
                if(is_wp_error($binding))continue;
                $companies[]=array(
                    'company_code'=>$code,
                    'company_site_uuid'=>$binding['company_site_uuid'],
                    'rights'=>array_values(array_unique($rights)),
                    'binding_verified'=>true
                );
            }
            if($companies)$results[]=array(
                'manager_id'=>$manager_id,
                'manager_code'=>(string)($manager['manager_code']??''),
                'companies'=>$companies
            );
        }
        return array('contract'=>'mad4b.company-manager-mcp-self-access.v1',
            'authenticated'=>true,'source'=>'current_wordpress_oauth_identity',
            'managers'=>$results,'count'=>count($results),
            'authority_granted'=>false,'write_authorized_by_discovery'=>false);
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
        // Read-only private provider receipt in the ORIGINAL Company Manager.
        // A draft Service is never treated as an enrolled WordPress site.
        register_rest_route($prefix,
            '/company-manager-coolify-services/(?P<company_code>[a-z][a-z0-9-]{2,47})',[
             'methods'=>'GET','permission_callback'=>[__CLASS__,'admin_permission'],
             'callback'=>static function($r){
                $receipt=self::coolify_service_reservation((string)$r['company_code']);
                return $receipt ? self::reply($receipt):
                    self::error('coolify_service_not_reserved','No verified original provider receipt',404);
             }]);
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
            'mad4b/company-manager-my-access'=>[[],[],static fn($a)=>self::my_access()],
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

// Company domain features are mounted at the original Core bootstrap boundary.
// The Core owns site authority and the lifecycle; this Add-on owns business
// classes and only registers their existing hooks/REST routes.
add_action('mad4b_scp_company_management_bootstrap', static function (): void {
    if (wp_get_environment_type() !== 'staging'
        || !class_exists('MAD4B_SCP_Admin_Route_Registry', false)) return;
    require_once __DIR__ . '/includes/class-mad4b-scp-company-communication-team.php';
    require_once __DIR__ . '/includes/class-mad4b-scp-company-master-admin.php';
    require_once __DIR__ . '/includes/class-mad4b-scp-company-task-handoff.php';
    require_once __DIR__ . '/includes/class-mad4b-scp-company-user-lifecycle.php';
    require_once __DIR__ . '/includes/class-mad4b-scp-company-operations-policy.php';
    require_once __DIR__ . '/includes/class-mad4b-scp-company-release-status.php';
    require_once __DIR__ . '/includes/class-mad4b-scp-company-owner-activation.php';
    require_once __DIR__ . '/includes/class-mad4b-scp-company-tenant-migration.php';
    require_once __DIR__ . '/includes/class-mad4b-scp-migration-decision-catalog.php';
    require_once __DIR__ . '/includes/class-mad4b-scp-company-migration-decisions.php';
    require_once __DIR__ . '/includes/class-mad4b-scp-staging-brand-trial.php';
    require_once __DIR__ . '/includes/class-mad4b-scp-staging-context-fixtures.php';
    require_once __DIR__ . '/includes/class-mad4b-scp-staging-fixture-drafts.php';
}, 10);


// Advertise Company Management through the existing Control Plane provider
// manifest hook. A manifest is inventory only; all existing Core write guards,
// original provider grants and execution receipts remain authoritative.
add_filter('mad4b_scp_dynamic_provider_manifests', static function ($items) {
    if (!is_array($items) || !MAD4B_Company_Portfolio_Manager_V1::staging()
        || !class_exists('MAD4B_SCP_Operation_Registry', false)) return $items;
    $items[]=[
        'provider_id'=>'company_management_addon',
        'existing_registry_identity'=>'central_company_management',
        'implementation_version'=>'0.4.0-company-addon',
        'supports'=>[
            'company_portfolio_read',
            'company_migration_planning',
            'company_code_reconciliation',
            'company_communication_policy',
            'company_domain_planning',
            'company_readiness_readback'
        ],
        'side_effects'=>[[
            'effect_scope'=>'company_site',
            'ownership'=>'provider',
            'reversibility'=>'unknown',
            'compensation_support'=>'none',
            'externality'=>'local'
        ]],
        'soft_elapsed_budget_ms'=>30000,
        'enforceable_deadline_supported'=>false,
    ];
    return $items;
}, 20, 1);

// Original Company Bootstrap methods now live in Company Management.
if (wp_get_environment_type() === 'staging') {
    require_once __DIR__ . '/includes/class-mad4b-company-bootstrap-contract.php';
}

// Tenant presentation option ownership: one canonical runtime provider
// for each field. This read-only schema is included in source reconcile plans.
if (wp_get_environment_type() === 'staging') {
    require_once __DIR__ . '/includes/class-mad4b-company-presentation-option-ownership.php';
    require_once __DIR__ . '/includes/class-mad4b-company-native-plugin-install-handler.php';
    require_once __DIR__ . '/includes/class-mad4b-company-native-plugin-install-provider.php';
}

// Original, read-only Start Company completeness projection: belongs to
// Company Management, and reuses the Core Readiness owner and REST lifecycle.
if (wp_get_environment_type() === 'staging') {
    require_once __DIR__ . '/includes/class-mad4b-company-start-reconcile-completeness.php';
}

// Company domain onboarding belongs to this Add-on. The original Cloudflare
// REST controller and authorization policy are retained without duplicating
// a Core provider or taking ownership of Cloudflare credentials.
add_action('plugins_loaded', static function (): void {
    if (wp_get_environment_type() !== 'staging') return;
    require_once __DIR__ . '/includes/class-mad4b-scp-company-domain-cloudflare.php';
    MAD4B_SCP_Company_Domain_Cloudflare::boot();
}, 16);

MAD4B_Company_Portfolio_Manager_V1::boot();
// FAORE Company Connections: registry-scoped observation, not operation authority.
require_once __DIR__ . '/includes/class-mad4b-company-faore-fleet-observability.php';
MAD4B_Company_FAORE_Fleet_Observability::boot();
require_once __DIR__ . '/includes/class-mad4b-company-faore-broker-readback.php';
MAD4B_Company_FAORE_Broker_Readback::boot();

// Company-scoped Service Catalog Mode: central planning only, no tenant apply.
if (wp_get_environment_type()==='staging') {
    require_once __DIR__ . '/includes/class-mad4b-company-service-catalog.php';
    require_once __DIR__ . '/includes/class-mad4b-company-network-membership.php';
    require_once __DIR__ . '/includes/class-mad4b-company-portability-readiness.php';
}

require_once __DIR__ . '/class-mad4b-unified-administrator-principal.php';
require_once __DIR__ . '/class-mad4b-central-admin-proxy.php';

// Company Management Add-on: bind existing Control Plane operations, not a
// second planner, grant issuer or executor. Load after Core boot; preserve
// previous public ability IDs and WP-CLI command names.
add_action('plugins_loaded', static function (): void {
    if (!MAD4B_Company_Portfolio_Manager_V1::staging()
        || !class_exists('MAD4B_SCP_Operation_Registry', false)) return;
    require_once __DIR__ . '/includes/class-mad4b-scp-company-code-reconciliation.php';
    require_once __DIR__ . '/includes/class-mad4b-scp-company-local-wpcli.php';
    MAD4B_SCP_Company_Code_Reconciliation::boot();
    MAD4B_SCP_Company_Local_WPCLI::boot();
    require_once __DIR__ . '/includes/class-mad4b-company-environment-portfolio-evidence.php';
    require_once __DIR__ . '/includes/class-mad4b-company-contentjob-skill-preflight.php';
    require_once __DIR__ . '/includes/class-mad4b-company-strategy-management.php';
    require_once __DIR__ . '/includes/class-mad4b-company-brand-readiness-hub.php';
    require_once __DIR__ . '/includes/class-mad4b-company-market-portfolio.php';
    require_once __DIR__ . '/includes/class-mad4b-company-launch-setup.php';
    require_once __DIR__ . '/includes/class-mad4b-vps-company-launch.php';
    require_once __DIR__ . '/includes/class-mad4b-company-plugin-sync-operation.php';
    require_once __DIR__ . '/includes/class-mad4b-company-task-origin-card.php';
}, 40);

