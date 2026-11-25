from pathlib import Path
p=Path(__file__).resolve().parents[1]/'includes'
s=(p/'Admin/OperationalPage.php').read_text()
assert 'name="dfsb_expected_revision"' in s and "$_POST['dfsb_expected_revision']" in s
assert 'name="etg_dfsb_expected_revision"' not in s
assert "settings_fields('etg_dfsb')" in s and "register_setting('etg_dfsb', Configuration::OPTION_NAME" in s
assert "check_admin_referer('etg_dfsb-options')" in s
assert 'OptionMutationGate::acquire(Configuration::OPTION_NAME)' in s
assert 'hash_equals($expected, $this->config->revision())' in s
print('DFSB_SETTINGS_FORM_CONTRACT: PASS')
