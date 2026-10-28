<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Automatic/REST server intake of an ORIGINAL saved Fluent Forms submission.
 * No Woo customer session, Cart, WC_Order, financial amount or supplier booking.
 */
final class RequestIntake
{
    public static function create(int $formId,int $submissionId):array|\WP_Error
    {
        global $wpdb;
        if($formId<1||$submissionId<1||!RequestCheckout::ready()||
            !ClaimLedger::ready())
            return new \WP_Error('request_intake_prerequisite_missing');
        $verified=SubmissionProvenance::verify($submissionId,$formId);
        if(is_wp_error($verified))return $verified;
        $forms=$wpdb->prefix.'fluentform_submissions';
        $source=$wpdb->get_row($wpdb->prepare(
            "SELECT form_id,status,response FROM $forms WHERE id=%d",$submissionId
        ),ARRAY_A);
        if(!is_array($source)||((int)$source['form_id']!==$formId)||
            in_array($source['status'],['trashed','spam','deleted'],true))
            return new \WP_Error('request_intake_form_unavailable');
        $data=json_decode((string)$source['response'],true);
        if(!is_array($data)||count($data)>250)
            return new \WP_Error('request_intake_source_data_invalid');
        // Only uses form fields in memory to validate the original saved
        // choices against trusted source/Rate relationships. No PII is stored.
        $intent=FluentSubmissionAdapter::prepareSubmissionIntent(
            $submissionId,$data,(object)['id'=>$formId]);
        if(is_wp_error($intent))return $intent;
        if(($intent['mode']??'')!=='request_only'||
            ($intent['checkout_authority']??null)!==false||
            ($intent['cart_authority']??null)!==false)
            return new \WP_Error('request_intake_financial_contract_invalid');
        $components=[];
        foreach(($intent['composition']['components']??[]) as $child){
            if(!is_array($child))return new \WP_Error('request_intake_component_invalid');
            $components[]=[
                'source_type'=>is_string($child['source_type']??null)?$child['source_type']:'',
                'source_id'=>(int)($child['source_id']??0),
                'group'=>is_string($child['group']??null)?$child['group']:'',
                'role'=>is_string($child['role']??null)?$child['role']:''
            ];
        }
        $snapshot=[[
            'source_id'=>(int)$intent['source_parent_id'],
            'rate_id'=>isset($intent['source_rate_id'])?
                (int)$intent['source_rate_id']:null,
            'form_id'=>$formId,
            'source_submission_id'=>$submissionId,
            'source_digest'=>$intent['selection_digest'],
            'components'=>$components
        ]];
        $json=wp_json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if(!is_string($json)||strlen($json)>1024*1024)
            return new \WP_Error('request_intake_snapshot_invalid');

        $identity='original-form:v1:'.$formId.':'.$submissionId;
        $fingerprint=hash_hmac('sha256',$identity,wp_salt('secure_auth'));
        $ownerHash=hash_hmac('sha256','background:'.$identity,wp_salt('auth'));
        $registry=$wpdb->prefix.'commerce_request_batches';
        $claims=ClaimLedger::table();
        if($wpdb->query('START TRANSACTION')===false)
            return new \WP_Error('request_intake_transaction_unavailable');
        $committed=false;
        try{
            // This unique source claim conflicts with browser-session
            // ClaimLedger claims for exactly the SAME saved submission.
            $insert=$wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO $claims
                 (form_id,submission_id,selection_sha256,session_sha256,state,created_utc,updated_utc)
                 VALUES (%d,%d,%s,%s,%s,%s,%s)",
                $formId,$submissionId,$intent['selection_digest'],$ownerHash,
                'intake',gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')
            ));
            if($insert===false)return new \WP_Error('request_intake_claim_write_failed');
            $claim=$wpdb->get_row($wpdb->prepare(
                "SELECT selection_sha256,session_sha256,state FROM $claims
                 WHERE form_id=%d AND submission_id=%d FOR UPDATE",
                $formId,$submissionId
            ),ARRAY_A);
            if(!is_array($claim)||
                !hash_equals((string)$claim['selection_sha256'],$intent['selection_digest'])||
                !hash_equals((string)$claim['session_sha256'],$ownerHash)||
                $claim['state']!=='intake')
                return new \WP_Error('request_intake_existing_guest_or_changed_claim');
            $new=$wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO $registry
                (basket_sha256,session_sha256,sales_order_id,state,pricing_state,snapshot_json,created_utc)
                VALUES (%s,%s,NULL,%s,%s,%s,%s)",
                $fingerprint,$ownerHash,'pending_review','not_quoted',
                $json,gmdate('Y-m-d H:i:s')
            ));
            if($new===false)return new \WP_Error('request_intake_registry_write_failed');
            $row=$wpdb->get_row($wpdb->prepare(
                "SELECT id,session_sha256,pricing_state,sales_order_id,state,snapshot_json
                 FROM $registry WHERE basket_sha256=%s FOR UPDATE",$fingerprint
            ),ARRAY_A);
            if(!is_array($row)||$row['state']!=='pending_review'||
                $row['pricing_state']!=='not_quoted'||
                $row['sales_order_id']!==null||
                !hash_equals((string)$row['session_sha256'],$ownerHash)||
                !hash_equals((string)$row['snapshot_json'],$json))
                return new \WP_Error('request_intake_registry_conflict');
            $id=(int)$row['id'];
            if($id<1||$wpdb->query('COMMIT')===false)
                return new \WP_Error('request_intake_commit_failed');
            $committed=true;
            $result=[
                'status'=>$new===1?'request_submitted':'already_submitted',
                'request_id'=>$id,'line_count'=>1,
                'pricing_state'=>'not_quoted',
                'sales_order_id'=>null,'checkout_authority'=>false,
                'intake_channel'=>'server_verified_form'
            ];
            if($new===1)
                do_action('commerce_composition_request_submitted',$result,$snapshot);
            return $result;
        }finally{
            if(!$committed)$wpdb->query('ROLLBACK');
        }
    }
}
