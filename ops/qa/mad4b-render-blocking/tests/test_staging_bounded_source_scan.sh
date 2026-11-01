#!/usr/bin/env bash
set -euo pipefail
root="$(cd -- "$(dirname -- "$0")/../../../.." && pwd -P)"
scan="$root/ops/qa/mad4b-render-blocking/staging_bounded_source_scan.sh"
bash -n "$scan"
bash -n "$0"
out="$(mktemp)"
err="$(mktemp)"
trap 'rm -f "$out" "$err"' EXIT
"$scan" repo 'MAD4B_Priceless_CSS_Cache_V1' >"$out" 2>"$err"
grep -Fq '324-mad4b-priceless-dynamic-css-cache.php' "$out"
grep -Fq 'scope=repo' "$err"
if "$scan" all 'search' >"$out" 2>"$err"; then
  echo 'FAIL: host-wide scan scope accepted' >&2
  exit 2
fi
if "$scan" repo '' >"$out" 2>"$err"; then
  echo 'FAIL: empty scan pattern accepted' >&2
  exit 2
fi
grep -Fq 'timeout --signal=TERM --kill-after=3s 15s' "$scan"
grep -Fq -- '-size -512k' "$scan"
grep -Fq 'e5e64bca-ed62-48d2-b2b3-dff693cd702a' "$scan"
echo 'PASS bounded literal-only source search and fail-closed Staging enrollment'
