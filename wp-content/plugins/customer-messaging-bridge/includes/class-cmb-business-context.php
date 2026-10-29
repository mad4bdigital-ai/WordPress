<?php
/**
 * ETG Lab: Fluent Forms, Fluent Support, and travel product source context.
 *
 * Explicitly passive: no provider mutations, no form answers, customer names,
 * email, message body, price or customer/order identifiers in telemetry.
 */
defined('ABSPATH') || exit;

final class CMB_Business_Context {
    public const OPTION = 'cmb_business_integrations_v1';
    public const FORMS = ['fluentform/submission_inserted', 'fluent_support/ticket_created', 'fluent_support/ticket_closed'];
    public const TYPES = [
        'tours-and-activities','properties','transportations',
        'tour-rates','property-rates','transport-rates',
    ];

    public static function boot(): void {
        add_action('fluentform/submission_inserted', [self::class, 'form_submitted'], 25, 3);
        add_action('fluent_support/ticket_created', [self::class, 'ticket_created'], 25, 2);
        add_action('fluent_support/ticket_closed', [self::class, 'ticket_closed'], 25, 2);
        add_action('cmb_admin_sections', [self::class, 'admin_panel'], 24);
        add_action('admin_post_cmb_business_save', [self::class, 'save']);
    }

    public static function settings(): array {
        return wp_parse_args((array) get_option(self::OPTION, []), [
            'forms' => '0', 'support' => '0',
            'enabled_form_ids' => [6, 7, 8, 11],
        ]);
    }

    public static function sanitize(array $raw): array {
        $ids = (array) ($raw['enabled_form_ids'] ?? []);
        $ids = array_values(array_unique(array_filter(array_map(
            static fn($v) => (is_scalar($v) && preg_match('/^[0-9]{1,10}$/D', (string) $v)) ? (int) $v : 0,
            $ids
        ))));
        return [
            'forms' => !empty($raw['forms']) ? '1' : '0',
            'support' => !empty($raw['support']) ? '1' : '0',
            'enabled_form_ids' => array_slice($ids, 0, 25),
        ];
    }

    public static function form_submitted($submission_id, $form_data, $form): void {
        $s = self::settings();
        $id = is_object($form) ? absint($form->id ?? 0) : 0;
        if ($s['forms'] !== '1' || !$id || !in_array($id, $s['enabled_form_ids'], true)) return;
        if (!is_numeric($submission_id) || (int) $submission_id < 1) return;
        // Never inspect, serialize, or retain $form_data; submissions have PII.
        CMB_Commerce::record('form.submitted', (string) $id . ':' . (string) $submission_id, [
            'status' => 'received',
        ]);
    }

    public static function ticket_created($ticket, $customer): void {
        if (self::settings()['support'] !== '1' || !is_object($ticket)) return;
        $id = absint($ticket->id ?? 0);
        if ($id) CMB_Commerce::record('ticket.created', (string) $id, ['status' => 'open']);
    }

    public static function ticket_closed($ticket, $person): void {
        if (self::settings()['support'] !== '1' || !is_object($ticket)) return;
        $id = absint($ticket->id ?? 0);
        if ($id) CMB_Commerce::record('ticket.closed', (string) $id, ['status' => 'closed']);
    }

    public static function capabilities(): array {
        $s = self::settings();
        $fluent = function_exists('fluentFormApi');
        $support = class_exists('FluentSupport\\App\\Models\\Ticket');
        $woo = class_exists('WooCommerce');
        return [
            'contract' => 'cmb.business-context.v1',
            'fluent_forms' => ['installed' => $fluent, 'observation_enabled' => $fluent && $s['forms'] === '1',
                'allowed_form_ids' => $s['enabled_form_ids'], 'answers_exposed' => false],
            'fluent_support' => ['installed' => $support, 'observation_enabled' => $support && $s['support'] === '1',
                'ticket_content_exposed' => false, 'ticket_mutations_allowed' => false],
            'cpt_woocommerce' => [
                'installed' => function_exists('cptwooint'),
                'mapping_provider' => class_exists('ETG_WooCommerce_Bridge') ? 'etg-woocommerce-bridge' : 'unavailable',
                'supported_source_types' => self::TYPES,
                'pricing_quote_certified' => false,
                'create_booking_allowed' => false,
                'price_or_inventory_exposed' => false,
            ],
            'woo_orders' => ['installed' => $woo, 'source_of_truth' => 'woocommerce',
                'external_sync_enabled' => false],
            'fluent_crm' => ['installed' => defined('FLUENTCRM'), 'outbound_sync_enabled' => false],
            'fluent_smtp' => ['installed' => function_exists('fluentMail'), 'mail_send_allowed' => false],
            'fluent_boards' => ['installed' => defined('FLUENT_BOARDS'), 'task_write_allowed' => false],
            'wp_erp' => ['installed' => defined('WPERP_VERSION'), 'finance_write_allowed' => false],
        ];
    }

    public static function product_context($product_id): array {
        $id = absint($product_id);
        if (!$id || !function_exists('wc_get_product')) {
            return ['state' => 'invalid_input', 'pricing_authorized' => false];
        }
        $product = wc_get_product($id);
        if (!$product) return ['state' => 'not_found', 'pricing_authorized' => false];
        $type = get_post_type($id);
        if (in_array($type, self::TYPES, true)) {
            $source = ['source_post_id' => $id, 'source_post_type' => $type, 'product_id' => $id];
        } elseif (class_exists('ETG_WooCommerce_Bridge')) {
            $source = ETG_WooCommerce_Bridge::source_for_product($id);
            if (is_wp_error($source)) return ['state' => 'unmapped', 'pricing_authorized' => false];
        } else {
            return ['state' => 'mapping_unavailable', 'pricing_authorized' => false];
        }
        if (!in_array($source['source_post_type'], self::TYPES, true)) {
            return ['state' => 'unsupported_source', 'pricing_authorized' => false];
        }
        $canonical_id = (int) $source['source_post_id'];
        if (class_exists('ETG_Rate_Domain') && method_exists('ETG_Rate_Domain', 'canonical_post_id')) {
            $resolved = ETG_Rate_Domain::canonical_post_id($canonical_id);
            if (!is_wp_error($resolved) && absint($resolved) > 0 &&
                get_post_type(absint($resolved)) === $source['source_post_type']) {
                $canonical_id = absint($resolved);
            }
        }
        return [
            'state' => 'mapped',
            'canonical_source_post_id' => $canonical_id,
            'product_id' => (int) $source['product_id'],
            'source_post_id' => (int) $source['source_post_id'],
            'source_post_type' => (string) $source['source_post_type'],
            'pricing_authorized' => false,
            'booking_authorized' => false,
            'quote_state' => 'requires_certified_pricing_engine',
        ];
    }

    public static function admin_panel(): void {
        if (!current_user_can('manage_options')) return;
        $s = self::settings();
        $c = self::capabilities();
        echo '<hr><h2>Fluent Forms, Support & CPT Products — Lab</h2>';
        echo '<p>Only local anonymized lifecycle observations. No submissions, contact data, prices, tickets or orders are sent to ChatbotX.</p>';
        echo '<p>Installed: Fluent Forms ' . esc_html($c['fluent_forms']['installed'] ? 'yes' : 'no') .
             ', Fluent Support ' . esc_html($c['fluent_support']['installed'] ? 'yes' : 'no') .
             ', CPT Woo integration ' . esc_html($c['cpt_woocommerce']['installed'] ? 'yes' : 'no') . '.</p>';
        echo '<form action="' . esc_url(admin_url('admin-post.php')) . '" method="post">';
        wp_nonce_field('cmb_business_save');
        echo '<input type="hidden" name="action" value="cmb_business_save">';
        foreach (['forms' => 'Capture selected Fluent Forms submission events locally',
                  'support' => 'Capture Fluent Support ticket lifecycle locally'] as $k => $label) {
            echo '<p><label><input type="checkbox" name="business[' . esc_attr($k) . ']" value="1" ' .
                checked($s[$k], '1', false) . '> ' . esc_html($label) . '</label></p>';
        }
        echo '<p><label>Allowed form IDs (comma-separated): <input class="regular-text" name="business[form_ids_csv]" value="' .
            esc_attr(implode(',', $s['enabled_form_ids'])) . '"></label></p>';
        submit_button('Save local event observation');
        echo '</form><p><strong>Pricing and booking:</strong> remain with ETG Rate/Quote Engine and WooCommerce; ChatbotX has no price or booking write authority.</p>';
    }

    public static function save(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
        check_admin_referer('cmb_business_save');
        $raw = isset($_POST['business']) && is_array($_POST['business']) ? wp_unslash($_POST['business']) : [];
        $csv = (string) ($raw['form_ids_csv'] ?? '');
        $raw['enabled_form_ids'] = preg_split('/[^0-9]+/', $csv, -1, PREG_SPLIT_NO_EMPTY);
        unset($raw['form_ids_csv']);
        update_option(self::OPTION, self::sanitize($raw), false);
        wp_safe_redirect(admin_url('options-general.php?page=customer-messaging-bridge&cmb_notice=business_saved'));
        exit;
    }
}
