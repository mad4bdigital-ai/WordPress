#!/usr/bin/env python3
"""Fail-closed audit: CSS-only private responses never claim HTML admission."""
from pathlib import Path
import re
import sys
root=Path(__file__).resolve().parents[2]
f=root/'wp-content/mu-plugins/424-mad4b-private-html-css-bundler-v1.php'
s=f.read_text()
def assert_case(name,value):
 if not value:raise SystemExit('FAIL '+name)
 print('PASS',name)
match=re.search(r'public static function protect_headers\(\): void \{(?P<body>.*?)\n    \}',s,re.S)
assert_case('private CSS handler exists',match is not None)
b=match.group('body')
assert_case('private module remains gated by original activation',
            "if (!self::$active || headers_sent())return;" in b)
assert_case('conflicting public HTML admission is removed',
            "header_remove('X-MAD4B-Cache-Eligibility');" in b)
assert_case('conflicting browser public claims are removed',
            "header_remove('X-MAD4B-Browser-HTML');" in b and
            "header_remove('X-MAD4B-Browser-HTML-TTL');" in b)
assert_case('private no-store is retained',
            "header('Cache-Control: private, no-store, max-age=0',true);" in b)
assert_case('private CSS delivery certificate is retained',
            "header('X-MAD4B-Private-CSS-Delivery: css-bundle-only-v1',true);" in b)
assert_case('public admission removal precedes private Cache-Control',
            b.index("header_remove('X-MAD4B-Cache-Eligibility')") <
            b.index("header('Cache-Control: private"))
assert_case('original signed preference security unchanged',
            'trusted_signed_preferences(string $raw)' in s and
            'if ($raw!==\'\' && !self::trusted_signed_preferences($raw))return false;' in s)
assert_case('original CSS-only JavaScript exclusion unchanged',
            "if (!in_array('^',$excludes,true))$excludes[]='^';" in s)
assert_case('source public-page opt-in remains a separate module',
            (root/'wp-content/mu-plugins/mad4b-general-public-page-origin-cache.php').is_file())
print('MAD4B_PRIVATE_CSS_HEADER_PRECEDENCE_REGRESSION 10')
