<?php
/**
 * Plugin Name: Commerce Operations Bridge
 * Description: Local-first governed event contracts connecting WooCommerce booking activity to operational adapters.
 * Version: 0.1.2
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 */
defined('ABSPATH') || exit;
require_once __DIR__.'/legacy-tour-bookings-adapter.php';
require_once __DIR__.'/intake-source-bridge.php';

/**
 * WordPress/HPOS native event envelope. This plugin is intentionally observe-only.
 * No external sync, CRM enrollment, accounts posting, task creation or emails.
 */
final class Commerce_Operations_Bridge {
    public const VERSION = '0.1.2';

    public static function sources(): array {
        return [
            'woocommerce' => class_exists('WooCommerce') && function_exists('wc_get_order'),
            'hpos' => get_option('woocommerce_custom_orders_table_enabled') === 'yes',
            'fluentcrm' => function_exists('FluentCrmApi'),
            'wp_erp' => function_exists('erp_get_option') || defined('WPERP_VERSION') || defined('ERP_VERSION'),
            'fluentboards' => function_exists('FluentBoardsApi'),
        ];
    }

    public static function ownership(): array {
        return [
            'order' => 'woocommerce_hpos',
            'order_lines' => 'woocommerce_order_items',
            'supplier_booking_and_confirmation' => 'jetengine_tour_bookings',
            'eligibility_and_inventory' => 'booking_inventory_registry',
            'customer_outreach' => 'fluentcrm',
            'operations_tasks' => 'fluentboards',
            'human_resources' => 'wp_erp_hrm',
            'vendor_invoices_payables_and_accounting' => 'wp_erp_accounting',
            'source_of_marketing_consent' => 'explicit_proven_consent',
        ];
    }

    public static function allowed_event_types(): array {
        return [
            'order.created', 'order.status_changed', 'order.refunded',
            'booking.confirmed', 'booking.cancelled', 'booking.supplier_confirmation_requested',
        ];
    }

    /**
     * Validate data contracts without changing any systems.
     * Event keys are stable per source event and never contain PII.
     */
    public static function normalize($event): array|WP_Error {
        if (!is_array($event)) return new WP_Error('commerce_ops_event_not_array');
        if (array_diff(array_keys($event),['type','entity','entity_id','event_key','attributes','schema_version','idempotency_hash']))return new WP_Error('commerce_ops_unapproved_event_fields');
        $type = $event['type'] ?? '';
        $entity = $event['entity'] ?? '';
        $id = filter_var($event['entity_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        $key = $event['event_key'] ?? '';
        if (!is_string($type) || !in_array($type,self::allowed_event_types(),true))
            return new WP_Error('commerce_ops_event_type_invalid');
        if (!in_array($entity,['order','booking'],true) || !$id || is_bool($event['entity_id']??null))
            return new WP_Error('commerce_ops_entity_invalid');
        if (!is_string($key) || !preg_match('/^[A-Za-z0-9:._-]{8,180}$/D',$key))
            return new WP_Error('commerce_ops_event_key_invalid');
        if (str_starts_with($type,'order.') && $entity!=='order')
            return new WP_Error('commerce_ops_event_entity_mismatch');
        if (str_starts_with($type,'booking.') && $entity!=='booking')
            return new WP_Error('commerce_ops_event_entity_mismatch');
        $attributes=$event['attributes']??[];
        if (!is_array($attributes) || array_diff(array_keys($attributes),['old_status','new_status','order_id','order_item_id']))
            return new WP_Error('commerce_ops_unapproved_event_attributes');
        $clean=[];
        foreach($attributes as $name=>$value){
            if (in_array($name,['order_id','order_item_id'],true)) {
                $n=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if (!$n) return new WP_Error('commerce_ops_attribute_invalid');
                $clean[$name]=(int)$n;
            } else {
                if (!is_string($value)||!preg_match('/^[a-z0-9_-]{1,64}$/D',$value))
                    return new WP_Error('commerce_ops_status_invalid');
                $clean[$name]=$value;
            }
        }
        return [
            'schema_version'=>1,
            'type'=>$type,'entity'=>$entity,'entity_id'=>(int)$id,
            'event_key'=>$key,
            'idempotency_hash'=>hash('sha256','commerce-ops:v1:'.$key),
            'attributes'=>$clean,
        ];
    }

    /**
     * System adapters deliberately return a plan, never mutate vendor databases.
     * The dispatcher remains disabled until each sink has approved permissions and acceptance QA.
     */
    public static function route_plan(array $event): array|WP_Error {
        $e=self::normalize($event);
        if (is_wp_error($e))return $e;
        $p=['schema_version'=>1,'event_key'=>$e['event_key'],'mutations_authorized'=>false,
            'financial_posting_authorized'=>false,'marketing_subscription_authorized'=>false,
            'routes'=>[]];
        $p['routes']['fluentcrm']=['action'=>'contact_timeline_projection','state'=>'requires_consent_and_adapter'];
        $p['routes']['fluentboards']=['action'=>'booking_service_task','state'=>'requires_board_stage_authorization'];
        $p['routes']['wp_erp']=['action'=>'accounting_reconciliation_review','state'=>'requires_approved_ledger_mapping'];
        return $p;
    }

    public static function inspect_order(int $order_id): array|WP_Error {
        if (!function_exists('wc_get_order'))return new WP_Error('commerce_ops_woocommerce_missing');
        $o=wc_get_order($order_id);
        if (!$o)return new WP_Error('commerce_ops_order_missing');
        $products=[];
        foreach($o->get_items('line_item') as $item) {
            $products[]=['order_item_id'=>$item->get_id(),'product_id'=>$item->get_product_id(),'quantity'=>$item->get_quantity()];
        }
        return [
            'source'=>'woocommerce_crud','order_id'=>$o->get_id(),'status'=>$o->get_status(),
            'currency'=>$o->get_currency(),'line_items'=>$products,
            'contains_customer_pii'=>false,'inventory_committed_by_inspection'=>false,
        ];
    }

    /** Broadcast a validated, non-PII event to independent future adapters. */
    public static function observe_order_created($order_id): void {
        $id=absint($order_id);
        if (!$id || !function_exists('wc_get_order') || !wc_get_order($id))return;
        $event=self::normalize([
            'type'=>'order.created','entity'=>'order','entity_id'=>$id,
            'event_key'=>'order:'.$id.':created',
        ]);
        if (!is_wp_error($event))do_action('commerce_ops/event_observed',$event);
    }

    /**
     * Do not convert Processing to booking confirmation.
     * Consumers may create projections only after separate authorization.
     */
    public static function observe_order_status_changed($order_id,$from,$to,$order): void {
        $id=absint($order_id);
        if(!$id||!is_string($from)||!is_string($to)||
            !preg_match('/^[a-z0-9_-]{1,64}$/D',$from)||
            !preg_match('/^[a-z0-9_-]{1,64}$/D',$to))return;
        $o=($order instanceof WC_Order)?$order:(function_exists('wc_get_order')?wc_get_order($id):false);
        if (!$o || (int)$o->get_id()!==$id)return;
        $stamp=$o->get_date_modified('edit');
        $revision=$stamp ? $stamp->getTimestamp() : 0;
        $event=self::normalize([
            'type'=>'order.status_changed','entity'=>'order','entity_id'=>$id,
            'event_key'=>'order:'.$id.':status:'.$from.':'.$to.':'.$revision,
            'attributes'=>['old_status'=>$from,'new_status'=>$to],
        ]);
        if(!is_wp_error($event))do_action('commerce_ops/event_observed',$event);
    }

    public static function boot(): void {
        add_action('admin_menu',[self::class,'register_admin']);
        add_action('woocommerce_new_order',[self::class,'observe_order_created'],20,1);
        add_action('woocommerce_order_status_changed',[self::class,'observe_order_status_changed'],20,4);
    }

    public static function register_admin(): void {
        add_submenu_page('woocommerce','Operations Integration Audit','Operations Integration','manage_woocommerce',
            'commerce-operations-bridge',[self::class,'render_admin']);
    }

    public static function render_admin(): void {
        if (!current_user_can('manage_woocommerce'))wp_die(esc_html__('Access denied.','commerce-operations-bridge'));
        echo '<div class="wrap"><h1>Commerce Operations Integration</h1>';
        echo '<p>Read-only diagnostics. No marketing subscriptions, order changes, task writes or financial entries.</p>';
        echo '<h2>Dependency inventory</h2><table class="widefat striped"><tbody>';
        foreach(self::sources() as $name=>$ready){
            echo '<tr><th>'.esc_html($name).'</th><td>'.esc_html($ready?'Available':'Not installed / not enabled').'</td></tr>';
        }
        echo '</tbody></table><h2>Data ownership</h2><table class="widefat striped"><tbody>';
        foreach(self::ownership() as $domain=>$owner){
            echo '<tr><th>'.esc_html($domain).'</th><td>'.esc_html($owner).'</td></tr>';
        }
        echo '</tbody></table><h2>Existing Tour Bookings reuse assessment</h2>';
        $audit=Commerce_Operations_Tour_Bookings_Adapter::audit();
        if(is_wp_error($audit)){
            echo '<p>'.esc_html($audit->get_error_code()).'</p>';
        } else {
            echo '<p>JetEngine tour_bookings remains the intended supplier-confirmation record. No schema migrated.</p><table class="widefat striped"><tbody>';
            foreach(['schema_defined_fields','records_in_this_environment','legacy_order_ref',
                      'woo_order_item_link_present','anonymous_read_requires_access_review','migration_safe_to_apply'] as $k) {
                echo '<tr><th>'.esc_html($k).'</th><td>'.esc_html(is_bool($audit[$k])?($audit[$k]?'Yes':'No'):(string)$audit[$k]).'</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }
}
Commerce_Operations_Bridge::boot();
