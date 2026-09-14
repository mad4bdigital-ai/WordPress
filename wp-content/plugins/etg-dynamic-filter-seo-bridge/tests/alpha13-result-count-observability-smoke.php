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
etg_result_count_expect(false !== strpos($filter, "data-etg-dfsb-result-count"), 'ETG result tags expose an explicit browser acceptance result-count marker');
etg_result_count_expect(false !== strpos($filter, "resolver->value('result_count', $this->etgPreviewContext())"), 'result summary derives the marker from the authoritative result_count token instead of parsing localized text');
etg_result_count_expect(false !== strpos($filter, 'is_numeric($count)'), 'result-count marker is emitted only for numeric authoritative values');
etg_result_count_expect(false !== strpos($observer, "'[data-etg-dfsb-result-count]'"), 'browser observer consumes the explicit ETG result-count marker');
etg_result_count_expect(false !== strpos($observer, 'return ids.length;'), 'DOM item-count fallback remains bounded for pages without an explicit marker');
etg_result_count_expect(false === strpos($observer, '.jet-listing-dynamic-field'), 'browser observer must not scrape arbitrary JetEngine dynamic-field numbers');

echo "Alpha13 result-count observability smoke tests passed.\n";
