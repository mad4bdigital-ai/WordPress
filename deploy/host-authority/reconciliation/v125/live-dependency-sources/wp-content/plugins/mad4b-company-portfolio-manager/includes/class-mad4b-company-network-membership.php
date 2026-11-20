<?php
/**
 * Company Network Autonomy — membership and content authority planning only.
 *
 * This module MUST NOT disconnect a company, change runtime service activation,
 * revoke network tokens, execute cross-site writes, or infer an authorized
 * publication from a plan. Those operations require separate target readback.
 */
defined('ABSPATH') || exit;

final class MAD4B_Company_Network_Membership_V1 {
    public const CONTRACT='mad4b.company-network-membership.v1';
    public const STORE='mad4b_company_network_memberships_v1';
    public const STATES=['connected','detach_prepared'];
    public const AUTHORITIES=['hub_primary','company_primary','hybrid'];
    public const ACTIONS=['prepare_detach','cancel_detach','set_authority'];

    public static function staging(): bool {
        return class_exists('MAD4B_Company_Portfolio_Manager_V1',false)
            && MAD4B_Company_Portfolio_Manager_V1::staging();
    }
    private static function error(string $name,string $message,int $http=409): WP_Error {
        return new WP_Error('mad4b_membership_'.$name,$message,['status'=>$http]);
    }
    public static function permission() {
        return self::staging()&&current_user_can('manage_options')
            &&current_user_can('activate_plugins')
            ? true:self::error('admin_required','Central Staging administrator required',403);
    }
    private static function binding(string $code) {
        if(!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$code))
            return self::error('company_invalid','Invalid company code',422);
        $b=MAD4B_Company_Portfolio_Manager_V1::company_binding($code);
        if(is_wp_error($b))return $b;
        if(!is_array($b)||($b['company_code']??null)!==$code
           ||!wp_is_uuid((string)($b['company_site_uuid']??''))
           ||!preg_match('/^[a-f0-9]{64}$/D',(string)($b['binding_sha256']??'')))
            return self::error('binding_invalid','Exact verified company binding required',403);
        return $b;
    }
    private static function records() {
        $rows=get_option(self::STORE,[]);
        return is_array($rows)?$rows:self::error('store_invalid','Membership registry is malformed',503);
    }
    public static function initial(string $code,array $binding): array {
        return [
            'contract'=>self::CONTRACT,
            'company_code'=>$code,
            'company_site_uuid'=>$binding['company_site_uuid'],
            'binding_sha256'=>$binding['binding_sha256'],
            'state'=>'connected',
            'content_authority'=>'hub_primary',
            'revision'=>0,
            'plan_id'=>null,
            'updated_at'=>null,
            'updated_by'=>null,
            'history'=>[]
        ];
    }
    public static function record(string $code) {
        if(!self::staging())return self::error('environment','Central Staging only',403);
        $b=self::binding($code);if(is_wp_error($b))return $b;
        $rows=self::records();if(is_wp_error($rows))return $rows;
        $record=$rows[$code]??self::initial($code,$b);
        if(!is_array($record)
           ||($record['contract']??null)!==self::CONTRACT
           ||($record['company_code']??null)!==$code
           ||($record['company_site_uuid']??null)!==$b['company_site_uuid']
           ||!is_string($record['binding_sha256']??null)
           ||!hash_equals($b['binding_sha256'],$record['binding_sha256'])
           ||!in_array($record['state']??null,self::STATES,true)
           ||!in_array($record['content_authority']??null,self::AUTHORITIES,true)
           ||!is_int($record['revision']??null)||$record['revision']<0
           ||!is_array($record['history']??null))
            return self::error('binding_drift','Membership identity changed; reconcile binding first',409);
        return $record;
    }

    /** No runtime writes, no network calls and no secret or customer inventory. */
    public static function readiness(array $record): array {
        $central=class_exists('MAD4B_SCP_Site_Profile',false)
            ? MAD4B_SCP_Site_Profile::profile():[];
        $same=is_array($central) && ($central['site_uuid']??null)===$record['company_site_uuid'];
        global $wpdb;
        $table=$wpdb->prefix.'mad4b_tenant_content_maps';
        $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))===$table;
        $mapCount=$exists?(int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE site_uuid=%s",$record['company_site_uuid'])):null;
        $gates=[
            'provider_export_readback_missing',
            'company_local_runtime_readback_missing',
            'central_grant_revocation_readback_missing',
            'last_common_source_snapshot_missing',
            'financial_and_booking_authority_handover_missing'
        ];
        if($same)$gates[]='separate_company_site_identity_missing';
        if(!$exists)$gates[]='tenant_mapping_table_unavailable';
        return [
            'manifest_contract'=>'mad4b.company-detach-readiness.v1',
            'company_site_uuid'=>$record['company_site_uuid'],
            'verified_company_binding'=>true,
            'same_as_central_host_uuid'=>$same,
            'tenant_content_map_count'=>$mapCount,
            'source_version_anchor'=>'requires_provider_manifest',
            'blocking_gates'=>$gates,
            'can_detach_now'=>false,
            'can_rejoin_now'=>false,
            'rejoin_preview_supported'=>true,
            'three_way_reconcile_provider'=>'MAD4B_Tenant_Sync_Planner',
            'publication_and_pricing_changes_authorized'=>false,
            'operational_membership_verified'=>false,
        ];
    }
    public static function current(string $code) {
        $r=self::record($code);if(is_wp_error($r))return $r;
        $r['readiness']=self::readiness($r);
        $r['runtime_changed']=false;
        $r['service_activation_changed']=false;
        $r['content_hub_publication_changed']=false;
        $r['tenant_site_write_authorized']=false;
        return $r;
    }

    /** Pure state transition for isolated tests, without WordPress writes. */
    public static function transition(array $record,string $action,int $expected,
                                      ?string $authority=null) {
        if(!in_array($action,self::ACTIONS,true))
            return self::error('action_invalid','Unsupported membership action',422);
        if($expected<0||($record['revision']??null)!==$expected)
            return self::error('revision_stale','Exact membership revision required',409);
        if(!in_array($record['state']??null,self::STATES,true)
           ||!in_array($record['content_authority']??null,self::AUTHORITIES,true))
            return self::error('state_invalid','Unknown membership state',409);
        $next=$record;
        if($action==='prepare_detach'){
            if($authority!==null)return self::error('payload_invalid','Authority is not part of detach preparation',422);
            if($record['state']==='detach_prepared')return $record;
            if($record['state']!=='connected')
                return self::error('state_conflict','Company cannot prepare from current state',409);
            $next['state']='detach_prepared';
        } elseif($action==='cancel_detach') {
            if($authority!==null)return self::error('payload_invalid','Cancel does not set content authority',422);
            if($record['state']==='connected')return $record;
            if($record['state']!=='detach_prepared')
                return self::error('state_conflict','No detachment preparation to cancel',409);
            $next['state']='connected';
        } else {
            if($record['state']!=='connected')
                return self::error('state_conflict','Content authority can change only while connected',409);
            if(!in_array($authority,self::AUTHORITIES,true))
                return self::error('authority_invalid','Supported content authority required',422);
            if($record['content_authority']===$authority)return $record;
            $next['content_authority']=$authority;
        }
        $next['revision']=$expected+1;
        $next['plan_id']='plan-'.substr(hash('sha256',implode('|',[
            $record['company_code'],$record['company_site_uuid'],$record['binding_sha256'],
            (string)$next['revision'],$action,$next['content_authority']
        ])),0,32);
        return $next;
    }
    public static function save(string $code,string $action,int $expected,
                                ?string $authority=null) {
        $permission=self::permission();if(is_wp_error($permission))return $permission;
        $binding=self::binding($code);if(is_wp_error($binding))return $binding;
        global $wpdb;
        $lock='mad4b:network:'.substr(hash('sha256',$code),0,29);
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1)
            return self::error('busy','Membership update busy',423);
        try {
            $before=self::record($code);
            if(is_wp_error($before))return $before;
            $next=self::transition($before,$action,$expected,$authority);
            if(is_wp_error($next))return $next;
            if($next['revision']===$before['revision'])return self::current($code);
            $all=self::records();if(is_wp_error($all))return $all;
            $next['updated_by']=get_current_user_id();
            $next['updated_at']=gmdate('c');
            $history=$before['history'];
            $history[]=[
                'revision'=>$next['revision'],'action'=>$action,
                'from_state'=>$before['state'],'to_state'=>$next['state'],
                'from_content_authority'=>$before['content_authority'],
                'to_content_authority'=>$next['content_authority'],
                'actor_id'=>$next['updated_by'],'at'=>$next['updated_at']
            ];
            $next['history']=array_slice($history,-50);
            $all[$code]=$next;
            if(!update_option(self::STORE,$all,false))
                return self::error('write_failed','Membership plan was not stored',503);
            $read=self::record($code);
            if(is_wp_error($read)||$read['revision']!==$next['revision']
                ||$read['plan_id']!==$next['plan_id'])
                return self::error('readback_failed','Membership readback mismatch',503);
            do_action('mad4b_company_network_membership_plan_saved',$code,$read);
            return self::current($code);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }

    private static function response($value) {
        $response=rest_ensure_response($value);
        if($response instanceof WP_REST_Response)
            $response->header('Cache-Control','private, no-store');
        return $response;
    }
    public static function routes(): void {
        if(!self::staging())return;
        $route='/company-management/companies/(?P<company_code>[a-z][a-z0-9-]{2,47})/network-membership';
        register_rest_route('mad4b/v1',$route,[
            [
                'methods'=>'GET',
                'permission_callback'=>[__CLASS__,'permission'],
                'callback'=>static fn($request)=>self::response(
                    self::current((string)$request['company_code']))
            ],
            [
                'methods'=>'POST',
                'permission_callback'=>[
                    'MAD4B_Company_Portfolio_Manager_V1','rest_write_permission'
                ],
                'callback'=>static function($request) {
                    $payload=$request->get_json_params();
                    if(!is_array($payload)||array_diff(array_keys($payload),
                           ['action','expected_revision','content_authority'])
                       ||!is_string($payload['action']??null)
                       ||!is_int($payload['expected_revision']??null)
                       ||$payload['expected_revision']<0
                       ||(array_key_exists('content_authority',$payload)
                          &&!is_string($payload['content_authority'])))
                        return self::response(self::error('payload_invalid',
                            'Exact action, revision and permitted fields required',422));
                    return self::response(self::save((string)$request['company_code'],
                        $payload['action'],$payload['expected_revision'],
                        $payload['content_authority']??null));
                }
            ]
        ]);
    }
    public static function admin_menu(): void {
        if(!self::staging())return;
        add_submenu_page('mad4b-company-brand-readiness',
            'Company Network Membership','Network Membership',
            'manage_options','mad4b-company-network-membership',
            [__CLASS__,'screen']);
    }
    public static function screen(): void {
        $permission=self::permission();
        if(is_wp_error($permission))wp_die(esc_html($permission->get_error_message()));
        echo '<div class="wrap"><h1>Company Management · Network Membership</h1>';
        echo '<p><strong>Plan only.</strong> Detach preparation does not disable the company, revoke any grants, switch Content Hub ownership, export data or authorize rejoin. Operational cutover requires site-owned proof and separate authorization.</p>';
        $notice=sanitize_key((string)($_GET['membership_status']??''));
        if($notice==='saved')echo '<div class="notice notice-success"><p>Company plan saved; no runtime cutover performed.</p></div>';
        elseif($notice==='error')echo '<div class="notice notice-error"><p>Plan not saved. Reload for a new revision or reconcile the site binding.</p></div>';
        echo '<table class="widefat striped"><thead><tr><th>Company</th><th>Identity</th><th>State</th><th>Content authority (planned)</th><th>Blocking evidence</th><th>Action</th></tr></thead><tbody>';
        $codes=class_exists('MAD4B_Company_Service_Catalog_V1',false)
            ? MAD4B_Company_Service_Catalog_V1::companies():[];
        foreach($codes as $code){
            $record=self::current($code);
            echo '<tr><td><strong>'.esc_html($code).'</strong></td>';
            if(is_wp_error($record)){
                echo '<td colspan="5">'.esc_html($record->get_error_message()).'</td></tr>';
                continue;
            }
            echo '<td><code>'.esc_html(substr($record['company_site_uuid'],0,8)).'…</code><p>Rev '.esc_html((string)$record['revision']).'</p></td>';
            echo '<td>'.esc_html($record['state']).'</td><td>'.esc_html($record['content_authority']).'</td>';
            $readiness=$record['readiness'];
            echo '<td>'.esc_html(implode(', ',$readiness['blocking_gates'])).'</td>';
            echo '<td><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('mad4b_network_membership_'.$code);
            echo '<input type="hidden" name="action" value="mad4b_network_membership_plan">';
            echo '<input type="hidden" name="company_code" value="'.esc_attr($code).'">';
            echo '<input type="hidden" name="expected_revision" value="'.esc_attr((string)$record['revision']).'">';
            echo '<select name="membership_action" aria-label="'.esc_attr('Membership action for '.$code).'">';
            foreach(['prepare_detach'=>'Prepare detach (no site changes)',
                      'cancel_detach'=>'Cancel preparation',
                      'set_authority'=>'Change planned content authority'] as $key=>$label){
                if($record['state']==='connected'&&$key==='cancel_detach')continue;
                if($record['state']==='detach_prepared'&&$key!=='cancel_detach')continue;
                echo '<option value="'.esc_attr($key).'">'.esc_html($label).'</option>';
            }
            echo '</select><select name="content_authority" aria-label="'.esc_attr('Planned content authority for '.$code).'">';
            foreach(self::AUTHORITIES as $auth)
                echo '<option value="'.esc_attr($auth).'"'.selected($auth,$record['content_authority'],false).'>'.esc_html($auth).'</option>';
            echo '</select> ';
            submit_button('Save plan','secondary','submit',false);
            echo '</form></td></tr>';
        }
        echo '</tbody></table><p>Rejoin preview and three-way reconciliation remain read-only until independent-site readback and tenant grant lifecycle providers are implemented.</p></div>';
    }
    public static function admin_post(): void {
        $per=self::permission();
        if(is_wp_error($per))wp_die(esc_html($per->get_error_message()),403);
        $code=sanitize_key((string)($_POST['company_code']??''));
        check_admin_referer('mad4b_network_membership_'.$code);
        $action=sanitize_key((string)($_POST['membership_action']??''));
        $rev=wp_unslash((string)($_POST['expected_revision']??''));
        $auth=sanitize_key((string)($_POST['content_authority']??''));
        $result=(ctype_digit($rev)&&strlen($rev)<=10)
            ?self::save($code,$action,(int)$rev,$action==='set_authority'?$auth:null)
            :self::error('revision_invalid','Revision required',422);
        wp_safe_redirect(add_query_arg('membership_status',
            is_wp_error($result)?'error':'saved',
            admin_url('admin.php?page=mad4b-company-network-membership')));
        exit;
    }
    public static function boot(): void {
        add_action('rest_api_init',[__CLASS__,'routes'],78);
        add_action('admin_menu',[__CLASS__,'admin_menu'],95);
        add_action('admin_post_mad4b_network_membership_plan',[__CLASS__,'admin_post']);
    }
}
MAD4B_Company_Network_Membership_V1::boot();
