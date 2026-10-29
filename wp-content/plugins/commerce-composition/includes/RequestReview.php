<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Nonfinancial operator decisions about an existing verified Request Registry.
 * Does NOT mutate source Forms, Woo orders, CRM, Boards or supplier bookings.
 */
final class RequestReview
{
    private static function states():string {
        global $wpdb;
        return $wpdb->prefix.'commerce_request_review_states';
    }
    private static function events():string {
        global $wpdb;
        return $wpdb->prefix.'commerce_request_review_events';
    }
    public static function install():true|\WP_Error
    {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $collation=$wpdb->get_charset_collate();
        $state=self::states();$event=self::events();
        dbDelta("CREATE TABLE $state (
            request_id bigint(20) unsigned NOT NULL,
            review_state varchar(30) NOT NULL,
            revision int(10) unsigned NOT NULL DEFAULT 0,
            actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
            updated_utc datetime NOT NULL,
            PRIMARY KEY  (request_id),
            KEY review_state (review_state)
        ) $collation;");
        dbDelta("CREATE TABLE $event (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            request_id bigint(20) unsigned NOT NULL,
            command_sha256 char(64) NOT NULL,
            payload_sha256 char(64) NOT NULL,
            from_state varchar(30) NOT NULL,
            to_state varchar(30) NOT NULL,
            actor_id bigint(20) unsigned NOT NULL,
            reason_code varchar(40) NOT NULL,
            revision int(10) unsigned NOT NULL,
            created_utc datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY command_key (command_sha256),
            UNIQUE KEY request_revision (request_id,revision)
        ) $collation;");
        return self::ready()?true:new \WP_Error('review_schema_unavailable');
    }
    public static function ready():bool
    {
        global $wpdb;
        if(!RequestCheckout::ready())return false;
        foreach([self::states(),self::events()] as $table)
            if($wpdb->get_var($wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
                $table))!=='InnoDB')return false;
        return true;
    }
    public static function enabled():bool
    {
        // WordPress scalars in wp_options are commonly reloaded as strings.
        // Only an explicit persisted 1/true may enable review; null/yes/other
        // values remain forbidden. Site policy filters receive a boolean.
        $persisted=get_option('commerce_composition_request_review_enabled',false);
        $approved=in_array($persisted,[true,1,'1'],true);
        return apply_filters('commerce_composition_enable_operator_request_review',
            $approved)===true;
    }
    public static function authorized():bool
    {
        // No blanket WP admin power for staff. Existing administrators keep
        // access; an independently granted, narrow capability allows review.
        return current_user_can('manage_options')
            || current_user_can('commerce_review_requests');
    }
    private static function source(int $requestId):array|\WP_Error
    {
        global $wpdb;
        $table=$wpdb->prefix.'commerce_request_batches';
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT id,state,pricing_state,sales_order_id,snapshot_json
             FROM $table WHERE id=%d",$requestId),ARRAY_A);
        if(!is_array($row)||$row['state']!=='pending_review'||
            $row['pricing_state']!=='not_quoted'||$row['sales_order_id']!==null)
            return new \WP_Error('review_unpriced_source_not_found');
        $items=json_decode((string)$row['snapshot_json'],true);
        if(!is_array($items)||!$items||count($items)>100)
            return new \WP_Error('review_source_invalid');
        foreach($items as $item)if(!is_array($item)||
            (int)($item['source_id']??0)<1||
            (int)($item['source_submission_id']??0)<1||
            (int)($item['form_id']??0)<1)
            return new \WP_Error('review_original_source_invalid');
        return ['request_id'=>$requestId,'item_count'=>count($items),
            'sources'=>array_map(static fn($item)=>[
                'source_id'=>(int)$item['source_id'],
                'form_id'=>(int)$item['form_id'],
                'submission_id'=>(int)$item['source_submission_id']
            ],$items)];
    }
    public static function read(int $requestId):array|\WP_Error
    {
        global $wpdb;
        if(!self::enabled()||!self::authorized()||!self::ready())
            return new \WP_Error('review_access_denied');
        $original=self::source($requestId);
        if(is_wp_error($original))return $original;
        $t=self::states();
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT review_state,revision,actor_id,updated_utc
             FROM $t WHERE request_id=%d",$requestId),ARRAY_A);
        return $original+[
            'review_state'=>$row['review_state']??'pending_review',
            'revision'=>(int)($row['revision']??0),
            'actor_id'=>(int)($row['actor_id']??0),
            'updated_utc'=>$row['updated_utc']??null,
            'supplier_booking_id'=>null,
            'woo_order_id'=>null,
            'commercial_price'=>null
        ];
    }
    /** Private bounded operator Inbox. No Form answers or contact PII. */
    public static function listing(int $page=1,int $pageSize=20):array|\WP_Error
    {
        global $wpdb;
        if(!self::enabled()||!self::authorized()||!self::ready())
            return new \WP_Error('review_access_denied');
        $page=max(1,$page);$pageSize=max(1,min(50,$pageSize));
        $requests=$wpdb->prefix.'commerce_request_batches';
        $states=self::states();
        $offset=($page-1)*$pageSize;
        $total=(int)$wpdb->get_var(
            "SELECT COUNT(*) FROM $requests WHERE state='pending_review'
             AND pricing_state='not_quoted' AND sales_order_id IS NULL");
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT r.id,COALESCE(s.review_state,'pending_review') review_state,
                COALESCE(s.revision,0) revision,r.created_utc
             FROM $requests r LEFT JOIN $states s ON r.id=s.request_id
             WHERE r.state='pending_review' AND r.pricing_state='not_quoted'
                AND r.sales_order_id IS NULL
             ORDER BY r.id DESC LIMIT %d OFFSET %d",$pageSize,$offset
        ),ARRAY_A);
        return ['items'=>array_map(static fn($row)=>[
            'request_id'=>(int)$row['id'],
            'review_state'=>$row['review_state'],
            'revision'=>(int)$row['revision'],
            'created_utc'=>$row['created_utc']
        ],$rows),'pagination'=>[
            'total'=>$total,'page'=>$page,'page_size'=>$pageSize
        ]];
    }

    /** Native review source of truth for every API and UI consumer. */
    public static function transitionsFor(string $state):array
    {
        $states=[
            'pending_review'=>['under_review','needs_information','rejected'],
            'under_review'=>['needs_information','accepted_for_processing','rejected'],
            'needs_information'=>['under_review','rejected'],
            'accepted_for_processing'=>[],
            'rejected'=>[]
        ];
        return $states[$state]??[];
    }
    public static function knownReviewStates():array
    {
        return ['pending_review','under_review','needs_information',
            'accepted_for_processing','rejected'];
    }
    public static function reasonCodes():array
    {
        return ['review_started','information_missing','source_unavailable',
            'customer_request','review_completed'];
    }

    public static function decide(int $requestId,string $next,int $expectedRevision,
        string $reason,string $intent):array|\WP_Error
    {
        global $wpdb;
        if(!self::enabled()||!self::authorized()||!self::ready())
            return new \WP_Error('review_access_denied');

        if($requestId<1||$expectedRevision<0||
            !in_array($next,self::knownReviewStates(),true)||
            !in_array($reason,self::reasonCodes(),true)||
            !preg_match('/^[A-Za-z0-9._:-]{16,128}$/D',$intent))
            return new \WP_Error('review_command_invalid');
        // Accepting or rejecting a request is a manager decision, NOT
        // the same authority as reviewing it. It still does not sell/book.
        if(in_array($next,['accepted_for_processing','rejected'],true)
            &&!current_user_can('manage_options')
            &&!current_user_can('commerce_approve_request_processing'))
            return new \WP_Error('review_manager_approval_required');
        $actor=get_current_user_id();
        $payload=hash('sha256',wp_json_encode([$requestId,$next,
            $expectedRevision,$reason],JSON_UNESCAPED_SLASHES));
        $dedupe=hash_hmac('sha256',$actor.':'.$intent,wp_salt('secure_auth'));
        if($wpdb->query('START TRANSACTION')===false)
            return new \WP_Error('review_transaction_unavailable');
        $committed=false;$result=null;
        try {
            $source=self::source($requestId);
            if(is_wp_error($source))return $source;
            if($next==='accepted_for_processing'){
                // Operator acceptance requires that every original stored
                // submission still exists under the right native Form.
                // It never grants supplier/financial authorization.
                $forms=$wpdb->prefix.'fluentform_submissions';
                foreach($source['sources'] as $entry){
                    $valid=SubmissionProvenance::verify(
                        $entry['submission_id'],$entry['form_id']);
                    if(is_wp_error($valid))return $valid;
                    $status=$wpdb->get_var($wpdb->prepare(
                        "SELECT status FROM $forms WHERE id=%d AND form_id=%d",
                        $entry['submission_id'],$entry['form_id']));
                    if(!is_string($status)||
                        in_array($status,['trashed','spam','deleted'],true))
                        return new \WP_Error('review_original_submission_inactive');
                }
            }
            $state=self::states();$events=self::events();
            $previous=$wpdb->get_row($wpdb->prepare(
                "SELECT payload_sha256,request_id,to_state,revision FROM $events
                 WHERE command_sha256=%s FOR UPDATE",$dedupe),ARRAY_A);
            if(is_array($previous)){
                if($previous['payload_sha256']!==$payload||
                    (int)$previous['request_id']!==$requestId)
                    return new \WP_Error('review_idempotency_conflict');
                if($wpdb->query('COMMIT')===false)
                    return new \WP_Error('review_commit_failed');
                $committed=true;
                return ['status'=>'already_applied','request_id'=>$requestId,
                    'review_state'=>$previous['to_state'],
                    'revision'=>(int)$previous['revision']];
            }
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO $state
                 (request_id,review_state,revision,actor_id,updated_utc)
                 VALUES (%d,%s,0,0,%s)",
                $requestId,'pending_review',gmdate('Y-m-d H:i:s')));
            $row=$wpdb->get_row($wpdb->prepare(
                "SELECT review_state,revision FROM $state
                 WHERE request_id=%d FOR UPDATE",$requestId),ARRAY_A);
            if(!is_array($row))return new \WP_Error('review_state_unavailable');
            $from=$row['review_state'];$rev=(int)$row['revision'];
            if($rev!==$expectedRevision)
                return new \WP_Error('review_revision_conflict');
            if(!in_array($next,self::transitionsFor($from),true))
                return new \WP_Error('review_transition_not_allowed');
            $revision=$rev+1;$now=gmdate('Y-m-d H:i:s');
            $changed=$wpdb->query($wpdb->prepare(
                "UPDATE $state SET review_state=%s,revision=%d,
                  actor_id=%d,updated_utc=%s WHERE request_id=%d
                  AND revision=%d AND review_state=%s",
                $next,$revision,$actor,$now,$requestId,$rev,$from));
            if($changed!==1)return new \WP_Error('review_atomic_update_failed');
            $insert=$wpdb->query($wpdb->prepare(
                "INSERT INTO $events
                 (request_id,command_sha256,payload_sha256,from_state,
                  to_state,actor_id,reason_code,revision,created_utc)
                 VALUES (%d,%s,%s,%s,%s,%d,%s,%d,%s)",
                $requestId,$dedupe,$payload,$from,$next,$actor,$reason,
                $revision,$now));
            if($insert!==1)return new \WP_Error('review_audit_failed');
            if($wpdb->query('COMMIT')===false)
                return new \WP_Error('review_commit_failed');
            $committed=true;
            $result=['status'=>'review_updated','request_id'=>$requestId,
                'review_state'=>$next,'revision'=>$revision,
                'supplier_authorized'=>false,'sale_authorized'=>false];
        }finally{
            if(!$committed)$wpdb->query('ROLLBACK');
        }
        if($result!==null)
            do_action('commerce_composition_request_review_transitioned',
                $result,['actor_id'=>$actor,'reason_code'=>$reason]);
        return $result??new \WP_Error('review_transition_failed');
    }
}
