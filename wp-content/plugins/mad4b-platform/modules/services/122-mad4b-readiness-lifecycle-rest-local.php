<?php
/** Local-Lab REST extension for the native MAD4B Content Readiness lifecycle.
 * No write on GET, no new source of truth; preserve native app-password gate.
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'local') return;
final class MAD4B_Local_Readiness_Lifecycle_REST_v1 {
    const NS = 'mad4b-content-readiness/v1';
    public static function boot() { add_action('rest_api_init', array(__CLASS__, 'routes'), 30); }
    public static function reader() { return current_user_can('edit_others_posts'); }
    public static function admin() { return current_user_can('manage_options'); }
    public static function writer() {
        return self::admin() && class_exists('MAD4B_SCP_Content_Readiness_Alerts') &&
            MAD4B_SCP_Content_Readiness_Alerts::rest_local_client_write_permission();
    }
    private static function error($code, $message, $status) {
        return new WP_Error($code, $message, array('status'=>$status));
    }
    private static function read(array $payload) {
        $response=rest_ensure_response($payload);
        $response->header('Cache-Control','private, no-store, max-age=0');
        return $response;
    }
    public static function routes() {
        register_rest_route(self::NS,'/lifecycle',array('methods'=>'GET','callback'=>array(__CLASS__,'lifecycle'),'permission_callback'=>array(__CLASS__,'reader')));
        register_rest_route(self::NS,'/workflow',array('methods'=>'GET','callback'=>array(__CLASS__,'workflow'),'permission_callback'=>array(__CLASS__,'admin')));
        register_rest_route(self::NS,'/events',array('methods'=>'GET','callback'=>array(__CLASS__,'events'),
            'permission_callback'=>array(__CLASS__,'admin'),
            'args'=>array('per_page'=>array('type'=>'integer','default'=>25,'minimum'=>1,'maximum'=>100))));
        foreach(array('/issues/(?P<issue_id>[a-f0-9]{64})'=>'issue',
                      '/issues/(?P<issue_id>[a-f0-9]{64})/task'=>'task') as $path=>$handler) {
            register_rest_route(self::NS,$path,array('methods'=>'GET','callback'=>array(__CLASS__,$handler),
                'permission_callback'=>array(__CLASS__,'reader')));
        }
        register_rest_route(self::NS,'/issues/(?P<issue_id>[a-f0-9]{64})/assign',array(
            'methods'=>'POST','callback'=>array(__CLASS__,'assign'),
            'permission_callback'=>array(__CLASS__,'writer'),
            'args'=>array('assignee_id'=>array('type'=>'integer','required'=>true,'minimum'=>1))));
        register_rest_route(self::NS,'/issues/(?P<issue_id>[a-f0-9]{64})/task-status',array(
            'methods'=>'POST','callback'=>array(__CLASS__,'taskStatus'),
            'permission_callback'=>array(__CLASS__,'writer'),
            'args'=>array('status'=>array('type'=>'string','required'=>true,'enum'=>array('open','closed')))));
    }
    private static function registry(string $id) {
        $row=((array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,array()))[$id]??null;
        if(!is_array($row) || !hash_equals((string)($row['id']??''),$id))
            return self::error('mad4b_readiness_issue_not_found','Unknown source issue fingerprint.',404);
        return $row;
    }
    private static function verifiedTask(string $issueId, array $row) {
        $id=absint($row['board_task_id']??0);
        if(!$id)return self::error('mad4b_readiness_task_unlinked','No Fluent Boards task has been linked to this issue.',404);
        if(!class_exists('FluentBoards\\App\\Models\\Task'))
            return self::error('mad4b_readiness_board_provider_unavailable','Fluent Boards task provider unavailable.',503);
        $task=\FluentBoards\App\Models\Task::find($id);
        if(!$task || (string)$task->source!=='mad4b-readiness' ||
            (string)$task->source_id!==$issueId)
            return self::error('mad4b_readiness_task_source_mismatch','Stored task link failed source verification.',409);
        return $task;
    }
    private static function linkedTask(array $row) {
        $id=(string)($row['id']??'');
        $task=self::verifiedTask($id,$row);
        return is_wp_error($task)?$task:$task;
    }
    private static function viewIssue(array $row) {
        return array('issue_id'=>(string)$row['id'],
            'code'=>(string)($row['code']??''),'label'=>(string)($row['label']??''),
            'severity'=>(string)($row['severity']??'medium'),
            'post_type'=>(string)($row['post_type']??''),'object_id'=>absint($row['object_id']??0),
            'status'=>(string)($row['status']??'open'),
            'board_task_id'=>absint($row['board_task_id']??0)?:null,
            'first_seen'=>$row['first_seen']??null,'last_seen'=>$row['last_seen']??null,
            'resolved_at'=>$row['resolved_at']??null,'reopens'=>absint($row['reopens']??0));
    }
    private static function viewTask($task) {
        $assigned=array();
        try {
            foreach($task->assignees as $user)$assigned[]=absint($user->ID??$user->id??0);
        } catch (Throwable $e) { return self::error('mad4b_readiness_task_assignees_unavailable','Task assignment source unavailable.',503); }
        return array('task_id'=>(int)$task->id,'board_id'=>(int)$task->board_id,
            'stage_id'=>(int)$task->stage_id,'status'=>(string)$task->status,
            'assignee_ids'=>array_values(array_unique(array_filter($assigned))),
            'source'=>(string)$task->source,'source_id'=>(string)$task->source_id,
            'updated_at'=>(string)($task->updated_at??''));
    }
    public static function lifecycle($request) {
        if(!class_exists('MAD4B_SCP_Content_Readiness_Alerts'))return self::error('mad4b_readiness_unavailable','Content Readiness inactive.',503);
        $workflow=MAD4B_SCP_Readiness_Workflow_Options::settings();
        return self::read(array('contract'=>'mad4b.content-readiness.lifecycle.v1','read_only'=>true,
            'routes'=>array(
                'GET /mad4b-content-readiness/v1/summary',
                'GET /mad4b-content-readiness/v1/issues',
                'GET /mad4b-content-readiness/v1/issues/{issue_id}',
                'GET /mad4b-content-readiness/v1/issues/{issue_id}/task',
                'GET /mad4b-content-readiness/v1/lifecycle',
                'GET /mad4b-content-readiness/v1/workflow (admin)',
                'GET /mad4b-content-readiness/v1/events (admin)',
                'POST /mad4b-content-readiness/v1/admin/completeness/scan (admin)',
                'POST /mad4b-content-readiness/v1/sync-one (governed)',
                'POST /mad4b-content-readiness/v1/sync-batch (governed)',
                'POST /mad4b-content-readiness/v1/issues/{issue_id}/assign (governed)',
                'POST /mad4b-content-readiness/v1/issues/{issue_id}/task-status (governed)'),
            'tasks_enabled'=>!empty($workflow['board_tasks']),
            'board_provider_present'=>class_exists('FluentBoards\\App\\Services\\TaskService'),
            'native_board_stage_valid'=>MAD4B_SCP_Readiness_Workflow_Options::validate_board_stage(
                absint($workflow['board_id']??0),absint($workflow['stage_id']??0)),
            'writes_permitted_for_request'=>self::writer(),
            'resolution_authority'=>'complete_source_scan_only',
            'task_closed_does_not_resolve_issue'=>true,
            'external_notifications_from_preview'=>false,
        ));
    }
    public static function workflow($request) {
        $settings=MAD4B_SCP_Readiness_Workflow_Options::settings();
        return self::read(array('contract'=>'mad4b.content-readiness.workflow.v1',
            'read_only'=>true,'settings'=>$settings,
            'board_stage_valid'=>MAD4B_SCP_Readiness_Workflow_Options::validate_board_stage(
                absint($settings['board_id']),absint($settings['stage_id'])),
            'write_policy_open'=>MAD4B_SCP_Policy::can_mutate(),
            'local_client_bound'=>class_exists('MAD4B_SCP_Local_Client')&&MAD4B_SCP_Local_Client::bound(),
            'auth_note'=>'Native writes require bound local-client WP application password and MAD4B policy approval.',
        ));
    }
    public static function events($request) {
        $rows=(array)get_option(MAD4B_SCP_Content_Readiness_Alerts::AUDIT,array());
        $limit=max(1,min(100,absint($request['per_page']?:25)));
        $out=array();
        foreach(array_slice(array_reverse($rows),0,$limit) as$row){
            if(!is_array($row))continue;
            $out[]=array('event'=>sanitize_key((string)($row['event']??'')),
                'timestamp'=>sanitize_text_field((string)($row['at']??'')),
                'actor_id'=>absint($row['actor']??0),
                'data'=>is_array($row['data']??null)?$row['data']:array());
        }
        return self::read(array('contract'=>'mad4b.content-readiness.events.v1',
            'read_only'=>true,'total_saved'=>count($rows),'items'=>$out,'limit'=>$limit));
    }
    public static function issue($request) {
        $row=self::registry((string)$request['issue_id']);
        return is_wp_error($row)?$row:self::read(array('contract'=>'mad4b.content-readiness.issue.v1',
            'read_only'=>true,'issue'=>self::viewIssue($row)));
    }
    public static function task($request) {
        $row=self::registry((string)$request['issue_id']);if(is_wp_error($row))return $row;
        $task=self::linkedTask($row);if(is_wp_error($task))return $task;
        $view=self::viewTask($task);if(is_wp_error($view))return $view;
        return self::read(array('contract'=>'mad4b.content-readiness.board-task.v1',
            'read_only'=>true,'issue'=>self::viewIssue($row),'task'=>$view,
            'task_closed_does_not_resolve_issue'=>true));
    }
    private static function mutationTarget($request) {
        // A second guard inside the callback prevents direct invocation bypassing route permission.
        if(!self::writer())return self::error('mad4b_readiness_write_gate_closed','Governed write authority is unavailable.',403);
        $id=strtolower((string)$request['issue_id']);
        $row=self::registry($id);if(is_wp_error($row))return $row;
        $task=self::linkedTask($row);if(is_wp_error($task))return $task;
        $settings=MAD4B_SCP_Readiness_Workflow_Options::settings();
        if((int)$task->board_id!==absint($settings['board_id']) ||
           !MAD4B_SCP_Readiness_Workflow_Options::validate_board_stage((int)$task->board_id,(int)$task->stage_id))
            return self::error('mad4b_readiness_task_board_mismatch','Task is not in an approved board/stage.',409);
        return array('issue'=>$row,'task'=>$task);
    }
    public static function assign($request) {
        $pair=self::mutationTarget($request);if(is_wp_error($pair))return $pair;
        $id=absint($request['assignee_id']);$user=get_user_by('id',$id);
        if(!$user || !user_can($user,'edit_others_posts'))return self::error('mad4b_readiness_invalid_assignee','Assignee must be a valid content editor.',422);
        $task=$pair['task'];$old=self::viewTask($task);if(is_wp_error($old))return $old;
        if(in_array($id,$old['assignee_ids'],true))return self::read(array('contract'=>'mad4b.readiness.assign.v1','status'=>'already_assigned','task'=>$old));
        try {(new \FluentBoards\App\Services\TaskService())->updateAssignee($id,$task);$task->refresh();}
        catch(Throwable $e){return self::error('mad4b_readiness_assignment_failed','Native Fluent Boards assignment failed.',502);}
        $after=self::viewTask($task);
        if(is_wp_error($after)||!in_array($id,$after['assignee_ids'],true))return self::error('mad4b_readiness_assignment_unconfirmed','Assignment readback failed.',502);
        return self::read(array('contract'=>'mad4b.readiness.assign.v1','status'=>'assigned','task'=>$after));
    }
    public static function taskStatus($request) {
        $pair=self::mutationTarget($request);if(is_wp_error($pair))return $pair;
        $wanted=(string)$request['status'];$task=$pair['task'];
        if((string)$task->status===$wanted)return self::read(array('contract'=>'mad4b.readiness.task-status.v1',
            'status'=>'already_'.$wanted,'source_issue_state'=>$pair['issue']['status']??'open','source_untouched'=>true));
        try {
            $svc=new \FluentBoards\App\Services\TaskService();
            $svc->updateTaskProperty('status',$wanted,$task);
            $task->refresh();
        } catch(Throwable $e){return self::error('mad4b_readiness_task_status_failed','Native Fluent Boards transition failed.',502);}
        if((string)$task->status!==$wanted)return self::error('mad4b_readiness_status_unconfirmed','Native task status readback failed.',502);
        return self::read(array('contract'=>'mad4b.readiness.task-status.v1','status'=>'updated',
            'task_id'=>(int)$task->id,'task_status'=>$wanted,
            'source_issue_state'=>$pair['issue']['status']??'open','source_untouched'=>true,
            'next_action'=>'Run a complete source scan to verify whether the content gap is resolved.'));
    }
}
MAD4B_Local_Readiness_Lifecycle_REST_v1::boot();
