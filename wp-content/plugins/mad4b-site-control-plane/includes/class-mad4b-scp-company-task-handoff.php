<?php
/** Tenant-scoped Fluent Boards task offer/accept/reject/cancel with source audit. */
defined('ABSPATH') || exit;
final class MAD4B_SCP_Company_Task_Handoff {
    const META='mad4b_company_task_handoff_v1';
    private static function err(string $c,string $m,int $code=409):WP_Error {return new WP_Error($c,$m,['status'=>$code]);}
    private static function master():bool {return current_user_can('mad4b_manage_company_operations');}
    private static function allowed(int $uid):bool {
        $u=get_userdata($uid);
        if(!$u)return false;
        if(!class_exists('MAD4B_SCP_Company_Master_Admin'))return false;
        return MAD4B_SCP_Company_Master_Admin::managedStaff($uid);
    }
    private static function members($task):array {
        $ids=[];foreach($task->assignees as$u)$ids[]=absint($u->ID??$u->id??0);
        return array_values(array_unique(array_filter($ids)));
    }
    private static function task(int $id){
        if($id<=0 || !class_exists('FluentBoards\\App\\Models\\Task'))return self::err('handoff_task_missing','Task provider unavailable.',404);
        $task=\FluentBoards\App\Models\Task::find($id);
        $settings=MAD4B_SCP_Readiness_Workflow_Options::settings();
        $board=absint($settings['board_id']??0);
        if(!$task || !$board || $board!==absint($task->board_id))return self::err('handoff_board_denied','Task not in this company board.',403);
        return $task;
    }
    private static function authorized($task):bool {
        $actor=get_current_user_id();
        return self::master() || (self::allowed($actor)&&in_array($actor,self::members($task),true));
    }
    private static function load($task):array {
        $v=$task->getMeta(self::META,[]);
        if(!is_array($v))$v=[];
        return ['revision'=>absint($v['revision']??0),
                'pending'=>is_array($v['pending']??null)?$v['pending']:null,
                'history'=>is_array($v['history']??null)?array_values($v['history']):[]];
    }
    private static function save($task,array &$state,string $event,array $details=[]):void {
        $state['revision']++;
        $state['history'][]=array_merge(['event'=>$event,'at'=>current_time('mysql',true),
            'actor_id'=>get_current_user_id(),'revision'=>$state['revision']],$details);
        $state['history']=array_slice($state['history'],-80);
        $task->updateMeta(self::META,$state);
    }
    private static function snapshot($task,array $state):array {
        return ['contract'=>'mad4b.company.task-handoff.v1','task_id'=>(int)$task->id,
            'board_id'=>(int)$task->board_id,'revision'=>$state['revision'],
            'assignee_ids'=>self::members($task),'pending'=>$state['pending'],
            'history'=>$state['history'],'source_task_status'=>(string)$task->status,
            'source_issue_untouched'=>true];
    }
    private static function lock(int $taskId,callable $callback){
        global $wpdb;
        $key='mad4b:handoff:'.get_current_blog_id().':'.$taskId;
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 3)',$key))!==1)
            return self::err('handoff_lock_busy','Another transfer is in progress.',423);
        try{return $callback();}
        finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key));}
    }
    public static function view($r){
        $task=self::task(absint($r['id']));if(is_wp_error($task))return $task;
        if(!self::authorized($task) && (!is_array(self::load($task)['pending'])||
            absint(self::load($task)['pending']['to']??0)!==get_current_user_id()))return self::err('handoff_access_denied','Not a task participant.',403);
        return rest_ensure_response(self::snapshot($task,self::load($task)));
    }
    private static function update($r,string $action){
        $id=absint($r['id']);$actor=get_current_user_id();
        if(!$actor)return self::err('handoff_unauthenticated','Authentication required.',401);
        $nonce=(string)$r->get_param('request_id');
        if(!preg_match('/^[a-zA-Z0-9_-]{12,72}$/',$nonce))return self::err('handoff_request_id_invalid','Stable request_id (12-72 characters) required.',422);
        $expected=$r->get_param('expected_revision');
        if($expected===null||!ctype_digit((string)$expected))return self::err('handoff_revision_required','Expected revision required.',428);
        return self::lock($id,function()use($r,$id,$actor,$nonce,$expected,$action){
            $task=self::task($id);if(is_wp_error($task))return $task;
            $state=self::load($task);
            $target=absint($r->get_param('to_user_id'));
            $from=absint($r->get_param('from_user_id'));
            $signature=hash('sha256',json_encode([$action,$id,$actor,$from,$target,$nonce]));
            foreach(array_reverse($state['history']) as$e){
                if(($e['request_id']??'')===$nonce){
                    if(($e['signature']??'')!==$signature)return self::err('handoff_replay_conflict','Request ID reused with different arguments.');
                    return rest_ensure_response(array_merge(self::snapshot($task,$state),['replay'=>true]));
                }
            }
            if($state['revision']!==(int)$expected)return self::err('handoff_revision_conflict','Task ownership has changed; refresh before retry.');
            if($action==='offer'){
                if(!self::authorized($task))return self::err('handoff_actor_denied','Not authorized to transfer this task.',403);
                if($state['pending'])return self::err('handoff_offer_exists','Resolve pending offer first.');
                if(!self::allowed($target)||$target===$actor||$target===$from)
                    return self::err('handoff_invalid_target','Recipient must be another active internal team member.',422);
                $ids=self::members($task);
                if(!$from && count($ids)===1)$from=$ids[0];
                if(!$from || !in_array($from,$ids,true) || (!self::master()&&$actor!==$from))
                    return self::err('handoff_source_invalid','Existing assignee must initiate or company manager must approve.',403);
                if(in_array($target,$ids,true))return self::err('handoff_already_assigned','Recipient is already an assignee.');
                $state['pending']=['id'=>hash('sha256',$nonce.'|'.$id),'from'=>$from,'to'=>$target,
                    'initiator'=>$actor,'created_at'=>current_time('mysql',true),'reason'=>sanitize_text_field((string)$r->get_param('reason'))];
                self::save($task,$state,'offered',['request_id'=>$nonce,'signature'=>$signature,'from'=>$from,'to'=>$target]);
            }else{
                $pending=$state['pending'];
                if(!$pending)return self::err('handoff_offer_missing','No active offer.');
                $from=absint($pending['from']);$target=absint($pending['to']);
                if($action==='accept'){
                    if($actor!==$target || !self::allowed($actor))return self::err('handoff_accept_denied','Only intended active recipient can accept.',403);
                    $created=strtotime((string)($pending['created_at']??''));
                    $hours=class_exists('MAD4B_SCP_Company_Operations_Policy')?
                        (int)MAD4B_SCP_Company_Operations_Policy::get()['task_offer_ttl_hours']:72;
                    if(!$created || time()>$created+$hours*3600)
                        return self::err('handoff_offer_expired','Transfer offer expired. Cancel and issue a new offer.',409);
                    if(!in_array($from,self::members($task),true))return self::err('handoff_sender_changed','Original owner no longer assigned.');
                    // Add receiver, verify, then remove sender. On failure
                    // restore sender and remove only the recipient we added.
                    $service=new \FluentBoards\App\Services\TaskService();
                    $receiverAdded=false;
                    try {
                        $boardService=new \FluentBoards\App\Services\BoardService();
                        if(!$boardService->isAlreadyMember((int)$task->board_id,$target)){
                            if(!$boardService->addMembersInBoard((int)$task->board_id,$target))
                                throw new RuntimeException('board_membership_unconfirmed');
                        }
                        $task->load('assignees');
                        if(!in_array($target,self::members($task),true)){
                            $service->updateAssignee($target,$task);$receiverAdded=true;
                        }
                        $task->refresh();$task->load('assignees');
                        if(!in_array($target,self::members($task),true))
                            throw new RuntimeException('recipient_unconfirmed');
                        $service->updateAssignee($from,$task);
                        $task->refresh();$task->load('assignees');
                        $ids=self::members($task);
                        if(in_array($from,$ids,true)||!in_array($target,$ids,true))
                            throw new RuntimeException('transfer_readback_incomplete');
                    } catch(Throwable $ex) {
                        try {
                            $task->refresh();$task->load('assignees');
                            if(!in_array($from,self::members($task),true))$service->updateAssignee($from,$task);
                            $task->refresh();$task->load('assignees');
                            if($receiverAdded && in_array($target,self::members($task),true))
                                $service->updateAssignee($target,$task);
                        } catch(Throwable $rollbackError) {
                            return self::err('handoff_reconciliation_required','Native assignment failed and compensation could not be verified.',503);
                        }
                        return self::err('handoff_transfer_failed','Native transfer failed; previous owner restored.',502);
                    }
                    $event='accepted';
                }elseif($action==='reject'){
                    if($actor!==$target)return self::err('handoff_reject_denied','Only recipient may reject.',403);
                    $event='rejected';
                }elseif($action==='cancel'){
                    if($actor!==$from && $actor!==absint($pending['initiator']) && !self::master())
                        return self::err('handoff_cancel_denied','Only owner, initiator or company manager may cancel.',403);
                    $event='cancelled';
                }else{return self::err('handoff_action_invalid','Unknown transition.',422);}
                $state['pending']=null;
                self::save($task,$state,$event,['request_id'=>$nonce,'signature'=>$signature,'from'=>$from,'to'=>$target]);
            }
            return rest_ensure_response(array_merge(self::snapshot($task,$state),['replay'=>false]));
        });
    }
    public static function offer($r){return self::update($r,'offer');}
    public static function accept($r){return self::update($r,'accept');}
    public static function reject($r){return self::update($r,'reject');}
    public static function cancel($r){return self::update($r,'cancel');}
    public static function listHandoffs($r){
        $actor=get_current_user_id();
        if(!$actor || (!self::master() && !self::allowed($actor)))
            return self::err('handoff_list_denied','Company staff permission required.',403);
        $config=MAD4B_SCP_Readiness_Workflow_Options::settings();$board=absint($config['board_id']??0);
        $page=max(1,min(100,absint($r->get_param('page')?:1)));
        $per=max(1,min(50,absint($r->get_param('per_page')?:25)));
        $query=\FluentBoards\App\Models\Task::where('board_id',$board)->orderBy('id','desc')->skip(($page-1)*$per)->take($per);
        $items=[];
        foreach($query->get() as$task){
            $s=self::load($task);
            if(!$s['pending']&&!$s['history'])continue;
            if(!self::authorized($task)&&absint($s['pending']['to']??0)!==$actor)continue;
            $items[]=self::snapshot($task,$s);
        }
        $res=rest_ensure_response(['contract'=>'mad4b.company.handoffs.v1',
            'board_id'=>$board,'page'=>$page,'per_page'=>$per,'items'=>$items,
            'read_only'=>true,'outbound_messages_sent'=>0]);
        $res->header('Cache-Control','private, no-store');return $res;
    }
    public static function routes():void {
        register_rest_route('mad4b/v1','/company-tasks/handoffs',[
            'methods'=>'GET','callback'=>[__CLASS__,'listHandoffs'],
            'permission_callback'=>static fn()=>is_user_logged_in(),
            'args'=>['page'=>['type'=>'integer','minimum'=>1,'default'=>1],
                'per_page'=>['type'=>'integer','minimum'=>1,'maximum'=>50,'default'=>25]]]);
        $args=['request_id'=>['type'=>'string','required'=>true],
               'expected_revision'=>['type'=>'integer','required'=>true,'minimum'=>0]];
        foreach(['offer','accept','reject','cancel'] as$action){
            register_rest_route('mad4b/v1','/company-tasks/(?P<id>\\d+)/handoff/'.$action,[
                'methods'=>'POST','callback'=>[__CLASS__,$action],
                'permission_callback'=>static fn()=>is_user_logged_in(),'args'=>$args]);
        }
        register_rest_route('mad4b/v1','/company-tasks/(?P<id>\\d+)/handoff',[
            'methods'=>'GET','callback'=>[__CLASS__,'view'],
            'permission_callback'=>static fn()=>is_user_logged_in()]);
    }
    public static function dashboardNotice():void {
        if(!self::accessible() || !function_exists('get_current_screen'))return;
        $screen=get_current_screen();
        if(!$screen || $screen->id!=='dashboard')return;
        $settings=MAD4B_SCP_Readiness_Workflow_Options::settings();
        $board=absint($settings['board_id']??0);if(!$board)return;
        $pending=0;
        foreach(\FluentBoards\App\Models\Task::where('board_id',$board)
            ->orderBy('id','desc')->take(100)->get() as$task){
            $s=self::load($task);
            if(absint($s['pending']['to']??0)===get_current_user_id())$pending++;
        }
        if($pending>0){
            echo '<div class="notice notice-info"><p>You have '.esc_html((string)$pending)
                .' task handoff offer(s) awaiting your decision. <a href="'
                .esc_url(admin_url('admin.php?page=mad4b-task-handoffs'))
                .'">Review task handoffs</a></p></div>';
        }
    }
    public static function adminMenu():void {
        add_menu_page('My Task Handoffs','Task Handoffs','mad4b_work_company_tasks',
            'mad4b-task-handoffs',[__CLASS__,'screen'],'dashicons-migrate',34);
    }
    private static function accessible():bool {
        return current_user_can('mad4b_work_company_tasks') &&
            (self::master() || self::allowed(get_current_user_id()));
    }
    private static function renderForm(int $taskId,string $action,int $revision,string $label,
                                       int $from=0,int $to=0):void {
        echo '<form method="post" style="display:inline-block;margin:3px" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('mad4b_company_task_handoff');
        foreach(['action'=>'mad4b_company_task_handoff','task_id'=>$taskId,
            'transition'=>$action,'revision'=>$revision,'request_id'=>wp_generate_uuid4(),
            'from_user_id'=>$from,'to_user_id'=>$to]as$k=>$v)
            echo '<input type="hidden" name="'.esc_attr((string)$k).'" value="'.esc_attr((string)$v).'">';
        echo '<button class="button">'.esc_html($label).'</button></form>';
    }
    public static function screen():void {
        if(!self::accessible())wp_die('Not an approved internal team member.');
        $settings=MAD4B_SCP_Readiness_Workflow_Options::settings();$board=absint($settings['board_id']??0);
        $page=max(1,min(100,absint($_GET['task_page']??1)));
        echo '<div class="wrap"><h1>My Task Handoffs</h1><p>Transfer offers require explicit recipient acceptance. No email is sent by preview, and completing a task never resolves a source content gap by itself.</p>';
        $status=sanitize_key((string)($_GET['handoff_status']??''));
        if($status)echo '<div class="notice '.($status==='success'?'notice-success':'notice-warning').'"><p>Handoff: '.esc_html($status).'</p></div>';
        echo '<table class="widefat striped"><thead><tr><th>Task</th><th>Assigned WP User IDs</th><th>Pending transfer</th><th>Actions</th></tr></thead><tbody>';
        $rows=\FluentBoards\App\Models\Task::where('board_id',$board)->orderBy('id','desc')
            ->skip(($page-1)*50)->take(50)->get();
        foreach($rows as$task){
            $state=self::load($task);$assignees=self::members($task);
            $pending=$state['pending'];
            if(!self::master()&&!in_array(get_current_user_id(),$assignees,true)
                &&absint($pending['to']??0)!==get_current_user_id())continue;
            echo '<tr><td>#'.esc_html((string)$task->id).' '.esc_html((string)$task->title).'</td>';
            echo '<td>'.esc_html(implode(', ',array_map('strval',$assignees))).'</td>';
            echo '<td>'.($pending?'From #'.esc_html((string)$pending['from']).' to #'.esc_html((string)$pending['to']):'None').'</td><td>';
            if($pending){
                if((int)$pending['to']===get_current_user_id()){
                    self::renderForm($task->id,'accept',$state['revision'],'Accept');
                    self::renderForm($task->id,'reject',$state['revision'],'Reject');
                }
                if(self::master()||(int)$pending['from']===get_current_user_id())
                    self::renderForm($task->id,'cancel',$state['revision'],'Cancel offer');
            }elseif($assignees && (self::master()||in_array(get_current_user_id(),$assignees,true))){
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                wp_nonce_field('mad4b_company_task_handoff');
                foreach(['action'=>'mad4b_company_task_handoff','transition'=>'offer',
                    'task_id'=>$task->id,'revision'=>$state['revision'],'request_id'=>wp_generate_uuid4()]as$k=>$v)
                    echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr((string)$v).'">';
                echo '<label>From <select name="from_user_id">';
                foreach($assignees as$id)if(self::master()||$id===get_current_user_id())
                    echo '<option value="'.esc_attr((string)$id).'">User #'.esc_html((string)$id).'</option>';
                echo '</select></label> <label>To <select name="to_user_id">';
                $staff=get_users(['number'=>150,'meta_key'=>MAD4B_SCP_Company_Master_Admin::STAFF_OWNER,'fields'=>'ID']);
                $ids=array_map('intval',$staff);
                if(class_exists('MAD4B_SCP_Company_Communication_Team'))
                    foreach(MAD4B_SCP_Company_Communication_Team::members('internal',true)as$member)
                        if(!empty($member['wp_user_id']))$ids[]=(int)$member['wp_user_id'];
                foreach(array_unique($ids)as$id)if(self::allowed($id)&&!in_array($id,$assignees,true)){
                    $user=get_userdata($id);
                    echo '<option value="'.esc_attr((string)$id).'">'.esc_html($user->display_name).'</option>';
                }
                echo '</select></label> <input name="reason" placeholder="Handoff reason (optional)"> <button class="button">Offer transfer</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table><p><a href="'.esc_url(add_query_arg('task_page',$page+1,admin_url('admin.php?page=mad4b-task-handoffs'))).'">Next page</a></p></div>';
    }
    public static function adminPost():void {
        if(!self::accessible())wp_die('Company staff access required.',403);
        check_admin_referer('mad4b_company_task_handoff');
        $action=sanitize_key((string)($_POST['transition']??''));
        if(!in_array($action,['offer','accept','reject','cancel'],true))wp_die('Invalid transition.',422);
        $id=absint($_POST['task_id']??0);
        $request=new WP_REST_Request('POST','/mad4b/v1/company-tasks/'.$id.'/handoff/'.$action);
        $request->set_param('id',$id);
        $request->set_param('request_id',sanitize_text_field((string)($_POST['request_id']??'')));
        $request->set_param('expected_revision',absint($_POST['revision']??0));
        $request->set_param('from_user_id',absint($_POST['from_user_id']??0));
        $request->set_param('to_user_id',absint($_POST['to_user_id']??0));
        $request->set_param('reason',sanitize_text_field((string)($_POST['reason']??'')));
        $result=self::$action($request);
        $status=is_wp_error($result)?'blocked':'success';
        wp_safe_redirect(add_query_arg('handoff_status',$status,
            admin_url('admin.php?page=mad4b-task-handoffs')));exit;
    }
    public static function boot():void {
        add_action('rest_api_init',[__CLASS__,'routes'],30);
        add_action('admin_menu',[__CLASS__,'adminMenu'],12);
        add_action('admin_notices',[__CLASS__,'dashboardNotice']);
        add_action('admin_post_mad4b_company_task_handoff',[__CLASS__,'adminPost']);
    }
}
MAD4B_SCP_Company_Task_Handoff::boot();
