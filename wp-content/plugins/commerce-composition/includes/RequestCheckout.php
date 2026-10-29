<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Accepts unpriced booking REQUESTS into an independent registry.
 *
 * This intentionally never creates a WC_Order or writes to HPOS. A request
 * with unknown price has NO amount field; it cannot be interpreted as a
 * zero-price transaction. Products, suppliers, classifications and Rates
 * remain source-owned. Conversion to commercial WooCommerce is a later,
 * separately certified transition, never a side effect of a request.
 */
final class RequestCheckout
{
    private static function table():string
    {
        global $wpdb;
        return $wpdb->prefix.'commerce_request_batches';
    }

    /**
     * Upgrade a preexisting local nonfinancial HPOS batch schema in place.
     * Refuse automated conversion of legacy rows; they require owner review.
     * This migration DOES NOT touch any Woo order or booking post.
     */
    public static function install():true|\WP_Error
    {
        global $wpdb;
        $table=self::table();
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))
            return new \WP_Error('request_registry_table_invalid');
        $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))===$table;
        if($exists) {
            $columns=$wpdb->get_col("SHOW COLUMNS FROM $table",0);
            if(in_array('order_id',$columns,true)){
                $rows=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table");
                if($rows!==0)
                    return new \WP_Error('request_registry_legacy_rows_require_owner_review');
                // Safe here: earlier QA test orders were all deleted and batch is empty.
                $changed=$wpdb->query("ALTER TABLE $table CHANGE COLUMN order_id sales_order_id bigint(20) unsigned NULL DEFAULT NULL");
                if($changed===false)
                    return new \WP_Error('request_registry_legacy_column_upgrade_failed');
            }
        }
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $schema="CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            basket_sha256 char(64) NOT NULL,
            session_sha256 char(64) NOT NULL,
            sales_order_id bigint(20) unsigned NULL DEFAULT NULL,
            state varchar(24) NOT NULL,
            pricing_state varchar(24) NOT NULL,
            snapshot_json longtext NULL,
            created_utc datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY basket_identity (basket_sha256),
            KEY current_state (state)
        ) {$wpdb->get_charset_collate()};";
        dbDelta($schema);
        return self::ready()?true:new \WP_Error('request_registry_schema_not_ready');
    }

    public static function ready():bool
    {
        global $wpdb;
        $table=self::table();
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))
            return false;
        $engine=$wpdb->get_var($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
            $table
        ));
        if($engine!=='InnoDB')return false;
        $columns=$wpdb->get_col("SHOW COLUMNS FROM $table",0);
        foreach(['id','basket_sha256','session_sha256','sales_order_id',
            'state','pricing_state','snapshot_json','created_utc'] as $column)
            if(!in_array($column,$columns,true))return false;
        $nullable=$wpdb->get_row("SHOW COLUMNS FROM $table LIKE 'sales_order_id'",ARRAY_A);
        return ($nullable['Null']??'')==='YES' &&
            !in_array('order_id',$columns,true);
    }

    /** Return a registry record only for the current owning Woo session. */
    public static function find(int $requestId):array|\WP_Error
    {
        global $wpdb;
        if($requestId<1||!self::ready())
            return new \WP_Error('request_registry_unavailable');
        $session=WC()->session??null;
        if(!$session||!$session->has_session())
            return new \WP_Error('request_session_missing');
        $hash=hash_hmac('sha256',(string)$session->get_customer_id(),wp_salt('auth'));
        $table=self::table();
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT id,state,pricing_state,sales_order_id,snapshot_json,session_sha256
              FROM $table WHERE id=%d",$requestId),ARRAY_A);
        if(!is_array($row)||!hash_equals((string)$row['session_sha256'],$hash))
            return new \WP_Error('request_registry_not_owned');
        return [
            'request_id'=>(int)$row['id'],
            'status'=>$row['state'],
            'pricing_state'=>$row['pricing_state'],
            'sales_order_id'=>$row['sales_order_id']===null?null:(int)$row['sales_order_id'],
            'sources'=>json_decode((string)$row['snapshot_json'],true)??[],
        ];
    }

    /** Unpriced requests never directly promote to sale. */
    public static function promoteToSale(int $requestId,array $certification=[]):\WP_Error
    {
        return new \WP_Error('certified_sale_transition_not_configured');
    }

    /**
     * Verify claimed Fluent requests and persist only new source lines.
     * The original session claim and registry row commit in ONE transaction.
     * This method has no WooCommerce order API or monetary side effects.
     */
    public static function commit():array|\WP_Error
    {
        global $wpdb;
        if(!function_exists('WC')||!WC()->session||!WC()->session->has_session())
            return new \WP_Error('request_submission_session_missing');
        if(!ClaimLedger::ready()||!self::ready())
            return new \WP_Error('request_registry_schema_unavailable');
        $session=WC()->session;
        $sid=(string)$session->get_customer_id();
        if($sid==='')return new \WP_Error('request_submission_session_missing');
        $sessionHash=hash_hmac('sha256',$sid,wp_salt('auth'));
        $all=RequestBasket::entries();
        if(is_wp_error($all))return $all;
        if(!$all||count($all)>100)
            return new \WP_Error('request_submission_empty_or_oversized');
        $pending=array_filter($all,static fn($entry)=>
            !isset($entry['submitted_request_id'])
        );
        if(!$pending){
            $last=0;
            foreach($all as $entry){
                $id=$entry['submitted_request_id']??null;
                if(!is_int($id)||$id<1)
                    return new \WP_Error('request_submission_previous_reference_invalid');
                $last=max($last,$id);
            }
            $previous=self::find($last);
            if(is_wp_error($previous)||$previous['status']!=='pending_review'||
                $previous['pricing_state']!=='not_quoted' ||
                $previous['sales_order_id']!==null)
                return new \WP_Error('request_submission_previous_reference_unverified');
            return [
                'status'=>'already_submitted','request_id'=>$last,'line_count'=>0,
                'pricing_state'=>'not_quoted','sales_order_id'=>null,
                'checkout_authority'=>false
            ];
        }
        ksort($pending,SORT_STRING);
        $digestParts=[];$snapshot=[];
        foreach($pending as $entry){
            if(($entry['mode']??null)!=='request_only'||
                ($entry['checkout_authority']??null)!==false ||
                ($entry['price_authority']??null)!==false ||
                ($entry['native_woocommerce_cart_line']??null)!==false ||
                !is_int($entry['form_id']??null) ||
                !is_int($entry['submission_id']??null) ||
                !is_string($entry['selection_digest']??null) ||
                !preg_match('/^[a-f0-9]{64}$/D',$entry['selection_digest']))
                return new \WP_Error('request_submission_has_untrusted_financial_state');
            $digestParts[]=[
                'form'=>$entry['form_id'],
                'submission'=>$entry['submission_id'],
                'digest'=>$entry['selection_digest'],
            ];
            $components=[];
            foreach(($entry['composition']['components']??[]) as $component){
                if(!is_array($component))return new \WP_Error('request_component_invalid');
                $components[]=[
                    'source_type'=>is_string($component['source_type']??null)
                        ?$component['source_type']:'',
                    'source_id'=>(int)($component['source_id']??0),
                    'group'=>is_string($component['group']??null)?$component['group']:'',
                    'role'=>is_string($component['role']??null)?$component['role']:'',
                ];
            }
            $snapshot[]=[
                'source_id'=>(int)($entry['parent_id']??0),
                'rate_id'=>isset($entry['rate_id'])?(int)$entry['rate_id']:null,
                'form_id'=>$entry['form_id'],
                'source_submission_id'=>$entry['submission_id'],
                'source_digest'=>$entry['selection_digest'],
                'components'=>$components,
            ];
        }
        $fingerprint=hash_hmac('sha256',
            wp_json_encode([$sessionHash,$digestParts],JSON_UNESCAPED_SLASHES),
            wp_salt('secure_auth'));
        $batch=self::table();
        $claims=ClaimLedger::table();
        $sessions=$wpdb->prefix.'woocommerce_sessions';
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$sessions))
            return new \WP_Error('request_session_table_invalid');

        if($wpdb->query('START TRANSACTION')===false)
            return new \WP_Error('request_submission_transaction_unavailable');
        $committed=false;
        try {
            $raw=$wpdb->get_var($wpdb->prepare(
                "SELECT session_value FROM $sessions WHERE session_key=%s FOR UPDATE",$sid
            ));
            $values=is_string($raw)?maybe_unserialize($raw):null;
            $databaseBasket=is_array($values)?maybe_unserialize(
                $values['commerce_composition_request_basket_v1']??null
            ):null;
            if(!is_array($databaseBasket)||$databaseBasket!==$all)
                return new \WP_Error('request_submission_session_changed_retry');
            if(maybe_unserialize($values['cart']??[])!==$session->get('cart',[]))
                return new \WP_Error('request_submission_commercial_cart_changed_retry');

            foreach($pending as $entry){
                $claim=$wpdb->get_row($wpdb->prepare(
                    "SELECT state,session_sha256,selection_sha256 FROM $claims
                     WHERE form_id=%d AND submission_id=%d FOR UPDATE",
                    $entry['form_id'],$entry['submission_id']),ARRAY_A);
                if(!is_array($claim)||$claim['state']!=='staged'||
                    !hash_equals((string)$claim['session_sha256'],$sessionHash)||
                    !hash_equals((string)$claim['selection_sha256'],$entry['selection_digest']))
                    return new \WP_Error('request_submission_source_claim_unverified');
            }
            $now=gmdate('Y-m-d H:i:s');
            $json=wp_json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            if(!is_string($json)||strlen($json)>1024*1024)
                return new \WP_Error('request_submission_snapshot_too_large');
            $created=$wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO $batch
                  (basket_sha256,session_sha256,sales_order_id,state,pricing_state,snapshot_json,created_utc)
                 VALUES (%s,%s,NULL,%s,%s,%s,%s)",
                $fingerprint,$sessionHash,'pending_review','not_quoted',$json,$now
            ));
            if($created!==1){
                if($created===false)return new \WP_Error('request_submission_registry_write_failed');
                $existing=$wpdb->get_row($wpdb->prepare(
                    "SELECT id,state,pricing_state,sales_order_id,session_sha256,snapshot_json
                      FROM $batch WHERE basket_sha256=%s FOR UPDATE",
                    $fingerprint),ARRAY_A);
                if(!is_array($existing)||$existing['state']!=='pending_review'||
                    $existing['pricing_state']!=='not_quoted' ||
                    $existing['sales_order_id']!==null ||
                    !hash_equals($existing['session_sha256'],$sessionHash)||
                    !hash_equals((string)$existing['snapshot_json'],$json))
                    return new \WP_Error('request_submission_registry_conflict');
                $id=(int)$existing['id'];
            } else {
                $id=(int)$wpdb->insert_id;
            }
            if($id<1)return new \WP_Error('request_submission_registry_id_missing');
            $updated=$all;
            foreach($pending as $key=>$entry){
                $updated[$key]['submitted_request_id']=$id;
                $updated[$key]['submission_state']='pending_review';
            }
            $session->set('commerce_composition_request_basket_v1',$updated);
            $session->save_data();
            $stored=$wpdb->get_var($wpdb->prepare(
                "SELECT session_value FROM $sessions WHERE session_key=%s FOR UPDATE",$sid
            ));
            $storedValues=is_string($stored)?maybe_unserialize($stored):null;
            $read=is_array($storedValues)?maybe_unserialize(
                $storedValues['commerce_composition_request_basket_v1']??null
            ):null;
            if($read!==$updated)
                return new \WP_Error('request_submission_session_readback_failed');
            if($wpdb->query('COMMIT')===false)
                return new \WP_Error('request_submission_commit_failed');
            $committed=true;
            $outcome=[
                'status'=>$created===1?'request_submitted':'already_submitted',
                'request_id'=>$id,'line_count'=>count($pending),
                'pricing_state'=>'not_quoted','sales_order_id'=>null,
                'checkout_authority'=>false,
            ];
            if($created===1) {
                // The relationship automation runs ONLY after the original
                // request transaction has committed to durable storage.
                do_action('commerce_composition_request_submitted',$outcome,$snapshot);
            }
            return $outcome;
        }finally{
            if(!$committed)$wpdb->query('ROLLBACK');
        }
    }
}
