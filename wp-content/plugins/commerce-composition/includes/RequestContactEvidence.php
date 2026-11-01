<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Projects an already-proven original Fluent Forms contact reference onto
 * an unpriced Request. This is NOT an email lookup, CRM upsert or opt-in.
 */
final class RequestContactEvidence
{
    public static function enabled():bool
    {
        $setting=get_option('commerce_request_contact_projection_enabled',false);
        $approved=in_array($setting,[true,1,'1'],true);
        return apply_filters('commerce_composition_request_contact_projection_ready',
            $approved)===true;
    }

    public static function preview(int $requestId):array|\WP_Error
    {
        global $wpdb;
        if(!current_user_can('manage_options')||!ContactBridge::ready())
            return new \WP_Error('request_contact_access_denied');
        $request=RequestReview::read($requestId);
        if(is_wp_error($request))return $request;
        $configured=ContactBridge::sources();
        $target=[];
        foreach($configured as $key=>$item)
            if(($item['domain']??null)==='request')$target[]=$key;
        if(count($target)!==1)
            return new \WP_Error('request_contact_request_source_ambiguous');
        $identity=null;$sources=[];
        $links=$wpdb->prefix.'commerce_contact_links';
        $entries=$wpdb->prefix.'fluentform_submissions';
        foreach($request['sources'] as $source) {
            $formId=(int)$source['form_id'];
            $submissionId=(int)$source['submission_id'];
            $verified=SubmissionProvenance::verify($submissionId,$formId);
            if(is_wp_error($verified))return $verified;
            $current=$wpdb->get_var($wpdb->prepare(
                "SELECT status FROM $entries WHERE id=%d AND form_id=%d",
                $submissionId,$formId));
            if(!is_string($current)||
                in_array($current,['trashed','spam','deleted'],true))
                return new \WP_Error('request_contact_original_form_inactive');
            $keys=[];
            foreach($configured as $key=>$item)
                if(($item['domain']??null)==='fluent_form' &&
                    in_array($formId,array_map('intval',
                        (array)($item['form_ids']??[])),true))
                    $keys[]=$key;
            if(count($keys)!==1)
                return new \WP_Error('request_contact_form_source_ambiguous');
            $ids=$wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT contact_id FROM $links
                 WHERE domain='fluent_form' AND source_key=%s
                   AND source_id=%d AND role='requester' LIMIT 3",
                $keys[0],$submissionId));
            if(count($ids)!==1)
                return new \WP_Error('request_contact_original_identity_unverified');
            $id=(int)$ids[0];
            if($identity!==null&&$identity!==$id)
                return new \WP_Error('request_contact_mixed_customer_identity');
            $identity=$id;
            $sources[$formId.':'.$submissionId]=[
                'form_id'=>$formId,'submission_id'=>$submissionId
            ];
        }
        if($identity===null||!$sources)
            return new \WP_Error('request_contact_no_identity');
        $contacts=$wpdb->prefix.'fc_subscribers';
        $row=$wpdb->get_row($wpdb->prepare(
            "SELECT id,status FROM $contacts WHERE id=%d",$identity),ARRAY_A);
        if(!is_array($row)||!in_array($row['status'],
            ['pending','subscribed','transactional','unsubscribed','bounced',
             'complained','spammed','suppressed'],true))
            return new \WP_Error('request_contact_existing_contact_invalid');
        $prior=$wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT contact_id FROM $links
             WHERE domain='request' AND source_key=%s
               AND source_id=%d AND role='requester' LIMIT 3",
            $target[0],$requestId));
        if(count($prior)>1||(count($prior)===1&&(int)$prior[0]!==$identity))
            return new \WP_Error('request_contact_existing_requester_conflict');
        return [
            'request_id'=>$requestId,
            'existing_contact_id'=>$identity,
            'contact_status'=>$row['status'],
            'original_source_count'=>count($sources),
            'request_source_key'=>$target[0],
            'already_linked'=>count($prior)===1,
            'marketing_subscription_changed'=>false
        ];
    }

    public static function ensure(int $requestId):array|\WP_Error
    {
        if(!self::enabled())return new \WP_Error('request_contact_projection_disabled');
        $source=self::preview($requestId);
        if(is_wp_error($source))return $source;
        // ContactBridge remains the sole writer of contact/source references.
        // Re-read original source identity immediately before linking.
        $result=ContactBridge::link(
            (int)$source['existing_contact_id'],
            (string)$source['request_source_key'],
            $requestId,'requester'
        );
        if(is_wp_error($result))return $result;
        return [
            'request_id'=>$requestId,
            'contact_id'=>(int)$source['existing_contact_id'],
            'status'=>$result['status'],
            'relation_id'=>(int)$result['relation_id'],
            'marketing_subscription_changed'=>false,
            'supplier_confirmation_created'=>false,
            'woo_order_created'=>false
        ];
    }
}
