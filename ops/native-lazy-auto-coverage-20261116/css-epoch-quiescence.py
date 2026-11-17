#!/usr/bin/env python3
"""Fail-closed first-party epoch quiet period for heavy Chromium admission.

Usage: css-epoch-quiescence.py <runtime-json> <state-json> [quiet-seconds]
The JSON is produced by the current site's WP-CLI, never visitor headers.
No WordPress writes. A recently changed CSS source returns HOLD (exit 3).
"""
import json,os,sys,tempfile,time
from pathlib import Path

def eligible(current:int,last_home:int,previous:dict|None,now:int,quiet:int):
    if not 0<current<2_147_483_647:
        raise ValueError('invalid CSS epoch')
    if last_home<0 or last_home>now+120:
        raise ValueError('invalid recent source change time')
    if not 60<=quiet<=3600:
        raise ValueError('invalid quiet period')
    if previous and previous.get('epoch')==current:
        since=int(previous['first_seen_at'])
        if since>now+120 or since<0:
            raise ValueError('corrupted persisted epoch observer')
    else:
        # MAD4B Adaptive Asset Intelligence records the exact latest
        # home-impact edit in its first-party queue before bumping CSS epoch.
        # Use that authoritative event time for fast convergence. If no
        # trustworthy event exists, fall back to starting the interval now.
        since=last_home if last_home>0 else now
    quiet_since=max(since,last_home)
    return now-quiet_since>=quiet,{'contract':'mad4b.native-lazy.css-source-quiescence.v1',
         'epoch':current,'first_seen_at':since,'last_home_impact_at':last_home,
         'observed_at':now,'quiet_seconds':quiet}

def main():
    if len(sys.argv) not in (3,4):
        raise ValueError('runtime and state paths required')
    runtime=json.loads(Path(sys.argv[1]).read_text())
    assert runtime['contract']=='mad4b.native-lazy.css-source-observation.v1'
    current=int(runtime['epoch']);last=int(runtime['last_home_impact_at'])
    state=Path(sys.argv[2]);quiet=int(sys.argv[3]) if len(sys.argv)==4 else 600
    now=int(time.time())
    previous=json.loads(state.read_text()) if state.exists() else None
    ready,next_state=eligible(current,last,previous,now,quiet)
    state.parent.mkdir(parents=True,exist_ok=True)
    # No traversals or alternate parent locations are permitted by the caller.
    with tempfile.NamedTemporaryFile(mode='w',encoding='utf-8',
            dir=state.parent,prefix='.epoch-quiet-',delete=False) as fh:
        json.dump(next_state,fh,sort_keys=True)
        fh.write('\n')
        tmp=fh.name
    os.chmod(tmp,0o600)
    os.replace(tmp,state)
    wait=max(0,quiet-(now-max(next_state['first_seen_at'],last)))
    print(('READY' if ready else 'HOLD')+
          f' epoch={current} quiet_remaining_seconds={wait} last_home_impact_at={last}')
    if not ready:sys.exit(3)

if __name__=='__main__':
    try:main()
    except (ValueError,KeyError,TypeError,AssertionError,OSError,json.JSONDecodeError) as e:
        print('HOLD: invalid first-party CSS epoch observation:',str(e),file=sys.stderr)
        sys.exit(4)
