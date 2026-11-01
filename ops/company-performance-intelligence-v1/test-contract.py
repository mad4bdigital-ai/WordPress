from pathlib import Path
root=Path(__file__).parent
s=(root/'company-performance-intelligence.php').read_text()
needed=[
"mad4b/company-performance-scan-plan",
"mad4b.company-performance-intelligence.v1",
"'readonly'=>true",
"'show_in_rest'=>false",
"'public'=>false",
"'mutation_performed'=>false",
"wp_get_environment_type()",
"'staging'",
"'wp_abilities_api_init'",
"'permission_callback' => [__CLASS__, 'can_read']",
"'category' => 'mad4b-read'",
"hash('sha256'",
"'previous_snapshot_sha256'",
"'document_guard'",
"'woocommerce_default_customer_address'",
"'critical_css'",
"'fragments'",
"add_management_page(",
"'manage_options'",
"wp_die(",
]
for value in needed:assert value in s,value
for write in ['update_option(', 'delete_option(', 'wp_update_post(', 'wp_insert_post(', 'wp_remote_post(', 'file_put_contents(', 'unlink(', 'shell_exec(', 'exec(', 'system(', 'apply_optimization(', 'wp_schedule_event(']:
    assert write not in s,write
for customer in ['tours.dev.mad4b.com','35138','26212','25927','25917','187.7.76.129']:
    assert customer not in s,customer
assert s.count('class MAD4B_Company_Performance_Intelligence')==1
print('Company Performance Intelligence source contract PASS')
