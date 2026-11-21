#!/usr/bin/env bash
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
PYTHONDONTWRITEBYTECODE=1 python3 "$ROOT/tests/test_adaptive_policy.py"
node "$ROOT/tests/test_rum_js.js" "$ROOT/assets/mad4b-css-rum-client.js"
node --check "$ROOT/assets/web-vitals-6.2.3.iife.js"
node --check "$ROOT/assets/mad4b-css-rum-client.js"
php -l "$ROOT/438-mad4b-adaptive-css-rum-v1.php"
cat "$ROOT/tests/php-pre.php" "$ROOT/438-mad4b-adaptive-css-rum-v1.php" "$ROOT/tests/php-post.php" "$ROOT/tests/php-rest.php" "$ROOT/tests/php-scope.php" | php
