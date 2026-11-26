<?php
declare(strict_types=1);
namespace Commerce\Composition;
require_once __DIR__.'/LifecycleAccess.php';

/** Native tasks linked to original source submissions and reviewed requests. */
final class FluentBoardsTaskBridge
{
    private static function table():string {global $wpdb;return $wpdb->prefix.'commerce_request_task_links';}
    public static function install():true|\WP_Error {
        global $wpdb;require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $t=self::table();
        dbDelta("CREATE TABLE $t (
            request_id bigint(20) unsigned NOT NULL,
            board_id bigint(20) unsigned NOT NULL,
            stage_id bigint(20) unsigned NOT NULL,
            task_id bigint(20) unsigned NULL DEFAULT NULL,
            state varchar(24) NOT NULL,
            actor_id bigint(20) unsigned NOT NULL,
            created_utc datetime NOT NULL,
            updated_utc datetime NOT NULL,
            PRIMARY KEY  (request_id),
            UNIQUE KEY task_id (task_id)
        ) {$wpdb->get_charset_collate()};");
        return self::ready()?true:new \WP_Error('boards_link_schema_unavailable');
    }
    public static function ready():bool {
        global $wpdb;$t=self::table();
        return $wpdb->get_var($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$t))==='InnoDB';
    }
    public static function inspect(int $requestId):array|\WP_Error {
        global $wpdb;
        if(!RequestReview::authorized()||!self::ready())
            return new \WP_Error('boards_link_access_denied');
        $t=self::table();
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT request_id,board_id,stage_id,task_id,state FROM $t WHERE request_id=%d",
            $requestId),ARRAY_A);
        if(!$row)return ['request_id'=>$requestId,'linked'=>false];
        return ['request_id'=>$requestId,'linked'=>$row['state']==='linked',
            'board_id'=>(int)$row['board_id'],'stage_id'=>(int)$row['stage_id'],
            'task_id'=>$row['task_id']===null?null:(int)$row['task_id'],
            'status'=>$row['state']];
    }
    public static function ensure(int $requestId):array|\WP_Error {
        global $wpdb;
        if(!RequestReview::authorized())return new \WP_Error('boards_provider_unavailable');
        $review=RequestReview::read($requestId);
        if(is_wp_error($review))return $review;
        $locks=[];
        foreach(($review['sources']??[]) as $source){
            $locks[]=self::sourceLock((int)$source['form_id'],(int)$source['submission_id']);
        }
        $locks=array_values(array_unique($locks));sort($locks,SORT_STRING);
        $held=[];
        try {
            foreach($locks as $lock){
                if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)
                    return new \WP_Error('boards_submission_busy');
                $held[]=$lock;
            }
            return self::ensureReviewed($requestId);
        } finally {
            foreach(array_reverse($held) as $lock)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
        }
    }
    private static function ensureReviewed(int $requestId):array|\WP_Error {
        global $wpdb;
        if(!RequestReview::authorized()||
           !(current_user_can('commerce_manage_request_tasks') ||
             current_user_can('manage_options'))||
           !self::ready()||!function_exists('FluentBoardsApi'))
            return new \WP_Error('boards_provider_unavailable');
        $opt=get_option('commerce_request_review_board',[]);
        if(!is_array($opt)||($opt['enabled']??null)!==true||
           (int)($opt['board_id']??0)<1||(int)($opt['stage_id']??0)<1)
            return new \WP_Error('boards_target_not_approved');
        $boardId=(int)$opt['board_id'];$stageId=(int)$opt['stage_id'];
        $review=RequestReview::read($requestId);
        if(is_wp_error($review))return $review;
        if(!in_array($review['review_state'],['under_review','accepted_for_processing'],true))
            return new \WP_Error('boards_review_not_actionable');
        $boards=$wpdb->prefix.'fbs_boards';$stages=$wpdb->prefix.'fbs_board_terms';
        $valid=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $stages s INNER JOIN $boards b ON b.id=s.board_id
             WHERE s.id=%d AND s.board_id=%d AND s.type='stage'
             AND s.archived_at IS NULL AND b.archived_at IS NULL",$stageId,$boardId));
        if($valid!==1)return new \WP_Error('boards_target_unavailable');
        $existing=self::inspect($requestId);
        if(is_wp_error($existing))return $existing;
        if(($existing['linked']??false)===true){
            $tasks=$wpdb->prefix.'fbs_tasks';
            $found=(int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $tasks WHERE id=%d AND board_id=%d",
                $existing['task_id'],$existing['board_id']));
            if($existing['board_id']!==$boardId||$found!==1)
                return new \WP_Error('boards_existing_task_invalid');
            return $existing+['reused'=>true];
        }
        if(isset($existing['status']))return new \WP_Error('boards_link_reconciliation_required');
        $sourceTasks=[];
        foreach(($review['sources']??[]) as $source){
            $record=self::submissionTaskRecord((int)$source['form_id'],(int)$source['submission_id']);
            if(is_wp_error($record))return $record;
            $task=(int)($record['task_id']??0);
            if($task)$sourceTasks[$task]=true;
        }
        if(count($sourceTasks)>1)return new \WP_Error('boards_source_multiple_tasks_require_review');
        if($sourceTasks){
            $taskId=(int)array_key_first($sourceTasks);
            $task=\FluentBoards\App\Models\Task::find($taskId);
            if(!$task||(int)$task->board_id!==$boardId)return new \WP_Error('boards_existing_task_invalid');
            $t=self::table();$now=gmdate('Y-m-d H:i:s');
            $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $t (request_id,board_id,stage_id,task_id,state,actor_id,created_utc,updated_utc) VALUES (%d,%d,%d,%d,'linked',%d,%s,%s)",$requestId,$boardId,$stageId,$taskId,get_current_user_id(),$now,$now));
            return self::inspect($requestId)+['reused'=>true];
        }
        $t=self::table();$now=gmdate('Y-m-d H:i:s');
        $claimed=$wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $t
             (request_id,board_id,stage_id,task_id,state,actor_id,created_utc,updated_utc)
             VALUES (%d,%d,%d,NULL,'creating',%d,%s,%s)",
            $requestId,$boardId,$stageId,get_current_user_id(),$now,$now));
        if($claimed!==1)return new \WP_Error('boards_link_already_in_progress');
        try{
            // Intentionally omit customer email and CRM subscriber identity.
            // Native FluentBoards otherwise may subscribe an emailed contact.
            $task=FluentBoardsApi('tasks')->create([
                'title'=>'Review request #'.$requestId,
                'board_id'=>$boardId,'stage_id'=>$stageId,
                'status'=>'open','assignees'=>[],'labels'=>[]
            ]);
            $taskId=is_object($task)?(int)($task->id??0):0;
            if($taskId<1) {
                $wpdb->delete($t,['request_id'=>$requestId,'state'=>'creating']);
                return new \WP_Error('boards_native_task_refused');
            }
            $linked=$wpdb->update($t,['task_id'=>$taskId,'state'=>'linked',
                'updated_utc'=>gmdate('Y-m-d H:i:s')],
                ['request_id'=>$requestId,'state'=>'creating']);
            if($linked!==1)return new \WP_Error('boards_link_reconciliation_required');
            return ['linked'=>true,'reused'=>false,'request_id'=>$requestId,
                'task_id'=>$taskId,'board_id'=>$boardId,'stage_id'=>$stageId,
                'crm_subscribed'=>false,'supplier_confirmed'=>false];
        }catch(\Throwable $e){
            return new \WP_Error('boards_native_task_outcome_uncertain');
        }
    }
    public static function onReview(array $result,array $context):void {
        $opt=get_option('commerce_request_review_board',[]);
        if(!is_array($opt)||($opt['auto_create']??false)!==true)return;
        if(!in_array($result['review_state']??'',
            ['under_review','accepted_for_processing'],true))return;
        self::ensure((int)($result['request_id']??0));
    }
    /** One native task per original submission, reused by subsequent review/payment events. */
    public static function taskForSubmission(int $fid,int $sid):int {
        global $wpdb;
        $row=self::submissionTaskRecord($fid,$sid);
        return is_array($row)?(int)($row['task_id']??0):0;
    }
    private static function submissionTaskRecord(int $fid,int $sid):array|\WP_Error {
        global $wpdb;
        if($fid<1||$sid<1)return new \WP_Error('boards_source_invalid');
        $values=$wpdb->get_col($wpdb->prepare("SELECT value FROM {$wpdb->prefix}fluentform_submission_meta WHERE form_id=%d AND response_id=%d AND meta_key='_commerce_lifecycle_task_v1' LIMIT 2",$fid,$sid));
        if(!is_array($values)||$wpdb->last_error||count($values)>1)return new \WP_Error('boards_source_task_record_uncertain');
        if(!$values)return [];
        $row=json_decode((string)$values[0],true);
        if(!is_array($row)||empty($row['task_id']))return new \WP_Error('boards_source_task_record_uncertain');
        return $row;
    }
    private static function sourceLock(int $fid,int $sid):string {
        global $wpdb;
        return 'board_source_'.substr(hash('sha256',$wpdb->prefix.':'.$fid.':'.$sid),0,40);
    }
    private static function storeSourceRecord(int $fid,int $sid,array $record):void {
        $meta=\FluentForm\App\Models\SubmissionMeta::where('response_id',$sid)->where('form_id',$fid)->where('meta_key','_commerce_lifecycle_task_v1')->first();
        if(!$meta)$meta=new \FluentForm\App\Models\SubmissionMeta();
        $meta->fill(['response_id'=>$sid,'form_id'=>$fid,'meta_key'=>'_commerce_lifecycle_task_v1','value'=>wp_json_encode($record),'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')])->save();
    }
    public static function ensureSubmission(array $p,array $ctx,$o,int $contactId):array|\WP_Error {
        if(!\Commerce\Composition\LifecycleAccess::can('commerce_manage_request_tasks')||!function_exists('FluentBoardsApi'))return new \WP_Error('boards_lifecycle_provider_unavailable');
        $opt=get_option('commerce_request_review_board',[]);$board=(int)($opt['board_id']??0);$stage=(int)($opt['stage_id']??0);
        if(($opt['enabled']??false)!==true||!$board||!$stage)return new \WP_Error('boards_target_not_approved');
        global $wpdb;
        $valid=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}fbs_board_terms s JOIN {$wpdb->prefix}fbs_boards b ON b.id=s.board_id WHERE s.id=%d AND s.board_id=%d AND s.type='stage' AND s.archived_at IS NULL AND b.archived_at IS NULL",$stage,$board));
        if($valid!==1)return new \WP_Error('boards_target_unavailable');
        $lock=self::sourceLock((int)$p['form_id'],(int)$p['submission_id']);
        if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lock))!==1)return new \WP_Error('boards_submission_busy');
        try {
            $record=self::submissionTaskRecord($p['form_id'],$p['submission_id']);
            if(is_wp_error($record))return $record;
            $id=(int)($record['task_id']??0);
            if(!$id){
                $links=$wpdb->get_results($wpdb->prepare("SELECT DISTINCT l.task_id,l.state FROM {$wpdb->prefix}commerce_request_task_links l JOIN {$wpdb->prefix}commerce_request_batches b ON b.id=l.request_id WHERE JSON_CONTAINS(b.snapshot_json,JSON_OBJECT('form_id',%d,'source_submission_id',%d))",$p['form_id'],$p['submission_id']),ARRAY_A);
                if(!is_array($links)||$wpdb->last_error)return new \WP_Error('boards_source_task_record_uncertain');
                foreach($links as $link)if($link['state']!=='linked'||(int)$link['task_id']<1)
                    return new \WP_Error('boards_source_task_record_uncertain');
                $ids=array_unique(array_map(static fn($link)=>(int)$link['task_id'],$links));
                if(count($ids)>1)return new \WP_Error('boards_source_multiple_tasks_require_review');if($ids)$id=(int)reset($ids);
            }
            $description='Original Fluent Form #'.$p['form_id'].' / submission #'.$p['submission_id']."\nTour/source #".$ctx['source_id'].' / Rate #'.$ctx['rate_id']."\nFinal trip total: not agreed. Booking: not confirmed.";
            $state=$o?(string)$o->get_meta('_request_collection_state'):'request_received';
            if($o&&$o->get_meta('_request_customer_policy_fingerprint'))$description.="\nPolicy revision: ".$o->get_meta('_request_customer_policy_fingerprint');
            if($o) {
                $full=class_exists('\Commerce\Composition\RequestAdvancePayments')
                    && RequestAdvancePayments::isFullDueNow($o);
                $description.="\n".($full?'Full estimated payment':'Advance receipt').' #'
                    .$o->get_id().' / received '.$o->get_meta('_request_paid_amount').' '
                    .$o->get_currency().' / refunded '.$o->get_meta('_request_refunded_amount')
                    .' / booking not confirmed';
            }
            $earlyClaim=null;
            if($o&&class_exists('MAD4B_Early_Payment_Benefits_V1')){
                $earlyClaim=\MAD4B_Early_Payment_Benefits_V1::claimState($o);
                $benefitState=(string)($earlyClaim['state']??'offered');
                if(in_array($benefitState,['qualified_for_service','fulfilled','reversed_after_refund'],true))
                    $description.="\nEarly payment benefit: ".$benefitState.
                        " / separate service fulfilment required when qualified; no booking confirmation.";
            }
            if(!$id){
                // Persist an uncertainty marker in the original submission record BEFORE the native task effect.
                self::storeSourceRecord($p['form_id'],$p['submission_id'],['task_id'=>0,'board_id'=>$board,'state'=>'creating']);
                $check=$wpdb->get_col($wpdb->prepare("SELECT value FROM {$wpdb->prefix}fluentform_submission_meta WHERE form_id=%d AND response_id=%d AND meta_key='_commerce_lifecycle_task_v1' LIMIT 2",$p['form_id'],$p['submission_id']));
                if(!is_array($check)||count($check)!==1||$wpdb->last_error||
                   (json_decode((string)$check[0],true)['state']??'')!=='creating')
                    return new \WP_Error('boards_source_task_record_uncertain');
                $task=FluentBoardsApi('tasks')->create(['title'=>'Travel request #'.$p['submission_id'].' — under agreement','description'=>$description,'board_id'=>$board,'stage_id'=>$stage,'status'=>'open','assignees'=>[],'labels'=>[],'crm_contact_id'=>$contactId]);
                $id=is_object($task)?(int)$task->id:0;if(!$id)return new \WP_Error('boards_native_task_refused');
                $task->updateMeta('commerce_submission_source',['form_id'=>$p['form_id'],'submission_id'=>$p['submission_id']]);
            }
            $task=\FluentBoards\App\Models\Task::find($id);
            if(!$task||(int)$task->board_id!==$board)return new \WP_Error('boards_existing_task_invalid');
            self::storeSourceRecord($p['form_id'],$p['submission_id'],['task_id'=>$id,'board_id'=>$board]);
            if(self::taskForSubmission($p['form_id'],$p['submission_id'])!==$id)
                return new \WP_Error('boards_source_task_record_uncertain');
            FluentBoardsApi('tasks')->updateProperty($id,'description',$description);
            FluentBoardsApi('tasks')->updateProperty($id,'crm_contact_id',$contactId);
            $task->updateMeta('commerce_request_lifecycle',['state'=>'under_agreement','collection_state'=>$state,'source_id'=>$ctx['source_id'],'rate_id'=>$ctx['rate_id'],'order_id'=>$o?$o->get_id():0,'final_trip_total'=>null,'booking_confirmed'=>false]);
            if($o && is_array($earlyClaim) &&
               in_array((string)($earlyClaim['state']??''),['qualified_for_service','fulfilled','reversed_after_refund'],true)){
                $task->updateMeta('mad4b_early_payment_benefit_v1',[
                    'order_id'=>$o->get_id(),'state'=>$earlyClaim['state'],
                    'snapshot_fingerprint'=>$earlyClaim['offer_fingerprint']??'',
                    'reversal_action'=>$earlyClaim['reversal_action']??null,
                    'requires_separate_fulfilment'=>$earlyClaim['state']==='qualified_for_service',
                    'booking_confirmed'=>false,
                ]);
                \MAD4B_Early_Payment_Benefits_V1::boardLinked($o,$id);
            }
            return ['status'=>'boards_projected','task_id'=>$id,'booking_confirmed'=>false];
        }finally{$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
    }

    public static function bootstrap():void {
        add_action('commerce_composition_request_review_transitioned',
            [self::class,'onReview'],25,2);
    }
}
