<?php
declare(strict_types=1);
namespace Commerce\Composition;

/** One native FluentBoards task per nonfinancial reviewed request. */
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
    public static function bootstrap():void {
        add_action('commerce_composition_request_review_transitioned',
            [self::class,'onReview'],25,2);
    }
}
