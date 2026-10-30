#!/usr/bin/env bash
set -euo pipefail
: "${WP_CONTAINER:?Set WP_CONTAINER in the instance environment file}"
WP_ROOT="${WP_ROOT:-/var/www/html}"
[[ "$WP_CONTAINER" =~ ^[a-zA-Z0-9][a-zA-Z0-9_.-]*$ ]] || { echo invalid_container_name >&2; exit 2; }
[[ "$WP_ROOT" == /var/www/html ]] || { echo unexpected_wordpress_root >&2; exit 2; }
/usr/bin/docker exec --user www-data "$WP_CONTAINER" /usr/local/bin/wp --path="$WP_ROOT" eval '
if (!class_exists("MAD4B_Media_Reference_Audit")) { fwrite(STDERR,"audit ability unavailable\n"); exit(1); }
MAD4B_Media_Reference_Audit::scheduled();
$state = MAD4B_Media_Reference_Audit::status();
echo wp_json_encode(["contract"=>$state["contract"],"updated_at"=>$state["checkpoint"]["updated_at"]??null,"cursor"=>$state["checkpoint"]["cursor"]??0,"error"=>$state["checkpoint"]["last_error"]??null]);
' 2>/dev/null
