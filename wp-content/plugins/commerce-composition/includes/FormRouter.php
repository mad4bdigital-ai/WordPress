<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Resolve existing layered Booking/Inquiry templates from a server-owned policy.
 * No sale authority is granted by a published Fluent Forms definition.
 */
final class FormRouter
{
    private static function published(int $formId): bool
    {
        if($formId<1||!shortcode_exists('fluentform'))return false;
        global $wpdb;
        $name=$wpdb->prefix.'fluentform_forms';
        if(!preg_match('/^[a-zA-Z0-9_]+$/D',$name))return false;
        $sql='SELECT status FROM '.$name.' WHERE id=%d LIMIT 1';
        return $wpdb->get_var($wpdb->prepare($sql,$formId))==='published';
    }
    private static function policy(int $postId,?int $rateId): array|\WP_Error
    {
        $context=['post_id'=>$postId,'rate_id'=>$rateId];
        $provided=apply_filters('commerce_composition_booking_form_policy',null,$context);
        if(is_wp_error($provided))return $provided;
        if($provided!==null) {
            if(!is_array($provided))return new \WP_Error('form_policy_provider_invalid');
            return $provided;
        }
        if(function_exists('BookingExperience\\booking_experience_policy_resolve')) {
            $existing=\BookingExperience\booking_experience_policy_resolve($postId,0,$rateId??0);
            if(!is_wp_error($existing) && isset($existing['effective']) &&
               is_array($existing['effective']))
                return $existing['effective'];
            // An existing domain router need not know future registered service
            // types. Fall back only for a truly unsupported kind, not invalid
            // ownership, rate, permission or policy on a supported kind.
            if(is_wp_error($existing) &&
                $existing->get_error_code()!=='booking_context_type_unsupported')
                return $existing;
        }
        // Generic fallback for any future CPT/service. Does not depend on a
        // particular tour/accommodation mode in the Booking Experience plugin.
        $saved=$rateId ? get_post_meta($rateId,'_commerce_booking_form_policy',true) : null;
        if(!is_array($saved))$saved=get_post_meta($postId,'_commerce_booking_form_policy',true);
        if(!is_array($saved))return new \WP_Error('booking_form_policy_missing');
        return $saved;
    }
    public static function describe(int $postId,?int $rateId=null): array|\WP_Error
    {
        $form=Integration::formDescriptor($postId,$rateId);
        if(is_wp_error($form))return $form;
        $policy=self::policy($postId,$rateId);
        if(is_wp_error($policy))return $policy;
        $mode=$policy['display_mode']??null;
        if(!is_string($mode)||!in_array($mode,
            ['inquiry_only','booking_only','dual_tabs','dual_select'],true))
            return new \WP_Error('booking_form_mode_invalid');
        $bookingId=$policy['booking_form_id']??0;
        $inquiryId=$policy['inquiry_form_id']??0;
        if(!is_numeric($bookingId)||!is_numeric($inquiryId) ||
            (int)$bookingId<0||(int)$inquiryId<0)
            return new \WP_Error('booking_form_id_invalid');
        $bookingId=(int)$bookingId; $inquiryId=(int)$inquiryId;
        $bookingEnabled=in_array($mode,['booking_only','dual_tabs','dual_select'],true);
        $inquiryEnabled=in_array($mode,['inquiry_only','dual_tabs','dual_select'],true);
        $bookingReady=$bookingEnabled && self::published($bookingId) &&
            ($form['ready_to_configure']??false)===true;
        return [
            'schema'=>1,'parent_id'=>$postId,'rate_id'=>$rateId,
            'mode'=>$mode,'booking_form_id'=>$bookingReady?$bookingId:null,
            'inquiry_form_id'=>$inquiryEnabled&&self::published($inquiryId)?$inquiryId:null,
            'booking_available'=>$bookingReady,
            'booking_disabled_reason'=>$bookingReady?null:
                (!$bookingEnabled?'inquiry_only_policy':(!self::published($bookingId)?
                'booking_template_not_published':'required_component_unavailable')),
            'booking_inputs'=>$bookingReady?$form['groups']:[],
            'revision'=>$form['revision']??null,
            'checkout_authority'=>false,
        ];
    }
}
