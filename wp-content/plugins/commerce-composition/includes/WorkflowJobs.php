<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Durable command/outbox with idempotency, principal preservation and
 * fail-closed domain dispatch. Never logs personal form answers or secrets.
 */
final class WorkflowJobs
{
    private static function table():string
    {
        global $wpdb;
        return $wpdb->prefix.'commerce_workflow_jobs';
    }
    public static function install():true|\WP_Error
    {
        global $wpdb;
        $t=self::table();
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$t))
            return new \WP_Error('workflow_queue_table_invalid');
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE $t (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            dedupe_sha256 char(64) NOT NULL,
            payload_sha256 char(64) NOT NULL,
            action_key varchar(80) NOT NULL,
            payload_json text NOT NULL,
            actor_id bigint(20) unsigned NOT NULL,
            origin varchar(20) NOT NULL,
            state varchar(20) NOT NULL,
            attempts int(10) unsigned NOT NULL DEFAULT 0,
            result_json text NULL,
            error_code varchar(100) NULL,
            created_utc datetime NOT NULL,
            updated_utc datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY unique_command (dedupe_sha256),
            KEY ready_queue (state,id),
            KEY actor_lookup (actor_id,id)
        ) {$wpdb->get_charset_collate()};");
        return self::ready()?true:new \WP_Error('workflow_queue_install_failed');
    }
    public static function ready():bool
    {
        global $wpdb;
        $t=self::table();
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$t))return false;
        return $wpdb->get_var($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            $t
        ))==='InnoDB';
    }
    /** Only actions that are safe to re-run and have a server-verifiable owner. */
    public static function enqueue(string $action,array $payload,string $intentKey,
        int $actorId,string $origin='api'):array|\WP_Error
    {
        global $wpdb;
        if(!self::ready())return new \WP_Error('workflow_queue_not_ready');
        if(!in_array($origin,['api','event','internal'],true))
            return new \WP_Error('workflow_queue_origin_not_allowed');
        if(!preg_match('/^[A-Za-z0-9._:-]{16,128}$/D',$intentKey))
            return new \WP_Error('workflow_idempotency_key_invalid');
        if($actorId<1||!user_can($actorId,'manage_options'))
            return new \WP_Error('workflow_actor_not_authorized');
        // Publicly enqueued background jobs must not depend on an HTTP
        // customer's transient Woo session or gain sale/supplier authority.
        if(!in_array($action,[
            'link_contact_record',
            'create_request_from_saved_submission'
        ],true))return new \WP_Error('workflow_action_not_async_certified');
        $keys=$action==='link_contact_record'
            ?['contact_id','source_key','source_id','role']
            :['form_id','submission_id'];
        $actualKeys=array_keys($payload);
        sort($actualKeys,SORT_STRING);
        $requiredKeys=$keys;
        sort($requiredKeys,SORT_STRING);
        if($actualKeys!==$requiredKeys)
            return new \WP_Error('workflow_payload_schema_invalid');
        if($action==='link_contact_record'){
            if(!is_int($payload['contact_id'])||$payload['contact_id']<1||
                !is_int($payload['source_id'])||$payload['source_id']<1||
                !is_string($payload['source_key'])||
                !preg_match('/^[a-z][a-z0-9_-]{1,89}$/D',$payload['source_key'])||
                !is_string($payload['role'])||
                !preg_match('/^[a-z][a-z0-9_-]{1,35}$/D',$payload['role']))
                return new \WP_Error('workflow_payload_schema_invalid');
            $payload=[
                'contact_id'=>$payload['contact_id'],
                'source_key'=>$payload['source_key'],
                'source_id'=>$payload['source_id'],
                'role'=>$payload['role']
            ];
        }else{
            if(!is_int($payload['form_id'])||$payload['form_id']<1||
                !is_int($payload['submission_id'])||$payload['submission_id']<1)
                return new \WP_Error('workflow_payload_schema_invalid');
            $verified=SubmissionProvenance::verify(
                $payload['submission_id'],$payload['form_id']);
            if(is_wp_error($verified))return $verified;
            $payload=[
                'form_id'=>$payload['form_id'],
                'submission_id'=>$payload['submission_id']
            ];
        }
        $json=wp_json_encode($payload,JSON_UNESCAPED_SLASHES);
        if(!is_string($json)||strlen($json)>4096)
            return new \WP_Error('workflow_payload_encoding_invalid');
        $keyHash=hash_hmac('sha256',$actorId.':'.$origin.':'.$intentKey,wp_salt('secure_auth'));
        $digest=hash('sha256',$action.':'.$json);
        $t=self::table();
        $now=gmdate('Y-m-d H:i:s');
        $inserted=$wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $t
             (dedupe_sha256,payload_sha256,action_key,payload_json,actor_id,origin,state,attempts,created_utc,updated_utc)
             VALUES (%s,%s,%s,%s,%d,%s,%s,%d,%s,%s)",
             $keyHash,$digest,$action,$json,$actorId,$origin,'queued',0,$now,$now
        ));
        if($inserted===false)
            return new \WP_Error('workflow_queue_insert_failed');
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT id,payload_sha256,state,action_key FROM $t WHERE dedupe_sha256=%s",
            $keyHash
        ),ARRAY_A);
        if(!is_array($row))return new \WP_Error('workflow_queue_readback_failed');
        if($row['payload_sha256']!==$digest||$row['action_key']!==$action)
            return new \WP_Error('workflow_idempotency_payload_conflict');
        $id=(int)$row['id'];
        if($inserted===1&&function_exists('as_enqueue_async_action'))
            as_enqueue_async_action('commerce_composition_process_workflow_job',
                [$id],'commerce-composition',true);
        return [
            'job_id'=>$id,'status'=>$inserted===1?'queued':$row['state'],
            'already_exists'=>$inserted!==1
        ];
    }
    public static function status(int $id,int $viewerId):array|\WP_Error
    {
        global $wpdb;
        if($id<1||!self::ready()||$viewerId<1||!user_can($viewerId,'manage_options'))
            return new \WP_Error('workflow_job_access_denied');
        $t=self::table();
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT id,actor_id,action_key,state,attempts,error_code,created_utc,updated_utc
             FROM $t WHERE id=%d",$id
        ),ARRAY_A);
        if(!is_array($row)||((int)$row['actor_id']!==$viewerId &&
            !user_can($viewerId,'manage_options')))
            return new \WP_Error('workflow_job_not_found');
        return [
            'job_id'=>(int)$row['id'],'action_key'=>$row['action_key'],
            'status'=>$row['state'],'attempts'=>(int)$row['attempts'],
            'error_code'=>$row['error_code'],'created_utc'=>$row['created_utc'],
            'updated_utc'=>$row['updated_utc'],
        ];
    }
    public static function listing(int $viewerId,int $page=1,int $pageSize=20,
        string $state=''):array|\WP_Error
    {
        global $wpdb;
        if($viewerId<1||!user_can($viewerId,'manage_options')||!self::ready())
            return new \WP_Error('workflow_jobs_list_access_denied');
        if($state!==''&&!in_array($state,
            ['queued','running','succeeded','failed'],true))
            return new \WP_Error('workflow_jobs_state_invalid');
        $page=max(1,$page);
        $pageSize=max(1,min(100,$pageSize));
        $offset=($page-1)*$pageSize;
        $t=self::table();
        if($state!==''){
            $total=(int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $t WHERE state=%s",$state
            ));
            $rows=$wpdb->get_results($wpdb->prepare(
                "SELECT id,actor_id,action_key,state,attempts,error_code,created_utc,updated_utc
                 FROM $t WHERE state=%s ORDER BY id DESC LIMIT %d OFFSET %d",
                $state,$pageSize,$offset
            ),ARRAY_A);
        }else{
            $total=(int)$wpdb->get_var("SELECT COUNT(*) FROM $t");
            $rows=$wpdb->get_results($wpdb->prepare(
                "SELECT id,actor_id,action_key,state,attempts,error_code,created_utc,updated_utc
                 FROM $t ORDER BY id DESC LIMIT %d OFFSET %d",
                $pageSize,$offset
            ),ARRAY_A);
        }
        return [
            'items'=>array_map(static fn($row)=>[
                'job_id'=>(int)$row['id'],'action_key'=>$row['action_key'],
                'status'=>$row['state'],'attempts'=>(int)$row['attempts'],
                'error_code'=>$row['error_code'],
                'created_utc'=>$row['created_utc'],'updated_utc'=>$row['updated_utc']
            ],$rows),
            'pagination'=>[
                'page'=>$page,'page_size'=>$pageSize,
                'total'=>$total,'total_pages'=>(int)ceil($total/$pageSize)
            ]
        ];
    }

    /** Idempotent queue claim: only one process can switch queued -> running. */
    public static function process(int $id):array|\WP_Error
    {
        global $wpdb;
        if($id<1||!self::ready())return new \WP_Error('workflow_job_unavailable');
        $t=self::table();
        $now=gmdate('Y-m-d H:i:s');
        $claimed=$wpdb->query($wpdb->prepare(
            "UPDATE $t SET state='running', attempts=attempts+1, updated_utc=%s
             WHERE id=%d AND state='queued' AND attempts<3",$now,$id
        ));
        if($claimed!==1)return new \WP_Error('workflow_job_not_claimable');
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT actor_id,action_key,payload_json,payload_sha256,attempts FROM $t WHERE id=%d",$id
        ),ARRAY_A);
        if(!is_array($row))return new \WP_Error('workflow_job_state_missing');
        $actor=(int)$row['actor_id'];
        $payload=json_decode($row['payload_json'],true);
        $result=null;
        if(!is_array($payload) ||
            !hash_equals((string)$row['payload_sha256'],
                hash('sha256',$row['action_key'].':'.$row['payload_json'])))
            $result=new \WP_Error('workflow_job_payload_integrity_failed');
        elseif(!user_can($actor,'manage_options'))
            $result=new \WP_Error('workflow_job_actor_revoked');
        else {
            $previous=get_current_user_id();
            try{
                wp_set_current_user($actor);
                $result=WorkflowActions::execute($row['action_key'],$payload);
            }catch(\Throwable $e){
                $result=new \WP_Error('workflow_job_handler_exception');
            }finally{
                wp_set_current_user($previous);
            }
        }
        $failed=is_wp_error($result);
        $state=$failed?'failed':'succeeded';
        $error=$failed?$result->get_error_code():null;
        $safeResult=$failed?null:wp_json_encode([
            'status'=>$result['status']??'completed',
            'relation_id'=>(int)($result['relation_id']??0),
            'request_id'=>(int)($result['request_id']??0)
        ],JSON_UNESCAPED_SLASHES);
        $wpdb->query($wpdb->prepare(
            "UPDATE $t SET state=%s,error_code=%s,result_json=%s,updated_utc=%s
             WHERE id=%d AND state='running'",
            $state,$error,$safeResult,gmdate('Y-m-d H:i:s'),$id
        ));
        return $failed?new \WP_Error($error):[
            'job_id'=>$id,'status'=>'succeeded','outcome'=>$result['status']??'completed'
        ];
    }
    /** Dedicated QA cron can drain ONLY this plugin's validated jobs. */
    public static function drain(int $limit=10):array
    {
        global $wpdb;
        if(!self::ready())return ['processed'=>0,'succeeded'=>0,'failed'=>0];
        $t=self::table();
        $cutoff=gmdate('Y-m-d H:i:s',time()-900);
        // A killed worker can leave state=running. The only certified
        // background effect is a SQL-unique link, so retrying it is safe.
        $wpdb->query($wpdb->prepare(
            "UPDATE $t SET state='queued',error_code='workflow_worker_recovered'
             WHERE state='running' AND attempts<3 AND updated_utc<%s",
            $cutoff
        ));
        $wpdb->query($wpdb->prepare(
            "UPDATE $t SET state='failed',error_code='workflow_retry_limit_reached'
             WHERE state='running' AND attempts>=3 AND updated_utc<%s",
            $cutoff
        ));
        $ids=$wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $t WHERE state='queued' ORDER BY id ASC LIMIT %d",
            max(1,min($limit,50))
        ));
        $succeeded=0;$failed=0;
        foreach($ids as $id){
            $result=self::process((int)$id);
            if(is_wp_error($result))$failed++;
            else $succeeded++;
        }
        return ['processed'=>$succeeded+$failed,
            'succeeded'=>$succeeded,'failed_or_claimed_elsewhere'=>$failed];
    }
    public static function bootstrap():void
    {
        add_action('commerce_composition_process_workflow_job',
            [self::class,'process'],10,1);
    }
}
