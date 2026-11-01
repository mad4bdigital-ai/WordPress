<?php
declare(strict_types=1);
namespace Commerce\Composition;

use DomainException;

/**
 * Policy -> portable form descriptor. A Fluent Forms adapter may render these
 * controls inside existing published form templates; no separate product page.
 * Descriptors NEVER authorize a purchase or replace server revalidation.
 */
final class FormSchema
{
    public static function describe(array $policy, array $activationOptions): array
    {
        if (($policy['schema'] ?? null)!==1 || !is_array($policy['parent']??null) ||
            !is_array($policy['groups']??null)) throw new DomainException('form_policy_invalid');
        $parent=$policy['parent'];
        $owner=$parent['service']??null;
        if (!is_string($owner)||!Channels::allowed($activationOptions,$owner,'standalone')) {
            throw new DomainException('form_parent_channel_unavailable');
        }
        $result=[];$complete=true;$seen=[];
        foreach($policy['groups'] as $group) {
            if (!is_array($group)) throw new DomainException('form_group_invalid');
            $key=$group['key']??null;$role=$group['role']??null;
            if (!is_string($key)||!preg_match('/^[a-z][a-z0-9_-]{0,79}$/D',$key)
                ||isset($seen[$key]))throw new DomainException('form_group_key_invalid');
            $seen[$key]=true;
            $control=match($role) {
                'fixed_included'=>'summary',
                'choose_one_included'=>'select',
                'upgrade_delta'=>'select',
                'optional_attached','separately_accounted_extra'=>'checkbox',
                default=>throw new DomainException('form_role_invalid')
            };
            $opts=[];
            foreach(($group['options']??[]) as $item){
                if(!is_array($item)||!isset($item['service'],$item['source_type'],$item['source_id']))
                    throw new DomainException('form_source_invalid');
                if(!is_string($item['service']))throw new DomainException('form_service_invalid');
                if(!Channels::allowed($activationOptions,$item['service'],'package'))continue;
                $opts[]=[
                    'source_type'=>(string)$item['source_type'],'source_id'=>(int)$item['source_id'],
                    // Never trust this label as a price or supplier assertion.
                    'label'=>is_string($item['label']??null)?$item['label']:'',
                ];
            }
            $min=$group['min']??($role==='choose_one_included'||$role==='fixed_included'?1:0);
            $max=$group['max']??($control==='select'||$control==='summary'?1:count($opts));
            $required=$min>0;
            $available=count($opts)>=$min && ($control!=='summary'||count($opts)===1);
            if(!$available && $required)$complete=false;
            $result[]=[
                'key'=>$key,'role'=>$role,'control'=>$control,
                'required'=>$required,'available'=>$available,
                'options'=>$opts,
                'max_selectable'=>$max,
                'disabled_reason'=>$available?null:'no_eligible_service_options',
                'price_display'=>'server_preview_only',
            ];
        }
        return [
            'schema'=>1,'source'=>'policy_driven','parent_service'=>$owner,
            'revision'=>$policy['revision']??null,
            'ready_to_configure'=>$complete,
            'groups'=>$result,
            'mobile_layout'=>'progressive_single_column',
            'desktop_layout'=>'options_with_side_summary',
            'checkout_authority'=>false,
        ];
    }
}
