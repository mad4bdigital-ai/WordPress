#!/usr/bin/env python3
"""Regression: errors from either generation snapshot must remain non-authorizing."""
from pathlib import Path
s=(Path(__file__).resolve().parents[1]/'includes/class-mad4b-scp-connector-resilience.php').read_text()
a=s.index('public static function generation_fenced_compact_read(')
b=s.index('public static function execute_read(',a)
region=s[a:b]
for phase in ('before_read','after_read'):
    assert "'phase' => '"+phase+"'" in region, phase
assert region.count("catch ( Throwable $error )") >= 2
assert region.count("'mad4b_generation_fenced_read_snapshot_exception'") == 2
assert region.count("'mutation_performed' => false") >= 2
assert region.count("'raw_error_message_exposed' => false") >= 2
assert region.index("'phase' => 'before_read'") < region.index('self::safe_read( $name')
assert region.index("'phase' => 'after_read'") > region.index('self::safe_read( $name')
print('GENERATION_SNAPSHOT_EXCEPTION_GUARDS: PASS')
