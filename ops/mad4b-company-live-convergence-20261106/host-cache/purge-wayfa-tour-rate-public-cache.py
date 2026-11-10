#!/usr/bin/env python3
"""Purge only Wayfatours anonymous Nginx Tour-detail HTML after native WP rate-view changes.

Fixed container + fixed cache directory; no incoming payload, commands, URLs or user data.
"""
import subprocess
import sys
from datetime import datetime, timezone

CONTAINER = 'nginx-guxhp9y6vgh7oouuw8plxrlr'
PURGE = r'''
set -eu
count=0
seen=0
for f in $(find /var/cache/nginx/wayfa -type f); do
    seen=$((seen+1))
    # Native Nginx FastCGI cache keys are recorded at the start of each cache object.
    key=$(head -c 660 "$f" | strings | grep '^KEY: ' | head -n1 || :)
    # Bound the operation to the known public company domain, all its supported
    # language variants, and only *single Tour* detail URL keys (not the archive).
    if printf '%s\n' "$key" | grep -Eq '^KEY: wayfa-public-v3\|https?://wayfatours\.com/((en|es|fr|de|it|ar)/)?tours-and-activities/[^/?[:space:]][^[:space:]]*'; then
        rm -f -- "$f"
        count=$((count+1))
    fi
done
printf 'cache_objects_inspected=%s tour_detail_objects_removed=%s\n' "$seen" "$count"
'''

def main():
    cmd = ['docker','exec',CONTAINER,'sh','-c',PURGE]
    try:
        r = subprocess.run(cmd, text=True, stdout=subprocess.PIPE,
                           stderr=subprocess.PIPE, timeout=27, check=False)
    except (OSError, subprocess.TimeoutExpired) as exc:
        print('MAD4B Tour Cache invalidation ERROR: '+str(exc),file=sys.stderr)
        return 1
    if r.returncode:
        print('MAD4B Tour Cache invalidation ERROR: '+r.stderr[-550:],file=sys.stderr)
        return 1
    print(datetime.now(timezone.utc).isoformat(), 'MAD4B Wayfatours Tour cache:',r.stdout.strip())
    return 0

if __name__ == '__main__':
    sys.exit(main())
