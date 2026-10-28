<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Case identity for verified intake. Does not impersonate a FluentCRM deal
 * or create a Woo Order. Its only writer stores a stable, source-owned link.
 */
final class IntakeCaseRegistry
{
    private static function table():string
    {
        global $wpdb;
        return $wpdb->prefix.'commerce_intake_cases';
    }

    public static function install():true|\WP_Error
    {
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $t=self::table();
        dbDelta("CREATE TABLE $t (
            request_id bigint(20) unsigned NOT NULL,
            source_sha256 char(64) NOT NULL,
            policy_sha256 char(64) NOT NULL,
            route_mode varchar(20) NOT NULL,
            source_channel varchar(48) NOT NULL,
            contact_id bigint(20) unsigned NOT NULL,
            woo_order_id bigint(20) unsigned NULL DEFAULT NULL,
            stage varchar(42) NOT NULL,
            actor_id bigint(20) unsigned NOT NULL,
            created_utc datetime NOT NULL,
            PRIMARY KEY  (request_id),
            KEY contact_lookup (contact_id,stage)
        ) {$wpdb->get_charset_collate()};");
        return self::ready()?true:new \WP_Error('intake_cases_schema_unavailable');
    }

    public static function ready():bool
    {
        global $wpdb;
        $t=self::table();
        return $wpdb->get_var($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            $t))==='InnoDB';
    }

    public static function enabled():bool
    {
        return in_array(get_option('commerce_intake_case_registry_enabled',false),
            [true,1,'1'],true);
    }

    public static function preview(int $requestId):array|\WP_Error
    {
        global $wpdb;
        if(!current_user_can('manage_options')||!RequestReview::enabled())
            return new \WP_Error('intake_case_access_denied');
        $review=RequestReview::read($requestId);
        if(is_wp_error($review))return $review;
        if(in_array($review['review_state'],['rejected'],true))
            return new \WP_Error('intake_case_original_rejected');
        $source=$wpdb->prefix.'commerce_request_batches';
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT snapshot_json,state,pricing_state,sales_order_id FROM $source WHERE id=%d",
            $requestId),ARRAY_A);
        if(!is_array($row)||$row['state']!=='pending_review'||
            $row['pricing_state']!=='not_quoted'||$row['sales_order_id']!==null)
            return new \WP_Error('intake_case_source_not_independent');
        $items=json_decode((string)$row['snapshot_json'],true);
        if(!is_array($items)||count($items)!==$review['item_count'])
            return new \WP_Error('intake_case_source_invalid');
        $types=[];
        foreach($items as $item) {
            if(!is_array($item))return new \WP_Error('intake_case_source_invalid');
            $sourceId=(int)($item['source_id']??0);
            $post=get_post($sourceId);
            if(!$post||in_array($post->post_status,['trash','auto-draft','inherit'],true))
                return new \WP_Error('intake_case_service_missing');
            $types[]=$post->post_type;
        }
        // Existing WC-session Request Registry has a known web intake.
        // Other channel adapters must establish their own authenticated
        // provenance, not trust a caller-provided channel string.
        $route=IntakeModePolicy::resolve('web',$types);
        if(is_wp_error($route))return $route;
        $identity=RequestContactEvidence::preview($requestId);
        if(is_wp_error($identity))return $identity;
        return [
            'request_id'=>$requestId,
            'source_sha256'=>hash('sha256',(string)$row['snapshot_json']),
            'policy_sha256'=>$route['policy_digest'],
            'route_mode'=>$route['mode'],
            'source_channel'=>'web',
            'contact_id'=>(int)$identity['existing_contact_id'],
            'can_create_case'=>$route['mode']==='crm_first',
            'order_first_blocked'=>$route['mode']==='order_first',
            'woo_order_id'=>null,
            'supplier_booking_authorized'=>false
        ];
    }

    public static function read(int $requestId):array|\WP_Error
    {
        global $wpdb;
        if(!current_user_can('manage_options')||!self::ready())
            return new \WP_Error('intake_case_access_denied');
        $t=self::table();
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT request_id,route_mode,source_channel,contact_id,woo_order_id,stage,policy_sha256
              FROM $t WHERE request_id=%d",$requestId),ARRAY_A);
        if(!$row)return new \WP_Error('intake_case_not_found');
        return [
            'request_id'=>(int)$row['request_id'],
            'route_mode'=>$row['route_mode'],
            'source_channel'=>$row['source_channel'],
            'contact_id'=>(int)$row['contact_id'],
            'woo_order_id'=>$row['woo_order_id']===null?null:(int)$row['woo_order_id'],
            'stage'=>$row['stage'],
            'policy_sha256'=>$row['policy_sha256'],
            'crm_native_task_created'=>false,
            'marketing_subscription_changed'=>false
        ];
    }

    public static function ensure(int $requestId):array|\WP_Error
    {
        global $wpdb;
        if(!self::enabled()||!IntakeModePolicy::enabled())
            return new \WP_Error('intake_case_creation_disabled');
        if(!current_user_can('manage_options')||!self::ready())
            return new \WP_Error('intake_case_access_denied');
        $plan=self::preview($requestId);
        if(is_wp_error($plan))return $plan;
        if($plan['route_mode']!=='crm_first')
            return new \WP_Error('intake_case_order_first_factory_not_certified');
        $t=self::table();
        $now=gmdate('Y-m-d H:i:s');
        // One atomic, unique-key claim. A duplicate read must compare the
        // exact immutable customer/source/policy, not just return any case.
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $t
             (request_id,source_sha256,policy_sha256,route_mode,source_channel,
              contact_id,woo_order_id,stage,actor_id,created_utc)
             VALUES (%d,%s,%s,%s,%s,%d,NULL,%s,%d,%s)",
            $requestId,$plan['source_sha256'],$plan['policy_sha256'],
            'crm_first','web',$plan['contact_id'],
            'awaiting_approved_business_board',get_current_user_id(),$now
        ));
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT source_sha256,policy_sha256,route_mode,contact_id,woo_order_id
             FROM $t WHERE request_id=%d",$requestId),ARRAY_A);
        if(!$row)return new \WP_Error('intake_case_insert_failed');
        if(!hash_equals((string)$row['source_sha256'],$plan['source_sha256'])||
            !hash_equals((string)$row['policy_sha256'],$plan['policy_sha256'])||
            $row['route_mode']!=='crm_first'||
            (int)$row['contact_id']!==$plan['contact_id']||
            $row['woo_order_id']!==null)
            return new \WP_Error('intake_case_original_or_policy_conflict');
        return self::read($requestId);
    }
}
