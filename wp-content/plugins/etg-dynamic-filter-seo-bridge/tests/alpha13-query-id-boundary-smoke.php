<?php
declare(strict_types=1);

if (!function_exists('etg_qid_expect')) {
    function etg_qid_expect($condition, string $message): void {
        if (!$condition) {
            fwrite(STDERR, "FAILED: {$message}\n");
            exit(1);
        }
    }
}
if (!function_exists('etg_qid_same')) {
    function etg_qid_same($expected, $actual, string $message): void {
        if ($expected !== $actual) {
            fwrite(STDERR, "FAILED: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
            exit(1);
        }
    }
}

$root = dirname(__DIR__);
require_once $root . '/includes/Identifiers/QueryId.php';
require_once $root . '/includes/Diagnostics/RuntimeInventoryQueryTrait.php';
require_once $root . '/includes/Diagnostics/InventoryReconcilerBindingTrait.php';
require_once $root . '/includes/Diagnostics/InventoryReconcilerSupportTrait.php';

use ETG\DynamicFilterSEOBridge\Diagnostics\InventoryReconcilerBindingTrait;
use ETG\DynamicFilterSEOBridge\Diagnostics\InventoryReconcilerSupportTrait;
use ETG\DynamicFilterSEOBridge\Diagnostics\RuntimeInventoryQueryTrait;
use ETG\DynamicFilterSEOBridge\Identifiers\QueryId;

final class ETG_QueryIdentityRuntimeInventoryHarness {
    use RuntimeInventoryQueryTrait;
    public function key(string $id, string $customId): string { return $this->queryIdentityKey($id, $customId); }
}

final class ETG_QueryIdentityReconcilerSupportHarness {
    use InventoryReconcilerSupportTrait;
    public function key(array $query): string { return $this->queryIdentityKey($query); }
}

final class ETG_QueryIdentityReconcilerBindingHarness {
    use InventoryReconcilerBindingTrait;
    public function resolve(string $provider, string $providerQueryId, array $route, array $queryIndex, array $conflicts, array $topology): array {
        return $this->resolveRouteIdentity($provider, $providerQueryId, $route, $queryIndex, $conflicts, $topology);
    }
    private function queryIdentityCompleteFromTopology(array $topology): bool { return true; }
}

etg_qid_same('myGrid', QueryId::normalize('myGrid'), 'shared QueryId preserves mixed case');
etg_qid_same('mygrid', QueryId::normalize('mygrid'), 'shared QueryId preserves lowercase identity');
etg_qid_expect(QueryId::normalize('myGrid') !== QueryId::normalize('mygrid'), 'case-distinct Query IDs remain distinct');

$runtimeHarness = new ETG_QueryIdentityRuntimeInventoryHarness();
etg_qid_same('myGrid', $runtimeHarness->key('77', 'myGrid'), 'runtime inventory preserves mixed-case custom query identity');
etg_qid_same('mygrid', $runtimeHarness->key('78', 'mygrid'), 'runtime inventory keeps case-distinct custom query identities separate');

$supportHarness = new ETG_QueryIdentityReconcilerSupportHarness();
etg_qid_same('myGrid', $supportHarness->key(array('identity_key'=>'myGrid')), 'reconciler identity index preserves mixed case');
etg_qid_same('mygrid', $supportHarness->key(array('identity_key'=>'mygrid')), 'reconciler identity index preserves lowercase peer separately');

$bindingHarness = new ETG_QueryIdentityReconcilerBindingHarness();
$explicit = $bindingHarness->resolve('jet-engine', 'myGrid', array('query_builder_query_id'=>'myGrid'), array(), array(), array());
etg_qid_same(true, $explicit['resolved'], 'explicit mixed-case query-builder identity resolves');
etg_qid_same('myGrid', $explicit['query_builder_query_id'], 'explicit query-builder identity preserves mixed case');

$topology = array('available'=>true, 'contract'=>'etg.test', 'truncated'=>false, 'bindings'=>array(
    array('provider'=>'jet-engine','provider_query_id'=>'myGrid','query_builder_custom_query_id'=>'myGrid','status'=>'verified'),
    array('provider'=>'jet-engine','provider_query_id'=>'mygrid','query_builder_custom_query_id'=>'mygrid','status'=>'verified'),
));
$resolved = $bindingHarness->resolve('jet-engine', 'myGrid', array(), array(), array(), $topology);
etg_qid_same(true, $resolved['resolved'], 'topology mixed-case identity resolves');
etg_qid_same('myGrid', $resolved['query_builder_query_id'], 'topology does not collapse myGrid into mygrid');

$files = array(
    'result_count_adapter' => $root . '/includes/SEO/JetEngineResultCountAdapter.php',
    'publication_count_probe' => $root . '/includes/SEO/PublicationResultCountProbe.php',
    'post_type_observer' => $root . '/includes/Runtime/PostTypeObserver.php',
    'runtime_inventory_query' => $root . '/includes/Diagnostics/RuntimeInventoryQueryTrait.php',
    'inventory_reconciler' => $root . '/includes/Diagnostics/InventoryReconciler.php',
    'inventory_binding' => $root . '/includes/Diagnostics/InventoryReconcilerBindingTrait.php',
    'inventory_support' => $root . '/includes/Diagnostics/InventoryReconcilerSupportTrait.php',
);
foreach ($files as $label => $path) {
    $source = file_get_contents($path);
    etg_qid_expect(is_string($source) && false !== strpos($source, 'QueryId::normalize'), $label . ' must use the shared case-preserving QueryId contract');
}

$forbidden = array(
    "sanitize_key( (string) ( \$context['query_id'] ?? '' ) )",
    "sanitize_key( (string) ( \$parsed['query_id'] ?? '' ) )",
    "sanitize_key( (string) \$query->query_id )",
    "sanitize_key((string)((\$route['provider_query_id']??'')?:(\$route['query_id']??'')))",
    "sanitize_key((string)(\$binding['provider_query_id']??''))",
    "sanitize_key((string)(\$binding['query_builder_custom_query_id']??''))",
    "sanitize_key((string)(\$conflict['identity_key']??''))",
);
foreach ($files as $label => $path) {
    $source = file_get_contents($path);
    foreach ($forbidden as $needle) {
        etg_qid_expect(false === strpos($source, $needle), $label . ' must not slug-normalize Query ID identity');
    }
}

echo "Alpha13 Query-ID boundary smoke tests passed.\n";
