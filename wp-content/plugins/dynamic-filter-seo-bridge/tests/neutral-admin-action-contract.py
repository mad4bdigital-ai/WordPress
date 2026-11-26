from pathlib import Path
import re
root=Path(__file__).resolve().parents[1]/'includes/Admin'
expected={'DynamicContentPage':2,'InventoryControlPage':1,'OperationalPage':2,'PublicationPage':3,'MediaLabPage':2}
for name,count in expected.items():
 s=(root/(name+'.php')).read_text()
 assert not re.search(r'name="action" value="etg_dfsb_',s),name
 actions=re.findall(r'name="action" value="(dfsb_[a-z_]+)"',s)
 assert len(actions)==count,(name,actions)
 for action in actions:
  assert 'admin_post_'+action in s
  assert "checkActionNonce('etg_"+action+"'" in s
  assert re.search(r"wp_(?:nonce_field|create_nonce)\('"+action+r"(?:'|:)",s)
print('DFSB_NEUTRAL_ADMIN_ACTION_CONTRACT: PASS (10 forms)')
