<?php
declare(strict_types=1);
namespace Commerce\Composition;

/** Connect the general consent engine to a site-owned, configured registry. */
final class ConsentPolicySource
{
    public static function all():array
    {
        $source=get_option('commerce_marketing_consent_source',[]);
        $option=is_array($source)?($source['option_key']??null):null;
        if(!is_string($option)||!preg_match('/^[a-z][a-z0-9_-]{2,119}$/D',$option))
            return [];
        $policies=get_option($option,[]);
        return is_array($policies)?$policies:[];
    }
    public static function forForm(int $formId):array|\WP_Error
    {
        $policy=self::all()[$formId]??null;
        if(!is_array($policy))return new \WP_Error('marketing_consent_policy_missing');
        return $policy;
    }
}
