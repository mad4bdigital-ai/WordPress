from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PARITY = ROOT / "wp-content/mu-plugins/432-mad4b-rate-price-language-parity-v1.php"
JOB = ROOT / "ops/runtime/rate-price-language-parity/reconcile.sh"


def test_price_fields_are_whitelisted_and_cross_currency_is_excluded():
    src = PARITY.read_text()
    for field in (
        "single_price", "double_price", "triple_price",
        "single_sale_price", "double_sale_price", "triple_sale_price",
        "egyptians_single_price", "egyptians_double_price",
        "egyptians_triple_price", "has_egyptians_price", "is_on_sale"
    ):
        assert "'" + field + "'" in src
    assert "self::currency($candidate) !== $currency" in src
    assert "self::parent_trid($candidate) !== $parentTrid" in src
    assert "base_currency'" not in src.split("const SYNC_META = [", 1)[1].split("];", 1)[0]


def test_price_changes_apply_on_wordpress_meta_events():
    src = PARITY.read_text()
    for event in ("added_post_meta", "updated_post_meta", "deleted_post_meta"):
        assert event in src
    assert "self::$syncing" in src
    assert "self::copy_field($postId, $peer, $key)" in src
    assert "return (string)$after === (string)$value" in src
    assert "self::remember_tours($members)" in src
    assert "litespeed_purge_post" in src


def test_latest_edit_wins_and_ties_fail_closed():
    src = PARITY.read_text()
    assert "post_modified_gmt" in src
    assert "max(array_column($group, 'modified'))" in src
    assert "conflicting_latest_timestamps" in src
    assert "rate_limit_exceeded" in src
    assert "drift_count" in src
    assert "writes_unconfirmed" in src


def test_recurring_maintenance_requires_real_pricing_access():
    src = PARITY.read_text()
    job = JOB.read_text()
    assert "current_user_can('commerce_manage_pricing')" in src
    assert "authorization_missing" in src
    assert "mad4b_rate_price_parity_integrity_daily_v1" in src
    assert "wp_schedule_event(" in src
    assert "--user=1" in job
    assert "current_user_can(\"commerce_manage_pricing\")" in job
    assert "drift_count" in job
    assert "writes_unconfirmed" in job
    assert "site_mismatch" in job
    assert "docker exec -u www-data" in job
    assert "WP_CLI::error" in job


def test_repo_cron_is_staggered_and_low_priority():
    cron = (ROOT / "ops/runtime/rate-price-language-parity/cron.d.mad4b-tour-rate-language-parity").read_text()
    assert "CRON_TZ=UTC" in cron
    assert "flock" in cron
    assert "timeout -k 5s 120s" in cron
    assert "nice -n 15" in cron
    assert "ionice -c 3" in cron
    assert "reconcile.sh staging" in cron
    assert "reconcile.sh wayfatours" in cron
