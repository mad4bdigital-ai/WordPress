#!/usr/bin/env bash
# Read-only, bounded repository or enrolled Staging MU-plugin literal search.
# Replaces recursive host-wide grep searches that can orphan CPU-heavy processes.
set -euo pipefail
usage() {
  echo "Usage: $(basename "$0") {repo|staging-mu} LITERAL_PATTERN" >&2
  exit 2
}
[[ "$#" -eq 2 ]] || usage
scope="$1";needle="$2"
[[ -n "$needle" ]] || usage
[[ "$(printf '%s' "$needle" | wc -c)" -le 160 ]] || usage
case "$scope" in
  repo)
    root="$(cd -- "$(dirname -- "$0")/../../.." && pwd -P)"
    ;;
  staging-mu)
    url="$(docker exec etg-commerce-staging-php wp option get siteurl --allow-root)"
    env="$(docker exec etg-commerce-staging-php wp eval 'echo wp_get_environment_type();' --allow-root)"
    uuid="$(docker exec etg-commerce-staging-php wp eval '$p=get_option("mad4b_scp_brand_context_profile_v1",[]);echo $p["site_uuid"]??"";' --allow-root)"
    [[ "$url" == "https://tours.dev.mad4b.com" ]] || exit 3
    [[ "$env" == staging ]] || exit 3
    [[ "$uuid" == "e5e64bca-ed62-48d2-b2b3-dff693cd702a" ]] || exit 3
    root="/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content/mu-plugins"
    ;;
  *)usage;;
esac
[[ -d "$root" && ! -L "$root" ]] || exit 3
command -v timeout >/dev/null
command -v find >/dev/null
command -v grep >/dev/null
tmp="$(mktemp /tmp/mad4b-scan.XXXXXX)"
trap 'rm -f -- "$tmp"' EXIT
set +e
timeout --signal=TERM --kill-after=3s 15s \
  find "$root" \
    \( -name .git -o -name vendor -o -name node_modules -o -name uploads \) -prune -o \
    -type f -name '*.php' -size -512k -exec grep -IlF -- "$needle" {} + > "$tmp"
status=$?
set -e
head -n 80 "$tmp"
printf 'scope=%s matches=%s shown_max=80 exit_status=%s\n' "$scope" "$(wc -l < "$tmp")" "$status" >&2
case "$status" in
  0|1) exit 0;;
  124|137) echo 'Search reached time budget and was stopped.' >&2;exit 124;;
  *)exit "$status";;
esac
