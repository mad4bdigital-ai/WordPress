#!/usr/bin/env python3
"""Source-only tests: scheduled inspection never silently inherits host write authority."""
from pathlib import Path
s=(Path(__file__).resolve().parents[1]/'includes/class-mad4b-scp-authority-auto-reconciliation.php').read_text()
assert 'add_action(self::EVENT,array(__CLASS__,\'scheduled_inspect\'))' in s
assert "wp_schedule_event(time()+120,'hourly',self::EVENT)" in s
assert "'staging'!==MAD4B_SCP_Site_Profile::current_environment()" in s
assert "!MAD4B_SCP_Site_Profile::origin_enrolled()" in s
assert "!MAD4B_SCP_Site_Profile::site_urls_match_enrollment()" in s
assert "'read_only'=>true,'mutation_performed'=>false" in s
assert "MAD4B_SCP_CLI_Execution_Context::resolve('authority.reconcile',false)" in s
assert "!defined('WP_CLI')||true!==WP_CLI||'cli'!==PHP_SAPI" in s
assert "!function_exists('posix_geteuid')||0!==posix_geteuid()" in s
assert "0!==get_current_user_id()" in s
assert "1!==count($users)" in s
assert "'append_only_no_automatic_deletion'" in s
assert "'production_mutation'=>false" in s
print('AUTHORITY_AUTO_RECONCILE_HOST_BOUNDARY PASS')
