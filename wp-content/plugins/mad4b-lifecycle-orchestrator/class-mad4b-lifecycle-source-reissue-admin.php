<?php
defined('ABSPATH') || exit;
/**
 * Existing original Content Hub Queue owner: exact Staging administrator
 * explicitly reissues one untouched read-only old ContentJob batch.
 * This is NOT a queue executor, new authority, 313, or background job.
 */
final class MAD4B_Lifecycle_Source_Reissue_Admin {
    const CONTRACT='mad4b.lifecycle-source-reissue-admin.v1';
    const PAGE='mad4b-lifecycle-source-reissue';

    static function allowed() {
        return class_exists('MAD4B_Lifecycle_Orchestrator')
            && MAD4B_Lifecycle_Orchestrator::can_write()
            && current_user_can('activate_plugins')
            && class_exists('MAD4B_Content_Hub')
            && MAD4B_Content_Hub::permission()
            && class_exists('MAD4B_Hub_Bulk_Jobs_Queue')
            && MAD4B_Hub_Bulk_Jobs_Queue::writable()
            && class_exists('MAD4B_Hub_Governed_Single_Flight')
            && MAD4B_Hub_Governed_Single_Flight::enabled()
            && MAD4B_Hub_Governed_Single_Flight::ready()
            && class_exists('MAD4B_Hub_Original_Context_Evidence');
    }
    static function error($code) {
        return new WP_Error('mad4b_reissue_'.$code,$code,['status'=>409]);
    }
    static function menu() {
        if(!self::allowed())return;
        add_submenu_page('mad4b-shared-content-hub','Source Reissue','Source Reissue',
            'manage_options',self::PAGE,[__CLASS__,'page']);
    }
    static function uuid($value) {
        return is_string($value)
          && (bool)preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$value);
    }
    /** Read-only, source-derived and hash-fenced. Never supplies old certificate as new. */
    static function plan($batch_id) {
        if(!self::allowed())return self::error('original_admin_authority_required');
        if(!self::uuid($batch_id))return self::error('batch_id_invalid');
        global $wpdb;
        $b=$wpdb->prefix.'mad4b_hub_bulk_batches';
        $t=$wpdb->prefix.'mad4b_hub_bulk_tasks';
        $f=$wpdb->prefix.'mad4b_hub_semantic_flights';
        $control=$wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $b WHERE batch_id=%s",$batch_id),ARRAY_A);
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $t WHERE batch_id=%s ORDER BY id",$batch_id),ARRAY_A);
        if($wpdb->last_error || !is_array($control)
           ||($control['state']??'')!=='ACTIVE'
           ||(int)($control['item_count']??0)!==2
           ||count((array)$rows)!==2)return self::error('exact_two_item_active_batch_required');
        $runtime=null;$stage=null;
        foreach($rows as $row){
            if((int)($row['attempts']??-1)!==0
               ||!empty($row['lease_owner'])||!empty($row['lease_hash'])
               ||!empty($row['lease_expires_at'])
               ||($row['stage']??'')!=='INTAKE')
                return self::error('prior_execution_or_lease_requires_review');
            if($row['operation']==='runtime_resolve'&&$row['state']==='QUEUED')$runtime=$row;
            elseif($row['operation']==='stage_work'&&$row['state']==='WAITING_EXECUTOR')$stage=$row;
            else return self::error('batch_has_nonstandard_task');
        }
        if(!$runtime||!$stage)return self::error('paired_task_missing');
        $job_id=(string)$runtime['content_job_id'];
        if(!self::uuid($job_id)||$stage['content_job_id']!==$job_id
           ||$runtime['dependencies_json']!=='[]'
           ||json_decode($stage['dependencies_json'],true)!==[$runtime['task_key']])
            return self::error('original_job_or_dependencies_invalid');
        $read=MAD4B_SCP_Content_Jobs::get_job(['job_id'=>$job_id]);
        if(is_wp_error($read)||!is_array($read['job']??null))
            return self::error('original_job_readback_failed');
        $job=$read['job'];
        if(($job['job_id']??'')!==$job_id
           ||($job['stage']??'')!=='INTAKE'
           ||!in_array($job['state']??'', ['NEW','QUEUED','RUNNING'],true))
            return self::error('job_stage_or_state_changed');
        $r=json_decode($runtime['payload_json'],true);
        $s=json_decode($stage['payload_json'],true);
        if(!is_array($r)||!is_array($s)
           ||($r['assigned_executor']??'')!=='vps_runner'
           ||($s['assigned_executor']??'')!=='mcp_client'
           ||($r['content_job_id']??'')!==$job_id
           ||($s['content_job_id']??'')!==$job_id
           ||!isset($r['content_job_revision'],$s['content_job_revision'])
           ||(int)$r['content_job_revision']!==(int)$s['content_job_revision']
           ||(int)$r['content_job_revision']>=(int)$job['job_revision'])
            return self::error('original_revision_not_stale');
        $flight=$wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $f WHERE leader_task_id=%s AND job_id=%s",
            $runtime['task_id'],$job_id),ARRAY_A);
        if(!is_array($flight)||(int)$flight['job_revision']!==(int)$r['content_job_revision']
           ||($flight['source_record_id']??'')!==$job_id)
            return self::error('original_single_flight_receipt_missing');
        $future=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $f WHERE job_id=%s AND job_revision=%d",
            $job_id,(int)$job['job_revision']));
        $others=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $t WHERE content_job_id=%s AND batch_id<>%s AND state IN
             ('QUEUED','RETRY','WAITING_EXECUTOR','RUNNING','NEEDS_RECONCILIATION')",
            $job_id,$batch_id));
        if($future!==0||$others!==0)return self::error('current_revision_reserved_or_other_work_active');
        $key='l5.reissue.runtime.'.str_replace('-','',$runtime['task_id']);
        $duplicate=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $b WHERE batch_key=%s",$key));
        if($duplicate!==0||$wpdb->last_error)
            return self::error('reissue_key_already_used_or_readback_failed');
        $rp=array_intersect_key($r,array_flip([
            'assigned_executor','resolution_stage','source_task_key','content_job_id',
            'provider','capability_query','stage_input','stage_ability']));
        $sp=array_intersect_key($s,array_flip([
            'assigned_executor','stage_adapter_id','resolution_task','requested_step',
            'content_job_id','preferred_ability','provider','capability_query',
            'stage_input','stage_ability']));
        foreach([&$rp,&$sp] as &$payload){
            $payload['content_job_revision']=(int)$job['job_revision'];
            $payload['trigger_source']='manual';
            $payload['trigger_event_type']='manual';
        }
        unset($payload);
        $items=[
            ['task_key'=>$runtime['task_key'],'stage'=>$runtime['stage'],
             'operation'=>'runtime_resolve','job_id'=>$job_id,
             'target_ref'=>$runtime['target_ref'],'depends_on'=>[],
             'payload'=>$rp,'priority'=>(int)$runtime['priority'],
             'max_attempts'=>(int)$runtime['max_attempts']],
            ['task_key'=>$stage['task_key'],'stage'=>$stage['stage'],
             'operation'=>'stage_work','job_id'=>$job_id,
             'target_ref'=>$stage['target_ref'],'depends_on'=>[$runtime['task_key']],
             'payload'=>$sp,'priority'=>(int)$stage['priority'],
             'max_attempts'=>(int)$stage['max_attempts']]
        ];
        $new=['batch_key'=>$key,'max_concurrency'=>min(2,max(1,(int)$control['max_concurrency'])),
              'items'=>$items];
        // These three are the existing original providers; no synthetic signature.
        $normalized=MAD4B_Hub_Workflow_Origin::normalize_batch($new);
        if(is_wp_error($normalized))return $normalized;
        $certified=MAD4B_Hub_Original_Context_Evidence::bind_batch($normalized);
        if(is_wp_error($certified))return $certified;
        $first=$certified['items'][0];
        $eligible=MAD4B_Hub_Governed_Single_Flight::plan([
            'job_id'=>$job_id,'stage'=>$first['stage'],
            'operation'=>$first['operation'],'target_ref'=>$first['target_ref'],
            'payload'=>$first['payload'],'depends_on'=>[],
            '_batch_key'=>$key]);
        if(is_wp_error($eligible)||($eligible['eligible']??false)!==true)
            return self::error('new_original_single_flight_not_eligible');
        $fingerprint=hash('sha256',wp_json_encode([
          'current_revision'=>(int)$job['job_revision'],'batch'=>$batch_id,
          'source_job'=>$job_id,'control'=>$control,'tasks'=>$rows,'flight'=>$flight
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return ['contract'=>self::CONTRACT,'eligible'=>true,
          'batch_id'=>$batch_id,'job_id'=>$job_id,
          'old_revision'=>(int)$r['content_job_revision'],
          'new_revision'=>(int)$job['job_revision'],'new_batch_key'=>$key,
          'source_preimage_sha256'=>$fingerprint,
          'new_certificate_source_verified'=>true,
          'new_single_flight_source_verified'=>true,
          'mutation_performed'=>false,'original_queue_owns_execution'=>true,
          'native_313_certified'=>false,'enqueue_input'=>$new];
    }

    /** Only invoked by an actual authenticated admin POST with a fresh nonce. */
    static function submit() {
        if(($_SERVER['REQUEST_METHOD']??'')!=='POST')
            wp_die('Original Content Hub requires POST',403);
        if(!self::allowed())wp_die('Original Content Hub authority required',403);
        $batch=sanitize_text_field(wp_unslash((string)($_POST['batch_id']??'')));
        check_admin_referer('mad4b_lifecycle_reissue_'.$batch);
        $expected=sanitize_text_field(wp_unslash((string)($_POST['expected_sha256']??'')));
        $p=self::plan($batch);
        if(is_wp_error($p))wp_die(esc_html($p->get_error_code()),409);
        if(!preg_match('/^[a-f0-9]{64}$/D',$expected)
           ||!hash_equals($p['source_preimage_sha256'],$expected))
            wp_die('Stale source revision or batch evidence: re-plan required',409);
        // Quiesce the original claimable batch before admitting replacement.
        // Each mutation uses the original governed Queue API, never raw SQL.
        // If any stage becomes uncertain the old batch remains PAUSED, not
        // deleted or silently resumed with a stale certificate.
        $pause=MAD4B_Hub_Bulk_Jobs_Queue::batch_control([
            'batch_id'=>$batch,'action'=>'PAUSE']);
        if(is_wp_error($pause))wp_die(
            'Original pause rejected: '.esc_html($pause->get_error_code()),409);
        $old=MAD4B_Hub_Bulk_Jobs_Queue::list_queue(['batch_id'=>$batch]);
        if(is_wp_error($old))wp_die('Original pause readback unavailable; reconciliation required.',409);
        $oldBatch=$old['batches'][0]??[];
        $tasks=$oldBatch['tasks']??[];
        if(($oldBatch['state']??'')!=='PAUSED' || count($tasks)!==2
           ||array_filter($tasks,static fn($t)=>($t['attempts']??-1)!=0
              ||!empty($t['lease_owner'])||!empty($t['lease_expires_at']))
           ||count(array_filter($tasks,static fn($t)=>
               ($t['operation']??'')==='runtime_resolve'&&($t['state']??'')==='QUEUED'))!==1
           ||count(array_filter($tasks,static fn($t)=>
               ($t['operation']??'')==='stage_work'&&($t['state']??'')==='WAITING_EXECUTOR'))!==1)
            wp_die('Original pause state or untouched source changed; manual reconciliation required.',409);
        // The original provider re-reads the current ContentJob, rebinds
        // Workflow Origin, certifies Context Evidence and reserves Single Flight.
        $new=MAD4B_Hub_Bulk_Jobs_Queue::enqueue($p['enqueue_input']);
        if(is_wp_error($new))
            wp_die('Original batch remains PAUSED; new intake rejected: '
                    .esc_html($new->get_error_code()).'. Manual reconciliation required.',409);
        $newBatch=$new['batches'][0]??[];
        $newId=(string)($newBatch['batch_id']??'');
        if(!self::uuid($newId) ||($newBatch['batch_key']??'')!==$p['new_batch_key'])
            wp_die('Original enqueue receipt uncertain; old batch remains PAUSED.',409);
        $verified=MAD4B_Hub_Bulk_Jobs_Queue::list_queue(['batch_id'=>$newId]);
        if(is_wp_error($verified))wp_die(
            'New Queue readback unavailable; old batch remains PAUSED.',409);
        $confirmed=$verified['batches'][0]??[];
        $newRows=$confirmed['tasks']??[];
        if(($confirmed['state']??'')!=='ACTIVE'
           ||($confirmed['batch_id']??'')!==$newId
           ||($confirmed['batch_key']??'')!==$p['new_batch_key']
           ||count($newRows)!==2
           ||count(array_filter($newRows,static fn($t)=>
               ($t['content_job_id']??'')!==$p['job_id']
               ||($t['attempts']??-1)!=0
               ||!empty($t['lease_owner'])||!empty($t['lease_expires_at'])))!==0
           ||count(array_filter($newRows,static fn($t)=>
               ($t['operation']??'')==='runtime_resolve'
               &&($t['state']??'')==='QUEUED'
               &&($t['assigned_executor']??'')==='vps_runner'
               &&($t['depends_on']??null)===[]))!==1
           ||count(array_filter($newRows,static fn($t)=>
               ($t['operation']??'')==='stage_work'
               &&($t['state']??'')==='WAITING_EXECUTOR'
               &&($t['assigned_executor']??'')==='mcp_client'))!==1)
            wp_die('New source-bound batch verification failed; old remains PAUSED.',409);
        // Only after the replacement is independently readable may the
        // original owner cancel two never-executed old tasks.
        $cancel=MAD4B_Hub_Bulk_Jobs_Queue::batch_control([
            'batch_id'=>$batch,'action'=>'CANCEL']);
        if(is_wp_error($cancel))wp_die(
            'New batch exists; old remains PAUSED or uncertain: '
            .esc_html($cancel->get_error_code()).'. Reconcile before further action.',409);
        $after=MAD4B_Hub_Bulk_Jobs_Queue::list_queue(['batch_id'=>$batch]);
        if(is_wp_error($after))wp_die('Old cancellation readback unavailable; reconcile.',409);
        $oldFinal=$after['batches'][0]??[];
        $oldRows=$oldFinal['tasks']??[];
        if(($oldFinal['state']??'')!=='CANCELLED'||count($oldRows)!==2
           ||count(array_filter($oldRows,static fn($t)=>
                  ($t['state']??'')!=='CANCELLED'))!==0)
            wp_die('Old cancellation proof incomplete; do not reissue again.',409);
        wp_safe_redirect(add_query_arg([
            'page'=>self::PAGE,'batch_id'=>$batch,
            'state'=>'original-reissue-verified','new_batch_id'=>$newId],
            admin_url('admin.php')));
        exit;
    }
    static function page() {
        if(!self::allowed())wp_die('Original Content Hub authority required',403);
        $batch=sanitize_text_field(wp_unslash((string)($_GET['batch_id']??'')));
        echo '<div class="wrap"><h1>Source-certified ContentJob reissue</h1>';
        echo '<p>Exact Tours.dev Staging only. Reads the original ContentJob, Workflow Origin, Context Evidence and Single Flight. No 313 is issued here.</p>';
        echo '<form method="get"><input type="hidden" name="page" value="'.esc_attr(self::PAGE).'">';
        echo '<label for="original_batch">Original Batch ID</label> ';
        echo '<input id="original_batch" name="batch_id" value="'.esc_attr($batch).'" size="40">';
        echo ' <button class="button">Verify original source</button></form>';
        if($batch===''){echo '</div>';return;}
        $plan=self::plan($batch);
        if(is_wp_error($plan)){
            echo '<p class="notice notice-error">'.esc_html($plan->get_error_code()).'</p></div>';return;
        }
        echo '<p>Old Revision '.esc_html((string)$plan['old_revision'])
           .' → Current Revision '.esc_html((string)$plan['new_revision'])
           .' | Exact source-bound reissue eligible. No prior execution or lease.</p>';
        echo '<p>The old batch will be PAUSED first, its new source-bound replacement created and verified, then the old never-executed tasks cancelled. Failed or uncertain steps require manual reconciliation and do not authorize a replay.</p>';
        echo '<form action="'.esc_url(admin_url('admin-post.php')).'" method="post">';
        echo '<input type="hidden" name="action" value="mad4b_lifecycle_source_reissue">';
        echo '<input type="hidden" name="batch_id" value="'.esc_attr($batch).'">';
        echo '<input type="hidden" name="expected_sha256" value="'.esc_attr($plan['source_preimage_sha256']).'">';
        wp_nonce_field('mad4b_lifecycle_reissue_'.$batch);
        echo '<button type="submit" class="button button-primary">Confirm original source-certified reissue</button>';
        echo '</form></div>';
    }
    static function boot() {
        if(!class_exists('MAD4B_Lifecycle_Orchestrator')
           ||!MAD4B_Lifecycle_Orchestrator::scoped())return;
        add_action('admin_menu',[__CLASS__,'menu'],99);
        add_action('admin_post_mad4b_lifecycle_source_reissue',[__CLASS__,'submit']);
    }
}
