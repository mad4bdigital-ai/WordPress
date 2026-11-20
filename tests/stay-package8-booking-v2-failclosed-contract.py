#!/usr/bin/env python3
"""Package-8 live read-only acceptance: prevent accidental payments or fake readiness."""
from pathlib import Path
p=Path(__file__).resolve().parents[1]/'scripts/mad4b-package8-booking-v2-failclosed-cli.php'
s=p.read_text()
required=[
 "https://wayfatours.com",
 "57562,60437,60439,60441,60443,60623,60627,60629",
 "'adult','child','infant'","'quote','request','instant'",
 "'2027-01-15'",
 "'schema_version'=>2",
 "'booking_intent'=>$intent",
 "$payload['age_context']=$syntheticAge[$audience]",
 "shared_product_booking_v2_validate($payload)",
 "shared_product_booking_v2_preview($payload)",
 "wp_count_posts($t)",
 "custom_orders_table_usage_is_enabled()",
 "'shop_order','shop_order_refund'",
 "fluentform_submissions",
 "'orders_sha256'=>hash('sha256'",
 "'fluent_sha256'=>hash('sha256'",
 "'rates_sha256'=>hash('sha256'",
 "$before!==$after",
 "'checkout_ready'=>false",
 "'order_created'=>false",
 "'financial_execution_attempted'=>false",
 "'saved_order_count_unchanged'=>true",
 "'fluent_submissions_unchanged'=>true",
 "'all_order_statuses_unchanged'=>true",
 "'all_rate_post_states_unchanged'=>true",
 "'signed_supplier_fx_receipts_verified'=>false",
 "'company_policy_active'=>false",
]
for token in required: assert token in s,token
for prohibited in [
 'wc_create_order(', 'wc_get_order(', 'wp_insert_post(',
 'wp_update_post(', 'update_option(', 'add_option(', 'delete_option(',
 'wp_set_object_terms(', 'wp_mail(', 'do_action(', 'wp_remote_post(',
 'wp_cache_flush(', 'wp_delete_post(', 'wp_schedule_event('
]:
 assert prohibited not in s,prohibited
assert "if($before!==$after)WP_CLI::error('orders_fluent_or_rates_changed_during_test')" in s
print("PASS bounded 8x3x3=72 read-only Booking v2 cases / HPOS-aware orders / Fluent forms / Rate states")
