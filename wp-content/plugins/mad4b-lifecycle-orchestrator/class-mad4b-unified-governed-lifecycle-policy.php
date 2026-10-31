<?php
defined('ABSPATH') || exit;
/**
 * MAD4B Unified Governed Lifecycle Policy v1 — bounded, read-only planning
 * for the installed runtime. Original providers own writes and readback.
 */
final class MAD4B_Unified_Governed_Lifecycle_Policy {
    const CONTRACT='mad4b.unified-governed-lifecycle-policy.v1';
    const STATES=['READY','RUNNING','RECOVERING','PARTIAL',
        'BLOCKED_AUTHORITY','BLOCKED_DEPENDENCY',
        'NEEDS_RECONCILIATION','VERIFIED'];
    private static function step($id,$label,$depends,$ability,$kind='read') {
        return ['id'=>$id,'label'=>$label,'depends_on'=>$depends,
            'ability'=>$ability,'execution_kind'=>$kind];
    }
    private static function schema($goal) {
        $s=static fn($id,$label,$deps,$ability,$kind='read')=>
            self::step($id,$label,$deps,$ability,$kind);
        switch($goal) {
          case 'seo':
            return [
              $s('context','Resolve exact content, language and company scope',[],null),
              $s('discover','Discover native SEO provider',['context'],'seo/status'),
              $s('content_plan','Independent SEO content diagnostics',['context'],'mad4b/seo-cycle-plan'),
              $s('media_plan','Independent image SEO discovery',['context'],'mad4b/seo-image-plan'),
              $s('generate','Prepare SEO metadata proposal',['content_plan'],'mad4b/seo-cycle-generate'),
              $s('write_meta','Governed Rank Math metadata patch',['generate','discover'],'seo/update-meta','write'),
              $s('write_image','Governed image ALT/metadata patch',['media_plan'],'media/update-metadata','write'),
              $s('verify_meta','Read native SEO data after write',['write_meta'],'seo/get-meta','verify'),
              $s('verify_markup','Inspect rendered image markup',['write_image'],'mad4b/seo-render-audit','verify'),
              $s('score','Calculate native editor-owned Rank Math score',['verify_meta'],null,'external'),
              $s('release','Evaluate content delivery evidence',['verify_meta','verify_markup'],null,'release'),
            ];
          case 'media':
            return [
              $s('context','Scope attachment and company',[],null),
              $s('discover','Read canonical attachment',['context'],'media/get'),
              $s('image_plan','Review visual ALT candidate',['discover'],'mad4b/seo-image-plan'),
              $s('metadata_write','Native metadata mutation with Journal',['image_plan'],'media/update-metadata','write'),
              $s('metadata_verify','Native media readback',['metadata_write'],'media/get','verify'),
              $s('markup_verify','Rendered attachment ALT audit',['metadata_write'],'mad4b/seo-render-audit','verify'),
            ];
          case 'content':
            return [
              $s('context','Resolve source ContentJob and runtime',[],null),
              $s('intake','Event/manual source intake',['context'],'mad4b/hub-runtime-intake'),
              $s('plan','Discover content blueprint',['intake'],'mad4b/content-orchestration-plan'),
              $s('queue','Queue bounded work',['plan'],'mad4b/hub-bulk-enqueue','write'),
              $s('handoff','Dispatch through selected original executor',['queue'],'mad4b/hub-runtime-handoff','write'),
              $s('verify','Read native job and content receipts',['handoff'],'mad4b/hub-runtime-verify','verify'),
              $s('release','Publish only through original governed owner',['verify'],null,'release'),
            ];
          case 'company':
            return [
              $s('context','Resolve verified company binding and acting principal',[],null),
              $s('delegation','Inspect scoped company rights',['context'],'mad4b/central-admin-company-context'),
              $s('strategy_plan','Plan company strategy without mutation',['context'],'mad4b/company-brand-hub-catalog'),
              $s('company_apply','Use original company strategy provider',['delegation','strategy_plan'],null,'write'),
              $s('verify','Read original company change receipt',['company_apply'],null,'verify'),
            ];
        }
        return [];
    }
    public static function admin_menu() {
        if(!MAD4B_Lifecycle_Orchestrator::scoped())return;
        add_management_page('MAD4B Unified Lifecycle','MAD4B Lifecycle',
            'manage_options','mad4b-unified-lifecycle-policy',[__CLASS__,'admin_page']);
    }
    public static function admin_page() {
        if(!MAD4B_Lifecycle_Orchestrator::can_read())wp_die('Staging administrator required',403);
        $goal=isset($_GET['goal'])?sanitize_key((string)wp_unslash($_GET['goal'])):'seo';
        $id=isset($_GET['target_id'])?absint($_GET['target_id']):0;
        $job=isset($_GET['job_id'])?(string)wp_unslash($_GET['job_id']):'';
        $company=isset($_GET['company_code'])?(string)wp_unslash($_GET['company_code']):'';
        if(!in_array($goal,['seo','media','content','company'],true))$goal='seo';
        $kind=['seo'=>'post','media'=>'attachment','content'=>'content_job','company'=>'company'][$goal];
        $input=['goal'=>$goal,'target_kind'=>$kind,'event_type'=>'manual','target_id'=>$id,
            'job_id'=>$job,'company_code'=>$company];
        if($goal==='company')$input['representation_mode']='central_admin_proxy';
        $has_target=$goal==='company'?$company!=='':($goal==='content'?$job!=='':$id>0);
        $plan=$has_target?MAD4B_Lifecycle_Orchestrator::policy($input):null;
        echo '<div class="wrap"><h1>MAD4B Unified Governed Lifecycle Policy v1</h1>';
        echo '<p>Read-only dependency plan. READY means eligible for a step, not completed or approved. '
            .'Native provider writes and publication remain separately governed.</p>';
        echo '<form method="get"><input type="hidden" name="page" value="mad4b-unified-lifecycle-policy">';
        echo '<label>Goal <select name="goal">';
        foreach(['seo','media','content','company'] as $candidate)
            echo '<option value="'.esc_attr($candidate).'" '.selected($goal,$candidate,false).'>'
                .esc_html(strtoupper($candidate)).'</option>';
        echo '</select></label> <label>Post/Media ID <input name="target_id" type="number" min="0" value="'.esc_attr($id).'"></label> ';
        echo '<label>ContentJob UUID <input name="job_id" value="'.esc_attr($job).'"></label> ';
        echo '<label>Company Code <input name="company_code" value="'.esc_attr($company).'"></label> ';
        echo '<button class="button button-primary">Inspect dependencies</button></form>';
        if(is_wp_error($plan))echo '<p class="notice notice-error">'.esc_html($plan->get_error_code()).'</p>';
        elseif(is_array($plan)){
            echo '<h2>Workflow: '.esc_html($plan['state']).'</h2>';
            echo '<p>Independent ready steps: '.count($plan['ready_independent_steps'])
                .' · Pending: '.count($plan['pending_steps']).'.</p>';
            echo '<table class="widefat striped"><thead><tr><th>Stage</th><th>State</th>'
                .'<th>Dependencies</th><th>Ability</th><th>Reason</th></tr></thead><tbody>';
            foreach($plan['steps'] as $step)echo '<tr><td>'.esc_html($step['label']).'</td>'
                .'<td><code>'.esc_html($step['state']).'</code></td>'
                .'<td>'.esc_html(implode(', ',$step['depends_on'])).'</td>'
                .'<td><code>'.esc_html($step['ability']??'source-owned').'</code></td>'
                .'<td>'.esc_html($step['reason']).'</td></tr>';
            echo '</tbody></table><p>No mutations were performed by this inspection.</p>';
        }
        echo '</div>';
    }
    public static function classify_error($error) {
        $code=is_wp_error($error)?$error->get_error_code():(string)$error;
        if(strlen($code)>180||!preg_match('/^[a-zA-Z0-9_.:-]+$/D',$code))
            $code='unknown_error';
        if(preg_match('/(commit_uncertain|rollback_uncertain|journal_recovery|reconciliation_required|write_unknown|readback_failed)/i',$code))
            return ['state'=>'NEEDS_RECONCILIATION','retry_write'=>false,
                'action'=>'read_exact_source_state_before_any_write'];
        if(preg_match('/(permission|authority|cap_denied|identity_required|certif|grant|unauthoriz|approval_required|owner_required|binding_stale)/i',$code))
            return ['state'=>'BLOCKED_AUTHORITY','retry_write'=>false,
                'action'=>'resolve_original_authority_or_owner_approval'];
        if(preg_match('/(wpcli_busy|staging_wpcli_busy|timeout|lock_busy|global_claim_busy|rate_limit|backpressure|temporary_unavailable)/i',$code))
            return ['state'=>'RECOVERING','retry_write'=>false,
                'action'=>'bounded_readiness_recheck_without_blind_mutation'];
        return ['state'=>'BLOCKED_DEPENDENCY','retry_write'=>false,
            'action'=>'discover_or_repair_missing_dependency'];
    }
    public static function plan($resolution) {
        if(!is_array($resolution)||!in_array(($resolution['goal']??''),['seo','media','content','company'],true)
           ||($resolution['site']??'')!=='https://tours.dev.mad4b.com/')
            return new WP_Error('mad4b_policy_resolution_invalid','Authenticated Staging resolution required');
        $graph=self::schema($resolution['goal']);$by_id=[];$steps=[];
        $auth=(array)($resolution['authority_blockers']??[]);
        $nonempty_reads=(array)($resolution['read_abilities']??[]);
        $registered_writes=(array)($resolution['write_abilities_registered']??[]);
        foreach($graph as $node) {
            $id=$node['id'];$kind=$node['execution_kind'];$ability=$node['ability'];
            $deps_missing=[];
            foreach($node['depends_on'] as $dependency) {
                $previous=$by_id[$dependency]??null;
                // A registered Ability is not a completed prerequisite.
                // Only the already-scoped context is proven during planning.
                if($previous===null || $dependency!=='context')
                    $deps_missing[]=$dependency;
            }
            $available=$ability===null || (function_exists('wp_has_ability')&&wp_has_ability($ability));
            $state='READY';$reason='registered_capability_only_no_execution';
            if($id==='context'){$reason='bounded_scope_readback';}
            elseif(!$available){$state='BLOCKED_DEPENDENCY';$reason='ability_not_registered';}
            elseif($kind==='write') {
                $state='BLOCKED_AUTHORITY';
                $reason='native_execution_requires_exact_principal_and_provider_gates';
                if($resolution['goal']==='seo'&&$id==='write_meta' && $auth)
                    $reason='rank_math_write_not_certified';
                // A blocked write does not block independent read plans.
            } elseif($deps_missing) {
                $state='BLOCKED_DEPENDENCY';
                $reason='prerequisite_execution_receipt_missing';
            } elseif(in_array($kind,['verify','external','release'],true)) {
                $state='BLOCKED_DEPENDENCY';
                $reason='await_native_execution_or_verification_receipt';
            }
            $row=$node+[
                'state'=>$state,'reason'=>$reason,
                'ability_registered'=>$available,
                'blocked_by'=>$deps_missing,
                'mutation_performed'=>false,'verified'=>false,
                'human_approval_needed'=>false,
            ];
            if($kind==='write')$row['requires_native_provider_permission']=true;
            if($kind==='external')$row['source_owned_scoring_required']=true;
            $steps[]=$row;$by_id[$id]=$row;
        }
        $ready=array_values(array_map(
            static fn($s)=>$s['id'],
            array_filter($steps,static fn($s)=>$s['state']==='READY')));
        $blocked=array_values(array_map(
            static fn($s)=>$s['id'],
            array_filter($steps,static fn($s)=>$s['state']!=='READY')));
        $states=array_unique(array_column($steps,'state'));
        $overall=$auth?'PARTIAL':(count($blocked)?'PARTIAL':'READY');
        if(!$ready && in_array('BLOCKED_AUTHORITY',$states,true))$overall='BLOCKED_AUTHORITY';
        elseif(!$ready)$overall='BLOCKED_DEPENDENCY';
        return [
            'contract'=>self::CONTRACT,'policy_version'=>'1.0.0-staging',
            'goal'=>$resolution['goal'],'state'=>$overall,
            'available_states'=>self::STATES,
            'steps'=>$steps,'ready_independent_steps'=>$ready,'pending_steps'=>$blocked,
            'authority_blockers'=>$auth,
            'parallel_read_tasks_allowed'=>count($ready)>1,
            'readiness_not_write_permission'=>true,
            'provider_write_executed'=>false,
            'provider_mutation_receipt'=>null,
            'native_verification_complete'=>false,
            'automatic_retry_of_unknown_writes'=>false,
            'production_mutation_allowed'=>false,
            'delivery_release_approved'=>false,
            'source_resolution_sha256'=>$resolution['resolution_sha256']??'',
        ];
    }
}
