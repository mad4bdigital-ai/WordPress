#!/usr/bin/env python3
"""Verify that recurrence names never substitute for actual scheduled hook identity."""
import subprocess
import sys
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'tools'))
import production_readiness_registry as reg
head=subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip()
path=reg.PLUGIN+'includes/class-mad4b-scp-authority-auto-reconciliation.php'
found=reg.registered_surfaces(head,path)
assert 'cron:mad4b_authority_auto_reconciliation_inspect' in found,found
assert 'cron:hourly' not in found,found
src=(Path(__file__).resolve().parents[1]/path).read_text()
assert "add_action(self::EVENT,array(__CLASS__,'scheduled_inspect'))" in src
assert "'staging'!==MAD4B_SCP_Site_Profile::current_environment()" in src
assert "wp_schedule_event(time()+120,'hourly',self::EVENT)" in src
assert "public static function scheduled_inspect()" in src
assert "$p=self::plan();" in src
assert "if(is_wp_error($p))return;" in src
print('PASS CRON SOURCE IDENTITY: scoped hook, staging admission, inspect-only planning')
