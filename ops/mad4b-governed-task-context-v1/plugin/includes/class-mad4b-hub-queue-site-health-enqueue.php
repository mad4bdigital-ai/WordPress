<?php
defined('ABSPATH') || exit;

/**
 * One-purpose Queue admission: source registration does not confer an NHI
 * grant, approval ticket or permission to execute an Ability.
 * The existing MAD4B Control Plane wraps write execution and enforces these.
 */
final class MAD4B_Hub_Queue_Site_Health_Enqueue {
    const ABILITY = 'mad4b/hub-queue-site-health-enqueue';
    const CONTRACT = 'mad4b.hub.site-health-queue-ingress.v1';
    const ORIGIN = 'https://tours.dev.mad4b.com';
    const DATABASE = 'etg_commerce_staging';

    /**
     * Catalog enrollment is site-based, never request-user-based.
     * Visibility is not execution authority; original NHI and grants still
     * gate each invocation through the registered Write Ability boundary.
     */
    public static function site_enrolled() {
        return function_exists('wp_get_environment_type')
            && wp_get_environment_type() === 'staging'
            && function_exists('home_url')
            && rtrim((string) home_url(), '/') === self::ORIGIN
            && defined('DB_NAME') && DB_NAME === self::DATABASE
            && class_exists('MAD4B_Content_Hub');
    }

    public static function site_ready() {
        return self::site_enrolled()
            && MAD4B_Content_Hub::permission()
            && class_exists('MAD4B_Hub_Bulk_Jobs_Queue')
            && MAD4B_Hub_Bulk_Jobs_Queue::writable()
            && class_exists('MAD4B_SCP_Policy')
            && MAD4B_SCP_Policy::can_mutate();
    }

    private static function reject($reason) {
        return new WP_Error('mad4b_hub_site_health_' . $reason, $reason,
            array('status' => 403, 'contract' => self::CONTRACT));
    }

    public static function normalize_request($input) {
        if (!is_array($input) || array_values($input) === $input
            || array_diff(array_keys($input), array('batch_key'))
            || !isset($input['batch_key']) || !is_string($input['batch_key'])) {
            return self::reject('exact_input_required');
        }
        $key = $input['batch_key'];
        if (!preg_match('/^qa[.]site-health[.]vps[.][a-z0-9][a-z0-9-]{11,63}$/D', $key)) {
            return self::reject('diagnostic_batch_key_invalid');
        }

        // No caller-supplied executor, operation, job, payload or target.
        return array(
            'batch_key' => $key,
            'max_concurrency' => 1,
            'items' => array(array(
                'task_key' => 'diagnostic-site-health',
                'stage' => 'SOURCE_DISCOVERY',
                'operation' => 'site_health',
                'job_id' => '',
                'target_ref' => 'site:' . self::ORIGIN,
                'depends_on' => array(),
                'priority' => 1,
                'max_attempts' => 1,
                'payload' => array(
                    'assigned_executor' => 'vps_runner',
                    'trigger_source' => 'manual',
                    'trigger_event_type' => 'manual',
                    'no_automatic_publish' => true,
                ),
            )),
        );
    }

    public static function execute($input) {
        if (!self::site_ready()) return self::reject('site_or_policy_not_ready');
        $batch = self::normalize_request($input);
        if (is_wp_error($batch)) return $batch;
        // Invoked only behind the original governed Ability execution wrapper:
        // its grant, approval, identity and execution journal are not bypassed.
        return MAD4B_Hub_Bulk_Jobs_Queue::enqueue($batch);
    }

    public static function register() {
        if (!self::site_enrolled() || !function_exists('wp_register_ability')) return;
        if (function_exists('wp_has_ability') && wp_has_ability(self::ABILITY)) return;
        wp_register_ability(self::ABILITY, array(
            'label' => 'Staging Site Health Queue Canary',
            'description' => 'One VPS diagnostic Queue task; no general enqueue authority.',
            'category' => 'mad4b-write',
            'execute_callback' => array(__CLASS__, 'execute'),
            'permission_callback' => array(__CLASS__, 'site_ready'),
            'input_schema' => array(
                'type' => 'object',
                'properties' => array('batch_key' => array('type' => 'string')),
                'required' => array('batch_key'),
                'additionalProperties' => false,
            ),
            'output_schema' => array('type' => 'object', 'additionalProperties' => true),
            'meta' => array(
                'public' => false,
                'show_in_rest' => false,
                'annotations' => array(
                    'readonly' => false, 'destructive' => false, 'idempotent' => true,
                ),
                'mcp' => array(
                    'public' => false,
                    'type' => 'tool',
                    'surface' => 'write',
                    'mad4b_governed_write_authority' => true,
                ),
            ),
        ));
    }

    public static function boot() {
        add_action('wp_abilities_api_init', array(__CLASS__, 'register'), 48);
    }
}
