import ast,pathlib,sys
p=pathlib.Path(sys.argv[1]);code=(p/'host-local-wp-cron.py').read_text();ast.parse(code)
assert "'/var/www/html/wp-cron.php'" in code
assert "'--user','33:33'" in code
assert 'php\',\'-d\',\'max_execution_time=115\'' in code
assert 'another_local_wp_cron_active' in code
assert 'wordpress_cron_or_commerce_binding_not_ready' in code
assert 'WP_CRON' in code and 'action_scheduler_run_queue' in code
service=(p/'systemd/mad4b-host-local-wp-cron@.service').read_text()
assert 'OnFailure=mad4b-alert-handler@%n.service' in service
assert 'ExecStart=/usr/bin/python3' in service
for site,clock in [('source','*:01/5'),('wayfa','*:03/5')]:
 timer=(p/f'systemd/mad4b-host-local-wp-cron@{site}.timer').read_text()
 assert clock in timer and 'Persistent=true' in timer
print('PASS source-owned independent host-aware cron contract')
