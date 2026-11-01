#!/usr/bin/env python3
"""Exact, read-only source contract for the bounded Content Hub Queue write mount."""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CATALOG = ROOT / "wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-server-write-catalog.php"
INGRESS = ROOT / "ops/mad4b-governed-task-context-v1/plugin/includes/class-mad4b-hub-queue-site-health-enqueue.php"
HOST = ROOT / "ops/mad4b-governed-task-context-v1/host/native_queue_authority.py"
catalog = CATALOG.read_text()
ingress = INGRESS.read_text()
host = HOST.read_text()

assert "MAD4B_Hub_Queue_Site_Health_Enqueue::ABILITY" in catalog
assert "wp_has_ability( MAD4B_Hub_Queue_Site_Health_Enqueue::ABILITY )" in catalog
assert "MAD4B_Hub_Queue_Site_Health_Enqueue::site_ready()" in catalog
assert "'mad4b/hub-bulk-enqueue'" not in catalog, "General Queue enqueue must stay unmounted"
assert "'mad4b/hub-queue-site-health-enqueue'" in ingress
assert "'category' => 'mad4b-write'" in ingress
assert "'mad4b_governed_write_authority' => true" in ingress
assert "'operation' => 'site_health'" in ingress
assert "'assigned_executor' => 'vps_runner'" in ingress
assert "array_diff(array_keys($input), array('batch_key'))" in ingress
assert "DB_NAME === self::DATABASE" in ingress
assert "'wordpress_actor_user_id'" in host
assert "'wp_actor': 1" not in host
print("MAD4B_HUB_WRITE_CATALOG_SOURCE_CONTRACT: PASS")
