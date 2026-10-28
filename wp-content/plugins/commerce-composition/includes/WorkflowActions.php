<?php
declare(strict_types=1);
namespace Commerce\Composition;

/**
 * Explicit lifecycle boundaries. A relationship is not a conversion. A
 * request is not a supplier booking. A quote is not a paid Woo Order.
 */
final class WorkflowActions
{
    public static function definitions():array
    {
        $base=[
            'link_contact_record'=>[
                'owner'=>'crm_relationship','available'=>ContactBridge::ready(),
                'effect'=>'link_only','requires'=>[
                    'contact_id','source_key','source_id','role'
                ]
            ],
            'submit_request_review'=>[
                'owner'=>'request_registry','available'=>RequestCheckout::ready(),
                'effect'=>'request_registry_write','requires'=>[
                    'verified_current_customer_session'
                ]
            ],
            'create_request_from_saved_submission'=>[
                'owner'=>'request_registry','available'=>RequestCheckout::ready(),
                'effect'=>'verified_original_form_request_intake',
                'requires'=>['form_id','submission_id','approved_server_policy']
            ],
            'create_supplier_booking'=>[
                'owner'=>'supplier_booking','available'=>false,
                'effect'=>'not_authorized',
                'requires'=>['source_request_review','supplier_selection',
                    'provider_permission','explicit_fulfillment_approval']
            ],
            'convert_request_to_certified_sale'=>[
                'owner'=>'woocommerce','available'=>false,
                'effect'=>'not_authorized',
                'requires'=>['approved_quote','per_line_price_receipts',
                    'tax_fx','customer_payment_intent']
            ],
            'convert_legacy_order_to_sale'=>[
                'owner'=>'woocommerce','available'=>false,
                'effect'=>'not_authorized',
                'requires'=>['verified_source_order','certified_quote',
                    'idempotent_woocommerce_order_link']
            ]
        ];
        // Other first-party plugins may publish their domain actions without
        // coupling this reusable core to brand names or fixed CPTs. Additive
        // declarations are informational until an authorized provider executes
        // with its own durable deduplication and approval contract.
        $additional=apply_filters('commerce_composition_workflow_action_catalog',[]);
        if(!is_array($additional))return $base;
        foreach($additional as $key=>$definition){
            if(!is_string($key)||!preg_match('/^[a-z][a-z0-9_]{2,79}$/D',$key)||
                isset($base[$key])||!is_array($definition)||
                !is_string($definition['owner']??null)||
                !is_array($definition['requires']??null))
                continue;
            $base[$key]=[
                'owner'=>$definition['owner'],
                'available'=>false,
                'effect'=>'external_provider_not_certified',
                'requires'=>array_slice(array_values(array_filter(
                    $definition['requires'],'is_string')),0,30)
            ];
        }
        return $base;
    }

    /** Admin-only, explicit invocation. No code executes based on UI labels. */
    public static function execute(string $action,array $payload):array|\WP_Error
    {
        if(!current_user_can('manage_options'))
            return new \WP_Error('workflow_action_permission_denied');
        $actions=self::definitions();
        if(!array_key_exists($action,$actions))
            return new \WP_Error('workflow_action_unknown');
        if(!$actions[$action]['available'])
            return new \WP_Error('workflow_action_required_authority_missing');
        if($action==='link_contact_record'){
            if(!isset($payload['contact_id'],$payload['source_key'],
                $payload['source_id'],$payload['role'])||
                !is_int($payload['contact_id'])||
                !is_int($payload['source_id'])||
                !is_string($payload['source_key'])||
                !is_string($payload['role']))
                return new \WP_Error('workflow_action_payload_invalid');
            return ContactBridge::link($payload['contact_id'],$payload['source_key'],
                $payload['source_id'],$payload['role']);
        }
        if($action==='create_request_from_saved_submission'){
            if(count($payload)!==2||
                !is_int($payload['form_id']??null)||
                !is_int($payload['submission_id']??null)||
                $payload['form_id']<1||$payload['submission_id']<1)
                return new \WP_Error('workflow_action_saved_form_payload_invalid');
            return RequestIntake::create($payload['form_id'],$payload['submission_id']);
        }
        if($action==='submit_request_review'){
            // The original global form claim and session authorizations
            // are re-evaluated by this existing nonpayment owner.
            return RequestCheckout::commit();
        }
        return new \WP_Error('workflow_action_unimplemented');
    }
}
