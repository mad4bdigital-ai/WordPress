<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Vendor-neutral WordPress adapter. Read only; no checkout/cart/payment/CRM mutation hooks.
 * All cross-system extensions are callbacks, not company-specific constants or environment checks.
 */
final class Integration
{
    public static function bootstrap(): void
    {
        // Intentionally no checkout interception. Expose callable functionality to form/cart adapters.
        do_action('commerce_composition_ready', self::class);
    }
    public static function activationOptions(): array
    {
        $key = (string) apply_filters('commerce_composition_activation_option_key','service-activation');
        if (!preg_match('/^[a-z][a-z0-9_-]{0,119}$/D', $key)) return [];
        $options = get_option($key, []);
        $options = apply_filters('commerce_composition_activation_options', $options);
        return is_array($options) ? $options : [];
    }
    public static function serviceForPostType(string $type): ?string
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,79}$/D', $type)) return null;
        $service = apply_filters('commerce_composition_service_for_post_type',null,$type);
        if ($service===null && function_exists('service_channel_owner')) {
            $service = service_channel_owner($type);
        }
        // Fallback allows future CPT channels with a matching canonical service ID,
        // but only when the single existing activation option explicitly mentions it.
        if ($service===null) {
            $options=self::activationOptions();
            $listed=$options['active_services']??[];
            $mapped=$options['service_channel_modes']??[];
            if (is_array($listed)&&in_array($type,$listed,true) ||
                is_array($mapped)&&array_key_exists($type,$mapped) ||
                array_key_exists($type.'_channel_mode',$options)) $service=$type;
        }
        return is_string($service) && preg_match('/^[a-z][a-z0-9_-]{0,79}$/D',$service) ? $service : null;
    }
    /** Typed policy may be attached to any published Woo-backed CPT or provider-backed service. */
    public static function policyFor(int $parentId, ?int $rateId = null): array|\WP_Error
    {
        if ($parentId<1 || get_post_status($parentId)!=='publish') return new \WP_Error('composition_parent_unavailable');
        if ($rateId!==null && ($rateId<1 || get_post_status($rateId)!=='publish')) return new \WP_Error('composition_rate_unavailable');
        if ($rateId!==null) {
            $proof=RateProvenance::verify($parentId,$rateId);
            if (is_wp_error($proof))return $proof;
        }
        $policy = $rateId ? get_post_meta($rateId, '_commerce_composition_policy', true) : null;
        if (!is_array($policy)) $policy=get_post_meta($parentId, '_commerce_composition_policy', true);
        $policy=apply_filters('commerce_composition_resolved_policy', $policy, $parentId, $rateId);
        if (!is_array($policy) || ($policy['schema']??null)!==1) return new \WP_Error('composition_policy_missing_or_invalid');
        $p=$policy['parent']??[];
        if (!is_array($p) || (int)($p['source_id']??0)!==$parentId ||
            ($rateId!==null && (int)($p['rate_id']??0)!==$rateId))
            return new \WP_Error('composition_policy_parent_mismatch');
        // A composite/package identity is NOT inferred from a pricing tier, child
        // relationship or manually declared policy alone. It is an exact fact from
        // the parent CPT's trusted taxonomy/field classifier registry.
        if (!empty($policy['groups'])) {
            $kind=$policy['required_kind']??null;
            if (!is_string($kind)||!preg_match('/^[a-z][a-z0-9_-]{0,99}$/D',$kind))
                return new \WP_Error('composition_source_kind_required');
            $qualified=SourceClassifier::hasKind($parentId,$kind);
            if (is_wp_error($qualified))return $qualified;
            if (!$qualified)return new \WP_Error('composition_source_kind_mismatch');
        }
        // Composition options must be published, typed and explicitly linked
        // in the original owning CPT/Rate records. Never infer membership
        // merely because a custom policy enumerates a child ID.
        foreach (($policy['groups']??[]) as $group) {
            if (!is_array($group)||!is_array($group['options']??null))
                return new \WP_Error('composition_policy_group_invalid');
            foreach ($group['options'] as $option) {
                if (!is_array($option) ||
                    !isset($option['source_type'],$option['source_id'],$option['service']))
                    return new \WP_Error('composition_policy_option_invalid');
                $type=$option['source_type'];
                $id=$option['source_id'];
                if(!is_string($type)||!is_int($id)||$id<1 ||
                    get_post_type($id)!==$type||get_post_status($id)!=='publish'||
                    self::serviceForPostType($type)!==$option['service'])
                    return new \WP_Error('composition_source_unverified');
                $linked=RelationProvenance::check($parentId,$rateId,$type,$id);
                if(is_wp_error($linked))return $linked;
            }
        }
        return $policy;
    }
    /** Validated conditional controls; cannot show Package options on non-package source. */
    public static function formDescriptor(int $parentId, ?int $rateId=null): array|\WP_Error
    {
        $policy=self::policyFor($parentId,$rateId);
        if (is_wp_error($policy))return $policy;
        try {
            return FormSchema::describe($policy,self::activationOptions());
        } catch (\DomainException $exception) {
            return new \WP_Error('composition_form_rejected',$exception->getMessage());
        } catch (\Throwable $exception) {
            return new \WP_Error('composition_form_internal_error');
        }
    }

    /**
     * Selectively verify trusted policy options against live WordPress source identity.
     * This is a *read-only configuration* check, not a quote or sale authorization.
     */
    public static function prepare(int $parentId, ?int $rateId, array $selection, ?callable $priceResolver = null): array|\WP_Error
    {
        $policy = self::policyFor($parentId,$rateId);
        if (is_wp_error($policy)) return $policy;
        $options=self::activationOptions();
        $sources = [$policy['parent']];
        foreach (($policy['groups']??[]) as $group)
            foreach (($group['options']??[]) as $source) $sources[]=$source;
        foreach ($sources as $source) {
            if (!is_array($source)) return new \WP_Error('composition_source_invalid');
            $type=$source['source_type']??null;
            $id=$source['source_id']??null;
            $service=$source['service']??null;
            if (!is_string($type) || !is_int($id) || $id<1 || get_post_type($id)!==$type ||
                get_post_status($id)!=='publish' || self::serviceForPostType($type)!==$service)
                return new \WP_Error('composition_source_unverified');
        }
        $priceResolver ??= static function($parent,$group,$option,$quantity,$pricing,$intent): array {
            // Never infer 0 or derive price from arbitrary client metadata.
            return $pricing==='included' ? ['state'=>'included'] : ['state'=>'pending'];
        };
        try {
            $plan=Composer::compose($policy,$selection,$options,$priceResolver);
            return apply_filters('commerce_composition_prepared_plan',$plan,$policy,$selection);
        } catch (\DomainException $exception) {
            return new \WP_Error('composition_rejected', $exception->getMessage());
        } catch (\Throwable $exception) {
            return new \WP_Error('composition_internal_error');
        }
    }
}
