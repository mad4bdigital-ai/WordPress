<?php
defined('ABSPATH') || exit;
/**
 * Event-based and manual triggers for MAD4B Hub Runtime.
 * Post-commit signals only; an event may enqueue work, but may never
 * manufacture successful stage execution or widen the originating actor.
 */
final class MAD4B_Hub_Trigger_Bus {
    const CONTRACT='mad4b.hub.workflow-triggers.v1';
    const OPTION='mad4b_hub_workflow_triggers_v1';
    const EVENTS=array('job_created','job_transition','artifact_created','executor_optin','stage_event');
    const LIMIT=35;

    public static function boot(){
        add_action('mad4b_scp_content_job_created',array(__CLASS__,'on_created'),10,1);
        add_action('mad4b_scp_content_job_transitioned',array(__CLASS__,'on_transition'),10,1);
        add_action('mad4b_scp_content_artifact_created',array(__CLASS__,'on_artifact'),10,1);
        add_action('mad4b_hub_workflow_event',array(__CLASS__,'on_custom'),10,1);
        add_action('rest_api_init',array(__CLASS__,'routes'),51);
        add_action('wp_abilities_api_init',array(__CLASS__,'abilities'),51);
        add_action('admin_menu',array(__CLASS__,'menu'),29);
    }
    private static function error($reason,$http=409){
        return new WP_Error('mad4b_trigger_'.$reason,$reason,array('status'=>$http,'contract'=>self::CONTRACT));
    }
    private static function allowed($write=false){
        return $write?MAD4B_Hub_Dynamic_Runtime_Resolver::writable()
            :MAD4B_Hub_Dynamic_Runtime_Resolver::readable();
    }
    private static function policy(){
        $p=get_option(self::OPTION,array());
        if(!is_array($p))$p=array();
        $events=array();
        foreach(self::EVENTS as $e)$events[$e]=($p['events'][$e]??true)===true;
        return array(
          'activated_at'=>(string)($p['activated_at']??''),
          'auto_enabled'=>($p['auto_enabled']??true)===true,
          'manual_enabled'=>($p['manual_enabled']??true)===true,
          'events'=>$events,'revision'=>max(1,(int)($p['revision']??1))
        );
    }
    public static function install(){
        if(!self::allowed(true))return self::error('permission',403);
        $p=get_option(self::OPTION,null);
        if(is_array($p)&&!empty($p['activated_at']))return self::status();
        $policy=array('activated_at'=>gmdate('Y-m-d H:i:s'),
          'auto_enabled'=>true,'manual_enabled'=>true,
          'events'=>array_fill_keys(self::EVENTS,true),'revision'=>1);
        add_option(self::OPTION,$policy,'','no');
        return self::status();
    }
    public static function configure($input){
        if(!self::allowed(true))return self::error('permission',403);
        if(!is_array($input))return self::error('invalid_input',400);
        $before=self::policy();
        $expected=(int)($input['expected_revision']??0);
        if($expected!==$before['revision'])return self::error('revision_conflict');
        $next=$before;
        foreach(array('auto_enabled','manual_enabled') as $key){
            if(array_key_exists($key,$input)){
                if(!is_bool($input[$key]))return self::error('invalid_toggle',400);
                $next[$key]=$input[$key];
            }
        }
        if(isset($input['events'])){
            if(!is_array($input['events']))return self::error('invalid_events',400);
            foreach($input['events'] as $key=>$enabled){
                if(!in_array($key,self::EVENTS,true)||!is_bool($enabled))
                    return self::error('invalid_event_policy',400);
                $next['events'][$key]=$enabled;
            }
        }
        $next['revision']++;
        if(!update_option(self::OPTION,$next,false)&&get_option(self::OPTION)!==$next)
            return self::error('config_save_failed');
        return self::status();
    }
    private static function valid_id($id){
        return is_string($id)&&preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$id);
    }
    private static function seen($key){
        global $wpdb;
        $table=$wpdb->prefix.'mad4b_hub_bulk_batches';
        return (bool)$wpdb->get_var($wpdb->prepare("SELECT batch_id FROM $table WHERE batch_key=%s",$key));
    }
    private static function valid_job_event($event){
        $id=strtolower((string)($event['job_id']??''));
        if(!self::valid_id($id))return self::error('job_id_invalid',400);
        $r=MAD4B_SCP_Content_Jobs::get_job(array('job_id'=>$id));
        if(is_wp_error($r))return $r;
        $job=$r['job']??array();
        if(($job['job_id']??'')!==$id)return self::error('out_of_scope_job',403);
        if(in_array($job['state'],array('CANCELLED','COMPLETED','FAILED','BLOCKED','WAITING_REVIEW'),true))
            return self::error('job_not_runnable',409);
        $expected=(int)($event['job_revision']??0);
        if($expected!==0&&$expected!==(int)$job['job_revision'])
            return self::error('stale_job_revision',409);
        if(!in_array((string)$job['stage'],MAD4B_Hub_Bulk_Jobs_Queue::stages(),true))
            return self::error('unsupported_stage');
        return $job;
    }
    public static function on_created($event){
        return self::event('job_created',$event);
    }
    public static function on_transition($event){
        // Closing a ContentJob must fence pending/running tasks immediately,
        // even when automatic enqueue is disabled.
        if(is_array($event)&&!empty($event['job_id'])&&self::allowed(true)){
            $r=MAD4B_SCP_Content_Jobs::get_job(array('job_id'=>$event['job_id']));
            if(!is_wp_error($r)&&is_array($r['job']??null))
                MAD4B_Hub_Bulk_Jobs_Queue::sync_job_state($r['job']);
        }
        return self::event('job_transition',$event);
    }
    public static function on_artifact($event){
        return self::event('artifact_created',$event);
    }
    public static function on_custom($event){
        $type=is_array($event)&&($event['event_kind']??'')==='executor_optin'
          ?'executor_optin':'stage_event';
        return self::event($type,$event);
    }
    private static function event($event_type,$event){
        if(!self::allowed(true)||!is_array($event))return self::error('access_denied',403);
        $p=self::policy();
        if(!$p['auto_enabled']||empty($p['events'][$event_type]))
            return array('contract'=>self::CONTRACT,'ignored'=>'auto_disabled');
        if(!$p['activated_at'])return self::error('not_installed',503);
        $job=self::valid_job_event($event);
        if(is_wp_error($job))return $job;
        $stage=(string)$job['stage'];
        $key='';
        if($event_type==='job_created'){
            if((int)$job['job_revision']!==1)return self::error('not_creation_revision');
            $key=$job['job_id'].':1';
        }elseif($event_type==='job_transition'){
            if(($event['state']??'')!==$job['state']
              ||($event['stage']??'')!==$stage)return self::error('transition_mismatch');
            if(!in_array($job['state'],array('QUEUED','RUNNING','NEW'),true))
                return self::error('transition_not_runnable');
            $key=$job['job_id'].':'.$job['job_revision'];
        }elseif($event_type==='artifact_created'){
            $id=(string)($event['artifact_id']??'');
            if(!self::valid_id($id))return self::error('invalid_artifact_id');
            $a=MAD4B_SCP_Artifacts::get_artifact(array('artifact_id'=>$id));
            if(is_wp_error($a))return $a;
            $item=$a['artifact']??array();
            if(($item['job_id']??'')!==$job['job_id']||($item['status']??'')!=='active')
                return self::error('artifact_scope_invalid');
            $key=$job['job_id'].':'.$id;
        }elseif($event_type==='executor_optin'){
            $key=$job['job_id'].':'.(int)($event['selection_revision']??$job['job_revision']);
        }else{
            $origin=(string)($event['source_event_key']??'');
            if(!preg_match('/^[A-Za-z0-9._:-]{8,120}$/D',$origin))
                return self::error('source_event_key_required');
            $key=$job['job_id'].':'.$origin;
        }
        return self::schedule($event_type,$key,$job['job_id'],$stage,'');
    }
    private static function schedule($trigger,$key,$jobid,$stage,$target){
        // One manual key may be reused across different jobs/targets without collision.
        $batch_key='event.'.substr(hash('sha256',$trigger.'|'.$key.'|'.$jobid.'|'.$target.'|'.$stage),0,44);
        if(self::seen($batch_key)){
            return array('contract'=>self::CONTRACT,'batch_key'=>$batch_key,
              'replayed'=>true,'event_type'=>$trigger,'execution_performed'=>false);
        }
        // Dispatch scope originates after valid_job_event() / artifact readback.
        // It is request-local, bound to this job/type/digest and always cleared.
        $event_id=hash('sha256',$trigger.'|'.$key.'|'.$jobid.'|'.$stage);
        $prior=MAD4B_Hub_Workflow_Origin::begin_verified_dispatch($trigger,$event_id,$jobid);
        try {
        $r=MAD4B_Hub_Dynamic_Runtime_Resolver::intake(array(
          'batch_key'=>$batch_key,'max_concurrency'=>2,
          'trigger_source'=>$trigger==='manual'?'manual':'event',
          'trigger_event_type'=>$trigger,
          'trigger_event_id'=>$event_id,
          'items'=>array(
            array('task_key'=>'triggered','stage'=>$stage,'job_id'=>$jobid,
              'target_ref'=>$target,'priority'=>7))));
        } finally {
          MAD4B_Hub_Workflow_Origin::end_verified_dispatch($prior);
        }
        if(is_wp_error($r))return $r;
        return array('contract'=>self::CONTRACT,'event_type'=>$trigger,
          'batch_id'=>$r['batch_id'],'batch_key'=>$batch_key,
          'stage'=>$stage,'replayed'=>(bool)$r['replayed'],
          'queue_created'=>true,'execution_performed'=>false);
    }
    public static function manual($input=array()){
        if(!self::allowed(true))return self::error('access_denied',403);
        if(!is_array($input))return self::error('invalid_input',400);
        $p=self::policy();
        if(!$p['manual_enabled'])return self::error('manual_disabled');
        $event_key=(string)($input['event_key']??'');
        if(!preg_match('/^[A-Za-z0-9._:-]{8,120}$/D',$event_key))
            return self::error('manual_event_key_required',400);
        $jobid=strtolower((string)($input['job_id']??''));
        $stage=strtoupper((string)($input['stage']??''));
        $target=(string)($input['target_ref']??'');
        if($jobid!==''){
            $job=self::valid_job_event(array('job_id'=>$jobid));
            if(is_wp_error($job))return $job;
            $expected=(int)($input['expected_job_revision']??0);
            if($expected>0&&$expected!==(int)$job['job_revision'])
                return self::error('stale_job_revision',409);
            if($stage===''||$stage===$job['stage'])$stage=(string)$job['stage'];
            else return self::error('requested_stage_does_not_match_job',409);
        }else return self::error('content_job_required_create_via_native_intake',422);
        if(!in_array($stage,MAD4B_Hub_Bulk_Jobs_Queue::stages(),true))
            return self::error('manual_stage_invalid');
        if(($input['dry_run']??false)===true) {
            $preview=MAD4B_Hub_Dynamic_Runtime_Resolver::resolve(
              array('job_id'=>$jobid,'stage'=>$stage,'target_ref'=>$target));
            if(is_wp_error($preview))return $preview;
            return array('contract'=>self::CONTRACT,'dry_run'=>true,
              'content_job_id'=>$jobid,'content_job_revision'=>(int)$job['job_revision'],
              'stage'=>$stage,'trigger_source'=>'manual','event_key'=>$event_key,
              'resolution_sha256'=>$preview['resolution_sha256'],
              'execution_route'=>$preview['recommended_route'],
              'queued'=>false,'mutation_performed'=>false);
        }
        return self::schedule('manual',$event_key,$jobid,$stage,$target);
    }
    /** Read the canonical ContentJob and every linked queue batch; no broader tenant scan. */
    public static function job_status($input=array()){
        if(!self::allowed())return self::error('access_denied',403);
        $id=strtolower(trim((string)($input['job_id']??'')));
        if(!self::valid_id($id))return self::error('job_id_invalid',400);
        $job=MAD4B_SCP_Content_Jobs::get_job(array('job_id'=>$id));
        if(is_wp_error($job))return $job;
        $queue=MAD4B_Hub_Bulk_Jobs_Queue::list_queue(array('job_id'=>$id));
        if(is_wp_error($queue))return $queue;
        $batches=array();$states=array();
        foreach((array)($queue['batches']??array()) as $batch) {
            $tasks=array();$jobCounts=array();
            foreach((array)($batch['tasks']??array()) as $t) {
                // A shared batch can include tasks for other ContentJobs.
                // Report only tasks owned by the requested job.
                if(($t['content_job_id']??'')!==$id)continue;
                $states[$t['state']]=($states[$t['state']]??0)+1;
                $jobCounts[$t['state']]=($jobCounts[$t['state']]??0)+1;
                $tasks[]=array('task_id'=>$t['task_id'],'task_key'=>$t['task_key'],
                    'content_job_id'=>$id,'stage'=>$t['stage'],
                    'operation'=>$t['operation'],'state'=>$t['state'],
                    'attempts'=>$t['attempts']);
            }
            $batches[]=array('batch_id'=>$batch['batch_id'],
              'batch_key'=>$batch['batch_key'],'state'=>$batch['state'],
              'counts'=>$jobCounts,'tasks'=>$tasks);
        }
        return array('contract'=>self::CONTRACT,'content_job_id'=>$id,
          'content_job_revision'=>(int)$job['job']['job_revision'],
          'job_state'=>$job['job']['state'],'job_stage'=>$job['job']['stage'],
          'batch_count'=>count($batches),'task_states'=>$states,'batches'=>$batches,
          'read_only'=>true,'mutation_performed'=>false);
    }
    /** Manual operations on existing batches use the same queue state machine. */
    public static function manual_control($input=array()){
        if(!self::allowed(true))return self::error('access_denied',403);
        if(!is_array($input))return self::error('invalid_input',400);
        $action=strtoupper(trim((string)($input['action']??'')));
        $p=self::policy();
        if(!in_array($action,array('PAUSE','RESUME','CANCEL','REQUEUE','DEAD_LETTER'),true))
            return self::error('invalid_control_action',400);
        if(!$p['manual_enabled']&&!in_array($action,array('PAUSE','CANCEL','DEAD_LETTER'),true))
            return self::error('manual_disabled');
        if(in_array($action,array('PAUSE','RESUME','CANCEL'),true)){
            $id=(string)($input['batch_id']??'');
            if(!self::valid_id($id))return self::error('invalid_batch_id',400);
            return MAD4B_Hub_Bulk_Jobs_Queue::batch_control(
                array('batch_id'=>$id,'action'=>$action));
        }
        $id=(string)($input['task_id']??'');
        if(!self::valid_id($id))return self::error('invalid_task_id',400);
        return MAD4B_Hub_Bulk_Jobs_Queue::reconcile(array(
          'task_id'=>$id,'action'=>$action,
          'verification_ref'=>(string)($input['verification_ref']??''),
          'confirmed_no_side_effects'=>($input['confirmed_no_side_effects']??false)===true));
    }

    /** Bounded repair for post-commit signals lost during a process interruption. */
    public static function sweep($limit=25){
        if(!self::allowed(true))return self::error('access_denied',403);
        $p=self::policy();
        if(!$p['activated_at']||!$p['auto_enabled'])return array(
          'contract'=>self::CONTRACT,'scanned'=>0,'scheduled'=>0,'disabled'=>true);
        $limit=max(1,min(self::LIMIT,(int)$limit));
        $jobs=MAD4B_SCP_Content_Jobs::list_jobs(array('limit'=>100));
        if(is_wp_error($jobs))return $jobs;
        $scanned=0;$scheduled=0;$errors=array();
        foreach((array)($jobs['items']??array()) as $j){
            if($scanned>=$limit)break;
            if(($j['updated_at']??'')<$p['activated_at'])continue;
            // Restore queue fencing even if the post-commit callback was interrupted.
            MAD4B_Hub_Bulk_Jobs_Queue::sync_job_state($j);
            if(!in_array($j['state'],array('NEW','QUEUED','RUNNING'),true))continue;
            $scanned++;
            $type=(int)$j['job_revision']===1?'job_created':'job_transition';
            if(empty($p['events'][$type]))continue;
            $event=array('job_id'=>$j['job_id'],'job_revision'=>(int)$j['job_revision'],
                'state'=>$j['state'],'stage'=>$j['stage']);
            $r=self::event($type,$event);
            if(is_wp_error($r))$errors[]=$r->get_error_code();
            elseif(!empty($r['queue_created']))$scheduled++;
        }
        // Recover evidence-triggered work independently of stage transitions.
        if(!empty($p['events']['artifact_created'])){
            $scope=MAD4B_SCP_Operational_Integrity::capture();
            if(!is_wp_error($scope)){
                global $wpdb;
                $table=MAD4B_SCP_Schema::tables()['artifacts'];
                $site=(string)($scope['scope']['site_uuid']??'');
                $rows=$wpdb->get_results($wpdb->prepare(
                  "SELECT artifact_id,job_id FROM $table WHERE site_uuid=%s AND created_at>=%s AND status='active' ORDER BY id DESC LIMIT %d",
                  $site,$p['activated_at'],$limit),ARRAY_A);
                foreach((array)$rows as $row){
                    $job=MAD4B_SCP_Content_Jobs::get_job(array('job_id'=>$row['job_id']));
                    if(is_wp_error($job)
                        ||!in_array($job['job']['state']??'',array('NEW','QUEUED','RUNNING'),true))
                        continue;
                    $r=self::event('artifact_created',$row);
                    if(is_wp_error($r))$errors[]=$r->get_error_code();
                    elseif(!empty($r['queue_created']))$scheduled++;
                }
            }
        }
        return array('contract'=>self::CONTRACT,'job_events_scanned'=>$scanned,
            'new_batches'=>$scheduled,'errors'=>array_values(array_unique($errors)),
            'bounded'=>true,'source'=>'vps_periodic_recovery');
    }
    public static function status($input=array()){
        if(!self::allowed())return self::error('access_denied',403);
        $p=self::policy();
        return array('contract'=>self::CONTRACT,'policy'=>$p,
          'event_types'=>self::EVENTS,
          'modes'=>array('auto_event_based','manual_based','scheduled_recovery'),
          'manual_endpoint'=>'/mad4b-hub/v1/control-workflow/triggers/manual',
          'automatic_execution_granted'=>false,'mutation_performed'=>false);
    }
    public static function routes(){
        foreach(array(
          '/control-workflow/triggers/status'=>array('GET','status',false),
          '/control-workflow/triggers/job-status'=>array('GET','job_status',false),
          '/control-workflow/triggers/manual'=>array('POST','manual',true),
          '/control-workflow/triggers/manual-control'=>array('POST','manual_control',true),
          '/control-workflow/triggers/configure'=>array('POST','configure',true),
          '/control-workflow/triggers/sweep'=>array('POST','sweep_rest',true)
        ) as $path=>$spec){
            register_rest_route('mad4b-hub/v1',$path,array(
              'methods'=>$spec[0],
              'callback'=>static function($req)use($spec){
                  return call_user_func(array(__CLASS__,$spec[1]),$req->get_params());
              },
              'permission_callback'=>static fn()=>self::allowed($spec[2])
            ));
        }
    }
    public static function sweep_rest($input=array()){
        return self::sweep((int)($input['limit']??25));
    }
    public static function abilities(){
        if(!function_exists('wp_register_ability'))return;
        foreach(array('status'=>false,'job_status'=>false,'manual'=>true,'manual_control'=>true,'configure'=>true,'sweep_rest'=>true) as $method=>$write){
            $name='mad4b/hub-trigger-'.str_replace('_','-',$method);
            if(wp_has_ability($name))continue;
            wp_register_ability($name,array(
              'label'=>'MAD4B Workflow Triggers '.str_replace('_',' ',$method),
              'description'=>'Authorized event-based and manual ContentJob lifecycle trigger.',
              'category'=>$write?'mad4b-write':'mad4b-read',
              'execute_callback'=>array(__CLASS__,$method),
              'permission_callback'=>static fn()=>self::allowed($write),
              'input_schema'=>array('type'=>'object','properties'=>array(
                'event_key'=>array('type'=>'string'),
                'job_id'=>array('type'=>'string'),'stage'=>array('type'=>'string'),
                'dry_run'=>array('type'=>'boolean'),
                'expected_job_revision'=>array('type'=>'integer'),
                'target_ref'=>array('type'=>'string'),
                'auto_enabled'=>array('type'=>'boolean'),
                'manual_enabled'=>array('type'=>'boolean'),
                'expected_revision'=>array('type'=>'integer'),
                'action'=>array('type'=>'string'),'batch_id'=>array('type'=>'string'),
                'task_id'=>array('type'=>'string'),
                'verification_ref'=>array('type'=>'string'),
                'confirmed_no_side_effects'=>array('type'=>'boolean'),
                'events'=>array('type'=>'object','additionalProperties'=>array('type'=>'boolean')),
                'limit'=>array('type'=>'integer')
              ),'required'=>$method==='manual'?array('event_key'):($method==='manual_control'?array('action'):array()),
                'additionalProperties'=>false),
              'output_schema'=>array('type'=>'object','additionalProperties'=>true),
              'meta'=>array('public'=>false,'show_in_rest'=>false,
                'mcp'=>array('public'=>false,'type'=>'tool','surface'=>$write?'write':'read'),
                'annotations'=>array('readonly'=>!$write,'destructive'=>$write,'idempotent'=>!$write))
            ));
        }
    }
    public static function menu(){
        if(!self::allowed())return;
        add_submenu_page('mad4b-shared-content-hub','Workflow Triggers',
          'Workflow Triggers','manage_options','mad4b-hub-workflow-triggers',array(__CLASS__,'page'));
    }
    public static function page(){
        if(!self::allowed())wp_die('Forbidden');
        $notice='';
        if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
            if(!self::allowed(true)||!check_admin_referer('mad4b_hub_trigger_controls'))wp_die('Forbidden');
            if(isset($_POST['manual_control'])){
                $r=self::manual_control(array(
                  'action'=>sanitize_text_field(wp_unslash($_POST['control_action']??'')),
                  'batch_id'=>sanitize_text_field(wp_unslash($_POST['batch_id']??'')),
                  'task_id'=>sanitize_text_field(wp_unslash($_POST['task_id']??'')),
                  'verification_ref'=>sanitize_text_field(wp_unslash($_POST['verification_ref']??'')),
                  'confirmed_no_side_effects'=>isset($_POST['confirmed_no_side_effects'])));
            }elseif(isset($_POST['manual_fire'])){
                $r=self::manual(array(
                  'event_key'=>sanitize_text_field(wp_unslash($_POST['event_key']??'')),
                  'job_id'=>sanitize_text_field(wp_unslash($_POST['job_id']??'')),
                  'stage'=>sanitize_text_field(wp_unslash($_POST['stage']??'')),
                  'target_ref'=>sanitize_text_field(wp_unslash($_POST['target_ref']??'')),
                  'expected_job_revision'=>absint($_POST['expected_job_revision']??0),
                  'dry_run'=>isset($_POST['dry_run'])));
            }else{
                $p=self::policy();
                $events=array();
                foreach(self::EVENTS as $event)$events[$event]=isset($_POST['event_'.$event]);
                $r=self::configure(array('expected_revision'=>$p['revision'],
                  'auto_enabled'=>isset($_POST['auto_enabled']),
                  'manual_enabled'=>isset($_POST['manual_enabled']),
                  'events'=>$events));
            }
            $notice=is_wp_error($r)?$r->get_error_code():'Saved / queued';
        }
        $p=self::policy();
        echo '<div class="wrap"><h1>Content Hub — Workflow Triggers</h1>';
        echo '<p>Auto event-based and manual-based triggers share the same bounded queue, executor routing and verified receipts.</p>';
        if($notice)echo '<div class="notice notice-info"><p>'.esc_html($notice).'</p></div>';
        echo '<h2>Trigger policy</h2><form method="post">';
        wp_nonce_field('mad4b_hub_trigger_controls');
        echo '<p><label><input type="checkbox" name="auto_enabled" '.checked($p['auto_enabled'],true,false).'> Auto Event-Based</label></p>';
        echo '<p><label><input type="checkbox" name="manual_enabled" '.checked($p['manual_enabled'],true,false).'> Manual-Based</label></p>';
        echo '<h3>Automatic events</h3>';
        foreach(self::EVENTS as $event)
          echo '<p><label><input type="checkbox" name="event_'.esc_attr($event).'" '.checked($p['events'][$event],true,false).'> '.esc_html(str_replace('_',' ',$event)).'</label></p>';
        echo '<p><button type="submit" class="button button-secondary">Save policy</button></p></form>';
        echo '<h2>Manual trigger</h2><form method="post">';
        wp_nonce_field('mad4b_hub_trigger_controls');
        echo '<p><label>Event key <input name="event_key" required placeholder="manual-unique-key-001"></label></p>';
        echo '<p><label>Job UUID <input name="job_id" size="40"></label></p>';
        echo '<p><label>Stage <select name="stage"><option value="">Current Job stage</option>';
        foreach(MAD4B_Hub_Bulk_Jobs_Queue::stages() as $s)
            echo '<option value="'.esc_attr($s).'">'.esc_html($s).'</option>';
        echo '</select></label></p>';
        echo '<p><label>Expected ContentJob revision (optional) <input type="number" name="expected_job_revision" min="0" step="1" placeholder="Current revision"></label></p>';
        echo '<p><label><input type="checkbox" name="dry_run"> Dry run: resolve and preview only, do not enqueue</label></p>';
        echo '<p><small>Existing ContentJob UUID is required. Create a new job through the native ContentJob Intake Ability first. Every queued task retains this Job ID and its revision.</small></p>';
        echo '<p><button class="button button-primary" name="manual_fire" value="1">Preview or enqueue trigger</button></p></form>';
        echo '<h2>Manual batch controls</h2><form method="post">';
        wp_nonce_field('mad4b_hub_trigger_controls');
        echo '<p><label>Action <select name="control_action">';
        foreach(array('PAUSE','RESUME','CANCEL','REQUEUE','DEAD_LETTER') as $op)
            echo '<option value="'.esc_attr($op).'">'.esc_html($op).'</option>';
        echo '</select></label></p>';
        echo '<p><label>Batch UUID <input name="batch_id" size="40"></label></p>';
        echo '<p><label>Task UUID (requeue/dead letter) <input name="task_id" size="40"></label></p>';
        echo '<p><label>Verification reference <input name="verification_ref"></label></p>';
        echo '<p><label><input type="checkbox" name="confirmed_no_side_effects"> Verified no side effects (required for REQUEUE)</label></p>';
        echo '<p><button class="button" name="manual_control" value="1">Apply manual control</button></p></form>';
        echo '<h2>Auto event sources</h2><p>'.esc_html(implode(' · ',self::EVENTS)).'</p>';
        echo '<p>Scheduled recovery is bounded, and never treats queueing as completion.</p></div>';
    }
}
