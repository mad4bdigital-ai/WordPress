#!/usr/bin/env python3
from pathlib import Path
import ast
base=Path(__file__).resolve().parent
plugin=base.parent.parent.parent/'wp-content/mu-plugins/429-mad4b-css-wpcron-dispatch-v1.php'
source=plugin.read_text()
worker=(base/'css-wpcron-worker.py').read_text()
checks={
 'WP-Cron dirty events registered':all(x in source for x in ('mad4b_ccss_public_dirty_v1','mad4b_css_scheduler_dirty_v1','wp_schedule_single_event')),
 'frontend never starts shell':all(x not in source for x in ('shell_exec(', 'proc_open(', 'passthru(', 'system(', 'exec(')),
 'WP-CLI only due-event pump':"defined('WP_CLI')" in source and 'wp_get_scheduled_event' in source and 'wp_unschedule_event' in source,
 'generation guarded acknowledgement':"($old['pending_id']??0)!==$id" in source,
 'only enrolled original companies':all(x in source for x in ('tours.dev.mad4b.com','wayfatours.com')),
 'native policies independent of browser QA':'never runs Chromium' in source,
 'backend fixed first-party commands':all(x in worker for x in ('run-public-route.py','run-site.sh','refresh-source-families.py')),
 'bounded cross-company execution':"for site in ('source','wayfa')" in worker and 'WPCSS_WORDPRESS_ACK' in worker,
 'due jobs originate in WordPress':'::pump()' in worker and 'do_action_ref_array' in source
}
ast.parse(worker)
for name,ok in checks.items():
 if not ok:raise AssertionError(name)
 print('PASS',name)
print('PASS',len(checks),'/',len(checks),'WP-Cron source checks')
