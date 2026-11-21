#!/usr/bin/env bash
set -euo pipefail
SOURCE="$(cd "$(dirname "$0")/../../.." && pwd)/wp-content/mu-plugins/mad4b-render-query-governor.php"
DIR="$(cd "$(dirname "$0")" && pwd)"
TMP="$(mktemp)"
trap 'rm -f "$TMP"' EXIT
python3 - "$SOURCE" "$TMP" <<'PY'
from pathlib import Path
import sys
src=Path(sys.argv[1]).read_text()
a=src.index('function mad4b_render_query_scope_option(')
b=src.index("\n/**\n * Browser QA certificates",a)
Path(sys.argv[2]).write_text('<?php\n'+src[a:b])
PY
php "$DIR/test-render-query-scope.php" "$TMP"
