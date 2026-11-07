#!/usr/bin/env python3
"""Run the exact VPS Broker regression in isolated GitHub CI (no live actor)."""
import runpy
import sys
import types
from pathlib import Path

# Production-only dynamic enrolled actor discovery intentionally does not
# exist on CI. Fail closed if a test stops mocking that host-side dependency.
actor = types.ModuleType('wpcli_dynamic_actor')
def forbidden_live_actor(*args, **kwargs):
    raise RuntimeError('HERMETIC_WPCLI_ACTOR_ACCESS_FORBIDDEN')
actor.with_actor = forbidden_live_actor
sys.modules['wpcli_dynamic_actor'] = actor
source = (Path(__file__).resolve().parents[2]
          / 'company-readiness-workflow/central-signed-wpcli-priority-v1/test_vps_broker_busy.py')
assert source.is_file()
runpy.run_path(str(source), run_name='__main__')
