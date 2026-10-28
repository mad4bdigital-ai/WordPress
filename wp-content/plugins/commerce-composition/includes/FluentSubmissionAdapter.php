<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * On a genuinely persisted Fluent Forms submission, use a trusted server
 * context provider to resolve parent/Rate. Never use client POST fields to
 * authorize source identity, price or permission. Feature is opt-in.
 */
final class FluentSubmissionAdapter
{
    /** Process-local status used ONLY for the current Fluent confirmation. */
    private static array $lastOutcome = [];

    public static function bootstrap():void
    {
        add_action('fluentform/submission_inserted',[self::class,'onSubmission'],90,3);
        add_filter('fluentform/submission_confirmation',[self::class,'confirmation'],999,5);
    }
    public static function onSubmission($submissionId,$formData,$form):void
    {
        if(!is_object($form)||!isset($form->id)||!is_numeric($form->id)||
            !is_numeric($submissionId))return;
        $enabled=apply_filters('commerce_composition_enable_submission_to_request_basket',false,
            (int)$form->id,(int)$submissionId);
        if($enabled!==true)return;
        $result=self::stageSubmission($submissionId,$formData,$form);
        self::$lastOutcome[(int)$form->id][(int)$submissionId]=[
            'succeeded'=>!is_wp_error($result),
            'status'=>is_wp_error($result)?$result->get_error_code():
                ($result['outcome']['status']??'unconfirmed'),
        ];
        if(is_wp_error($result)) {
            // Form success is not basket success. UI confirmation must be honest.
            do_action('commerce_composition_request_staging_failed',
                $result->get_error_code(),(int)$submissionId,(int)$form->id);
        } else {
            do_action('commerce_composition_request_staged',$result['outcome'],$result['intent']);
        }
    }

    /**
     * Fluent Forms standard modern confirmation filter.
     * Only overrides confirmation for a submission explicitly processed by us.
     * Never changes inquiry forms, other forms or a normal disabled integration.
     */
    public static function confirmation(
        $response,$form,$confirmation=null,$submissionId=null,$formData=null
    ) {
        if(!is_array($response)||!is_object($form)||!isset($form->id)||
            !is_numeric($form->id))return $response;
        $formId=(int)$form->id;
        $byId=self::$lastOutcome[$formId]??[];
        if(!is_array($byId)||!$byId)return $response;
        if($submissionId!==null && is_numeric($submissionId)) {
            $result=$byId[(int)$submissionId]??null;
        }elseif(count($byId)===1){
            $result=reset($byId);
        }else{
            return $response; // Ambiguous legacy handler, do not guess.
        }
        if(!is_array($result))return $response;
        if($result['succeeded']===true){
            $response['message']=__(
                'Your booking request has been added to your request basket. Prices and availability are pending confirmation. No payment has been taken.',
                'woocommerce'
            );
        }else{
            $response['message']=__(
                'Your form was received, but the booking request could not be added to the request basket. Please retry or contact support. No payment has been taken.',
                'woocommerce'
            );
        }
        // Always show the actual status rather than following a generic
        // success redirect before the customer can read it.
        unset($response['redirectUrl']);
        return $response;
    }

    /** Prepare immutable business intent; no customer session or writes. */
    public static function prepareSubmissionIntent($submissionId,$formData,$form):array|\WP_Error
    {
        if(!is_object($form)||!isset($form->id)||!is_numeric($form->id)||
            !is_numeric($submissionId)||!is_array($formData))
            return new \WP_Error('request_submission_parameters_invalid');
        $context=apply_filters('commerce_composition_trusted_submission_context',null,
            (int)$submissionId,(int)$form->id);
        if($context===null){
            // Background workers have no original page/request context.
            // Retrieve the immutable server-owned saved-submission receipt.
            $context=SubmissionContext::read((int)$form->id,(int)$submissionId);
            if(is_wp_error($context))return $context;
        }
        if(!is_array($context) || !isset($context['parent_id']))
            return new \WP_Error('request_source_context_unavailable');
        $parent=(int)$context['parent_id'];
        $rate=isset($context['rate_id'])?(int)$context['rate_id']:null;
        if($parent<1||($rate!==null&&$rate<1))
            return new \WP_Error('request_source_context_invalid');
        $descriptor=FormRouter::describe($parent,$rate);
        if(is_wp_error($descriptor))return $descriptor;
        if(!$descriptor['booking_available']||
            $descriptor['booking_form_id']!==(int)$form->id)
            return new \WP_Error('request_booking_template_or_mode_disabled');
        $mapping=$rate?get_post_meta($rate,'_commerce_form_field_bindings',true):null;
        if(!is_array($mapping))$mapping=get_post_meta($parent,'_commerce_form_field_bindings',true);
        $mapping=apply_filters('commerce_composition_form_field_bindings',$mapping,$parent,$rate);
        if(!is_array($mapping)||($mapping['schema']??null)!==1 ||
            ($mapping['form_id']??null)!==(int)$form->id ||
            !is_array($mapping['groups']??null))
            return new \WP_Error('request_field_mapping_not_approved');
        $policy=Integration::policyFor($parent,$rate);
        if(is_wp_error($policy))return $policy;
        $groups=[];
        $mapped=[];
        foreach($mapping['groups'] as $binding) {
            if(!is_array($binding)||!isset($binding['key'],$binding['field_name']))
                return new \WP_Error('request_field_binding_invalid');
            $key=$binding['key'];$field=$binding['field_name'];
            if(!is_string($key)||!is_string($field)||isset($mapped[$key]))
                return new \WP_Error('request_field_binding_duplicate_or_invalid');
            $mapped[$key]=true;
            $data=$formData[$field]??null;
            $selected=$data===null||$data===''?[]:(is_array($data)?$data:[$data]);
            $groups[$key]=[];
            foreach($selected as $value) {
                if(!is_scalar($value)||!preg_match('/^[1-9][0-9]*$/D',(string)$value))
                    return new \WP_Error('request_field_value_not_source_id');
                $match=[];
                foreach(($policy['groups']??[]) as $group){
                    if(($group['key']??null)!==$key)continue;
                    foreach(($group['options']??[]) as $option) {
                        if((int)($option['source_id']??0)===(int)$value)$match[]=$option;
                    }
                }
                if(count($match)!==1)return new \WP_Error('request_field_choice_unlinked');
                $groups[$key][]=[
                    'source_type'=>$match[0]['source_type'],
                    'source_id'=>(int)$match[0]['source_id']
                ];
            }
        }
        $selection=['intent'=>'request','channel'=>'standalone',
            'parent_source_id'=>$parent,'groups'=>$groups];
        if($rate!==null)$selection['parent_rate_id']=$rate;
        return FormIntent::prepare($parent,$rate,(int)$form->id,
            (int)$submissionId,$selection);
    }

    /** Browser path stays session-oriented; server intake reuses the intent. */
    public static function stageSubmission($submissionId,$formData,$form):array|\WP_Error
    {
        $intent=self::prepareSubmissionIntent($submissionId,$formData,$form);
        if(is_wp_error($intent))return $intent;
        $result=ClaimLedger::stage($intent);
        if(is_wp_error($result))return $result;
        return ['outcome'=>$result,'intent'=>$intent];
    }
}
