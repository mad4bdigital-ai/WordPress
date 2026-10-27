<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-mad4b-scp-chatbotx-governed-writes.php';
require_once __DIR__ . '/class-mad4b-scp-chatbotx-write-preflight.php';

/**
 * Source-reviewed ChatbotX provider adapter for ETG WooCommerce Lab.
 *
 * Six read and three explicitly governed write abilities. No arbitrary endpoint invocation,
 * plugin callbacks, order/customer data, token output or mutation authority.
 * Registration through the existing reviewed bundled-class policy only.
 */
final class MAD4B_SCP_ChatbotX_Provider_Adapter extends MAD4B_SCP_Adapter_Base {
    const PLUGIN = 'customer-messaging-bridge/customer-messaging-bridge.php';
    // Site-neutral: the installed, explicitly reviewed provider owns its own
    // network origin. Control Plane cannot independently override that host.
    private function origin() {
        return $this->is_available() ? Customer_Messaging_Bridge::API_ORIGIN : '';
    }
    const CONTRACT = 'mad4b.chatbotx-provider.v1';

    public function id() { return 'chatbotx-provider'; }
    public function label() { return 'ChatbotX Omnichannel Provider'; }
    protected function detect_plugin_version() {
        $p = WP_PLUGIN_DIR . '/' . self::PLUGIN;
        if ( ! is_file($p) || ! function_exists('get_file_data') ) return '';
        $meta = get_file_data($p, array('Version' => 'Version'), 'plugin');
        return isset($meta['Version']) && is_string($meta['Version']) ? substr($meta['Version'], 0, 30) : '';
    }
    public function is_available() {
        return defined('WP_PLUGIN_DIR')
            && in_array(self::PLUGIN, (array) get_option('active_plugins', array()), true)
            && class_exists('Customer_Messaging_Bridge', false)
            && class_exists('CMB_Channels', false)
            && class_exists('CMB_Commerce', false)
            && class_exists('CMB_Webhooks', false);
    }
    public function ability_names() {
        return array('read' => array(
            'mad4b/chatbotx-provider-snapshot',
            'mad4b/chatbotx-channels-read',
            'mad4b/chatbotx-remote-health',
            'mad4b/chatbotx-business-context',
            'mad4b/chatbotx-cpt-product-source',
            'mad4b/chatbotx-write-preflight',
        ), 'content' => array(), 'write' => array(
            'mad4b/chatbotx-support-create-ticket',
            'mad4b/chatbotx-support-add-note',
            'mad4b/chatbotx-cpt-create-draft'
        ), 'admin' => array());
    }
    protected function mutation_requires_certification() { return true; }

    public function reversible_contracts() { return array(); }
    public function register_abilities() {
        if ( ! $this->is_available() ) return;
        $permission = array('MAD4B_SCP_Policy', 'can_read');
        $this->add_ability('mad4b/chatbotx-provider-snapshot',
            'Read ChatbotX Provider and WordPress Surface Inventory',
            'provider_snapshot', $permission, $this->schema(array()));
        $this->add_ability('mad4b/chatbotx-channels-read',
            'Read Connected ChatbotX Workspace Inboxes',
            'channels_read', $permission, $this->schema(array()));
        $this->add_ability('mad4b/chatbotx-remote-health',
            'Probe Fixed ChatbotX Health Endpoint',
            'remote_health', $permission, $this->schema(array()));
        $this->add_ability('mad4b/chatbotx-write-preflight',
            'Assess ChatbotX Write Prerequisites Without Mutating Anything',
            'write_preflight', $permission, $this->schema(array(
                'ability' => array('type'=>'string', 'enum'=>array_keys(MAD4B_SCP_ChatbotX_Write_Preflight::ACTIONS)),
                'customer_id'=>array('type'=>'integer','minimum'=>1),
                'mailbox_id'=>array('type'=>'integer','minimum'=>1),
                'ticket_id'=>array('type'=>'integer','minimum'=>1),
                'post_type'=>array('type'=>'string','enum'=>array('tours-and-activities','properties','transportations'))
            ),array('ability')));
        // All writes pass SCP's site, provider and centralized authorization
        // gates before a native provider API is called. No arbitrary REST.
        $admin=array('MAD4B_SCP_Policy','can_admin');
        $idempotency=array('type'=>'string','minLength'=>16,'maxLength'=>100);
        $this->add_ability('mad4b/chatbotx-support-create-ticket',
            'Create Ticket For Existing Fluent Support Customer',
            'support_create_ticket',$admin,$this->schema(array(
                'customer_id'=>array('type'=>'integer','minimum'=>1),
                'mailbox_id'=>array('type'=>'integer','minimum'=>1),
                'title'=>array('type'=>'string','minLength'=>1,'maxLength'=>150),
                'content'=>array('type'=>'string','minLength'=>1,'maxLength'=>2000),
                'idempotency_key'=>$idempotency
            ),array('customer_id','mailbox_id','title','content','idempotency_key')),
            'write',false,true,true);
        $this->add_ability('mad4b/chatbotx-support-add-note',
            'Add Agent-only Support Note','support_add_note',$admin,$this->schema(array(
                'ticket_id'=>array('type'=>'integer','minimum'=>1),
                'content'=>array('type'=>'string','minLength'=>1,'maxLength'=>2000),
                'idempotency_key'=>$idempotency
            ),array('ticket_id','content','idempotency_key')),
            'write',false,true,true);
        $this->add_ability('mad4b/chatbotx-cpt-create-draft',
            'Create Unpublished Travel CPT Draft','cpt_create_draft',$admin,$this->schema(array(
                'post_type'=>array('type'=>'string','enum'=>array('tours-and-activities','properties','transportations')),
                'title'=>array('type'=>'string','minLength'=>1,'maxLength'=>180),
                'idempotency_key'=>$idempotency
            ),array('post_type','title','idempotency_key')),
            'write',false,true,true);
        $this->add_ability('mad4b/chatbotx-business-context',
            'Read Fluent Forms, Support and CPT WooCommerce Integration Readiness',
            'business_context', $permission, $this->schema(array()));
        $this->add_ability('mad4b/chatbotx-cpt-product-source',
            'Read Canonical WooCommerce Product-to-CPT Mapping Without Price',
            'cpt_product_source', $permission,
            $this->schema(array('product_id' => array('type' => 'integer', 'minimum' => 1)), array('product_id')));
    }

    public function write_preflight($input = array()) {
        if (!$this->is_available()) return $this->unavailable_error();
        return MAD4B_SCP_ChatbotX_Write_Preflight::assess($input);
    }
    public function support_create_ticket($input) {
        return MAD4B_SCP_ChatbotX_Governed_Writes::support_create_ticket((array)$input);
    }
    public function support_add_note($input) {
        return MAD4B_SCP_ChatbotX_Governed_Writes::support_add_note((array)$input);
    }
    public function cpt_create_draft($input) {
        return MAD4B_SCP_ChatbotX_Governed_Writes::cpt_create_draft((array)$input);
    }
    private function scope() {
        return array(
            'contract' => self::CONTRACT,
            'source' => 'installed_lab_bridge',
            'provider' => 'chatbotx',
            'read_only' => true,
            'authorizing' => false,
            'mutations_enabled' => false,
            'mcp_read_available' => $this->is_available(),
            'write_abilities_declared' => 3,
            'central_mutation_gate_ready' => MAD4B_SCP_Policy::can_mutate(),
            'provider_certification_required' => true,
        );
    }
    public function business_context($input = array()) {
        if (!$this->is_available() || !class_exists('CMB_Business_Context', false)) {
            return $this->unavailable_error();
        }
        return array_merge($this->scope(), array(
            'business_integrations' => CMB_Business_Context::capabilities()
        ));
    }
    public function cpt_product_source($input = array()) {
        if (!$this->is_available() || !class_exists('CMB_Business_Context', false)) {
            return $this->unavailable_error();
        }
        $id = isset($input['product_id']) ? absint($input['product_id']) : 0;
        return array_merge($this->scope(), array(
            'product_context' => CMB_Business_Context::product_context($id)
        ));
    }

    public function provider_snapshot($input = array()) {
        if ( ! $this->is_available() ) return $this->unavailable_error();
        $site = Customer_Messaging_Bridge::settings();
        $commerce = CMB_Commerce::settings();
        $counts = get_option(CMB_Commerce::OUTBOX, array());
        $legacy = get_option(Customer_Messaging_Bridge::AUDIT, array());
        $routes = array(
            array('method' => 'POST', 'path' => '/cmb/v1/events',
                'state' => CMB_Webhooks::secret() ? 'configured_not_sender_certified' : 'disabled_without_private_secret'),
            array('method' => 'GET', 'path' => '/cmb/v1/status',
                'state' => 'wordpress_administrator_only'),
        );
        // Read the exact registered metadata if the REST server has already
        // been bootstrapped; never trigger rest_api_init during inventory.
        global $wp_rest_server;
        $registered = is_object($wp_rest_server) && method_exists($wp_rest_server, 'get_routes')
            ? array_keys((array) $wp_rest_server->get_routes()) : array();
        foreach ($routes as &$route) $route['registered_in_current_lifecycle'] = in_array($route['path'], $registered, true);
        unset($route);
        // Enumerate new bridge-namespace paths without calling their
        // callbacks. Unknown endpoints are discovery candidates, not tools.
        $extra_total = 0;
        if (is_object($wp_rest_server)) foreach ($registered as $path) {
            if (!is_string($path) || 0 !== strpos($path, '/cmb/v1/')) continue;
            $known = false;
            foreach ($routes as $row) if ($row['path'] === $path) { $known = true; break; }
            if ($known) continue;
            ++$extra_total;
            if ($extra_total > 64) continue;
            $routes[] = array(
                'method' => 'unreviewed',
                'path' => substr($path, 0, 220),
                'state' => 'discovered_requires_schema_and_permission_review',
                'registered_in_current_lifecycle' => true,
                'execution_authorized' => false,
            );
        }
        return array_merge($this->scope(), array(
            'plugin_file' => self::PLUGIN,
            'plugin_version' => $this->detect_plugin_version(),
            'webchat' => array(
                'enabled' => Customer_Messaging_Bridge::ready(),
                'workspace_id' => (string) $site['workspace_id'],
                'webchat_id' => (string) $site['webchat_id'],
                'browser_round_trip_certified' => false,
            ),
            'local_commerce' => array(
                'orders_observed' => '1' === $commerce['orders'],
                'customers_observed' => '1' === $commerce['customers'],
                'cart_observation' => '1' === $commerce['abandoned_cart'],
                'retained_events' => is_array($counts) ? count($counts) : 0,
                'legacy_retained_events' => is_array($legacy) ? count($legacy) : 0,
                'outbound_sync_enabled' => false,
            ),
            'business_integrations' => class_exists('CMB_Business_Context', false)
                ? CMB_Business_Context::capabilities()
                : array('state' => 'adapter_missing'),
            'channels' => array(
                'known_types' => array_keys(CMB_Channels::CHANNELS),
                'workspace_token_present' => '' !== CMB_Channels::api_token(),
                'provider_oauth_verified' => false,
            ),
            'surfaces' => array(
                'rest' => $routes,
                'unreviewed_route_count' => $extra_total,
                'unreviewed_route_truncated' => $extra_total > 64,
                'admin_post' => array('cmb_save','cmb_test','cmb_commerce_save','cmb_commerce_clear','cmb_business_save'),
                'registered_options' => array('cmb_settings_v1', 'cmb_commerce_settings_v1', 'cmb_business_integrations_v1'),
                'settings_write_via_mcp' => false,
            ),
            'external_contracts' => array(
                array('method' => 'GET', 'path' => '/api/health', 'origin' => $this->origin()),
                array('method' => 'GET', 'path' => '/api/v1/inboxes', 'origin' => $this->origin(),
                    'requires_scope' => 'inbox', 'permission' => 'read_only'),
            ),
            'authorization_notice' => 'Discovered surfaces do not grant permission to invoke external writes, callbacks or arbitrary routes.',
        ));
    }
    public function channels_read($input = array()) {
        if ( ! $this->is_available() ) return $this->unavailable_error();
        $token = CMB_Channels::api_token();
        if ( '' === $token ) return array_merge($this->scope(), array('state' => 'needs_read_only_inbox_token', 'inboxes' => array()));
        // Client discovery caches in wp_options; this adapter intentionally
        // performs an uncached read without any WordPress side effects.
        $items = array();
        for ($page = 1; $page <= 10; $page++) {
            $response = wp_remote_get($this->origin() . '/api/v1/inboxes?page=' . $page . '&perPage=50', array(
                'timeout' => 8, 'redirection' => 0, 'sslverify' => true,
                'headers' => array('Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'),
            ));
            if ( is_wp_error($response) ) return array_merge($this->scope(), array('state' => 'transport_error', 'inboxes' => array()));
            if (200 !== (int) wp_remote_retrieve_response_code($response)) {
                return array_merge($this->scope(), array('state' => 'remote_status_' . (int) wp_remote_retrieve_response_code($response), 'inboxes' => array()));
            }
            $payload = json_decode((string) wp_remote_retrieve_body($response), true);
            $pages = is_array($payload) ? ($payload['pageCount'] ?? 1) : null;
            $entries = is_array($payload) ? ($payload['data'] ?? null) : null;
            if (!is_int($pages) || $pages < 1 || $pages > 10 || !is_array($entries)
                || ($entries && array_keys($entries) !== range(0, count($entries) - 1))) {
                return array_merge($this->scope(), array('state' => 'invalid_remote_response', 'inboxes' => array()));
            }
            foreach ($entries as $item) {
                if (!is_array($item) || !isset($item['channel'], $item['id'])) continue;
                $channel = sanitize_key((string) $item['channel']);
                if (!isset(CMB_Channels::CHANNELS[$channel])) continue;
                $items[] = array('channel' => $channel,
                    'inbox_id' => sanitize_text_field((string) $item['id']),
                    'status' => sanitize_key((string) ($item['status'] ?? 'unknown')));
            }
            if ($page >= $pages) break;
        }
        return array_merge($this->scope(), array('state' => 'connected_to_api', 'inboxes' => $items,
            'provider_round_trip_verified' => false, 'inbox_count' => count($items)));
    }
    public function remote_health($input = array()) {
        if ( ! $this->is_available() ) return $this->unavailable_error();
        $response = wp_remote_get($this->origin() . '/api/health', array(
            'timeout' => 7, 'redirection' => 0, 'sslverify' => true,
            'headers' => array('Accept' => 'application/json'),
        ));
        if ( is_wp_error($response) ) return array_merge($this->scope(), array('http_status' => 0, 'healthy' => false));
        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        return array_merge($this->scope(), array('http_status' => $status,
            'healthy' => 200 === $status && is_array($data) && ($data['status'] ?? '') === 'healthy'));
    }
}
