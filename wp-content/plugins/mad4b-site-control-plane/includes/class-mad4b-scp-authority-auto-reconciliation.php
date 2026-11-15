<?php
defined('ABSPATH') || exit;

/**
 * Non-production exact authority self-heal.
 *
 * Two privilege levels:
 *  - WP-Cron / HTTP: inspect only, report drift, never mutate.
 *  - enrolled privileged host CLI: optionally bind its unique host subject to
 *    the already-enrolled owner/write Agent and retire proven obsolete grants.
 *
 * This never moves an OAuth subject, invents OAuth/approval, creates a new
 * agent, creates a write grant, enables Full Authority, or changes Production.
 * Missing grants and contested OAuth ownership stay with canonical governance.
 */
final class MAD4B_SCP_Authority_Auto_Reconciliation {
    const CONTRACT='mad4b.authority-auto-reconciliation.v1';
    const EVENT='mad4b_authority_auto_reconciliation_inspect';

    public static function boot() {
        if(function_exists('add_action')) {
            add_action(self::EVENT,array(__CLASS__,'scheduled_inspect'));
            add_action('init',array(__CLASS__,'schedule'),90);
            add_action('upgrader_process_complete',array(__CLASS__,'schedule_soon'),90,2);
        }
        if(defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')){
            WP_CLI::add_command('mad4b authority auto-plan',array(__CLASS__,'cli_plan'));
            WP_CLI::add_command('mad4b authority reconcile-safe',array(__CLASS__,'cli_reconcile'));
        }
    }

    private static function site() {
        if(!class_exists('MAD4B_SCP_Site_Profile') || !MAD4B_SCP_Site_Profile::configured()
            || 'staging'!==MAD4B_SCP_Site_Profile::current_environment()
            || !MAD4B_SCP_Site_Profile::origin_enrolled()
            || !MAD4B_SCP_Site_Profile::site_urls_match_enrollment())
            return new WP_Error('mad4b_auto_reconcile_site_denied','Exact enrolled Staging site required.');
        if(!class_exists('MAD4B_SCP_Live_Acceptance_Observer'))
            return new WP_Error('mad4b_auto_reconcile_build_missing','Canonical provenance unavailable.');
        $build=MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
        if(!is_array($build)||empty($build['manifest_valid'])||empty($build['runtime_manifest_match'])
            ||!empty($build['stale'])||!empty($build['provenance_mismatch']))
            return new WP_Error('mad4b_auto_reconcile_build_stale','Exact live build required.');
        return $build;
    }

    public static function plan() {
        $build=self::site();
        if(is_wp_error($build))return $build;
        if(!class_exists('MAD4B_SCP_Staging_Write_Authority'))
            return new WP_Error('mad4b_auto_reconcile_authority_missing','Write reconciliation owner missing.');
        $p=MAD4B_SCP_Staging_Write_Authority::reconciliation_plan();
        if(is_wp_error($p))return $p;
        $safe=array(); $reviews=array();
        foreach((array)($p['unreviewed_stale_allow_grants']??array()) as $row) {
            $env=(string)($row['environment']??'');
            // Never decide that a current Staging grant is obsolete. Only
            // non-current or globally scoped grants lacking inventory matches
            // are safe to narrow in this specifically enrolled Staging Agent.
            if(in_array($env,array('local','development','production','all'),true)
                && 'stale_allow_unreviewed'===($row['reason']??'')
                && (int)($row['id']??0)>0 && !empty($row['ability'])
                && !empty($row['provider']))
                $safe[]=$row;
            else $reviews[]=$row;
        }
        $fp=hash('sha256',wp_json_encode(array(
            'source'=>(string)($build['source_commit_sha']??''),
            'build'=>(string)($build['build_fingerprint']??''),
            'agent'=>(string)($p['agent_public_id']??''),
            'grant_rows'=>(string)($p['persisted_grant_records_fingerprint']??''),
            'safe'=>$safe,'review'=>$reviews,
            'missing'=>(array)($p['exact_grants_missing']??array()),
            'subjects'=>(array)($p['subject_preflight_blockers']??array()),
        ),JSON_UNESCAPED_SLASHES));
        return array(
            'contract'=>self::CONTRACT,'site_uuid'=>MAD4B_SCP_Site_Profile::site_uuid(),
            'source_commit_sha'=>$build['source_commit_sha'],
            'plan_sha256'=>$fp,'agent_public_id'=>(string)($p['agent_public_id']??''),
            'safe_stale_retirement'=>$safe,
            'needs_review'=>$reviews,
            'missing_exact_grants'=>$p['exact_grants_missing']??array(),
            'subject_conflicts'=>$p['subject_preflight_blockers']??array(),
            'candidate_binding_match'=>!empty($p['candidate_binding']['match']),
            'write_ready'=>!empty($p['effective_ready']),
            'next_action'=>'registered_host_worker_then_native_grant_reconciliation',
            'read_only'=>true,'mutation_performed'=>false,
        );
    }

    public static function schedule() {
        if(!class_exists('MAD4B_SCP_Site_Profile')
            || 'staging'!==MAD4B_SCP_Site_Profile::current_environment())return;
        if(function_exists('wp_next_scheduled') && !wp_next_scheduled(self::EVENT)
            && function_exists('wp_schedule_event')) wp_schedule_event(time()+120,'hourly',self::EVENT);
    }
    public static function schedule_soon() {
        if(class_exists('MAD4B_SCP_Site_Profile')
            && 'staging'===MAD4B_SCP_Site_Profile::current_environment()
            && function_exists('wp_schedule_single_event'))
            wp_schedule_single_event(time()+45,self::EVENT);
    }
    public static function scheduled_inspect() {
        $p=self::plan();
        if(is_wp_error($p))return;
        $fingerprint=$p['plan_sha256'];
        $key='mad4b_auto_authority_last_plan';
        $previous=get_option($key,'');
        if($previous!==$fingerprint) {
            // Non-authorizing diagnostics. Operator queues may subscribe to
            // this event; they cannot treat it as an approval ticket.
            do_action('mad4b_authority_reconciliation_required',$p);
            update_option($key,$fingerprint,false);
        }
    }
    public static function cli_plan($args,$assoc_args) {
        if($args||$assoc_args)WP_CLI::error('No caller-selected site or identity.');
        $p=self::plan();
        if(is_wp_error($p))WP_CLI::error($p->get_error_code());
        WP_CLI::line(wp_json_encode($p,JSON_UNESCAPED_SLASHES));
    }
    public static function cli_reconcile($args,$assoc_args) {
        if($args||$assoc_args)WP_CLI::error('No caller-selected site, grants or identity.');
        $r=self::reconcile_safe();
        if(is_wp_error($r)){
            $data=$r->get_error_data();
            if(is_array($data)&&!empty($data['recovery_required'])){
                // Explicit non-secret machine-readable recovery receipt before
                // the nonzero CLI exit. Never report a completed mutation as
                // a simple retryable failure.
                WP_CLI::line(wp_json_encode(array_merge(
                    array('state'=>'RECONCILIATION_REQUIRED',
                          'error_code'=>$r->get_error_code()),$data
                ),JSON_UNESCAPED_SLASHES));
            }
            WP_CLI::error($r->get_error_code());
        }
        WP_CLI::line(wp_json_encode($r,JSON_UNESCAPED_SLASHES));
    }

    /**
     * A host write/binding may already have completed before a later grant
     * fails. Return an explicit reconciliation-only error, never a retryable
     * general failure. No new grant or permission is inferred by this marker.
     */
    private static function uncertain_result($error, array $before, array $retired, $binding_changed) {
        return new WP_Error(
            is_wp_error($error) ? $error->get_error_code() : 'mad4b_auto_reconcile_uncertain',
            is_wp_error($error) ? $error->get_error_message() : 'Authority reconciliation needs an exact readback.',
            array(
                'contract'=>self::CONTRACT, 'site_uuid'=>$before['site_uuid'],
                'source_commit_sha'=>$before['source_commit_sha'],
                'plan_sha256'=>$before['plan_sha256'],
                'host_binding_changed'=>(bool)$binding_changed,
                'retired_stale_grant_ids'=>$retired,
                'recovery_required'=>true, 'reconciliation_required'=>true,
                'blind_retry_allowed'=>false,
                'production_mutation'=>false,
                'next_action'=>'independent_agent_grant_and_host_binding_readback',
            )
        );
    }

    public static function reconcile_safe() {
        $before=self::plan();
        if(is_wp_error($before))return $before;
        if(!class_exists('MAD4B_SCP_CLI_Execution_Context')
            || !class_exists('MAD4B_SCP_Execution_Authority')
            || !class_exists('MAD4B_SCP_Agent_Registry'))
            return new WP_Error('mad4b_auto_reconcile_executor_missing','Canonical local executor unavailable.');
        $facts=MAD4B_SCP_CLI_Execution_Context::resolve('authority.reconcile',false);
        if(!is_array($facts)||empty($facts['read_context_verified'])
            ||empty($facts['host_site_binding_verified'])||!empty($facts['blockers'])
            ||!defined('WP_CLI')||true!==WP_CLI||'cli'!==PHP_SAPI
            ||!function_exists('posix_geteuid')||0!==posix_geteuid()
            ||0!==get_current_user_id())
            return new WP_Error('mad4b_auto_reconcile_host_denied','Privileged verified non-impersonating host CLI required.');
        // A read-only host attestation cannot authorize mutation. Check the
        // original operation-bound executor before creating a host binding.
        $apply_facts=MAD4B_SCP_CLI_Execution_Context::resolve('authority.apply',true);
        if(!is_array($apply_facts)||empty($apply_facts['local_operation_authorized'])
            ||empty($apply_facts['host_site_binding_verified'])
            ||!empty($apply_facts['blockers'])
            ||empty($facts['canonical_origin'])||empty($apply_facts['canonical_origin'])
            ||!hash_equals((string)$facts['canonical_origin'],(string)$apply_facts['canonical_origin']))
            return new WP_Error('mad4b_auto_reconcile_apply_denied','Verified exact host write operation required before host binding.');
        // The Host NHI is NOT a WordPress user. Bind only to the exact
        // already-enrolled canonical Write Agent from the immutable plan;
        // its historic wp_user_id is human attribution, not Host identity.
        $agent=MAD4B_SCP_Agent_Registry::get_agent_by_public_id($before['agent_public_id']);
        if(!is_array($agent)||(int)($agent['id']??0)<1
            ||!hash_equals((string)$before['agent_public_id'],(string)($agent['public_id']??''))
            ||'chatgpt-governed-write'!==($agent['slug']??'')
            ||'enabled'!==($agent['status']??'')
            ||'staging'!==($agent['environment']??''))
            return new WP_Error('mad4b_auto_reconcile_agent_denied',
                'Exact existing canonical governed Write Agent required.');
        $audit=class_exists('MAD4B_SCP_Audit')?MAD4B_SCP_Audit::storage_status():array();
        if(empty($audit['ready']) || 'append_only_no_automatic_deletion' !== ($audit['retention_mode'] ?? ''))
            return new WP_Error('mad4b_auto_reconcile_audit_denied','Append-only audit required.');
        // The current host attestation maps to exactly this agent. The bind
        // is idempotent and creates no new Agent or grant.
        $identity=array('authenticated'=>true,'subject_type'=>'host_operator',
            'subject_fingerprint'=>hash('sha256',"mad4b.host-operator.v1\0"
                .$before['site_uuid']."\0staging\0"
                .(string)$facts['canonical_origin']));
        $binding=MAD4B_SCP_Agent_Registry::subject_binding(
            $identity['subject_type'],$identity['subject_fingerprint']);
        if(is_wp_error($binding))return $binding;
        if(is_array($binding) && (int)$binding['agent_id']!==(int)$agent['id'])
            return new WP_Error('mad4b_auto_reconcile_host_conflict','Host subject already belongs to another Agent.');
        $binding_changed=false;
        $retired=array();
        if(!is_array($binding)||'enabled'!==($binding['status']??'')){
            $intent=MAD4B_SCP_Audit::record('mad4b/host-operator-bind-intent',
                array('contract'=>self::CONTRACT,'site_uuid'=>$before['site_uuid'],
                    'agent_public_id'=>$before['agent_public_id'],'plan_sha256'=>$before['plan_sha256']), 'ok');
            if(is_wp_error($intent))return $intent;
            $bound=MAD4B_SCP_Agent_Registry::bind_subject($agent['public_id'],'host_operator',
                $identity['subject_fingerprint'],'Registered MAD4B Staging host executor');
            if(is_wp_error($bound))return self::uncertain_result($bound,$before,$retired,true);
            $binding_changed=true;
        }
        $actor=MAD4B_SCP_Execution_Authority::resolve('apply');
        if(is_wp_error($actor))return $binding_changed
            ?self::uncertain_result($actor,$before,$retired,true):$actor;
        if('host_cli'!==($actor['transport']??'') || empty($actor['registry_bound'])
            || 0 !== (int)($actor['wp_user_id']??-1)
            || (int)($actor['registered_host_agent_id']??0)!==(int)$agent['id']
            || !hash_equals($identity['subject_fingerprint'],
                (string)($actor['subject_fingerprint']??''))){
            $denied=new WP_Error('mad4b_auto_reconcile_actor_denied','Exact bound host actor required for narrowing.');
            return $binding_changed?self::uncertain_result($denied,$before,$retired,true):$denied;
        }
        // One source snapshot; never act on a changed grant universe.
        $current=self::plan();
        if(is_wp_error($current))return $binding_changed
            ?self::uncertain_result($current,$before,$retired,true):$current;
        if(!hash_equals($before['plan_sha256'],$current['plan_sha256'])){
            $drift=new WP_Error('mad4b_auto_reconcile_drift','Write grants or build changed during reconciliation.');
            return $binding_changed?self::uncertain_result($drift,$before,$retired,true):$drift;
        }
        $grants=MAD4B_SCP_Agent_Registry::grants_for_agent((int)$agent['id'],'mad4b-write');
        $by_id=array();
        foreach($grants as $g)$by_id[(int)$g['id']]=$g;
        // Preflight ALL candidates before the first revocation. A late row
        // mismatch cannot cause an avoidable half-completed retirement.
        $seen=array();
        foreach($current['safe_stale_retirement'] as $row) {
            $id=(int)$row['id'];$g=$by_id[$id]??null;
            if(isset($seen[$id])||!is_array($g) || $g['effect']!=='allow'
                ||($g['server_id']??'mad4b-write')!=='mad4b-write'
                ||$g['ability_name']!==$row['ability']
                ||$g['provider']!==$row['provider']
                ||$g['environment']!==$row['environment']){
                $changed=new WP_Error('mad4b_auto_reconcile_grant_changed','Exact stale grant changed.');
                return $binding_changed?self::uncertain_result($changed,$before,$retired,true):$changed;
            }
            $seen[$id]=true;
        }
        foreach($current['safe_stale_retirement'] as $row) {
            $id=(int)$row['id'];
            $intent=MAD4B_SCP_Audit::record('mad4b/retire-obsolete-grant-intent',
                array('contract'=>self::CONTRACT,'plan_sha256'=>$current['plan_sha256'],
                'agent_public_id'=>$agent['public_id'],'grant_id'=>$id,
                'ability'=>$row['ability'],'provider'=>$row['provider'],
                'environment'=>$row['environment'],'source_commit_sha'=>$current['source_commit_sha']), 'ok');
            if(is_wp_error($intent))return ($binding_changed||$retired)
                ?self::uncertain_result($intent,$before,$retired,$binding_changed):$intent;
            $done=MAD4B_SCP_Agent_Registry::revoke_allow_grant_by_id(
                $agent['public_id'],$id,'mad4b-write',array(
                    'ability_name'=>$row['ability'],
                    'provider'=>$row['provider'],
                    'environment'=>$row['environment'],
                ));
            // A failed/zero-row conditional DELETE after an audited intent
            // requires independent readback even if no prior grant was retired.
            if(is_wp_error($done))return self::uncertain_result($done,$before,$retired,$binding_changed);
            $retired[]=$id;
        }
        $after=self::plan();
        if(is_wp_error($after))return self::uncertain_result($after,$before,$retired,$binding_changed);
        return array(
            'contract'=>self::CONTRACT,'state'=>'NARROWED_AND_REPLANNED',
            'host_agent_bound'=>true,'retired_stale_grant_ids'=>$retired,
            'remaining_stale'=>count($after['safe_stale_retirement'])+count($after['needs_review']),
            'missing_exact_count'=>count($after['missing_exact_grants']),
            'subject_conflicts'=>$after['subject_conflicts'],
            'candidate_binding_match'=>$after['candidate_binding_match'],
            'full_write_ready'=>$after['write_ready'],
            'next_action'=>'native_exact_grant_reconciliation_or_owner_review',
            'mutation_performed'=>($binding_changed||!empty($retired)), 'production_mutation'=>false,
        );
    }
}
MAD4B_SCP_Authority_Auto_Reconciliation::boot();
