<?php
namespace ETG\DynamicFilterSEOBridge\IndexFlow;

/** Storage service called only by the central reversible adapter, never by UI POST. */
final class RouteDocumentStore {
    public const OPTION = 'etg_indexflow_routes';
    public const MAX_BYTES = 32768;
    public const MAX_STORED_BYTES = 262144;
    private $factory;
    private $reader;
    public function __construct(?callable $factory = null, ?callable $reader = null) {
        $this->factory = $factory ?: array(self::class, 'architecture');
        $this->reader = $reader ?: array(self::class, 'readStored');
    }

    /** Authoritative DB read: request-local/persistent option caches are not CAS. */
    public static function readStored() {
        global $wpdb;
        if (!isset($wpdb->options) || !method_exists($wpdb, 'get_var') || !method_exists($wpdb, 'prepare')) throw new \RuntimeException('route_storage_database_unavailable');
        $raw = $wpdb->get_var($wpdb->prepare('SELECT LEFT(CAST(option_value AS BINARY), %d) FROM ' . $wpdb->options . ' WHERE option_name = %s LIMIT 1', self::MAX_STORED_BYTES + 1, self::OPTION));
        if (!empty($wpdb->last_error)) throw new \RuntimeException('route_storage_read_failed');
        if (null === $raw) return null;
        if (!is_string($raw) || strlen($raw) > self::MAX_STORED_BYTES || !is_serialized($raw)) throw new \InvalidArgumentException('route_storage_corrupt_or_oversized');
        // The document contract permits plain arrays/scalars only. No objects.
        $document = @unserialize($raw, array('allowed_classes' => false));
        $json = json_encode($document);
        if (!is_array($document) || !is_string($json) || json_decode($json, true) !== $document) throw new \InvalidArgumentException('route_storage_corrupt');
        return $document;
    }

    private function invalidateOptionCache(): void {
        wp_cache_delete(self::OPTION, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
    }

    public static function architecture(array $document): ManagedUrlArchitecture {
        $environment = new WordPressRouteEnvironment();
        $alias = null;
        if (function_exists('jet_smart_filters')) {
            $vendor = jet_smart_filters();
            if (isset($vendor->URL_aliases) && !empty($vendor->URL_aliases->use_url_aliases)
                && is_callable(array($vendor->URL_aliases, 'parse_aliases'))
                && is_callable(array($vendor->URL_aliases, 'apply_aliases_to_url'))) {
                $alias = array(new VendorUrlAliases($vendor->URL_aliases), 'dispatch');
            }
        }
        $native = new NativeFilterUrlAdapter(array($environment, 'term'), $alias);
        $home = parse_url(home_url('/'));
        $origin = $home['scheme'] . '://' . $home['host'] . (isset($home['port']) ? ':' . $home['port'] : '');
        return new ManagedUrlArchitecture($document, $origin, array($environment, 'term'), array($environment, 'page'), array($environment, 'collisions'), array($native, 'dispatch'));
    }

    public static function digest(array $state): string {
        $encoded = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) throw new \InvalidArgumentException('route_encoding_failed');
        return hash('sha256', $encoded);
    }

    public function snapshot(): array {
        $raw = call_user_func($this->reader);
        if (null !== $raw && !is_array($raw)) throw new \InvalidArgumentException('route_storage_corrupt');
        $state = array('exists' => null !== $raw, 'document' => $raw);
        if (strlen((string)json_encode($state)) > self::MAX_BYTES * 2) throw new \InvalidArgumentException('route_snapshot_too_large');
        return $state;
    }

    public function plan(array $document): array {
        $encoded = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded) || strlen($encoded) > self::MAX_BYTES || strlen(serialize($document)) > self::MAX_STORED_BYTES) throw new \InvalidArgumentException('route_document_too_large');
        if (json_decode($encoded, true) !== $document) throw new \InvalidArgumentException('route_document_not_plain_data');
        $new = call_user_func($this->factory, $document);
        $before = $this->snapshot();
        $old = call_user_func($this->factory, $before['document'] ?? array('version' => 1, 'profiles' => array(), 'routes' => array()));
        $migration = $old->planMigration($new);
        return array('contract' => 'indexflow.route-storage-plan.v1', 'read_only' => true, 'authorizing' => false,
            'expected_revision' => self::digest($before), 'expected_document_sha256' => self::digest($document),
            'expected_dependencies_sha256' => $new->revision(), 'expected_migration_sha256' => self::digest($migration),
            'migration' => $migration, 'preview' => $new->preview(), 'runtime_enabled' => false,
            'unchanged' => $before['exists'] && $before['document'] === $document);
    }

    /** Must execute while the adapter owns the shared option lease. */
    public function apply(array $document, array $review): array {
        $plan = $this->plan($document);
        foreach (array('expected_revision', 'expected_document_sha256', 'expected_dependencies_sha256', 'expected_migration_sha256') as $key) {
            if (!is_string($review[$key] ?? null) || !hash_equals($plan[$key], $review[$key])) throw new \InvalidArgumentException('route_plan_drift_' . $key);
        }
        if (!$plan['unchanged']) { $this->invalidateOptionCache(); update_option(self::OPTION, $document, false); }
        $after = $this->snapshot();
        if (!$after['exists'] || $after['document'] !== $document) throw new \RuntimeException('route_write_readback_failed');
        return array('verified' => true, 'unchanged' => $plan['unchanged'], 'state_sha256' => self::digest($after), 'runtime_enabled' => false);
    }

    /** Central undo has already checked journal/authority; retain exact-state CAS. */
    public function restore(array $before, string $expectedAfter): array {
        if (!hash_equals(self::digest($this->snapshot()), $expectedAfter)) throw new \InvalidArgumentException('route_undo_state_drift');
        if (!is_bool($before['exists'] ?? null) || ($before['exists'] && !is_array($before['document'] ?? null))
            || (!$before['exists'] && null !== ($before['document'] ?? null))
            || strlen((string)json_encode($before)) > self::MAX_BYTES * 2) throw new \InvalidArgumentException('route_undo_snapshot_invalid');
        // Do not revalidate old term/page dependencies here: a failed apply or
        // removed translation must not prevent restoring the captured bytes.
        $this->invalidateOptionCache();
        if ($before['exists']) update_option(self::OPTION, $before['document'], false);
        else delete_option(self::OPTION);
        if ($this->snapshot() !== $before) throw new \RuntimeException('route_undo_readback_failed');
        return array('restored' => true, 'verified' => true, 'runtime_enabled' => false);
    }
}
