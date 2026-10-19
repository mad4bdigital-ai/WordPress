<?php
/** Separate Staging route-storage authority. No routing or SEO activation. */
if (!defined('ABSPATH')) exit;

final class MAD4B_SCP_IndexFlow_Routes_Adapter extends MAD4B_SCP_Adapter_Base {
    const PLAN = 'indexflow/routes-plan';
    const APPLY = 'indexflow/routes-apply';
    const VERIFY = 'indexflow/routes-verify';
    const UNDO = 'mad4b.rollback.indexflow-routes.v1';
    private $lease = null;
    public function id() { return 'indexflow-routes'; }
    public function label() { return 'IndexFlow Staging Route Registry'; }
    protected function certified_provider_key() { return 'indexflow-routes'; }
    protected function mutation_requires_certification() { return true; }
    protected function detect_plugin_version() { return defined('ETG_DFSB_VERSION') ? ETG_DFSB_VERSION : ''; }
    public function is_available() {
        return function_exists('wp_get_environment_type') && 'staging' === wp_get_environment_type()
            && class_exists('\\ETG\\DynamicFilterSEOBridge\\IndexFlow\\RouteDocumentStore')
            && class_exists('\\ETG\\DynamicFilterSEOBridge\\Config\\Configuration')
            && class_exists('\\ETG\\DynamicFilterSEOBridge\\Runtime\\OptionMutationGate');
    }
    public function ability_names() { return array('read' => array(self::PLAN, self::VERIFY), 'admin' => array(self::APPLY), 'content' => array(), 'write' => array()); }
    public function reversible_contracts() { return array(self::APPLY => self::UNDO); }
    public function register_abilities() {
        $document = array('type' => 'object', 'maxProperties' => 3, 'additionalProperties' => true);
        $hex = array('type' => 'string', 'pattern' => '^[a-f0-9]{64}$');
        $read = array($this, 'can_read_routes'); $write = array($this, 'can_write_routes');
        $this->add_ability(self::PLAN, 'Plan IndexFlow route storage', 'routes_plan', $read,
            $this->schema(array('document' => $document), array('document')), 'read', true, false, true);
        $this->add_ability(self::VERIFY, 'Verify IndexFlow route storage', 'routes_verify', $read,
            $this->schema(array('expected_sha256' => $hex), array()), 'read', true, false, true);
        $fields = array('document' => $document, 'confirmed' => array('type' => 'boolean', 'const' => true));
        foreach (array('expected_revision', 'expected_document_sha256', 'expected_dependencies_sha256', 'expected_migration_sha256') as $key) $fields[$key] = $hex;
        $this->add_ability(self::APPLY, 'Apply reviewed IndexFlow route storage on Staging', 'routes_apply', $write,
            $this->schema($fields, array_keys($fields)), 'admin', false, true, false);
    }
    public function can_read_routes($input = array()) {
        return $this->is_available() && current_user_can('manage_options') && MAD4B_SCP_Policy::can_read();
    }
    public function can_write_routes($input = array()) {
        return $this->is_available() && current_user_can('manage_options');
    }
    private function store() { return new \ETG\DynamicFilterSEOBridge\IndexFlow\RouteDocumentStore(); }
    private function guard() {
        if (!$this->can_write_routes()) return new WP_Error('indexflow_staging_admin_required', 'Route storage requires a Staging administrator.');
        if ((new \ETG\DynamicFilterSEOBridge\Config\Configuration())->enabled()) return new WP_Error('indexflow_global_on', 'Global Bridge must be OFF.');
        return true;
    }
    private function error(\Throwable $error) {
        $reason = $error->getMessage();
        if (!preg_match('/^[a-z0-9_]{1,120}$/D', $reason)) $reason = 'route_operation_failed';
        return new WP_Error('indexflow_route_contract_failed', $reason);
    }
    public function routes_plan($input = array()) {
        if (!$this->can_read_routes() || !is_array($input['document'] ?? null)) return new WP_Error('indexflow_plan_denied', 'A bounded route document and authorized read are required.');
        try { return $this->store()->plan($input['document']); } catch (\Throwable $e) { return $this->error($e); }
    }
    public function routes_verify($input = array()) {
        if (!$this->can_read_routes()) return new WP_Error('indexflow_verify_denied', 'Authorized Staging administrator read required.');
        try {
            $sha = \ETG\DynamicFilterSEOBridge\IndexFlow\RouteDocumentStore::digest($this->store()->snapshot());
            $expected = $input['expected_sha256'] ?? '';
            return array('state_sha256' => $sha, 'expected_matches' => is_string($expected) && hash_equals($sha, $expected),
                'read_only' => true, 'authorizing' => false, 'runtime_activation_performed' => false,
                'runtime_status' => 'not_evaluated_by_storage_verification');
        } catch (\Throwable $e) { return $this->error($e); }
    }
    public function acquire_reversible_scope($ability, array $input) {
        $guard = $this->guard(); if (is_wp_error($guard)) return $guard;
        if (self::APPLY !== $ability || null !== $this->lease) return new WP_Error('indexflow_scope_invalid', 'Invalid or nested route mutation scope.');
        $lease = \ETG\DynamicFilterSEOBridge\Runtime\OptionMutationGate::acquire(\ETG\DynamicFilterSEOBridge\IndexFlow\RouteDocumentStore::OPTION);
        if (!is_wp_error($lease)) $this->lease = $lease;
        return $lease;
    }
    public function acquire_reversible_undo_scope($ability, array $target) {
        if ($target !== array('kind' => 'indexflow_routes', 'id' => 'registry')) return new WP_Error('indexflow_undo_target_invalid', 'Unexpected undo target.');
        return $this->acquire_reversible_scope($ability, array());
    }
    public function release_reversible_scope($lease) {
        if (!is_array($lease) || $lease !== $this->lease) return;
        \ETG\DynamicFilterSEOBridge\Runtime\OptionMutationGate::release($lease); $this->lease = null;
    }
    public function capture_reversible_state($ability, array $input) {
        $guard = $this->guard(); if (is_wp_error($guard)) return $guard;
        if (self::APPLY !== $ability || true !== ($input['confirmed'] ?? null) || !is_array($input['document'] ?? null) || null === $this->lease) return new WP_Error('indexflow_capture_denied', 'Reviewed confirmation, document and storage lease required.');
        try {
            $plan = $this->store()->plan($input['document']);
            foreach (array('expected_revision', 'expected_document_sha256', 'expected_dependencies_sha256', 'expected_migration_sha256') as $key) {
                if (!is_string($input[$key] ?? null) || !hash_equals($plan[$key], $input[$key])) throw new \InvalidArgumentException('route_plan_drift_' . $key);
            }
            return array('target_type' => 'indexflow_routes', 'target_id' => 'registry',
                'target' => array('kind' => 'indexflow_routes', 'id' => 'registry'), 'state' => $this->store()->snapshot());
        } catch (\Throwable $e) { return $this->error($e); }
    }
    public function read_reversible_state($ability, array $target) {
        if (self::APPLY !== $ability || $target !== array('kind' => 'indexflow_routes', 'id' => 'registry')) return new WP_Error('indexflow_readback_target_invalid', 'Unexpected route target.');
        try { return $this->store()->snapshot(); } catch (\Throwable $e) { return $this->error($e); }
    }
    public function routes_apply($input = array()) {
        $guard = $this->guard(); if (is_wp_error($guard)) return $guard;
        // Defense in depth: the normal adapter executor performs this check too.
        $certified = MAD4B_SCP_Provider_Contracts::mutation_guard('indexflow-routes', true);
        if (is_wp_error($certified)) return $certified;
        $ctx = MAD4B_SCP_Reversible_Adapter_Mutations::current_mutation_context();
        if (!is_array($ctx) || empty($ctx['mutation_id']) || $this->id() !== ($ctx['adapter_id'] ?? '') || null === $this->lease) return new WP_Error('indexflow_unjournaled_write', 'Central reversible journal and storage lease required.');
        $before = $this->capture_reversible_state(self::APPLY, $input); if (is_wp_error($before)) return $before;
        try { return $this->store()->apply($input['document'], $input); } catch (\Throwable $e) { return $this->error($e); }
    }
    public function restore_reversible_state($ability, array $target, array $state, array $record) {
        $guard = $this->guard(); if (is_wp_error($guard)) return $guard;
        if (!MAD4B_SCP_Policy::can_mutate() || null === $this->lease || self::APPLY !== $ability
            || $target !== array('kind' => 'indexflow_routes', 'id' => 'registry') || !is_string($record['after_sha256'] ?? null)) return new WP_Error('indexflow_undo_denied', 'Independent central undo authority, target and lease required.');
        try { return $this->store()->restore($state, $record['after_sha256']); } catch (\Throwable $e) { return $this->error($e); }
    }
}
add_action('mad4b_scp_register_adapters', static function ($registry) {
    if (is_object($registry) && method_exists($registry, 'register')) $registry->register(new MAD4B_SCP_IndexFlow_Routes_Adapter());
});
