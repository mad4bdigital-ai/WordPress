#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../../../.." && pwd)"
SRC="$ROOT/wp-content/mu-plugins/mad4b-render-query-governor.php"
D="$(cd "$(dirname "$0")" && pwd)"
TMP="$(mktemp)"
trap 'rm -f "$TMP"' EXIT
python3 - "$SRC" "$TMP" <<'PY'
from pathlib import Path
import sys
text=Path(sys.argv[1]).read_text()
a=text.index('function mad4b_render_query_scope_option(')
b=text.index('\n/**\n * Browser QA certificates',a)
c=text.index('function mad4b_render_query_is_generated_meta_artifact(')
d=text.index('\nforeach ( [',c)
start=text.index("add_action('transition_post_status'",b)
end=text.index("add_action( 'save_post'",start)
a2=text.index("add_action( 'set_object_terms'",end)
b2=text.index("add_action( 'edited_term'",a2)
Path(sys.argv[2]).write_text('<?php\n'+text[a:b]+'\n'+text[c:d]+'\n'+text[start:end]+'\n'+text[a2:b2])
PY
docker cp "$TMP" php-wkgtkzta35wkkgouwt6ruljc:/tmp/mad4b-isolated-taxonomy-pair.php
docker cp "$D/test-taxonomy-pair-isolation.php" php-wkgtkzta35wkkgouwt6ruljc:/tmp/mad4b-test-taxonomy-pair.php
docker exec -u0 php-wkgtkzta35wkkgouwt6ruljc chmod 644 /tmp/mad4b-isolated-taxonomy-pair.php /tmp/mad4b-test-taxonomy-pair.php
docker exec php-wkgtkzta35wkkgouwt6ruljc php /tmp/mad4b-test-taxonomy-pair.php /tmp/mad4b-isolated-taxonomy-pair.php
