from pathlib import Path
import sys
p=Path(sys.argv[1])/'systemd/95-mad4b-css-origin-contract.conf';s=p.read_text();assert s.startswith('[Service]\n')
assert s.count('ExecStart=\n')==1
assert s.count('ExecStart=/usr/bin/flock -w 9 /run/lock/mad4b-wayfa-home-cache-warm.lock')==1
assert 'mad4b-mobile-cache-prime-v2.py --company wayfatours' in s
assert 'ExecCondition=' not in s and 'SuccessExitStatus=' not in s
assert (Path(sys.argv[1])/'mad4b-mobile-cache-prime-v2.py').is_file()
print('PASS source-owned mobile-prime service override and original load-admission retention')
