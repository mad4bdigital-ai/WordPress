<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Company-owned route choice. Never use an untrusted REST field to decide
 * if a financial Order or an external contact should be created.
 */
final class IntakeModePolicy
{
    public static function config():array
    {
        $value=get_option('commerce_intake_routing_policy_v1',[]);
        return is_array($value)?$value:[];
    }
    public static function enabled():bool
    {
        return in_array(self::config()['enabled']??false,[true,1,'1'],true);
    }
    public static function resolve(string $channel,array $types):array|\WP_Error
    {
        $p=self::config();
        if(!self::enabled())return new \WP_Error('intake_routing_disabled');
        if(!preg_match('/^[a-z][a-z0-9_-]{1,47}$/D',$channel)||
            !$types||count($types)>100)
            return new \WP_Error('intake_route_source_invalid');
        foreach($types as $type)
            if(!is_string($type)||!preg_match('/^[a-z][a-z0-9_-]{1,79}$/D',$type))
                return new \WP_Error('intake_route_source_invalid');
        $allowed=['crm_first','order_first','manual_review'];
        $default=$p['default_mode']??'manual_review';
        if(!in_array($default,$allowed,true))
            return new \WP_Error('intake_route_policy_invalid');
        $channels=$p['channels']??[];
        $services=$p['services']??[];
        if(!is_array($channels)||!is_array($services)||count($channels)>100||
            count($services)>100)
            return new \WP_Error('intake_route_policy_invalid');
        foreach([$channels,$services] as $ruleGroup)
            foreach($ruleGroup as $key=>$mode)
                if(!is_string($key)||!is_string($mode)||
                    !preg_match('/^[a-z][a-z0-9_-]{1,79}$/D',$key)||
                    !in_array($mode,$allowed,true))
                    return new \WP_Error('intake_route_policy_invalid');
        $matching=[];
        foreach(array_unique($types) as $type)
            if(isset($services[$type]))$matching[]=$services[$type];
        $matching=array_unique($matching);
        if(count($matching)>1)
            return new \WP_Error('intake_route_services_conflict');
        $mode=$matching?reset($matching):($channels[$channel]??$default);
        if($mode==='manual_review')
            return new \WP_Error('intake_route_manual_review_required');
        return [
            'mode'=>$mode,'channel'=>$channel,
            'rule_source'=>$matching?'service':
                (isset($channels[$channel])?'channel':'company_default'),
            'policy_digest'=>hash('sha256',wp_json_encode($p,JSON_UNESCAPED_SLASHES)),
            'creates_woo_order_now'=>$mode==='order_first',
            'supplier_booking_authorized'=>false,
            'marketing_enrollment_authorized'=>false
        ];
    }
}
