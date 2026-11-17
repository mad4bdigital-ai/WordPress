#!/usr/bin/env bash
# MAD4B Rate Price Parity: bounded, authorized, same-currency WPML reconciliation.
# Event-driven updates are handled immediately by the MU plugin. The daily
# integrity job heals out-of-band drift without overwriting currency markets.
set -euo pipefail
if [ "$#" -ne 1 ]; then echo "MISSING_SITE_ARGUMENT" >&2; exit 64; fi
case "$1" in
  staging)
    container="php-wkgtkzta35wkkgouwt6ruljc"
    expected_site="https://tours.dev.mad4b.com"
    ;;
  wayfatours)
    container="php-guxhp9y6vgh7oouuw8plxrlr"
    expected_site="https://wayfatours.com"
    ;;
  *)
    echo "INVALID_SITE_ARGUMENT" >&2
    exit 64
    ;;
esac

php=$(cat <<'WP_CLI_PHP'
$expected = (string)getenv("MAD4B_EXPECTED_SITE");
if (home_url() !== $expected) WP_CLI::error("parity_expected_site_mismatch");
if (!current_user_can("commerce_manage_pricing"))
    WP_CLI::error("parity_authorized_pricing_actor_missing");
if (!class_exists("MAD4B_Tour_Rate_Price_Parity_V1", false))
    WP_CLI::error("parity_mu_plugin_missing");
$c = "MAD4B_Tour_Rate_Price_Parity_V1";
$before = $c::reconcile(false);
if ($before["rate_limit_exceeded"] || $before["conflicting_latest_timestamps"] ||
    $before["drift_count"] > 25)
    WP_CLI::error("parity_bounded_reconciliation_refused " . wp_json_encode($before));
if ($before["drift_count"]) {
    $written = $c::reconcile(true);
    if ($written["authorization_missing"] || $written["writes_unconfirmed"] ||
        $written["fields_changed"] !== $before["drift_count"])
        WP_CLI::error("parity_write_not_confirmed " . wp_json_encode($written));
}
$after = $c::reconcile(false);
if ($after["drift_count"] !== 0 || $after["rate_limit_exceeded"] ||
    $after["conflicting_latest_timestamps"])
    WP_CLI::error("parity_postcheck_failed " . wp_json_encode($after));
WP_CLI::success("rate_parity_checked " . wp_json_encode([
 "site" => $expected,
 "groups" => $after["groups_checked"],
 "corrected_fields" => $before["drift_count"],
 "remaining_drift" => $after["drift_count"],
 "actor_has_pricing_capability" => true
]));
WP_CLI_PHP
)

exec /usr/bin/docker exec -u www-data \
  --env "MAD4B_EXPECTED_SITE=$expected_site" \
  "$container" /usr/local/bin/wp --path=/var/www/html --user=1 eval "$php"
