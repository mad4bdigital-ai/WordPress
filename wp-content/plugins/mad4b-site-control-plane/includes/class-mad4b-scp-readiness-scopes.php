<?php
/**
 * Work scopes inspired by the batch/element/assignment separation in WPML.
 * Independently implemented; never touches the WPML translation tables.
 * A source issue is an item, a scope is its workload, a Board task is optional.
 */
defined('ABSPATH') || exit;

final class MAD4B_SCP_Readiness_Scopes {
    const STORE = 'mad4b_content_readiness_scopes_v1';
    const PAGE = 'mad4b-readiness-scopes';
    const NS = 'mad4b-content-readiness/v1';
    const TASK_SOURCE = 'mad4b-readiness-scope';
    const BITFLOWS_HOOK = 'mad4b/readiness/scope_digest';
    const MAX_ITEMS_PER_WORK_PACKET = 50;

    public static function boot() {
        add_action('rest_api_init',array(__CLASS__,'routes'));
        add_action('admin_menu',array(__CLASS__,'menu'),35);
    }
    public static function can_manage() {
        return MAD4B_SCP_Readiness_Integrations::can_manage();
    }
    public static function can_view() {
        return MAD4B_SCP_Readiness_Integrations::can_view();
    }
    private static function fail($code,$text,$status) {
        return new WP_Error($code,$text,array('status'=>$status));
    }
    private static function lang($issue) {
        $explicit=sanitize_key((string)($issue['source_evidence']['language']??''));
        if($explicit)return $explicit;
        $id=absint($issue['object_id']??0);
        $type=(string)($issue['post_type']??'');
        if($id&&defined('ICL_SITEPRESS_VERSION')&& !str_starts_with($type,'term:')) {
            $l=apply_filters('wpml_post_language_details',null,$id);
            if(is_array($l)&&!empty($l['language_code']))
                return sanitize_key((string)$l['language_code']);
        }
        if($id&&str_starts_with($type,'term:')&&defined('ICL_SITEPRESS_VERSION')){
            $l=apply_filters('wpml_element_language_code',null,
                array('element_id'=>$id,'element_type'=>'tax_'.substr($type,5)));
            if(is_string($l)&&$l)return sanitize_key($l);
        }
        return 'und';
    }
    public static function build($issues=null) {
        $issues=is_array($issues)?$issues:(array)get_option(MAD4B_SCP_Content_Readiness_Alerts::STORE,array());
        $buckets=array();
        foreach($issues as $issue) {
            if(!is_array($issue)||($issue['status']??'open')==='resolved')continue;
            $id=(string)($issue['id']??'');
            if(!preg_match('/^[a-f0-9]{64}$/D',$id))continue;
            $group=MAD4B_SCP_Readiness_Workflow_Options::group_for_issue($issue);
            $language=self::lang($issue);
            $severity=sanitize_key((string)($issue['severity']??'medium'));
            if(!in_array($severity,array('critical','high','medium','low'),true))$severity='medium';
            $type=sanitize_key(str_replace(':','_',((string)($issue['post_type']??'site'))));
            $identity=get_current_blog_id().'|'.$group.'|'.$language.'|'.$severity.'|'.$type;
            $scopeId=hash('sha256',$identity);
            if(!isset($buckets[$scopeId])){
                $buckets[$scopeId]=array('scope_id'=>$scopeId,'group'=>$group,'language'=>$language,
                    'severity'=>$severity,'content_type'=>$type,'items'=>array(),
                    'assignee_id'=>0,'counts'=>array(),'total'=>0,
                    'batch_id'=>$scopeId);
            }
            $buckets[$scopeId]['items'][$id]=$issue;
            $code=(string)($issue['code']??'unknown');
            $buckets[$scopeId]['counts'][$code]=($buckets[$scopeId]['counts'][$code]??0)+1;
        }
        // WPML-like: one logical group can contain multiple bounded work packets.
        // Stable fingerprint-prefix partitions prevent an oversized backlog from
        // becoming a single 603-item operator task or 603 separate notifications.
        $packets=array();
        foreach($buckets as $bucket) {
            if(count($bucket['items'])<=self::MAX_ITEMS_PER_WORK_PACKET) {
                $bucket['partition']='all';
                $packets[$bucket['scope_id']]=$bucket;
                continue;
            }
            self::partition($bucket,1,$packets);
        }
        $buckets=$packets;
        $approved=(array)get_option(self::STORE,array());
        foreach($buckets as &$bucket) {
            ksort($bucket['items'],SORT_STRING);
            ksort($bucket['counts'],SORT_STRING);
            $bucket['total']=count($bucket['items']);
            // One authorized owner per entire scope, never choose a random admin.
            $first=reset($bucket['items']);
            $bucket['assignee_id']=MAD4B_SCP_Readiness_Workflow_Options::assigned_user($first);
            $bucket['revision']=hash('sha256',implode('|',array_keys($bucket['items'])));
            $state=(array)($approved[$bucket['scope_id']]??array());
            $bucket['task_id']=absint($state['task_id']??0);
            $bucket['approval']=($state['revision']??null)===$bucket['revision']
                ?(string)($state['status']??'unreviewed'):'review_required';
            if(!$bucket['assignee_id'])$bucket['approval']='needs_owner';
        }
        unset($bucket);
        uasort($buckets,static function($a,$b){
            $priorities=array('critical'=>0,'high'=>1,'medium'=>2,'low'=>3);
            return ($priorities[$a['severity']]??4)<=>($priorities[$b['severity']]??4)
                ?:($b['total']<=>$a['total']) ?: strcmp($a['scope_id'],$b['scope_id']);
        });
        return $buckets;
    }
    private static function partition($source,$depth,&$dest) {
        $partitions=array();
        foreach($source['items'] as $id=>$issue) {
            $prefix=substr($id,0,$depth);
            $partitions[$prefix][$id]=$issue;
        }
        foreach($partitions as $prefix=>$subset) {
            $bucket=$source;
            $bucket['items']=$subset;
            $bucket['counts']=array();
            foreach($subset as $issue) {
                $code=(string)($issue['code']??'unknown');
                $bucket['counts'][$code]=($bucket['counts'][$code]??0)+1;
            }
            $bucket['partition']=$prefix;
            $bucket['scope_id']=hash('sha256',$source['scope_id'].'|'.$prefix);
            if(count($subset)>self::MAX_ITEMS_PER_WORK_PACKET && $depth<4)
                self::partition($bucket,$depth+1,$dest);
            else $dest[$bucket['scope_id']]=$bucket;
        }
    }
    private static function summary($scope) {
        return array_diff_key($scope,array('items'=>true));
    }
    private static function page($items,$request,$limit=50) {
        $page=max(1,absint($request->get_param('page')??1));
        $size=max(1,min($limit,absint($request->get_param('per_page')??25)));
        return array('total'=>count($items),'page'=>$page,'per_page'=>$size,
            'items'=>array_slice(array_values($items),($page-1)*$size,$size));
    }
    public static function rest_list($request) {
        $all=self::can_manage()&&$request->get_param('scope')==='all';
        $scopes=self::build();
        $out=array();
        foreach($scopes as $scope) {
            if(!$all && (int)$scope['assignee_id']!==get_current_user_id())continue;
            $out[]=self::summary($scope);
        }
        return rest_ensure_response(array('contract'=>'mad4b.readiness.work-scopes.v1',
            'aggregation'=>'group + language + severity + content_type',
            'notifications_sent'=>false,'pagination'=>self::page($out,$request)));
    }
    /** Parent batches stay stable even when their work packets are subdivided. */
    public static function batches($scopes=null) {
        $scopes=is_array($scopes)?$scopes:self::build();
        $batches=array();
        foreach($scopes as $scope) {
            $id=$scope['batch_id'];
            if(!isset($batches[$id]))$batches[$id]=array('batch_id'=>$id,
                'group'=>$scope['group'],'language'=>$scope['language'],
                'severity'=>$scope['severity'],'content_type'=>$scope['content_type'],
                'issue_count'=>0,'work_packet_count'=>0,'assigned_packet_count'=>0,
                'approved_packet_count'=>0,'synced_packet_count'=>0);
            $batches[$id]['issue_count']+=$scope['total'];
            $batches[$id]['work_packet_count']++;
            if($scope['assignee_id'])$batches[$id]['assigned_packet_count']++;
            if($scope['approval']==='approved')$batches[$id]['approved_packet_count']++;
            if($scope['approval']==='synced')$batches[$id]['synced_packet_count']++;
        }
        // Revision protects a digest from changes to its underlying packets.
        foreach($batches as $id=>&$batch) {
            $revisions=array();
            foreach($scopes as $scope)
                if($scope['batch_id']===$id)
                    $revisions[$scope['scope_id']]=$scope['revision'].'|'.$scope['task_id'];
            ksort($revisions,SORT_STRING);
            $batch['revision']=hash('sha256',implode(';',$revisions));
        }
        unset($batch);
        uasort($batches,static fn($a,$b)=>$b['issue_count']<=>$a['issue_count']);
        return $batches;
    }
    public static function rest_batches($request) {
        $batches=self::batches();
        return rest_ensure_response(array('contract'=>'mad4b.readiness.work-batches.v1',
            'aggregated'=>true,'notifications_sent'=>false,
            'pagination'=>self::page($batches,$request)));
    }
    public static function rest_digest_preview($request) {
        $id=(string)$request['batch_id'];
        $batch=self::batches()[$id]??null;
        if(!$batch)return self::fail('readiness_batch_unknown','Unknown work batch.',404);
        $cfg=MAD4B_SCP_Readiness_Workflow_Options::settings();
        $flows=MAD4B_SCP_Readiness_Integrations::bitflows_flow_status(self::BITFLOWS_HOOK);
        return rest_ensure_response(array('contract'=>'mad4b.readiness.batch-digest.v1',
            'dry_run'=>true,'hook_invoked'=>false,
            'ready_for_external_delivery'=>!empty($cfg['bitflows_notifications'])&&$flows['configured']&&
                $batch['synced_packet_count']===$batch['work_packet_count'],
            'payload'=>array('batch_id'=>$id,'site_id'=>get_current_blog_id(),
                'group'=>$batch['group'],'language'=>$batch['language'],
                'severity'=>$batch['severity'],'content_type'=>$batch['content_type'],
                'issue_count'=>$batch['issue_count'],
                'work_packet_count'=>$batch['work_packet_count'],
                'synced_packet_count'=>$batch['synced_packet_count'])));
    }
    public static function rest_notify_digest($request) {
        if(!MAD4B_SCP_Content_Readiness_Alerts::rest_local_client_write_permission())
            return self::fail('readiness_digest_agent_required','Verified governed Agent required.',403);
        $id=(string)$request['batch_id'];
        $batch=self::batches()[$id]??null;
        if(!$batch)return self::fail('readiness_digest_unknown','Work batch not found.',404);
        $cfg=MAD4B_SCP_Readiness_Workflow_Options::settings();
        if(empty($cfg['bitflows_notifications']) ||
            !MAD4B_SCP_Readiness_Integrations::bitflows_flow_status(self::BITFLOWS_HOOK)['configured'])
            return self::fail('readiness_digest_channel_disabled','Certified company-specific Bit Flows digest trigger required.',409);
        if(!$batch['work_packet_count'] ||
            $batch['work_packet_count']!==$batch['synced_packet_count'])
            return self::fail('readiness_digest_not_ready','Approve and sync all packets before notifying.',409);
        $payload=$request->get_json_params();
        if(!is_array($payload)||!is_string($payload['revision']??null)||
            !hash_equals($batch['revision'],$payload['revision']))
            return self::fail('readiness_digest_revision_conflict','Refresh current batch digest.',409);
        $identity=hash('sha256',$id.'|'.$batch['revision']);
        $lock='mad4b_readiness_digest_'.substr($identity,0,48);
        if(!add_option($lock,array('batch_id'=>$id,'revision'=>$batch['revision'],
            'claimed_at'=>gmdate('c'),'status'=>'claimed'),'','no'))
            return self::fail('readiness_digest_already_claimed','Digest already claimed for this revision.',409);
        // A WordPress Action Hooks flow is optional and independently provisioned.
        // Its invocation is not proof of email/WhatsApp delivery.
        $event=array('contract'=>'mad4b.readiness.batch-digest.v1',
            'batch_id'=>$id,'site_id'=>get_current_blog_id(),
            'group'=>$batch['group'],'language'=>$batch['language'],
            'severity'=>$batch['severity'],'content_type'=>$batch['content_type'],
            'issue_count'=>$batch['issue_count'],
            'work_packet_count'=>$batch['work_packet_count'],
            'revision'=>$batch['revision']);
        do_action(self::BITFLOWS_HOOK,$event);
        $receipt=(array)get_option($lock,array());
        $receipt['status']='hook_invoked';$receipt['invoked_at']=gmdate('c');
        update_option($lock,$receipt,false);
        return rest_ensure_response(array('contract'=>'mad4b.readiness.batch-digest.v1',
            'status'=>'hook_invoked','provider_delivery_confirmed'=>false,
            'batch_id'=>$id,'issue_count'=>$batch['issue_count']));
    }
    public static function rest_scope($request) {
        $id=(string)$request['scope_id'];
        $bucket=self::build()[$id]??null;
        if(!$bucket)return self::fail('readiness_scope_missing','Work scope not found.',404);
        if(!self::can_manage() && (int)$bucket['assignee_id']!==get_current_user_id())
            return self::fail('readiness_scope_private','Not assigned to the current WP user.',403);
        $items=array();
        foreach($bucket['items'] as $row) {
            $items[]=array('issue_id'=>$row['id'],'code'=>$row['code'],
                'post_type'=>$row['post_type'],'object_id'=>$row['object_id'],
                'severity'=>$row['severity'],'label'=>$row['label'],
                'board_task_id'=>absint($row['board_task_id']??0));
        }
        return rest_ensure_response(array('scope'=>self::summary($bucket),
            'pagination'=>self::page($items,$request,100)));
    }
    public static function rest_approve($request) {
        $id=(string)$request['scope_id'];
        $scope=self::build()[$id]??null;
        if(!$scope)return self::fail('readiness_scope_missing','Work scope not found.',404);
        $body=$request->get_json_params();
        if(!is_array($body)||!is_string($body['revision']??null)||
            !hash_equals($scope['revision'],$body['revision']))
            return self::fail('readiness_scope_stale','Scope changed. Refresh before approval.',409);
        if(!$scope['assignee_id'])
            return self::fail('readiness_scope_unassigned','Choose an eligible team member first.',409);
        if(!current_user_can('manage_options') &&
           (!class_exists('MAD4B_SCP_Company_Master_Admin')||
            !MAD4B_SCP_Company_Master_Admin::managedStaff($scope['assignee_id'])))
            return self::fail('readiness_scope_outside_team','Owner is outside the approved company staff.',403);
        $states=(array)get_option(self::STORE,array());
        $prior=(array)($states[$id]??array());
        if(!empty($prior['task_id']))
            return self::fail('readiness_scope_already_synced','Scope has a linked Board task.',409);
        $states[$id]=array('scope_id'=>$id,'revision'=>$scope['revision'],
            'status'=>'approved','assignee_id'=>$scope['assignee_id'],
            'approved_by'=>get_current_user_id(),'approved_at'=>gmdate('c'),
            'task_id'=>0,'total'=>$scope['total']);
        update_option(self::STORE,$states,false);
        $readback=(array)get_option(self::STORE,array());
        if(($readback[$id]['revision']??null)!==$scope['revision'])
            return self::fail('readiness_scope_readback_failed','Approval was not confirmed.',500);
        return rest_ensure_response(array('contract'=>'mad4b.readiness.work-scopes.v1',
            'scope'=>self::summary($scope),'status'=>'approved',
            'tasks_created'=>0,'messages_sent'=>0));
    }
    public static function rest_sync($request) {
        if(!MAD4B_SCP_Content_Readiness_Alerts::rest_local_client_write_permission())
            return self::fail('readiness_agent_required','Verified governed Agent required.',403);
        $id=(string)$request['scope_id'];
        $scope=self::build()[$id]??null;
        if(!$scope)return self::fail('readiness_scope_missing','Scope not found.',404);
        $states=(array)get_option(self::STORE,array());
        $record=(array)($states[$id]??array());
        if(($record['status']??'')==='synced' && !empty($record['task_id']))
            return rest_ensure_response(array('contract'=>'mad4b.readiness.work-scopes.v1',
                'status'=>'already_linked','task_id'=>(int)$record['task_id']));
        if(($record['status']??'')!=='approved' ||
            ($record['revision']??'')!==$scope['revision'] ||
            (int)($record['assignee_id']??0)!==(int)$scope['assignee_id'])
            return self::fail('readiness_scope_unapproved','Approve the current scope revision first.',409);
        $cfg=MAD4B_SCP_Readiness_Workflow_Options::settings();
        $board=absint($cfg['board_id']);$stage=absint($cfg['stage_id']);
        if(empty($cfg['board_tasks'])||
            !MAD4B_SCP_Readiness_Workflow_Options::validate_board_stage($board,$stage))
            return self::fail('readiness_scope_board_unavailable','Approved company board required.',409);
        if(!class_exists('FluentBoards\App\Services\TaskService'))
            return self::fail('readiness_scope_provider_unavailable','Fluent Boards unavailable.',503);
        global $wpdb;
        $existing=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fbs_tasks WHERE board_id=%d AND source=%s AND source_id=%s LIMIT 1",
            $board,self::TASK_SOURCE,$id));
        if($existing) {
            if(empty($record['task_id'])){
                $states[$id]['task_id']=$existing;$states[$id]['status']='synced';
                update_option(self::STORE,$states,false);
            }
            return rest_ensure_response(array('status'=>'already_linked','task_id'=>$existing));
        }
        $lock='mad4b_readiness_scope_lock_'.substr($id,0,48);
        if(!add_option($lock,array('at'=>gmdate('c')),'','no'))
            return self::fail('readiness_scope_inflight','Scope is claimed; reconcile previous outcome.',409);
        $title='['.strtoupper($scope['severity']).'] '.$scope['group'].' / '.
            $scope['language'].' / '.$scope['content_type'].' — '.$scope['total'].' issues';
        $body='Source: Content Readiness; scope: '.$id.
            '; revision: '.$scope['revision'].
            '; issue count: '.$scope['total'].
            '; use the Company Content Scopes dashboard for individual sources.';
        try {
            $task=(new \FluentBoards\App\Services\TaskService())->createTask(array(
                'title'=>$title,'description'=>$body,'stage_id'=>$stage,
                'source'=>self::TASK_SOURCE,'source_id'=>$id,
                'assignees'=>array((int)$scope['assignee_id'])
            ),$board);
        }catch(Throwable $e){
            return self::fail('readiness_scope_provider_failed','Fluent Boards creation requires reconciliation.',502);
        }
        $taskId=absint($task->id??0);
        if(!$taskId)return self::fail('readiness_scope_receipt_missing','Task receipt is uncertain.',502);
        $states[$id]['status']='synced';$states[$id]['task_id']=$taskId;
        $states[$id]['synced_at']=gmdate('c');
        update_option(self::STORE,$states,false);
        return rest_ensure_response(array('contract'=>'mad4b.readiness.work-scopes.v1',
            'status'=>'created','task_id'=>$taskId,'scope_id'=>$id,'issues_in_scope'=>$scope['total'],
            'external_messages_sent'=>0));
    }
    public static function routes() {
        register_rest_route(self::NS,'/scope-batches',array('methods'=>'GET',
            'permission_callback'=>array(__CLASS__,'can_manage'),
            'callback'=>array(__CLASS__,'rest_batches')));
        register_rest_route(self::NS,'/scope-batches/(?P<batch_id>[a-f0-9]{64})/notify',array(
            'methods'=>'POST','permission_callback'=>array('MAD4B_SCP_Content_Readiness_Alerts','rest_local_client_write_permission'),
            'callback'=>array(__CLASS__,'rest_notify_digest')));
        register_rest_route(self::NS,'/scope-batches/(?P<batch_id>[a-f0-9]{64})/digest-preview',array(
            'methods'=>'GET','permission_callback'=>array(__CLASS__,'can_manage'),
            'callback'=>array(__CLASS__,'rest_digest_preview')));
        register_rest_route(self::NS,'/scopes',array('methods'=>'GET',
            'permission_callback'=>array(__CLASS__,'can_view'),'callback'=>array(__CLASS__,'rest_list')));
        register_rest_route(self::NS,'/scopes/(?P<scope_id>[a-f0-9]{64})',array(
            'methods'=>'GET','permission_callback'=>array(__CLASS__,'can_view'),
            'callback'=>array(__CLASS__,'rest_scope')));
        register_rest_route(self::NS,'/scopes/(?P<scope_id>[a-f0-9]{64})/approve',array(
            'methods'=>'POST','permission_callback'=>array(__CLASS__,'can_manage'),
            'callback'=>array(__CLASS__,'rest_approve')));
        register_rest_route(self::NS,'/scopes/(?P<scope_id>[a-f0-9]{64})/sync',array(
            'methods'=>'POST','permission_callback'=>array('MAD4B_SCP_Content_Readiness_Alerts','rest_local_client_write_permission'),
            'callback'=>array(__CLASS__,'rest_sync')));
    }
    public static function menu() {
        add_submenu_page('mad4b-company','Content Work Scopes','Content Work Scopes',
            MAD4B_SCP_Readiness_Integrations::CAP,self::PAGE,array(__CLASS__,'screen'));
    }
    public static function screen() {
        if(!self::can_manage())wp_die('Forbidden',403);
        $scopes=self::build();
        $batches=self::batches($scopes);
        echo '<div class="wrap"><h1>Content Work Scopes</h1>';
        echo '<p><strong>'.count($batches).' work batches</strong> containing <strong>'.count($scopes).' bounded task packets</strong>.</p>';
        echo '<p>WPML-inspired work batches. Each scope groups the live issues by ownership group, language, severity and content type. Source issues remain individual and no outgoing notification is sent during a scan.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Group</th><th>Language</th><th>Content</th><th>Severity</th><th>Issues</th><th>Owner</th><th>State</th></tr></thead><tbody>';
        foreach($scopes as $scope){
            echo '<tr><td>'.esc_html($scope['group']).'</td><td>'.esc_html($scope['language']).
                '</td><td>'.esc_html($scope['content_type']).'</td><td>'.esc_html($scope['severity']).
                '</td><td>'.(int)$scope['total'].'</td><td>'.
                ($scope['assignee_id']?(int)$scope['assignee_id']:'Assign team owner').'</td><td>'.
                esc_html($scope['approval']).'</td></tr>';
        }
        echo '</tbody></table><p>Scope approvals and native Fluent Boards task creation are separate governed API operations. Company Master Admin can assign only managed staff; external Bit Flows is never called from this page.</p></div>';
    }
}
