from pathlib import Path
source=Path(__file__).resolve().parents[1]/'includes/class-mad4b-scp-company-master-admin.php'
s=source.read_text()
assert "const VERSION='20261117_v4';" in s
assert "'manage_woocommerce')as$cap)$master->remove_cap($cap);" in s
assert "'manage_options','activate_plugins','install_plugins','update_plugins'" in s
assert "if(!in_array(wp_get_environment_type(),['local','staging'],true))" in s
assert "MAD4B_SCP_Company_Owner_Activation::browserOwnerAuthorized()" in s
assert "if(get_option(self::MIGRATION)==self::VERSION)return;" in s
print("PASS 6 guarded role migration contracts, no Production direct mutation")
