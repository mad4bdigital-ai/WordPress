#!/usr/bin/env bash
# Read-only operational profiling; run ON a WordPress Staging host.
# Does not toggle plugins, touch the database, or invoke mutating WP-CLI commands.
set -euo pipefail
WP_CLI="${WP_CLI:-wp}"
SAMPLES="${SAMPLES:-3}"
if ! [[ "$SAMPLES" =~ ^[1-9][0-9]?$ ]]; then echo 'invalid sample count' >&2; exit 2; fi
if ! "$WP_CLI" eval 'if (wp_get_environment_type() !== "staging") exit(3);' --skip-plugins --skip-themes >/dev/null 2>&1; then
  echo 'STAGING_ONLY: required exact WordPress staging environment' >&2; exit 3
fi
measure() {
  local label="$1"; shift
  local i
  for ((i=1;i<=SAMPLES;i++)); do
    "$WP_CLI" eval 'printf("queries=%d files=%d peak_bytes=%d\\n",get_num_queries(),count(get_included_files()),memory_get_peak_usage(true));' --skip-themes "$@" |
      awk -v mode="$label" -v sample="$i" '{print mode "," sample "," $0}'
  done
}
printf 'profile,sample,metrics\n'
measure core_only --skip-plugins
measure other_plugins --skip-plugins=mad4b-site-control-plane
measure all_plugins
echo 'COMPARISON_ONLY: paired runs show correlation, not proven source-level attribution; do not use as release acceptance.'
