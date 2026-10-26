<?php
/**
 * Source contract for MAD4B rc.102's early REST zero-touch kernel.
 * WordPress-independent; no WordPress DB or HTTP mutation.
 */
$root = dirname(__DIR__, 2);
$bootstrap = file_get_contents($root.'/mad4b-site-control-plane.php');
$installer = file_get_contents($root.'/tools/apply_core_merge.py');
if ($bootstrap === false || $installer === false) { fwrite(STDERR, "Missing source\n"); exit(2); }
$expected = array(
  'policy','gates/plan','reviews/open','reviews/ai',
  'reviews/read','browser/plan','queue/gate','extensions'
);
$checks = 0;
$check = static function ($name, $ok) use (&$checks) {
  ++$checks;
  if (!$ok) { fwrite(STDERR, "FAIL ".$name."\n"); exit(1); }
  echo "PASS ".$name."\n";
};
$guard = strpos($bootstrap, 'Unified Approval is a Local-only');
$deny = strpos($bootstrap, "if ( ! \$mad4b_scp_early_owned ) \$mad4b_scp_early_zero_touch_reason = 'foreign_rest';");
$check('gate_precedes_foreign_rest_return', $guard !== false && $deny !== false && $guard < $deny);
$check('site_local_environment_scoped', strpos(substr($bootstrap, $guard, $deny - $guard), "'local' === wp_get_environment_type()") !== false);
foreach ($expected as $route) {
  $check('admitted_'.$route, strpos(substr($bootstrap, $guard, $deny - $guard), "'/mad4b-unified/v1/".$route."'") !== false);
}
$check('no_wildcard_namespace_admission', strpos(substr($bootstrap, $guard, $deny - $guard), "strpos( \$mad4b_scp_early_rest_path, '/mad4b-unified'") === false);
foreach (array(
  'class-mad4b-scp-unified-policy.php','class-mad4b-scp-unified-approval-workflow.php',
  'class-mad4b-scp-background-authorization.php','class-mad4b-scp-unified-gate-bridge.php',
  'class-mad4b-scp-unified-approval-admin.php',
  'class-mad4b-scp-unified-approval-extensions.php',
  'class-mad4b-scp-unified-approval-queue-bridge.php'
) as $file) {
  $check('bootstrap_'.$file, strpos($bootstrap, "'includes/".$file."'") !== false);
}
$check('installer_rest_gate_present', strpos($installer, 'EARLY_GATE_ANCHOR') !== false);
$check('installer_preserves_bootstrap_group', strpos($installer, 'original_bootstrap_stat.st_gid') !== false);
$check('installer_preserves_include_group', strpos($installer, 'original_include_stat.st_gid') !== false);
echo 'SUMMARY checks='.$checks.' failures=0'."\n";
