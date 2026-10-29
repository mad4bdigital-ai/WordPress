from pathlib import Path
import ast
p=Path(__file__).parent/'operator.py'
s=p.read_text()
ast.parse(s)
assert 'subprocess.run' in s
assert '"--execute"' in s
assert '"--policy"' in s
assert '"wp","--path="+EXPECTED_PATH' in s
assert '"cron","event","run",hook,"--due-now"' in s
assert 'wp-cron.php' not in s
assert 'wp_get_environment_type()!=="staging"' in s
assert 'DISABLE_WP_CRON!==true' in s
assert 'observed==expected' in s
assert 'source_sha256' in s
assert 'fcntl.LOCK_EX|fcntl.LOCK_NB' in s
assert 'business_hooks_executed":False' in s
assert 'full_cron_replay":False' in s
assert 'save(summary,state_file)' in s
assert 'tours.dev.mad4b.com' not in s
assert 'etg-commerce-staging' not in s
print("GOVERNED_CRON_RUNNER_SOURCE_CONTRACT PASS")
