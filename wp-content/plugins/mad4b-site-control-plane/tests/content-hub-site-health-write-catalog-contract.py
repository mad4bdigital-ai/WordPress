#!/usr/bin/env python3
"""Pin the site-only Hub diagnostic registration to original governed Write."""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
CATALOG = ROOT / "wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-server-write-catalog.php"
INGRESS = ROOT / "ops/mad4b-governed-task-context-v1/plugin/includes/class-mad4b-hub-queue-site-health-enqueue.php"
HOST = ROOT / "ops/mad4b-governed-task-context-v1/host/native_queue_authority.py"

catalog = CATALOG.read_text(encoding="utf-8")
ingress = INGRESS.read_text(encoding="utf-8")
host = HOST.read_text(encoding="utf-8")

assert "MAD4B_Hub_Queue_Site_Health_Enqueue::ABILITY" in catalog
assert "wp_has_ability( MAD4B_Hub_Queue_Site_Health_Enqueue::ABILITY )" in catalog
assert "MAD4B_Hub_Queue_Site_Health_Enqueue::site_enrolled()" in catalog
assert "MAD4B_Hub_Queue_Site_Health_Enqueue::site_ready()" not in catalog
assert "'mad4b/hub-bulk-enqueue'" not in catalog

assert "const ABILITY = 'mad4b/hub-queue-site-health-enqueue'" in ingress
assert "DB_NAME === self::DATABASE" in ingress
assert "wp_get_environment_type() === 'staging'" in ingress
assert "'category' => 'mad4b-write'" in ingress
assert "'mad4b_governed_write_authority' => true" in ingress
assert "if (!self::site_enrolled() || !function_exists('wp_register_ability'))" in ingress
assert "if (!self::site_ready()) return self::reject('site_or_policy_not_ready')" in ingress
assert "array_diff(array_keys($input), array('batch_key'))" in ingress
assert "'operation' => 'site_health'" in ingress
assert "'assigned_executor' => 'vps_runner'" in ingress
assert "'no_automatic_publish' => true" in ingress
assert "MAD4B_Hub_Bulk_Jobs_Queue::enqueue($batch)" in ingress

assert "scope.get('wordpress_actor_user_id')" in host
assert "'wp_actor': 1" not in host
assert "identity.get('actor_wp_user_id') != actor" in host
print("MAD4B_HUB_EXACT_WRITE_CATALOG_HISTORIC_REVIEW: PASS")
