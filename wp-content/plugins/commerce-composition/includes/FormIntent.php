<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Verify form submission selections before a future durable Cart writer.
 * Submission ID comes from a server-side Fluent Forms storage hook.
 */
final class FormIntent
{
    public static function prepare(
        int $parentId,?int $rateId,int $publishedFormId,int $serverSubmissionId,
        array $selection
    ): array|\WP_Error {
        if($parentId<1||$publishedFormId<1||$serverSubmissionId<1)
            return new \WP_Error('form_intent_invalid_identity');
        if(array_diff(array_keys($selection),['intent','channel','parent_source_id',
           'parent_rate_id','groups']))
            return new \WP_Error('form_intent_unexpected_fields');
        if(($selection['intent']??'request')!=='request')
            return new \WP_Error('form_intent_paid_sale_not_enabled');
        $descriptor=FormRouter::describe($parentId,$rateId);
        if(is_wp_error($descriptor))return $descriptor;
        if(!$descriptor['booking_available'])
            return new \WP_Error('form_intent_booking_disabled');
        if($descriptor['booking_form_id']!==$publishedFormId)
            return new \WP_Error('form_intent_template_mismatch');
        $submission=SubmissionProvenance::verify($serverSubmissionId,$publishedFormId);
        if(is_wp_error($submission))return $submission;
        $plan=Integration::prepare($parentId,$rateId,$selection);
        if(is_wp_error($plan))return $plan;
        if(($plan['intent']??null)!=='request')
            return new \WP_Error('form_intent_request_mode_required');
        $digest=hash('sha256',wp_json_encode([
            $parentId,$rateId,$publishedFormId,$serverSubmissionId,
            $descriptor['revision'],$plan['parent'],$plan['components'],$plan['price_state']
        ],JSON_UNESCAPED_SLASHES));
        return [
            'schema'=>1,'mode'=>'request_only',
            'form_id'=>$publishedFormId,'source_submission_id'=>$serverSubmissionId,
            'source_parent_id'=>$parentId,'source_rate_id'=>$rateId,
            'selection_digest'=>$digest,'policy_revision'=>$descriptor['revision'],
            'composition'=>$plan,
            'status'=>'validated_intent_not_cart_session_committed',
            'cart_authority'=>false,'checkout_authority'=>false,
        ];
    }
}
