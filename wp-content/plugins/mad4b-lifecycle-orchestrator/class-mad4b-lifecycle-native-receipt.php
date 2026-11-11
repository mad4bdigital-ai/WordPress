<?php
defined('ABSPATH') || exit;
/**
 * Read-only original-provider result receipt. A journal row alone never proves
 * that provider state remains current: exact provider readback is required.
 */
final class MAD4B_Lifecycle_Native_Receipt {
    const CONTRACT='mad4b.lifecycle-native-provider-receipt.v1';
    public static function read($input=[]) {
        if(!MAD4B_Lifecycle_Orchestrator::can_read())
            return new WP_Error('mad4b_lifecycle_receipt_admin_required','Administrator required');
        $id=is_array($input)?(string)($input['mutation_id']??''):'';
        if(!wp_is_uuid($id))
            return new WP_Error('mad4b_lifecycle_receipt_id_invalid','Exact mutation UUID required');
        if(!class_exists('MAD4B_SCP_Mutation_Manager')||
           !class_exists('MAD4B_SCP_Adapter_Registry'))
            return new WP_Error('mad4b_lifecycle_receipt_source_unavailable','Native mutation registry unavailable');
        $row=MAD4B_SCP_Mutation_Manager::get($id);
        if(!is_array($row)||($row['mutation_id']??'')!==$id)
            return new WP_Error('mad4b_lifecycle_receipt_not_found','Mutation not found');
        $ability=(string)($row['ability_name']??'');
        if(!in_array($ability,['media/update-metadata','seo/update-meta'],true))
            return new WP_Error('mad4b_lifecycle_receipt_ability_unsupported','Only reviewed SEO/Media reversible receipts supported');
        $state=(string)($row['status']??'');
        $out=[
            'contract'=>self::CONTRACT,'mutation_id'=>$id,
            'ability'=>$ability,'provider'=>(string)($row['provider']??''),
            'target_type'=>(string)($row['target_type']??''),
            'target_id'=>(string)($row['target_id']??''),
            'principal_type'=>(string)($row['principal_type']??''),
            'journal_status'=>$state,'verification_code'=>(string)($row['verification_code']??''),
            'provider_write_performed'=>in_array($state,['verified','verification_failed','undone'],true),
            'verified'=>false,'source_readback_current'=>false,
            'approval_substituted'=>false,'replay_write_performed'=>false,
        ];
        if($state==='undone'){
            $out['state']='PARTIAL';
            $out['reason']='original_mutation_already_undone';
            return $out;
        }
        if($state!=='verified' || ($row['verification_code']??'')!=='provider_readback_recorded'){
            $out['state']=in_array($state,['executing','undo_pending','verification_failed'],true)?
                'NEEDS_RECONCILIATION':'PARTIAL';
            $out['reason']='native_journal_not_fully_verified';
            return $out;
        }
        $payload=(string)($row['rollback_payload']??'');
        $hash=(string)($row['rollback_payload_sha256']??'');
        if($payload==='' || !preg_match('/^[a-f0-9]{64}$/D',$hash) ||
            !hash_equals(hash('sha256',$payload),$hash))
            return new WP_Error('mad4b_lifecycle_receipt_payload_integrity_failed','Native rollback evidence integrity failed');
        $record=json_decode($payload,true);
        if(!is_array($record)||($record['ability']??'')!==$ability||
           !is_array($record['target']??null)||!in_array(($record['adapter_id']??''),['media','seo'],true))
            return new WP_Error('mad4b_lifecycle_receipt_contract_mismatch','Native provider descriptor mismatch');
        if(($ability==='media/update-metadata'&&$record['adapter_id']!=='media')
            ||($ability==='seo/update-meta'&&$record['adapter_id']!=='seo'))
            return new WP_Error('mad4b_lifecycle_receipt_ability_adapter_mismatch','Exact registered adapter required');
        $adapter=MAD4B_SCP_Adapter_Registry::instance()->get($record['adapter_id']);
        if(!$adapter instanceof MAD4B_SCP_Adapter_Base ||
           (string)$adapter->provider_key()!==(string)$row['provider'])
            return new WP_Error('mad4b_lifecycle_receipt_adapter_drift','Exact native adapter binding changed');
        $fresh=$adapter->read_reversible_state($ability,$record['target']);
        if(is_wp_error($fresh)||!is_array($fresh)){
            $out['state']='BLOCKED_DEPENDENCY';
            $out['reason']='source_target_missing_or_unavailable';
            $out['historical_journal_verified']=true;
            return $out;
        }
        $fresh_hash=hash('sha256',wp_json_encode($fresh,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        $expected=(string)($row['after_sha256']??'');
        if(!preg_match('/^[a-f0-9]{64}$/D',$expected)||
           !hash_equals($expected,$fresh_hash)){
            $out['state']='NEEDS_RECONCILIATION';
            $out['reason']='provider_state_changed_since_mutation';
            return $out;
        }
        $out['state']='VERIFIED';
        $out['verified']=true;
        $out['source_readback_current']=true;
        $out['scope']='single_native_provider_mutation';
        $out['after_sha256']=$fresh_hash;
        $out['company_or_content_release_verified']=false;
        $out['score_recomputed']=false;
        return $out;
    }
    public static function routes() {
        register_rest_route('mad4b/lifecycle/v1','/native-receipt',[
            'methods'=>'GET','permission_callback'=>['MAD4B_Lifecycle_Orchestrator','can_read'],
            'callback'=>static fn($r)=>rest_ensure_response(self::read($r->get_params()))]);
    }
    public static function abilities() {
        if(!function_exists('wp_register_ability')||wp_has_ability('mad4b/lifecycle-native-receipt'))return;
        wp_register_ability('mad4b/lifecycle-native-receipt',[
            'label'=>'MAD4B native SEO/Media receipt readback',
            'description'=>'Inspect exact original journal + current provider state without writing.',
            'category'=>'mad4b-read','permission_callback'=>['MAD4B_Lifecycle_Orchestrator','can_read'],
            'execute_callback'=>[__CLASS__,'read'],
            'input_schema'=>['type'=>'object','properties'=>['mutation_id'=>['type'=>'string']],
                'required'=>['mutation_id'],'additionalProperties'=>false],
            'output_schema'=>['type'=>'object','additionalProperties'=>true],
            'meta'=>['public'=>false,'show_in_rest'=>false,'mcp'=>['type'=>'tool','surface'=>'read','public'=>false],
                'annotations'=>['readonly'=>true,'destructive'=>false,'idempotent'=>true]]
        ]);
    }
    public static function boot(){
        if(!MAD4B_Lifecycle_Orchestrator::scoped())return;
        add_action('rest_api_init',[__CLASS__,'routes'],99);
        add_action('wp_abilities_api_init',[__CLASS__,'abilities'],99);
    }
}
