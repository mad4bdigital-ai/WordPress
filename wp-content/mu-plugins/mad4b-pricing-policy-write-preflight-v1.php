<?php
/**
 * Plugin Name: MAD4B Pricing Policy Write Preflight
 * Version: 1.0.0
 * Description: Signed read-only source-aware write admission checks; never calls a pricing writer.
 */
defined('ABSPATH') || exit;

final class MAD4B_Pricing_Policy_Write_Preflight_V1 {
    public const ACTION = 'mad4b_pricing_policy_write_preflight_v1';
    private const LAB = 'mad4b_jetengine_pricing_lab_presets_v1';
    private const MAX_REFS = 12;

    public static function authorized(): bool {
        return current_user_can('commerce_manage_pricing')
            && current_user_can('manage_woocommerce');
    }

    private static function result(string $family, string $operation, string $state,
        array $details = []): array {
        return [
            'contract' => 'mad4b.policy-write-preflight.v1',
            'family' => $family, 'operation' => $operation,
            'state' => $state, 'read_only' => true,
            'write_executed' => false,
            'commercial_activation_approved' => false,
            'must_revalidate_at_native_commit' => true,
        ] + $details;
    }

    /** @return array|WP_Error */
    public static function inspect(string $family, string $operation, array $params = []) {
        if (!self::authorized())
            return new WP_Error('policy_preflight_forbidden');
        if (count($params) > 15)
            return new WP_Error('policy_preflight_input_invalid');
        $revision = $params['revision']??null;
        if (!is_int($revision) || $revision<0 || $revision>10000000)
            return new WP_Error('policy_preflight_revision_required');

        if ($family==='pricing_lab') {
            if ($operation!=='save_draft' && $operation!=='stage_disabled')
                return new WP_Error('policy_preflight_operation_invalid');
            $id = $params['id']??'';
            if (!is_string($id) || ($id!=='' &&
                !preg_match('/^pl-[a-f0-9]{32}$/D',$id)))
                return new WP_Error('policy_preflight_id_invalid');
            $rows=get_option(self::LAB,[]);
            if (!is_array($rows))return new WP_Error('policy_preflight_source_invalid');
            $found=null;
            foreach($rows as $row)
                if(is_array($row) && ($row['id']??null)===$id)
                    $found=$row;
            if($operation==='stage_disabled') {
                if(!$id || !$found)return new WP_Error('policy_preflight_draft_missing');
                if($found['status']!=='draft')
                    return new WP_Error('policy_preflight_not_draft');
            }
            if($found) {
                if($revision!==(int)($found['revision']??-1))
                    return new WP_Error('policy_preflight_stale_revision');
                if($operation==='save_draft' && $found['status']!=='draft')
                    return new WP_Error('policy_preflight_draft_locked');
            } elseif ($id!=='' || $revision!==0) {
                return new WP_Error('policy_preflight_draft_missing');
            }
            return self::result($family,$operation,'native_validation_required',[
                'native_writer'=>'MAD4B_JetEngine_Pricing_Lab_V1',
                'source_option'=>self::LAB,
                'source_revision'=>$found?(int)$found['revision']:0,
                'snapshot_sha256'=>hash('sha256',wp_json_encode($rows)),
                'reason'=>'Validated revision and draft state only; native pricing policy validation and approval still required',
            ]);
        }

        if ($family==='policy_binding') {
            if($operation!=='bind_references')
                return new WP_Error('policy_preflight_operation_invalid');
            $scope=(string)($params['scope']??'');
            $target=$params['target_id']??null;
            $ids=$params['ids']??null;
            if(!in_array($scope,['global','term','tour','rate'],true) ||
                !is_int($target) || $target<0 ||
                !is_array($ids) || count($ids)>self::MAX_REFS)
                return new WP_Error('policy_preflight_binding_invalid');
            $core='MAD4B_Hybrid_Pricing_Policies_V1';
            if(!class_exists($core,false))return new WP_Error('policy_preflight_provider_missing');
            $targetCheck=$core::validate_target($scope,$target);
            if(is_wp_error($targetCheck))return $targetCheck;
            $clean=$core::sanitize_meta($ids);
            if(count($ids)!==count($clean) ||
                count($clean)!==count(array_unique($ids)))
                return new WP_Error('policy_preflight_binding_refs_invalid');
            $presets=$core::presets();
            foreach($clean as $id)
                if(!isset($presets[$id]) ||
                   !in_array($presets[$id]['status']??null,['draft','staged'],true))
                    return new WP_Error('policy_preflight_binding_policy_invalid');
            $current=$core::read($scope,$target);
            if((int)$current['revision']!==$revision)
                return new WP_Error('policy_preflight_stale_revision');
            return self::result($family,$operation,
                $current['ids']===$clean?'unchanged':'native_validation_required',[
                    'native_writer'=>'MAD4B_Hybrid_Pricing_Policies_V1::bind',
                    'source_revision'=>$revision,
                    'target_scope'=>$scope,'target_id'=>$target,
                    'old_ids'=>$current['ids'],'proposed_ids'=>$clean,
                    'reason'=>'Native binder must recheck revision, target, source policies and user permission atomically at Save',
                ]);
        }

        if ($family==='jetengine_options') {
            if($operation!=='save_native')
                return new WP_Error('policy_preflight_operation_invalid');
            // The native JetEngine options controller currently increments
            // revisions but does NOT compare expected revision before writes.
            // Do not fake atomicity or start a parallel option store.
            return self::result($family,$operation,'blocked',[
                'reason'=>'native_options_writer_missing_compare_and_swap',
                'native_writer'=>'JetEngine Options Page',
                'required'=>'source-owned optimistic concurrency check and approved native Save receipt',
            ]);
        }
        if ($family==='checkout_activation') {
            return self::result($family,$operation,'blocked',[
                'reason'=>'certified_cost_fx_tax_passenger_and_approval_missing',
            ]);
        }
        return new WP_Error('policy_preflight_family_invalid');
    }

    public static function ajax(): void {
        if (!self::authorized())wp_send_json_error(['code'=>'policy_preflight_forbidden'],403);
        if (!check_ajax_referer(self::ACTION,'nonce',false))
            wp_send_json_error(['code'=>'policy_preflight_nonce_invalid'],403);
        $raw=isset($_POST['params'])&&is_string($_POST['params']) ?
            wp_unslash($_POST['params']):'{}';
        if(strlen($raw)>1800)wp_send_json_error(['code'=>'policy_preflight_request_large'],400);
        $params=json_decode($raw,true);
        if(!is_array($params))wp_send_json_error(['code'=>'policy_preflight_json_invalid'],400);
        $result=self::inspect(sanitize_key((string)wp_unslash($_POST['family']??'')),
            sanitize_key((string)wp_unslash($_POST['operation']??'')),$params);
        if(is_wp_error($result))wp_send_json_error(['code'=>$result->get_error_code()],409);
        wp_send_json_success($result);
    }
}
add_action('wp_ajax_'.MAD4B_Pricing_Policy_Write_Preflight_V1::ACTION,
    [MAD4B_Pricing_Policy_Write_Preflight_V1::class,'ajax']);
