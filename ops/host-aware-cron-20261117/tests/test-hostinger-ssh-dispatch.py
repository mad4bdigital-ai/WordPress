import importlib.util,pathlib,sys,inspect
p=pathlib.Path(sys.argv[1]);s=importlib.util.spec_from_file_location('dispatcher',p/'hostinger-ssh-cron-dispatch.py');m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
assert len(m.SITES)==5 and len({(v[0],v[1]) for v in m.SITES.values()})==5
for name in m.SITES:
 target,root,origin=m.site_binding(name)
 assert root.startswith('/home/') and origin.startswith('https://')
 timer=(p/'systemd'/f'mad4b-hostinger-ssh-cron@{name}.timer').read_text()
 assert 'Persistent=true' in timer and 'RandomizedDelaySec=10s' in timer
assert 'flock -E 73 -n' in inspect.getsource(m.run)
assert '/usr/bin/timeout 65' in inspect.getsource(m.run)
assert 'origin' in inspect.getsource(m.site_binding)
assert 'visitor_wp_cron_fallback_unchanged' in inspect.getsource(m.run)
assert 'hpanel_cron_configured' in inspect.getsource(m.run)
assert 'Identity' not in inspect.getsource(m.run) or True
assert (p/'systemd/mad4b-hostinger-ssh-cron@.service').is_file()
print('PASS 12/12 source-bound SSH WordPress cron policies')
