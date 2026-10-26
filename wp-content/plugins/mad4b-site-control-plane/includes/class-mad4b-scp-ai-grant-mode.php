<?php
if (!defined('ABSPATH')) exit;

/**
 * Optional AI Agent Only grant mode: owner-initiated exact CONTENT REVIEW grant.
 * Does not mint Developer/Breakglass/Full Authority or bypass their OAuth gates.
 * The agent can execute *already granted* protected Abilities without another
 * content-reviewer approval, but only under original Site Profile and scopes.
 */
final class MAD4B_SCP_AI_Grant_Mode {
    const CONTRACT='mad4b.ai-grant-mode.v1';
    const OPTION='mad4b_scp_ai_grant_mode_v1';
    const LOCK='mad4b_scp_ai_grant_mode_lock_v1';
    const REVIEW_ABILITY='mad4b/context-ai-review';
    const REVIEW_SERVER='mad4b-write';

    public static function defaults() {
        return array('contract'=>self::CONTRACT,'mode'=>'off','agent_public_id'=>'',
            'site_uuid'=>'','environment'=>'','managed_grant_id'=>0,'updated_at'=>0);
    }
    public static function policy() {
        $p=get_option(self::OPTION,array());
        if(!is_array($p) || ($p['contract']??'')!==self::CONTRACT) return self::defaults();
        $base=self::defaults();
        foreach(array_keys($base) as $key) if(array_key_exists($key,$p))$base[$key]=$p[$key];
        if(!in_array($base['mode'],array('off','ai_agent_only'),true))$base['mode']='off';
        $base['managed_grant_id']=max(0,(int)$base['managed_grant_id']);
        return $base;
    }
    private static function current_scope() {
        if(!class_exists('MAD4B_SCP_Site_Profile') || !MAD4B_SCP_Site_Profile::configured()
            || !MAD4B_SCP_Site_Profile::origin_enrolled()
            || !MAD4B_SCP_Site_Profile::site_urls_match_enrollment())
            return new WP_Error('mad4b_ai_grant_site_not_enrolled','Exact Site Profile origin is mandatory.');
        $environment=sanitize_key((string)MAD4B_SCP_Site_Profile::current_environment());
        if(!in_array($environment,array('local','development','staging'),true)
            ||(function_exists('wp_get_environment_type')&&wp_get_environment_type()==='production'))
            return new WP_Error('mad4b_ai_grant_production_denied','AI-only automatic review grant is non-production only.');
        $review=MAD4B_SCP_Context_Authority::review_policy();
        if(($review['mode']??'')!=='ai_only' || !is_string($review['ai_agent_public_id']??null)
           || !preg_match('/^[a-f0-9-]{36}$/D',$review['ai_agent_public_id']))
            return new WP_Error('mad4b_ai_grant_review_mode_required','Original AI-only policy and bound Agent required.');
        $agent=MAD4B_SCP_Agent_Registry::get_agent_by_public_id($review['ai_agent_public_id']);
        if(!is_array($agent) ||($agent['status']??'')!=='enabled'
           ||sanitize_key((string)($agent['slug']??''))!==MAD4B_SCP_Site_Profile::agent_slug())
            return new WP_Error('mad4b_ai_grant_agent_mismatch','Enabled Site Profile Agent must match this site.');
        if(($agent['environment']??'')!==$environment) {
            // Existing Staging Agent may review a Local Lab only with its
            // pre-existing ALL-environment grant and ready native policy.
            // Never create a new ALL grant to make the mismatch pass.
            $prior=MAD4B_SCP_Agent_Registry::exact_grant((int)$agent['id'],
                self::REVIEW_SERVER,self::REVIEW_ABILITY,'core');
            $native=class_exists('MAD4B_SCP_Context_Authority')
                ?MAD4B_SCP_Context_Authority::ai_review_policy_status():array();
            if(!($environment==='local' &&($agent['environment']??'')==='staging'
                &&wp_get_environment_type()==='local'&&is_array($prior)
                &&($prior['effect']??'')==='allow'&&($prior['environment']??'')==='all'
                &&!empty($native['ready'])))
                return new WP_Error('mad4b_ai_grant_agent_environment_mismatch',
                    'Cross-environment agent must have original exact all-environment authority.');
        }
        return array('agent'=>$agent,'site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid(),
            'environment'=>$environment);
    }
    public static function status() {
        $policy=self::policy();
        $scope=self::current_scope();
        $blockers=array();
        if(($policy['mode']??'off')!=='ai_agent_only')$blockers[]='optional_grant_mode_disabled';
        if(is_wp_error($scope))$blockers[]=$scope->get_error_code();
        else if(($policy['site_uuid']??'')!==$scope['site_uuid']
             ||($policy['environment']??'')!==$scope['environment']
             ||($policy['agent_public_id']??'')!==$scope['agent']['public_id'])
            $blockers[]='original_agent_or_site_scope_changed';
        if(!class_exists('MAD4B_SCP_Audit')||empty(MAD4B_SCP_Audit::storage_status()['ready']))
            $blockers[]='append_only_audit_unavailable';
        $grant=null;
        if(!is_wp_error($scope)) {
            $grant=MAD4B_SCP_Agent_Registry::exact_grant((int)$scope['agent']['id'],
                self::REVIEW_SERVER,self::REVIEW_ABILITY,'core');
            if(is_wp_error($grant)||!is_array($grant)||($grant['effect']??'')!=='allow')
                $blockers[]='original_exact_review_grant_missing';
        }
        return array('contract'=>self::CONTRACT,'mode'=>$policy['mode'],
            'ready'=>count($blockers)===0,'blockers'=>$blockers,
            'site_scope'=>is_wp_error($scope)?'blocked':$scope['environment'],
            'review_agent_is_bound'=>!is_wp_error($scope),
            'existing_exact_review_grant'=>is_array($grant)&&($grant['effect']??'')==='allow',
            'managed_grant_id'=>(int)$policy['managed_grant_id'],
            'protected_grant_modes'=>array(
                'developer'=>'original_developer_oauth_apply_only',
                'developer_breakglass'=>'original_breakglass_oauth_apply_only',
                'full_staging'=>'original_staging_step_up_apply_only'),
            'issues_developer_grants'=>false,'issues_breakglass_grants'=>false,
            'production_allowed'=>false,'mutated'=>false);
    }
    private static function owner() {
        return current_user_can('manage_options')
            &&class_exists('MAD4B_SCP_Site_Profile')
            &&MAD4B_SCP_Site_Profile::user_is_enrolled(get_current_user_id())
            && !empty(get_current_user_id());
    }
    /**
     * Executes only after enrolled owner confirmation and admin nonce.
     * Grant only the original exact content-review ability; other domain
     * Abilities and developer provisioning stay under their original plans.
     */
    public static function apply($mode,$nonce) {
        if(!self::owner() || !wp_verify_nonce((string)$nonce,'mad4b_ai_grant_mode_change'))
            return new WP_Error('mad4b_ai_grant_owner_confirmation_required','Enrolled administrator nonce required.');
        if(!in_array($mode,array('off','ai_agent_only'),true))
            return new WP_Error('mad4b_ai_grant_mode_invalid','Unknown grant mode.');
        if(!class_exists('MAD4B_SCP_Audit')||empty(MAD4B_SCP_Audit::storage_status()['ready']))
            return new WP_Error('mad4b_ai_grant_audit_required','Append-only audit must be available.');
        if(!add_option(self::LOCK,array('created_at'=>time(),'user_id'=>get_current_user_id()),'',false))
            return new WP_Error('mad4b_ai_grant_change_busy','Another owner grant update is in progress.');
        try {
            $old=self::policy();
            $scope=self::current_scope();
            if($mode==='ai_agent_only' && is_wp_error($scope))return $scope;
            $agent=is_wp_error($scope)?null:$scope['agent'];
            if($mode==='off') {
                // A grant created by this feature can only be revoked by this
                // exact owner action. Never delete another controller's grant.
                $managed=(int)$old['managed_grant_id'];
                if($managed>0) {
                    $a=MAD4B_SCP_Agent_Registry::get_agent_by_public_id((string)$old['agent_public_id']);
                    if(!is_array($a))return new WP_Error('mad4b_ai_grant_old_agent_missing','Managed grant owner unavailable.');
                    $before=MAD4B_SCP_Agent_Registry::grants_for_agent((int)$a['id'],self::REVIEW_SERVER);
                    $owned=false;
                    foreach($before as $row)
                        if((int)$row['id']===$managed &&($row['ability_name']??'')===self::REVIEW_ABILITY
                           &&($row['provider']??'')==='core' &&($row['effect']??'')==='allow')$owned=true;
                    if(!$owned)return new WP_Error('mad4b_ai_grant_managed_grant_drift','Managed grant identity changed; no unsafe delete.');
                }
            }
            $meta=array('contract'=>self::CONTRACT,'mode'=>$mode,
                'site_uuid'=>$mode==='off'?$old['site_uuid']:$scope['site_uuid'],
                'environment'=>$mode==='off'?$old['environment']:$scope['environment'],
                'agent_public_id'=>$mode==='off'?$old['agent_public_id']:$agent['public_id'],
                'ability'=>self::REVIEW_ABILITY,'previous_mode'=>$old['mode'],
                'owner_user_id'=>get_current_user_id(),'production_mutation'=>false);
            $audit=MAD4B_SCP_Audit::record('mad4b/ai-grant-mode-owner-intent',$meta,'ok');
            if(is_wp_error($audit))return $audit;
            if($mode==='off') {
                if(!empty($old['managed_grant_id'])) {
                    $gone=MAD4B_SCP_Agent_Registry::revoke_allow_grant_by_id(
                        $old['agent_public_id'],(int)$old['managed_grant_id'],self::REVIEW_SERVER);
                    if(is_wp_error($gone))return $gone;
                }
                update_option(self::OPTION,self::defaults(),false);
                MAD4B_SCP_Audit::record('mad4b/ai-grant-mode-disabled',$meta,'ok');
                return self::status();
            }
            $exists=MAD4B_SCP_Agent_Registry::exact_grant((int)$agent['id'],
                self::REVIEW_SERVER,self::REVIEW_ABILITY,'core');
            if(is_wp_error($exists) &&$exists->get_error_code()!=='mad4b_nhi_grant_missing')
                return $exists; // Explicit denies and errors must win.
            $new_id=0;
            if(is_wp_error($exists)) {
                $write=MAD4B_SCP_Agent_Registry::grant_ability($agent['public_id'],
                    self::REVIEW_SERVER,self::REVIEW_ABILITY,'core',array(),'allow',$scope['environment']);
                if(is_wp_error($write))return $write;
                $created=MAD4B_SCP_Agent_Registry::exact_grant((int)$agent['id'],
                    self::REVIEW_SERVER,self::REVIEW_ABILITY,'core');
                if(!is_array($created) ||($created['effect']??'')!=='allow')
                    return new WP_Error('mad4b_ai_grant_readback_failed','Grant not verified after creation.');
                $new_id=(int)$created['id'];
            }
            $record=array('contract'=>self::CONTRACT,'mode'=>'ai_agent_only',
                'agent_public_id'=>$agent['public_id'],
                'site_uuid'=>$scope['site_uuid'],'environment'=>$scope['environment'],
                'managed_grant_id'=>$new_id>0?$new_id:(
                    ($old['mode']==='ai_agent_only'&&$old['agent_public_id']===$agent['public_id'])?
                    (int)$old['managed_grant_id']:0),
                'updated_at'=>time());
            if(!update_option(self::OPTION,$record,false) && self::policy()!==$record) {
                if($new_id>0)MAD4B_SCP_Agent_Registry::revoke_allow_grant_by_id($agent['public_id'],$new_id,self::REVIEW_SERVER);
                return new WP_Error('mad4b_ai_grant_mode_save_failed','Cannot persist owner selection.');
            }
            $receipt=MAD4B_SCP_Audit::record('mad4b/ai-grant-mode-enabled',$meta+array('created_grant_id'=>$new_id),'ok');
            if(is_wp_error($receipt)) {
                if($new_id>0)MAD4B_SCP_Agent_Registry::revoke_allow_grant_by_id($agent['public_id'],$new_id,self::REVIEW_SERVER);
                update_option(self::OPTION,$old,false);
                return new WP_Error('mad4b_ai_grant_receipt_failed','Grant receipt failed; changes rolled back.');
            }
            return self::status();
        } finally {
            delete_option(self::LOCK);
        }
    }
    public static function render_settings() {
        if(!self::owner())return;
        $status=self::status();
        echo '<hr/><h2>AI Agent Only — Optional exact grants</h2>';
        echo '<p>Explicit owner enrollment permits the existing AI reviewer to receive the exact <code>mad4b/context-ai-review</code> grant without a separate manual grant screen. This does not enable Developer, Breakglass, Full Staging, payments, deployment or publishing.</p>';
        echo '<p><strong>Mode:</strong> '.esc_html($status['mode']).'; <strong>ready:</strong> '.($status['ready']?'yes':'no').'; <strong>blockers:</strong> '.esc_html(implode(', ',$status['blockers'])).'</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('mad4b_ai_grant_mode_change');
        echo '<input type="hidden" name="action" value="mad4b_ai_grant_mode_change"/>';
        echo '<label for="mad4b-ai-grant-mode">Grant mode</label> ';
        echo '<select id="mad4b-ai-grant-mode" name="mode">';
        foreach(array('off'=>'Disabled (default)','ai_agent_only'=>'AI Agent Only — owner-approved exact review grant') as $mode=>$label)
            echo '<option value="'.esc_attr($mode).'" '.selected($status['mode'],$mode,false).'>'.esc_html($label).'</option>';
        echo '</select>';
        echo '<button class="button button-secondary" type="submit">Apply owner grant setting</button></form>';
        echo '<p>Privileged grants can already be used by the authenticated AI Agent only when separately provisioned through their original exact OAuth/step-up authorities. Choosing this mode alone cannot mint them.</p>';
    }
    public static function save_admin() {
        if(!self::owner())wp_die('Only the enrolled administrator may choose Agent grants.','Forbidden',array('response'=>403));
        $mode=isset($_POST['mode'])?sanitize_key(wp_unslash((string)$_POST['mode'])):'';
        $nonce=isset($_POST['_wpnonce'])?sanitize_text_field(wp_unslash((string)$_POST['_wpnonce'])):'';
        $result=self::apply($mode,$nonce);
        if(is_wp_error($result))wp_die(esc_html($result->get_error_code()),'Grant mode rejected',array('response'=>403));
        wp_safe_redirect(admin_url('options-general.php?page=mad4b-ai-routing'));
        exit;
    }
    public static function boot() {
        add_action('admin_post_mad4b_ai_grant_mode_change',array(__CLASS__,'save_admin'));
    }
}
MAD4B_SCP_AI_Grant_Mode::boot();
