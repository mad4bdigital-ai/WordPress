<?php
declare(strict_types=1);

function etg_result_count_expect($condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$filter = file_get_contents($root . '/includes/Elementor/DynamicTags/FilterValueTag.php');
$observer = file_get_contents($root . '/assets/js/browser-acceptance-observer.js');

etg_result_count_expect(false !== strpos($filter, "array('result_count', 'result_summary')"), 'only ETG result count/summary tags may expose the browser result-count marker');
etg_result_count_expect(false !== strpos($filter, 'data-etg-dfsb-result-count=""'), 'ETG result tags expose a semantic browser acceptance result-count marker');
etg_result_count_expect(false !== strpos($filter, 'observer reads the tag\'s current text'), 'result-count marker deliberately tracks live tag text instead of storing a stale numeric attribute');
etg_result_count_expect(false !== strpos($observer, "'[data-etg-dfsb-result-count]'"), 'browser observer consumes the explicit ETG result-count marker');
etg_result_count_expect(false !== strpos($observer, "node.getAttribute('data-etg-dfsb-result-count') || node.textContent"), 'empty semantic marker falls through to the current live ETG tag text');
etg_result_count_expect(false !== strpos($observer, 'Math.min(5000'), 'DOM proof collection remains bounded to 5000 observed IDs');
etg_result_count_expect(false !== strpos($observer, 'proofIds.slice(0, 100)'), 'Published browser evidence remains bounded to 100 result IDs');
etg_result_count_expect(false !== strpos($observer, "return { count: ids.length, authoritative: false, source: 'dom_item_count_fallback' };"), 'DOM item-count fallback remains explicitly non-authoritative when no result-count marker exists');
etg_result_count_expect(false === strpos($observer, '.jet-listing-dynamic-field'), 'browser observer must not scrape arbitrary JetEngine dynamic-field numbers');

echo "Alpha13 result-count observability smoke tests passed.\n";
