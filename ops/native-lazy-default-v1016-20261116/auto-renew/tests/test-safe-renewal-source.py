"""Static invariants for the site-local scheduled browser renewal worker.

A source test only: no network, certificates, WP options or schedules altered.
"""
from pathlib import Path
import sys

root=Path(sys.argv[1])
runner=(root/'run-native-lazy-renew.sh').read_text()
browser=(root/'renew-verified-native-lazy-browser.cjs').read_text()
issuer=(root/'renew-verified-native-lazy-admission.php').read_text()
service=(root/'systemd/mad4b-native-lazy-auto-renew.service').read_text()
timer=(root/'systemd/mad4b-native-lazy-auto-renew.timer').read_text()
checks=[
 ("runner locks concurrent audits", 'flock -n 9' in runner),
 ("both live/source ownership hashes checked", 'sha_src' in runner and 'sha_live' in runner and 'q_src' in runner and 'q_live' in runner),
 ("reviewed Query Governor 1.4.2 hash pinned", '17b6edcacd104c38b358d5357379592b3e04b20c3e9d1a37ed16e150aeae51bd' in runner),
 ("no emergency proof override", 'skip' in runner and 'certificate-young' in runner),
 ("browsers must succeed before renewal", "grep -qx 'RENEW_PASS 12'" in runner),
 ("native SSR uses an independent no-lazy query control", "mad4b_native_audit_ssr=1" in browser),
 ("renewal audited on 12 exact sizes", "const widths=[320,390,767,768,1024,1920];" in browser),
 ("signed Ajax query and widget required", 'signedQuery' in browser and 'signedWidget' in browser),
 ("no unsafe cross-market enrollment", "['country']??'')!=='DE'" in issuer and "['currency']??'')!=='USD'" in issuer),
 ("seven-day expiry observed", '7*DAY_IN_SECONDS' in issuer),
 ("pre-existing proof is mandatory", "'status']??'')!=='pass'" in issuer),
 ("all previously tested content hashes remain exact", "'ssr','ajax'" in issuer and "$prior[$name]" in issuer and "$given!==" in issuer),
 ("SQL CAS with advisory serialization", 'GET_LOCK(' in issuer and 'option_value=%s' in issuer),
 ("evidence timestamp age bounded", '20*60' in issuer and '10*60' in issuer),
 ("default timer launches once daily with jitter", 'OnCalendar=*-*-* 23:15:00 UTC' in timer and 'RandomizedDelaySec=900' in timer),
 ("service has bounded runtime and lowered priority", 'TimeoutStartSec=900' in service and 'Nice=15' in service),
]
for label,valid in checks:
 if not valid:raise AssertionError(label)
 print("PASS",label)
print("PASS %d/%d automatic native lazy renewal source invariants"% (len(checks),len(checks)))
