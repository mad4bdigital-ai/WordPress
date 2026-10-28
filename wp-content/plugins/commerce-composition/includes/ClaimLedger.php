<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Durable, cross-session uniqueness for a stored submission -> request basket line.
 * Checkout/payment/supplier fulfillment is never authorized here.
 *
 * Uses a unique SQL index on (form_id, submission_id). A transaction surrounds
 * claim creation, session update and completion. On failures it rolls back.
 */
final class ClaimLedger
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'commerce_request_claims';
    }
    public static function install(): true|\WP_Error
    {
        global $wpdb;
        $table=self::table();
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))
            return new \WP_Error('claim_table_identifier_invalid');
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $sql="CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            form_id bigint(20) unsigned NOT NULL,
            submission_id bigint(20) unsigned NOT NULL,
            selection_sha256 char(64) NOT NULL,
            session_sha256 char(64) NOT NULL,
            state varchar(24) NOT NULL,
            created_utc datetime NOT NULL,
            updated_utc datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY submission_identity (form_id,submission_id),
            KEY session_lookup (session_sha256)
        ) {$wpdb->get_charset_collate()};";
        dbDelta($sql);
        $found=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table));
        if($found!==$table)return new \WP_Error('claim_table_install_failed');
        $engine=$wpdb->get_var($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            $table
        ));
        if($engine!=='InnoDB')return new \WP_Error('claim_requires_transactional_database');
        return true;
    }
    public static function ready(): bool
    {
        global $wpdb;
        $table=self::table();
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))return false;
        $engine=$wpdb->get_var($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            $table
        ));
        if($engine!=='InnoDB')return false;
        $sessionTable=$wpdb->prefix.'woocommerce_sessions';
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$sessionTable))return false;
        return $wpdb->get_var($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            $sessionTable
        ))==='InnoDB';
    }
    public static function stage(array $intent): array|\WP_Error
    {
        global $wpdb;
        if(!self::ready())return new \WP_Error('request_global_claim_ledger_not_installed');
        if(!isset($intent['form_id'],$intent['source_submission_id'],$intent['selection_digest']) ||
           !is_int($intent['form_id']) || $intent['form_id']<1 ||
           !is_int($intent['source_submission_id']) || $intent['source_submission_id']<1 ||
           !is_string($intent['selection_digest']) ||
           !preg_match('/^[a-f0-9]{64}$/D',$intent['selection_digest']))
            return new \WP_Error('request_claim_identity_invalid');
        if(!function_exists('WC') || !WC()->session)
            return new \WP_Error('request_session_unavailable');
        $session=WC()->session;
        if(!$session->has_session()){
            if(headers_sent())return new \WP_Error('request_guest_cookie_headers_sent');
            $session->set_customer_session_cookie(true);
        }
        if(!$session->has_session())return new \WP_Error('request_guest_cookie_unavailable');

        $customerId=(string)$session->get_customer_id();
        if($customerId==='')return new \WP_Error('request_customer_session_missing');
        $sessionHash=hash_hmac('sha256',$customerId,wp_salt('auth'));
        $formId=$intent['form_id'];
        $submissionId=$intent['source_submission_id'];
        $digest=$intent['selection_digest'];
        $table=self::table();
        $now=gmdate('Y-m-d H:i:s');
        $opened=$wpdb->query('START TRANSACTION');
        if($opened===false)return new \WP_Error('request_claim_transaction_not_started');
        $committed=false;
        try {
            $insert=$wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO $table (form_id,submission_id,selection_sha256,session_sha256,state,created_utc,updated_utc)
                 VALUES (%d,%d,%s,%s,%s,%s,%s)",
                $formId,$submissionId,$digest,$sessionHash,'pending',$now,$now
            ));
            if($insert===false) return new \WP_Error('request_claim_insert_failed');
            if($insert===0){
                $saved=$wpdb->get_row($wpdb->prepare(
                    "SELECT selection_sha256,session_sha256,state FROM $table WHERE form_id=%d AND submission_id=%d FOR UPDATE",
                    $formId,$submissionId),ARRAY_A);
                if(!is_array($saved))return new \WP_Error('request_claim_existing_unreadable');
                if(!hash_equals((string)$saved['selection_sha256'],$digest))
                    return new \WP_Error('request_claim_payload_conflict');
                if(!hash_equals((string)$saved['session_sha256'],$sessionHash))
                    return new \WP_Error('request_claim_owned_by_other_session');
                if($saved['state']!=='staged')
                    return new \WP_Error('request_claim_pending_recovery_required');
                $entries=RequestBasket::entries();
                $key=$formId.':'.$submissionId;
                if(is_wp_error($entries) || !isset($entries[$key]) ||
                    !hash_equals($entries[$key]['selection_digest'],$digest))
                    return new \WP_Error('request_claim_session_record_missing');
                // A memory/cache hit is not proof of a durable WooCommerce
                // session. Verify the SQL row on the duplicate path too.
                $sessionTable=$wpdb->prefix.'woocommerce_sessions';
                if(!preg_match('/^[A-Za-z0-9_]+$/D',$sessionTable))
                    return new \WP_Error('request_claim_session_table_invalid');
                $raw=$wpdb->get_var($wpdb->prepare(
                    "SELECT session_value FROM $sessionTable WHERE session_key=%s FOR UPDATE",
                    $customerId
                ));
                $state=is_string($raw)?maybe_unserialize($raw):null;
                $dbBasket=is_array($state)?maybe_unserialize(
                    $state['commerce_composition_request_basket_v1']??null
                ):null;
                if(!is_array($dbBasket)||!isset($dbBasket[$key]) ||
                    !hash_equals((string)($dbBasket[$key]['selection_digest']??''),$digest))
                    return new \WP_Error('request_claim_durable_session_record_missing');
                $commit=$wpdb->query('COMMIT');
                if($commit===false)return new \WP_Error('request_claim_commit_failed');
                $committed=true;
                return ['status'=>'already_present','key'=>$key,
                    'basket_count'=>count($entries),'checkout_authority'=>false];
            }
            $sessionTable=$wpdb->prefix.'woocommerce_sessions';
            if(!preg_match('/^[A-Za-z0-9_]+$/D',$sessionTable))
                return new \WP_Error('request_claim_session_table_invalid');

            // Lock the Woo session record BEFORE reading its request-basket state.
            // Another concurrent request for a different submission must not
            // silently replace an already persisted parent request.
            $create=$wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO $sessionTable (session_key,session_value,session_expiry) VALUES (%s,%s,%d)",
                $customerId,maybe_serialize([]),time()+2*DAY_IN_SECONDS
            ));
            if($create===false)return new \WP_Error('request_claim_session_slot_unavailable');
            $rawBefore=$wpdb->get_var($wpdb->prepare(
                "SELECT session_value FROM $sessionTable WHERE session_key=%s FOR UPDATE",
                $customerId
            ));
            $sessionValues=is_string($rawBefore)?maybe_unserialize($rawBefore):null;
            if(!is_array($sessionValues))
                return new \WP_Error('request_claim_session_state_corrupt');
            $dbBasket=maybe_unserialize(
                $sessionValues['commerce_composition_request_basket_v1']??[]
            );
            $current=RequestBasket::entries();
            if(!is_array($dbBasket)||is_wp_error($current) ||
                $dbBasket!==$current)
                return new \WP_Error('request_claim_concurrent_session_change_retry');
            // The commercial Woo cart is also session state. Refuse to clobber
            // a newer commercial cart loaded by another simultaneous request.
            $dbCart=maybe_unserialize($sessionValues['cart']??[]);
            $memoryCart=WC()->session->get('cart',[]);
            if($dbCart!==$memoryCart)
                return new \WP_Error('request_claim_concurrent_cart_change_retry');

            $stage=RequestBasket::stage($intent);
            if(is_wp_error($stage))return $stage;
            if(($stage['status']??null)!=='staged_request')
                return new \WP_Error('request_claim_session_state_unexpected');

            $raw=$wpdb->get_var($wpdb->prepare(
                "SELECT session_value FROM $sessionTable WHERE session_key=%s FOR UPDATE",
                $customerId
            ));
            $stored=is_string($raw)?maybe_unserialize($raw):null;
            $basket=is_array($stored)?maybe_unserialize($stored['commerce_composition_request_basket_v1']??null):null;
            $key=$formId.':'.$submissionId;
            if(!is_array($basket)||!isset($basket[$key])||
                !hash_equals($basket[$key]['selection_digest'],$digest))
                return new \WP_Error('request_claim_session_sql_readback_failed');

            $updated=$wpdb->query($wpdb->prepare(
                "UPDATE $table SET state=%s,updated_utc=%s
                 WHERE form_id=%d AND submission_id=%d AND state=%s",
                'staged',$now,$formId,$submissionId,'pending'
            ));
            if($updated!==1)return new \WP_Error('request_claim_final_status_failed');
            $commit=$wpdb->query('COMMIT');
            if($commit===false)return new \WP_Error('request_claim_commit_failed');
            $committed=true;
            return $stage+['global_claim'=>'staged'];
        }finally{
            if(!$committed) {
                $wpdb->query('ROLLBACK');
                // On a rollback the session object/cache may contain the
                // attempted line. Do not report it as successfully committed.
                if(defined('WC_SESSION_CACHE_GROUP')&&class_exists('WC_Cache_Helper'))
                    wp_cache_delete(
                        \WC_Cache_Helper::get_cache_prefix(WC_SESSION_CACHE_GROUP).$customerId,
                        WC_SESSION_CACHE_GROUP
                    );
            }
        }
    }
}
