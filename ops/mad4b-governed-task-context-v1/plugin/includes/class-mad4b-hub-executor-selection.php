<?php
defined('ABSPATH') || exit;

/** MCP-client-directed transport preference over one durable Bulk Queue. */
final class MAD4B_Hub_Executor_Selection {
    const CONTRACT='mad4b.hub.executor-selection.v1';
    const ACTION='mad4b_hub_bulk_as_tick';
    const GROUP='mad4b-hub-bulk';
    const EXECUTORS=array('mcp_client','action_scheduler','vps_runner');
    const SHORT=array('runtime_resolve','site_health','workflow_inspect','content_job_read','source_sync_verify');
    private static function allowed($write=false){
        return $write?MAD4B_Hub_Bulk_Jobs_Queue::writable():MAD4B_Hub_Bulk_Jobs_Queue::readable();
    }
    private static function error($name,$status=400){
        return new WP_Error('mad4b_executor_'.$name,$name,array('status'=>$status,'contract'=>self::CONTRACT));
    }
    private static function id($id){
        return is_string($id)&&preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$id);
    }
    public static function boot(){
        add_filter('mad4b_hub_bulk_authorized_executor',array(__CLASS__,'authorize_action_scheduler'),10,2);
        add_action(self::ACTION,array(__CLASS__,'run_action_scheduler'),10,0);
        add_action('rest_api_init',array(__CLASS__,'routes'),53);
        add_action('wp_abilities_api_init',array(__CLASS__,'abilities'),53);
        add_action('admin_menu',array(__CLASS__,'menu'),31);
    }
    public static function authorize_action_scheduler($allowed,$executor){
        return $allowed||($executor==='action_scheduler'
            && defined('WP_CLI')&&WP_CLI&&PHP_SAPI==='cli'
            &&wp_get_environment_type()==='staging'
            &&self::allowed(true));
    }
    private static function action_ready(){
        return wp_get_environment_type()==='staging'
          &&function_exists('as_has_scheduled_action')
          &&function_exists('as_schedule_recurring_action')
          &&(bool)get_option('mad4b_hub_as_pump_enabled_v1',false)
          &&(bool)as_has_scheduled_action(self::ACTION,array(),self::GROUP);
    }
    public static function ensure_action_schedule(){
        if(!self::allowed(true)||!defined('WP_CLI')||!WP_CLI)
            return self::error('trusted_wpcli_required',403);
        if(!function_exists('as_schedule_recurring_action')||!function_exists('as_has_scheduled_action'))
            return self::error('action_scheduler_missing',503);
        if(!as_has_scheduled_action(self::ACTION,array(),self::GROUP)){
            $id=as_schedule_recurring_action(time()+15,60,self::ACTION,array(),self::GROUP,true,4);
            if(!$id)return self::error('action_schedule_failed',500);
        }
        update_option('mad4b_hub_as_pump_enabled_v1',true,false);
        return self::status();
    }
    public static function run_action_scheduler(){
        // WP-Cron/browser hits NEVER impersonate a privileged principal.
        if(!self::authorize_action_scheduler(false,'action_scheduler'))
            return;
        MAD4B_Hub_Bulk_VPS_Runner::tick(3,'','action_scheduler',false);
    }
    private static function task_allowed($task,$executor){
        if($executor==='mcp_client')return array(true,'authorized_mcp_client_claim_required');
        $stage=(string)($task['stage']??'');
        $operation=(string)($task['operation']??'');
        $payload=(array)($task['payload']??array());
        if($executor==='action_scheduler'){
            if(!self::action_ready())return array(false,'action_scheduler_pump_not_ready');
            if(!in_array($operation,self::SHORT,true))
                return array(false,'not_bounded_action_scheduler_operation');
            return array(true,'bounded_short_readback');
        }
        if($executor==='vps_runner'){
            if(wp_get_environment_type()!=='staging'
               ||!class_exists('MAD4B_Hub_Bulk_VPS_Runner'))
                return array(false,'vps_runner_unavailable');
            $built_in=in_array($operation,array_merge(self::SHORT,
               array('source_sync_run_queued','canonical_gap_scan')),true);
            $adapter=$operation==='stage_work' && !empty($payload['stage_adapter_id'])
               &&class_exists('MAD4B_Hub_Dynamic_Runtime_Resolver')
               &&MAD4B_Hub_Dynamic_Runtime_Resolver::server_adapter(
                     $stage,(string)$payload['stage_adapter_id'],
                     array_merge($payload,[
                       'target_ref'=>(string)($task['target_ref']??''),
                       'content_job_id'=>(string)($task['content_job_id']??'')]))!==null;
            if(!$built_in&&!$adapter)return array(false,'no_verified_server_adapter');
            return array(true,$adapter?'registered_stage_executor':'registered_vps_operation');
        }
        return array(false,'executor_unknown');
    }
    public static function plan($input=array()){
        if(!self::allowed())return self::error('permission',403);
        if(!is_array($input))return self::error('invalid_input');
        $task=(array)($input['task']??array());
        $ids=(array)($input['preferences']??array());
        if(!$ids)$ids=($task['operation']??'')==='stage_work'
            ?array('vps_runner','mcp_client')
            :array('action_scheduler','vps_runner','mcp_client');
        if(count($ids)>3||count(array_unique($ids))!==count($ids)
           ||array_diff($ids,self::EXECUTORS)||array_values($ids)!==$ids)
            return self::error('invalid_preferences');
        if(!in_array((string)($task['stage']??''),MAD4B_Hub_Bulk_Jobs_Queue::stages(),true))
            return self::error('stage_required');
        if(!preg_match('/^[a-z][a-z0-9._:-]{2,127}$/D',(string)($task['operation']??'')))
            return self::error('operation_required');
        $options=array();$selected='';
        foreach($ids as $index=>$executor){
            list($ready,$reason)=self::task_allowed($task,$executor);
            if(class_exists('MAD4B_Hub_Workflow_Origin')){
                $lane=MAD4B_Hub_Workflow_Origin::selection_lane(
                    (array)($task['payload']??[]),$executor);
                if(empty($lane['allowed'])){
                    $ready=false;$reason=(string)$lane['reason'];
                }
            }
            $options[]=array('executor'=>$executor,'rank'=>$index+1,
              'eligible'=>$ready,'reason'=>$reason);
            if($selected===''&&$ready)$selected=$executor;
        }
        return array('contract'=>self::CONTRACT,'selected_executor'=>$selected,
           'options'=>$options,'selection_reason'=>$selected!==''?'first_eligible':'no_eligible_executor',
           'mcp_client_controls_selection'=>true,
           'execution_performed'=>false,'mutation_performed'=>false);
    }
    private static function task_row($id,$lock=false){
        global $wpdb;
        $table=$wpdb->prefix.'mad4b_hub_bulk_tasks';
        return $wpdb->get_row($wpdb->prepare("SELECT q.*,b.state AS batch_state FROM $table q
          INNER JOIN {$wpdb->prefix}mad4b_hub_bulk_batches b ON b.batch_id=q.batch_id
          WHERE q.task_id=%s LIMIT 1".($lock?' FOR UPDATE':''),$id),ARRAY_A);
    }
    public static function assign($input=array()){
        if(!self::allowed(true))return self::error('permission',403);
        if(!is_array($input))return self::error('invalid_input');
        $id=(string)($input['task_id']??'');
        $preferred=$input['preferences']??null;
        $mode=(string)($input['executor']??'auto');
        if(!self::id($id)||!in_array($mode,array('auto','mcp_client','vps_runner','action_scheduler'),true))
            return self::error('invalid_assignment');
        if($mode!=='auto'&&$preferred!==null)return self::error('contradictory_preference');
        $expected=(string)($input['expected_executor']??'');
        if($expected!==''&&!in_array($expected,self::EXECUTORS,true))
            return self::error('invalid_expected_executor');
        global $wpdb;
        $table=$wpdb->prefix.'mad4b_hub_bulk_tasks';
        if(false===$wpdb->query('START TRANSACTION'))return self::error('transaction_missing',503);
        $row=self::task_row($id,true);
        if(!$row){$wpdb->query('ROLLBACK');return self::error('task_not_found',404);}
        if($row['batch_state']!=='ACTIVE'||!in_array($row['state'],array('QUEUED','RETRY','WAITING_EXECUTOR'),true)){
            $wpdb->query('ROLLBACK');return self::error('task_not_reassignable',409);
        }
        $payload=json_decode((string)$row['payload_json'],true);
        if(!is_array($payload)){ $wpdb->query('ROLLBACK');return self::error('payload_invalid',409);}
        $old=(string)($payload['assigned_executor']??'mcp_client');
        if($expected!==''&&$expected!==$old){
            $wpdb->query('ROLLBACK');return self::error('assignment_changed',409);
        }
        $task=array('stage'=>$row['stage'],'operation'=>$row['operation'],
             'payload'=>$payload,'target_ref'=>$row['target_ref'],
             'content_job_id'=>$row['content_job_id']);
        $rank=self::plan(array('task'=>$task,
          'preferences'=>$mode==='auto'?(is_array($preferred)?$preferred:array()):array($mode)));
        if(is_wp_error($rank)||$rank['selected_executor']===''){
            $wpdb->query('ROLLBACK');return is_wp_error($rank)?$rank:self::error('no_eligible_executor',409);
        }
        $selected=$rank['selected_executor'];
        // A generic registered transport is not authorization to cross
        // product-gap, content-stage or provider-handoff execution lanes.
        $lane=MAD4B_Hub_Workflow_Origin::executor_guard(
          ['payload'=>$payload,'stage'=>$row['stage'],'operation'=>$row['operation'],
           'content_job_id'=>$row['content_job_id'],'target_ref'=>$row['target_ref']],
          $selected);
        if(is_wp_error($lane)){$wpdb->query('ROLLBACK');return $lane;}
        if($selected===$old&&$row['state']!=='WAITING_EXECUTOR'){
            $wpdb->query('COMMIT');
            return array('contract'=>self::CONTRACT,'task_id'=>$id,'assigned_executor'=>$old,
              'changed'=>false,'selection'=>$rank,'execution_performed'=>false);
        }
        // No lease currently held: fencing on both state and unchanged payload.
        $payload['assigned_executor']=$selected;
        $payload['executor_selection']=array(
            'method'=>$mode,'previous_executor'=>$old,'selected_by'=>get_current_user_id(),
            'selected_at'=>gmdate('c'),
            'reason'=>$rank['selection_reason'],
            'preferences'=>array_column($rank['options'],'executor'));
        if(strlen(wp_json_encode($payload))>16000){
            $wpdb->query('ROLLBACK');return self::error('payload_too_large');
        }
        $newstate='QUEUED';
        $updated=$wpdb->update($table,array('payload_json'=>wp_json_encode($payload),
           'state'=>$newstate,'available_at'=>gmdate('Y-m-d H:i:s'),
           'updated_at'=>gmdate('Y-m-d H:i:s')),
           array('id'=>$row['id'],'state'=>$row['state'],'payload_json'=>$row['payload_json']));
        if($updated!==1){
            $wpdb->query('ROLLBACK');return self::error('concurrent_assignment_conflict',409);
        }
        if(false===$wpdb->query('COMMIT'))return self::error('commit_unknown',500);
        return array('contract'=>self::CONTRACT,'task_id'=>$id,'batch_id'=>$row['batch_id'],
          'previous_executor'=>$old,'assigned_executor'=>$selected,
          'changed'=>true,'selection'=>$rank,'execution_performed'=>false);
    }
    public static function task_plan($input=array()){
        if(!self::allowed())return self::error('permission',403);
        $id=(string)($input['task_id']??'');
        if(!self::id($id))return self::error('task_id_invalid',400);
        $row=self::task_row($id);
        if(!$row)return self::error('task_not_found',404);
        $payload=json_decode($row['payload_json'],true)?:array();
        $current=(string)($payload['assigned_executor']??'mcp_client');
        $rank=self::plan(array('task'=>array('stage'=>$row['stage'],
          'operation'=>$row['operation'],'payload'=>$payload),
          'preferences'=>($input['preferences']??array())));
        if(is_wp_error($rank))return $rank;
        return array('contract'=>self::CONTRACT,'task_id'=>$id,'batch_id'=>$row['batch_id'],
          'stage'=>$row['stage'],'operation'=>$row['operation'],
          'state'=>$row['state'],'batch_state'=>$row['batch_state'],
          'current_executor'=>$current,'ranking'=>$rank,
          'allowed_to_reassign'=>in_array($row['state'],array('QUEUED','RETRY','WAITING_EXECUTOR'),true)
               &&$row['batch_state']==='ACTIVE',
          'mutation_performed'=>false);
    }
    public static function status(){
        if(!self::allowed())return self::error('permission',403);
        return array('contract'=>self::CONTRACT,'executors'=>self::EXECUTORS,
           'action_scheduler'=>array('installed'=>function_exists('as_schedule_recurring_action'),
               'pump_scheduled'=>function_exists('as_has_scheduled_action')
                 &&(bool)as_has_scheduled_action(self::ACTION,array(),self::GROUP),
               'wp_cron_enabled'=>!(defined('DISABLE_WP_CRON')&&DISABLE_WP_CRON),
               'pump_enabled'=>(bool)get_option('mad4b_hub_as_pump_enabled_v1',false),
               'wpcli_pump_required'=>true),
           'vps_runner'=>array('registered'=>class_exists('MAD4B_Hub_Bulk_VPS_Runner'),
                 'staging_only'=>true),
           'mcp_client'=>array('default_for_unverified_stage_execution'=>true),
           'single_queue_lease'=>true,'mutation_performed'=>false);
    }
    public static function routes(){
        foreach(array(
          '/control-workflow/executors/status'=>array('GET','status',false),
          '/control-workflow/executors/task-plan'=>array('GET','task_plan',false),
          '/control-workflow/executors/plan'=>array('POST','plan',false),
          '/control-workflow/executors/assign'=>array('POST','assign',true)
        ) as $path=>$v){
            register_rest_route('mad4b-hub/v1',$path,array(
               'methods'=>$v[0],
               'callback'=>static function($req)use($v){return call_user_func(array(__CLASS__,$v[1]),$req->get_params());},
               'permission_callback'=>static fn()=>self::allowed($v[2])));
        }
    }
    public static function abilities(){
        if(!function_exists('wp_register_ability'))return;
        foreach(array('status'=>false,'task_plan'=>false,'plan'=>false,'assign'=>true) as $method=>$write){
            $name='mad4b/hub-executor-select-'.str_replace('_','-',$method);
            if(wp_has_ability($name))continue;
            wp_register_ability($name,array(
                'label'=>'MAD4B Hub executor preference '.str_replace('_',' ',$method),
                'description'=>'Select ranked transport for an existing task without changing original Ability permissions.',
                'category'=>$write?'mad4b-write':'mad4b-read',
                'execute_callback'=>array(__CLASS__,$method),
                'permission_callback'=>static fn()=>self::allowed($write),
                'input_schema'=>array('type'=>'object','properties'=>array(
                    'task_id'=>array('type'=>'string'),'executor'=>array('type'=>'string'),
                    'expected_executor'=>array('type'=>'string'),
                    'preferences'=>array('type'=>'array','items'=>array('type'=>'string')),
                    'task'=>array('type'=>'object','additionalProperties'=>true)),
                    'required'=>in_array($method,array('task_plan','assign'),true)?array('task_id'):array(),
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
        add_submenu_page('mad4b-shared-content-hub','Executor Preferences',
            'Executor Preferences','manage_options','mad4b-hub-executor-preference',
            array(__CLASS__,'page'));
    }
    public static function page(){
        if(!self::allowed())wp_die('Forbidden');
        $notice='';
        if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&isset($_POST['executor_select'])){
            if(!self::allowed(true)||!check_admin_referer('mad4b_executor_selection'))
                wp_die('Forbidden');
            $r=self::assign(array(
              'task_id'=>sanitize_text_field(wp_unslash($_POST['task_id']??'')),
              'executor'=>sanitize_text_field(wp_unslash($_POST['executor']??'')),
              'expected_executor'=>sanitize_text_field(wp_unslash($_POST['expected_executor']??''))));
            $notice=is_wp_error($r)?$r->get_error_code():'Assignment saved';
        }
        echo '<div class="wrap"><h1>MAD4B — Executor Preferences</h1>';
        echo '<p>Client-directed preference for one task. No running task can be reassigned. The same queue lease protects all executors.</p>';
        if($notice)echo '<div class="notice notice-info"><p>'.esc_html($notice).'</p></div>';
        echo '<p>Action Scheduler is pumped through staging WP-CLI, not traffic-triggered WP-Cron.</p>';
        echo '<form method="post">';wp_nonce_field('mad4b_executor_selection');
        echo '<p><label>Task UUID <input size="40" name="task_id" required></label></p>';
        echo '<p><label>Preferred executor <select name="executor">';
        foreach(array('auto','mcp_client','action_scheduler','vps_runner') as $e)
            echo '<option value="'.esc_attr($e).'">'.esc_html($e).'</option>';
        echo '</select></label></p>';
        echo '<p><button class="button button-primary" name="executor_select" value="1">Assign eligible executor</button></p></form>';
        echo '<p><a href="'.esc_url(admin_url('admin.php?page=mad4b-hub-bulk-queue')).'">View Bulk Jobs Queue</a></p></div>';
    }
}
